<?php
// ─────────────────────────────────────────────────────────────────────────────
//  admin.php — UnityFund Admin Dashboard (standalone, dark Rocker theme)
// ─────────────────────────────────────────────────────────────────────────────
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

requireRole(['admin']);

$CATEGORIES = ['Technology','Arts','Community','Education','Environment','Health','Food','Other'];

$_cu = currentUser();
$userID = (int)$_cu['id'];

// ── Fetch all KPI stats ───────────────────────────────────────────────────────
$raised      = 0;
$donCount    = 0;
$donors      = 0;
$nUsers      = 0;
$nOrganizers = 0;
$nPendingOrg = 0;

try { $raised      = (float)$conn->query("SELECT COALESCE(SUM(Amt),0) FROM Donations")->fetchColumn(); } catch(PDOException $e) {}
try { $donCount    = (int)$conn->query("SELECT COUNT(*) FROM Donations")->fetchColumn(); } catch(PDOException $e) {}
try { $donors      = (int)$conn->query("SELECT COUNT(DISTINCT DonorID) FROM Donations WHERE DonorID IS NOT NULL")->fetchColumn(); } catch(PDOException $e) {}
try { $nUsers      = (int)$conn->query("SELECT COUNT(*) FROM Users")->fetchColumn(); } catch(PDOException $e) {}
try { $nOrganizers = (int)$conn->query("SELECT COUNT(*) FROM Users WHERE Role='organizer'")->fetchColumn(); } catch(PDOException $e) {}
try { $nPendingOrg = (int)$conn->query("SELECT COUNT(*) FROM Users WHERE Role='pending_organizer'")->fetchColumn(); } catch(PDOException $e) {}

// ── Campaign counts by status ─────────────────────────────────────────────────
$campByStatus = ['active' => 0, 'pending' => 0, 'closed' => 0];
try {
    $rows = $conn->query("SELECT Status, COUNT(*) AS N FROM Campaigns GROUP BY Status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($campByStatus[$r['Status']])) $campByStatus[$r['Status']] = (int)$r['N'];
    }
} catch(PDOException $e) {}
$nActiveCamps  = $campByStatus['active'];
$nPendingCamps = $campByStatus['pending'];
$nClosedCamps  = $campByStatus['closed'];
$nTotalCamps   = $nActiveCamps + $nPendingCamps + $nClosedCamps;

// ── Donut chart percentages ────────────────────────────────────────────────────
$dTotal      = max($nTotalCamps, 1);
$activePct   = round(($nActiveCamps  / $dTotal) * 100);
$pendingPct  = round(($nPendingCamps / $dTotal) * 100);
$closedPct   = 100 - $activePct - $pendingPct;

// ── All campaigns (for Campaigns panel) ──────────────────────────────────────
$campaigns = [];
try {
    $campaigns = $conn->query(
        "SELECT c.CampID, c.HostID, c.Title, c.GoalAmt, c.Status, c.Category,
                c.CreatedAt,
                COALESCE(MAX(u.Username),'Unknown') AS HostName,
                COALESCE(SUM(d.Amt),0)              AS TotalRaised,
                COUNT(DISTINCT d.ID)                AS DonationCount,
                COUNT(DISTINCT d.DonorID)           AS DonorCount
         FROM Campaigns c
         LEFT JOIN Users u ON c.HostID = u.UserID
         LEFT JOIN Donations d ON d.CampID = c.CampID
         GROUP BY c.CampID, c.HostID, c.Title, c.GoalAmt, c.Status,
                  c.Category, c.CreatedAt
         ORDER BY
            CASE c.Status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
            c.CreatedAt DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $_camp_err = $e->getMessage(); }

// Campaign images from MongoDB
$campaignImages = [];
try { $campaignImages = getCampaignDetailsMap(array_column($campaigns, 'CampID')); } catch(Exception $e) {}

// ── Pending organizer applications ───────────────────────────────────────────
$pendingOrganizers = [];
$organizerApplications = [];
try {
    $pendingOrganizers = $conn->query(
        "SELECT UserID, Username, Email, CreatedAt
         FROM Users WHERE Role='pending_organizer'
         ORDER BY CreatedAt ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $organizerApplications = getOrganizerApplicationsForUsers(array_column($pendingOrganizers,'UserID'));
} catch(PDOException $e) {}

// ── All users ─────────────────────────────────────────────────────────────────
$allUsers = [];
try {
    $allUsers = $conn->query(
        "SELECT u.UserID, u.Username, u.Email, u.Role, u.IsAnonymous, u.CreatedAt,
                COALESCE(SUM(d.Amt),0)    AS TotalDonated,
                COUNT(DISTINCT d.ID)      AS DonationCount,
                COUNT(DISTINCT c.CampID)  AS CampaignCount
         FROM Users u
         LEFT JOIN Donations d ON d.DonorID = u.UserID
         LEFT JOIN Campaigns c ON c.HostID  = u.UserID
         GROUP BY u.UserID, u.Username, u.Email, u.Role, u.IsAnonymous, u.CreatedAt
         ORDER BY
            CASE u.Role
                WHEN 'admin'             THEN 0
                WHEN 'organizer'         THEN 1
                WHEN 'pending_organizer' THEN 2
                ELSE 3
            END,
            u.CreatedAt DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

// ── All transactions (for Transactions panel) ────────────────────────────────
$transactions = [];
try {
    $transactions = $conn->query(
        "SELECT t.TxID, t.UserID, t.CampID, t.Amt, t.Status, t.GatewayRef,
                t.CreatedAt, t.ProcessedAt, t.FailReason,
                c.Title    AS CampaignTitle,
                u.Username AS DonorName
         FROM Transactions t
         JOIN Campaigns c ON t.CampID = c.CampID
         JOIN Users     u ON t.UserID = u.UserID
         ORDER BY t.CreatedAt DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

$txTotalAmt   = 0;
$txSucceeded  = 0;
$txFailed     = 0;
$txPending    = 0;
foreach ($transactions as $t) {
    if ($t['Status'] === 'success') { $txTotalAmt += (float)$t['Amt']; $txSucceeded++; }
    elseif ($t['Status'] === 'failed') $txFailed++;
    else $txPending++;
}

// ── All receipts (for Receipts panel) ────────────────────────────────────────
$receipts = [];
try {
    $receipts = $conn->query(
        "SELECT r.ID AS ReceiptID, r.DonID, c.CampID,
                u.Username AS DonorName,
                c.Title    AS CampaignTitle,
                d.Amt      AS DonationAmt,
                r.TaxAmount, r.IssuedAt
         FROM Receipts r
         JOIN Donations d ON r.DonID   = d.ID
         JOIN Campaigns c ON d.CampID  = c.CampID
         JOIN Users     u ON d.DonorID = u.UserID
         ORDER BY r.IssuedAt DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

$totalTaxIssued = 0;
foreach ($receipts as $r) $totalTaxIssued += (float)$r['TaxAmount'];

// ── Unread notifications count (for top bar badge) ────────────────────────────
$unread = 0;
try { $unread = countUnreadNotifications($userID); } catch(Exception $e) {}

// ── Real donation counts by month (last 7 months) ────────────────────────────
$months    = [];
$barCounts = [];
try {
    // Build last 7 months ending this month
    $monthMap = [];
    for ($i = 6; $i >= 0; $i--) {
        $dt  = new DateTime('first day of this month');
        $dt->modify("-{$i} months");
        $key             = $dt->format('Y-m');
        $monthMap[$key]  = 0;
    }

    $rows = $conn->query(
        "SELECT FORMAT(Time,'yyyy-MM') AS Ym, COUNT(*) AS N
         FROM Donations
         WHERE Time >= DATEADD(MONTH, -6, DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1))
         GROUP BY FORMAT(Time,'yyyy-MM')
         ORDER BY Ym"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = $r['Ym'];
        if (isset($monthMap[$key])) $monthMap[$key] = (int)$r['N'];
    }

    foreach ($monthMap as $key => $n) {
        $months[]    = (new DateTime($key . '-01'))->format('M');
        $barCounts[] = $n;
    }
} catch (PDOException $e) {
    // Fallback: empty 7 months
    for ($i = 6; $i >= 0; $i--) {
        $dt = new DateTime('first day of this month');
        $dt->modify("-{$i} months");
        $months[]    = $dt->format('M');
        $barCounts[] = 0;
    }
}
$barMax = max($barCounts) ?: 1;

// Sparkline ratios derived from real bar data for KPI cards
$spkMax = $barMax ?: 1;
$spk = array_map(fn($v) => $v / $spkMax, $barCounts);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — UnityFund</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════════════════════════
   CSS VARIABLES
══════════════════════════════════════════════════════════════════════════════ */
:root {
    --sb-bg:        #1a1a2e;
    --sb-w:         240px;
    --tb-bg:        #16213e;
    --tb-h:         62px;
    --body-bg:      #1e1e2e;
    --card-bg:      #252540;
    --card-bg2:     #2a2a4a;
    --card-border:  rgba(255,255,255,.07);
    --text-primary: #e2e8f0;
    --text-muted:   #94a3b8;
    --text-dim:     #64748b;
    --accent:       #02a95c;
    --accent-dim:   rgba(2,169,92,.15);
    --accent-hover: rgba(2,169,92,.25);
    --blue:         #3b82f6;
    --blue-dim:     rgba(59,130,246,.15);
    --amber:        #f59e0b;
    --amber-dim:    rgba(245,158,11,.15);
    --purple:       #8b5cf6;
    --purple-dim:   rgba(139,92,246,.15);
    --danger:       #ef4444;
    --danger-dim:   rgba(239,68,68,.15);
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESET / BASE
══════════════════════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    height: 100%;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    background: var(--body-bg);
    color: var(--text-primary);
    overflow-x: hidden;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LAYOUT SHELL
══════════════════════════════════════════════════════════════════════════════ */
.dash-shell {
    display: flex;
    min-height: 100vh;
}

/* ══════════════════════════════════════════════════════════════════════════════
   SIDEBAR
══════════════════════════════════════════════════════════════════════════════ */
.dash-sidebar {
    position: fixed;
    top: 0; left: 0;
    width: var(--sb-w);
    height: 100vh;
    background: var(--sb-bg);
    border-right: 1px solid var(--card-border);
    display: flex;
    flex-direction: column;
    z-index: 1040;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.08) transparent;
}

/* Logo area */
.sb-brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 20px 20px 18px;
    border-bottom: 1px solid var(--card-border);
    flex-shrink: 0;
}
.sb-brand-mark {
    width: 38px; height: 38px;
    background: var(--accent);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    color: #fff;
    flex-shrink: 0;
}
.sb-brand-name {
    font-size: .875rem;
    font-weight: 700;
    color: var(--text-primary);
    letter-spacing: -.01em;
    line-height: 1.2;
}
.sb-brand-sub {
    font-size: .7rem;
    color: var(--accent);
    font-weight: 500;
    letter-spacing: .04em;
    text-transform: uppercase;
}

/* Nav */
.sb-nav {
    flex: 1;
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.sb-section {
    font-size: .63rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--text-dim);
    padding: 14px 10px 6px;
}
.sb-section:first-child { padding-top: 2px; }

.dash-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 8px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: .83rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    background: transparent;
    width: 100%;
    transition: background .15s, color .15s, border-left-color .15s;
    border-left: 3px solid transparent;
    position: relative;
}
.dash-link:hover {
    background: rgba(255,255,255,.05);
    color: var(--text-primary);
}
.dash-link.is-active {
    background: var(--accent-dim);
    color: var(--accent);
    border-left-color: var(--accent);
}
.dash-link .sb-icon {
    font-size: 1rem;
    flex-shrink: 0;
    width: 18px;
    text-align: center;
}
.dash-link .sb-text {
    flex: 1;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sb-badge {
    font-size: .62rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 99px;
    background: rgba(255,255,255,.1);
    color: var(--text-muted);
    flex-shrink: 0;
}
.sb-badge.alert {
    background: var(--amber);
    color: #000;
}
.sb-ext-icon {
    font-size: .7rem;
    opacity: .3;
    flex-shrink: 0;
}

/* Sidebar footer */
.sb-footer {
    padding: 14px 14px 18px;
    border-top: 1px solid var(--card-border);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.sb-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent) 0%, #016b3a 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
    letter-spacing: -.01em;
}
.sb-footer-name {
    font-size: .82rem;
    font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sb-footer-role {
    font-size: .68rem;
    color: var(--accent);
    font-weight: 500;
}
.sb-signout {
    color: var(--text-dim);
    font-size: 1.05rem;
    text-decoration: none;
    flex-shrink: 0;
    transition: color .15s;
    display: flex; align-items: center;
}
.sb-signout:hover { color: var(--danger); }

.sb-sep {
    border: none;
    border-top: 1px solid var(--card-border);
    margin: 6px 0;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TOP BAR
══════════════════════════════════════════════════════════════════════════════ */
.dash-topbar {
    position: fixed;
    top: 0;
    left: var(--sb-w);
    right: 0;
    height: var(--tb-h);
    background: var(--tb-bg);
    border-bottom: 1px solid var(--card-border);
    display: flex;
    align-items: center;
    padding: 0 28px;
    gap: 12px;
    z-index: 1030;
}
.tb-breadcrumb {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .82rem;
    color: var(--text-muted);
}
.tb-breadcrumb .tb-page {
    font-size: .9rem;
    font-weight: 600;
    color: var(--text-primary);
}
.tb-breadcrumb .tb-sep {
    color: var(--text-dim);
}
.tb-right {
    display: flex;
    align-items: center;
    gap: 10px;
}
.tb-bell {
    position: relative;
    width: 36px; height: 36px;
    background: rgba(255,255,255,.06);
    border-radius: 8px;
    border: 1px solid var(--card-border);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 1rem;
    transition: background .15s, color .15s;
}
.tb-bell:hover { background: rgba(255,255,255,.1); color: var(--text-primary); }
.tb-bell-badge {
    position: absolute;
    top: -4px; right: -4px;
    min-width: 16px; height: 16px;
    background: var(--danger);
    color: #fff;
    font-size: .58rem;
    font-weight: 700;
    border-radius: 99px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px;
    border: 2px solid var(--tb-bg);
}
.tb-user {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 10px 5px 6px;
    background: rgba(255,255,255,.06);
    border: 1px solid var(--card-border);
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 500;
    color: var(--text-primary);
    cursor: pointer;
}
.tb-user-avatar {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent) 0%, #016b3a 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: .7rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
}

/* ══════════════════════════════════════════════════════════════════════════════
   MAIN CONTENT
══════════════════════════════════════════════════════════════════════════════ */
.dash-main {
    margin-left: var(--sb-w);
    margin-top: var(--tb-h);
    min-height: calc(100vh - var(--tb-h));
    padding: 28px;
    flex: 1;
}

/* ── Panels ── */
.dash-panel {
    display: none;
    animation: panelFade .2s ease;
}
.dash-panel.is-active { display: block; }

@keyframes panelFade {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Page header ── */
.page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.page-title {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text-primary);
    letter-spacing: -.02em;
    margin: 0;
}
.page-sub {
    font-size: .8rem;
    color: var(--text-muted);
    margin-top: 2px;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARDS
══════════════════════════════════════════════════════════════════════════════ */
.d-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 14px;
    padding: 22px 22px 18px;
}
.d-card-sm {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 12px;
    padding: 18px 18px 14px;
}

/* ══════════════════════════════════════════════════════════════════════════════
   KPI CARDS
══════════════════════════════════════════════════════════════════════════════ */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 24px;
}
.kpi-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 14px;
    padding: 20px 20px 16px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s, transform .15s;
}
.kpi-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    background: linear-gradient(135deg, rgba(255,255,255,.025) 0%, transparent 60%);
}
.kpi-card:hover {
    border-color: rgba(255,255,255,.14);
    transform: translateY(-1px);
}
.kpi-icon-wrap {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem;
    margin-bottom: 14px;
}
.ic-green  { background: var(--accent-dim);  color: var(--accent); }
.ic-blue   { background: var(--blue-dim);    color: var(--blue); }
.ic-amber  { background: var(--amber-dim);   color: var(--amber); }
.ic-purple { background: var(--purple-dim);  color: var(--purple); }
.ic-red    { background: var(--danger-dim);  color: var(--danger); }

.kpi-val {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -.03em;
    line-height: 1;
    margin-bottom: 4px;
}
.kpi-label {
    font-size: .75rem;
    font-weight: 500;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 14px;
}
.kpi-sub {
    font-size: .75rem;
    color: var(--text-dim);
}
.kpi-sub .kpi-accent { color: var(--accent); font-weight: 600; }
.kpi-sub .kpi-warn   { color: var(--amber);  font-weight: 600; }
.kpi-sub .kpi-blue   { color: var(--blue);   font-weight: 600; }
.kpi-sub .kpi-purple { color: var(--purple); font-weight: 600; }

/* Sparkline */
.sparkline {
    display: flex;
    align-items: flex-end;
    gap: 3px;
    height: 28px;
    margin-top: 12px;
}
.spark-bar {
    flex: 1;
    border-radius: 2px 2px 0 0;
    min-height: 3px;
    transition: opacity .15s;
}
.kpi-card:hover .spark-bar { opacity: 1 !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   LOWER GRID (2 cols)
══════════════════════════════════════════════════════════════════════════════ */
.lower-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 24px;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CSS LINE CHART (Platform Overview)
══════════════════════════════════════════════════════════════════════════════ */
.bar-chart-wrap {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    height: 120px;
    padding-bottom: 0;
}
.bar-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    height: 100%;
    justify-content: flex-end;
}
.bar-fill {
    width: 100%;
    border-radius: 4px 4px 0 0;
    background: linear-gradient(to top, var(--accent) 0%, rgba(2,169,92,.35) 100%);
    transition: opacity .2s;
    min-height: 4px;
    position: relative;
}
.bar-fill:hover { opacity: .85; }
.bar-fill::after {
    content: attr(data-val);
    position: absolute;
    top: -20px;
    left: 50%;
    transform: translateX(-50%);
    font-size: .62rem;
    color: var(--text-muted);
    white-space: nowrap;
    opacity: 0;
    transition: opacity .15s;
    pointer-events: none;
}
.bar-fill:hover::after { opacity: 1; }
.bar-label {
    font-size: .65rem;
    color: var(--text-dim);
    white-space: nowrap;
}
.chart-x-axis {
    border-top: 1px solid var(--card-border);
    margin-top: 8px;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CSS DONUT CHART (Campaign Status)
══════════════════════════════════════════════════════════════════════════════ */
.donut-wrap {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}
.donut-ring {
    position: relative;
    width: 130px; height: 130px;
    flex-shrink: 0;
}
.donut-ring-svg {
    width: 130px; height: 130px;
    border-radius: 50%;
    background: conic-gradient(
        var(--accent)   0%    <?= $activePct ?>%,
        var(--amber)    <?= $activePct ?>%   <?= $activePct + $pendingPct ?>%,
        #334155         <?= $activePct + $pendingPct ?>% 100%
    );
    position: relative;
}
.donut-inner {
    position: absolute;
    inset: 18px;
    border-radius: 50%;
    background: var(--card-bg);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
}
.donut-total {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1;
}
.donut-total-label {
    font-size: .6rem;
    color: var(--text-muted);
    margin-top: 2px;
}
.donut-legend {
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex: 1;
    min-width: 0;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: .8rem;
}
.legend-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.legend-label {
    color: var(--text-muted);
    flex: 1;
}
.legend-val {
    font-weight: 700;
    color: var(--text-primary);
}
.legend-pct {
    font-size: .7rem;
    color: var(--text-dim);
    width: 32px;
    text-align: right;
}

/* ══════════════════════════════════════════════════════════════════════════════
   STAT MINI CARDS (2×3 grid)
══════════════════════════════════════════════════════════════════════════════ */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.stat-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 12px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: border-color .2s;
}
.stat-card:hover { border-color: rgba(255,255,255,.14); }
.stat-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.stat-card-label {
    font-size: .7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-dim);
}
.stat-card-icon {
    width: 30px; height: 30px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem;
}
.stat-card-val {
    font-size: 1.45rem;
    font-weight: 800;
    letter-spacing: -.03em;
    color: var(--text-primary);
    line-height: 1;
}
.stat-card-sub {
    font-size: .7rem;
    color: var(--text-dim);
}
.stat-sparkline {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    height: 20px;
}
.stat-spark-bar {
    flex: 1;
    border-radius: 1px 1px 0 0;
    min-height: 2px;
    opacity: .55;
}

/* ══════════════════════════════════════════════════════════════════════════════
   ALERT ROWS (quick links)
══════════════════════════════════════════════════════════════════════════════ */
.alert-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--card-border);
    background: transparent;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, border-color .15s;
    width: 100%;
    text-align: left;
}
.alert-row:hover { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); }
.alert-row-icon {
    width: 36px; height: 36px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem;
    flex-shrink: 0;
}
.alert-row-title {
    font-size: .83rem;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1.3;
}
.alert-row-desc {
    font-size: .73rem;
    color: var(--text-muted);
    margin: 0;
    margin-top: 1px;
}
.alert-row-arrow {
    color: var(--text-dim);
    font-size: .85rem;
    flex-shrink: 0;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TOOLBAR / FILTER PILLS
══════════════════════════════════════════════════════════════════════════════ */
.d-toolbar {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.d-filter {
    padding: 5px 13px;
    border-radius: 99px;
    border: 1px solid var(--card-border);
    background: transparent;
    color: var(--text-muted);
    font-size: .78rem;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.d-filter:hover { border-color: rgba(255,255,255,.2); color: var(--text-primary); }
.d-filter.on {
    background: var(--accent-dim);
    border-color: var(--accent);
    color: var(--accent);
}

/* ══════════════════════════════════════════════════════════════════════════════
   TABLE AREA
══════════════════════════════════════════════════════════════════════════════ */
.d-table-wrap {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 14px;
    overflow: hidden;
}
.d-table-wrap .table {
    --bs-table-bg: transparent;
    --bs-table-hover-bg: rgba(255,255,255,.03);
    --bs-table-striped-bg: transparent;
    color: var(--text-primary);
    margin-bottom: 0;
}
.d-table-wrap .table thead th {
    background: rgba(0,0,0,.25);
    color: var(--text-dim);
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    border-bottom: 1px solid var(--card-border);
    padding: 11px 14px;
    white-space: nowrap;
}
.d-table-wrap .table tbody td {
    border-bottom: 1px solid var(--card-border);
    padding: 11px 14px;
    vertical-align: middle;
    font-size: .82rem;
}
.d-table-wrap .table tbody tr:last-child td { border-bottom: none; }
.d-table-wrap .table tbody tr:hover td { background: rgba(255,255,255,.025); }

/* ══════════════════════════════════════════════════════════════════════════════
   MODALS — Dark theme overrides
══════════════════════════════════════════════════════════════════════════════ */
.modal-content {
    background: var(--card-bg);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 16px;
    color: var(--text-primary);
}
.modal-header { border-bottom: 1px solid var(--card-border); }
.modal-footer { border-top: 1px solid var(--card-border); }
.modal-title { font-size: .95rem; font-weight: 700; color: var(--text-primary); }
.btn-close { filter: invert(1) brightness(.7); }
.form-control, .form-select {
    background: rgba(0,0,0,.25);
    border: 1px solid rgba(255,255,255,.1);
    color: var(--text-primary);
    border-radius: 8px;
}
.form-control:focus, .form-select:focus {
    background: rgba(0,0,0,.35);
    border-color: var(--accent);
    color: var(--text-primary);
    box-shadow: 0 0 0 3px var(--accent-dim);
}
.form-control::placeholder { color: var(--text-dim); }
.form-label { color: var(--text-muted); font-size: .78rem; margin-bottom: 5px; }
.form-select option { background: #252540; color: var(--text-primary); }

/* ── Bootstrap overrides ── */
.badge.bg-purple {
    background-color: var(--purple) !important;
    color: #fff !important;
}
.text-success-soft {
    color: var(--accent) !important;
    text-decoration: none;
}
.text-success-soft:hover { color: #04d97a !important; }

/* Progress bars in dark context */
.prog-bg {
    background: rgba(255,255,255,.08);
    border-radius: 99px;
    height: 5px;
    overflow: hidden;
    flex: 1;
}
.prog-fill {
    height: 100%;
    border-radius: 99px;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-dim);
}
.empty-state i { font-size: 2.8rem; }
.empty-state p { margin-top: 12px; font-size: .85rem; }

/* Decision textarea inside dark table */
.decision-textarea {
    background: rgba(0,0,0,.25) !important;
    border: 1px solid rgba(255,255,255,.08) !important;
    color: var(--text-primary) !important;
    font-size: .75rem !important;
}
.decision-textarea::placeholder { color: var(--text-dim) !important; }
.decision-textarea:focus {
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 2px var(--accent-dim) !important;
}

/* Scrollbar */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.2); }

/* Section divider label */
.section-divider {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--text-dim);
    margin: 6px 0 2px;
    padding: 0 6px;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVE — mobile: sidebar becomes top strip
══════════════════════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    :root { --sb-w: 0px; --tb-h: 52px; }

    .dash-sidebar {
        position: fixed;
        top: 0; left: 0; right: 0;
        width: 100%;
        height: auto;
        flex-direction: row;
        overflow-x: auto;
        overflow-y: hidden;
        border-right: none;
        border-bottom: 1px solid var(--card-border);
        z-index: 1050;
        padding: 0;
    }
    .sb-brand { display: none; }
    .sb-nav {
        flex-direction: row;
        padding: 0;
        gap: 0;
        align-items: stretch;
        overflow-x: auto;
    }
    .sb-section, .sb-sep, .sb-footer { display: none; }
    .dash-link {
        flex-direction: column;
        padding: 8px 12px;
        gap: 3px;
        border-radius: 0;
        border-left: none;
        border-bottom: 3px solid transparent;
        font-size: .7rem;
        white-space: nowrap;
    }
    .dash-link.is-active {
        border-bottom-color: var(--accent);
        border-left-color: transparent;
        background: var(--accent-dim);
    }
    .dash-link .sb-icon { font-size: .9rem; width: auto; }
    .sb-badge { display: none; }
    .sb-ext-icon { display: none; }

    .dash-topbar {
        top: 44px; /* below mobile sidebar */
        left: 0;
        padding: 0 14px;
    }
    .dash-main {
        margin-left: 0;
        margin-top: calc(44px + var(--tb-h));
        padding: 16px;
    }

    .kpi-grid { grid-template-columns: 1fr 1fr; }
    .lower-grid { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 480px) {
    .kpi-grid { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="dash-shell">

<!-- ══════════════════════════════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════════════════════════════════ -->
<aside class="dash-sidebar">

    <a href="index.php" class="sb-brand" style="text-decoration:none;cursor:pointer;" title="Back to UnityFund">
        <div class="sb-brand-mark"><i class="bi bi-lightning-charge-fill"></i></div>
        <div>
            <div class="sb-brand-name">UnityFund Admin</div>
            <div class="sb-brand-sub">Control Panel</div>
        </div>
    </a>

    <nav class="sb-nav">

        <div class="sb-section">Main</div>

        <button class="dash-link is-active" data-panel="overview">
            <i class="bi bi-grid-1x2-fill sb-icon"></i>
            <span class="sb-text">Dashboard</span>
        </button>

        <a href="index.php" class="dash-link">
            <i class="bi bi-compass-fill sb-icon"></i>
            <span class="sb-text">Discover</span>
            <i class="bi bi-arrow-up-right sb-ext-icon"></i>
        </a>

        <div class="sb-section">Management</div>

        <button class="dash-link" data-panel="campaigns">
            <i class="bi bi-collection-fill sb-icon"></i>
            <span class="sb-text">Campaigns</span>
            <span class="sb-badge"><?= $nTotalCamps ?></span>
        </button>

        <button class="dash-link" data-panel="applications">
            <i class="bi bi-person-badge-fill sb-icon"></i>
            <span class="sb-text">Applications</span>
            <?php if ($nPendingOrg > 0): ?>
            <span class="sb-badge alert"><?= $nPendingOrg ?></span>
            <?php endif; ?>
        </button>

        <button class="dash-link" data-panel="users">
            <i class="bi bi-people-fill sb-icon"></i>
            <span class="sb-text">Users</span>
            <span class="sb-badge"><?= $nUsers ?></span>
        </button>

        <hr class="sb-sep">
        <div class="sb-section">Finance</div>

        <button class="dash-link" data-panel="transactions">
            <i class="bi bi-clock-history sb-icon"></i>
            <span class="sb-text">Transactions</span>
            <span class="sb-badge"><?= count($transactions) ?></span>
        </button>

        <button class="dash-link" data-panel="receipts">
            <i class="bi bi-receipt sb-icon"></i>
            <span class="sb-text">Receipts</span>
            <span class="sb-badge"><?= count($receipts) ?></span>
        </button>

    </nav>

    <div class="sb-footer">
        <div class="sb-avatar"><?= strtoupper(substr($_cu['username'], 0, 2)) ?></div>
        <div style="min-width:0;flex:1;">
            <div class="sb-footer-name"><?= htmlspecialchars($_cu['username']) ?></div>
            <div class="sb-footer-role">Administrator</div>
        </div>
        <a href="logout.php" class="sb-signout" title="Sign out">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>

</aside>

<!-- ══════════════════════════════════════════════════════════════════════════
     TOP BAR
══════════════════════════════════════════════════════════════════════════════ -->
<header class="dash-topbar">
    <div class="tb-breadcrumb">
        <a href="index.php" class="tb-sep" style="color:var(--text-dim);font-size:.75rem;text-decoration:none;transition:color .12s;"
           onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'"
           title="Back to UnityFund site">
            <i class="bi bi-house-door me-1"></i>UnityFund
        </a>
        <i class="bi bi-chevron-right" style="font-size:.6rem;color:var(--text-dim);"></i>
        <span class="tb-page" id="topbar-page-title">Dashboard</span>
    </div>
    <div class="tb-right">
        <a href="inbox.php" class="tb-bell" title="Inbox">
            <i class="bi bi-bell"></i>
            <?php if ($unread > 0): ?>
            <span class="tb-bell-badge"><?= min($unread, 99) ?><?= $unread > 99 ? '+' : '' ?></span>
            <?php endif; ?>
        </a>
        <div class="tb-user">
            <div class="tb-user-avatar"><?= strtoupper(substr($_cu['username'], 0, 1)) ?></div>
            <span><?= htmlspecialchars($_cu['username']) ?></span>
            <i class="bi bi-chevron-down" style="font-size:.65rem;color:var(--text-dim);"></i>
        </div>
    </div>
</header>

<!-- ══════════════════════════════════════════════════════════════════════════
     MAIN CONTENT AREA
══════════════════════════════════════════════════════════════════════════════ -->
<main class="dash-main">

<?php
// ─── Compute sparkline bar heights (px out of 28px max) ──────────────────────
// $spk is built from real monthly data above
?>

<!-- ════════════════════════════════════════════════════════════════════════════
     PANEL: Overview
════════════════════════════════════════════════════════════════════════════ -->
<div id="panel-overview" class="dash-panel is-active">

    <div class="page-head">
        <div>
            <h1 class="page-title">Overview</h1>
            <p class="page-sub"><?= date('l, F j, Y') ?> &mdash; Platform health snapshot</p>
        </div>
        <a href="donate.php" class="btn btn-sm fw-semibold px-4"
           style="background:var(--accent);color:#fff;border:none;border-radius:8px;">
            <i class="bi bi-heart me-1"></i>Donate
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">

        <!-- Total Raised -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap ic-green"><i class="bi bi-currency-dollar"></i></div>
            <div class="kpi-val">$<?= number_format($raised, 0) ?></div>
            <div class="kpi-label">Total Raised</div>
            <div class="kpi-sub">
                <span class="kpi-accent"><?= number_format($donCount) ?></span> donations received
            </div>
            <div class="sparkline">
                <?php foreach ($spk as $h): ?>
                <span class="spark-bar"
                      style="height:<?= round($h*28) ?>px;background:rgba(2,169,92,<?= 0.35 + $h*0.5 ?>);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Total Donations / Donors -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap ic-blue"><i class="bi bi-heart-fill"></i></div>
            <div class="kpi-val"><?= number_format($donCount) ?></div>
            <div class="kpi-label">Total Donations</div>
            <div class="kpi-sub">
                from <span class="kpi-blue"><?= number_format($donors) ?></span> unique donors
            </div>
            <div class="sparkline">
                <?php
                $spk2 = [0.08, 0.12, 0.15, 0.22, 0.18, 0.14, 0.11];
                foreach ($spk2 as $h): ?>
                <span class="spark-bar"
                      style="height:<?= round($h / max($spk2) * 26) ?>px;background:rgba(59,130,246,<?= 0.4 + $h*2 ?>);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Active Campaigns -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap ic-amber"><i class="bi bi-lightning-fill"></i></div>
            <div class="kpi-val"><?= $nActiveCamps ?></div>
            <div class="kpi-label">Active Campaigns</div>
            <div class="kpi-sub">
                <?php if ($nPendingCamps > 0): ?>
                <span class="kpi-warn"><?= $nPendingCamps ?></span> pending review
                <?php else: ?>
                <span class="kpi-accent"><i class="bi bi-check-circle"></i> All clear</span>
                <?php endif; ?>
            </div>
            <div class="sparkline">
                <?php $spk3 = [0.5, 0.7, 0.4, 0.9, 0.6, 0.8, 0.65];
                foreach ($spk3 as $h): ?>
                <span class="spark-bar"
                      style="height:<?= round($h*26) ?>px;background:rgba(245,158,11,<?= 0.3+$h*0.6 ?>);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Total Users -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap ic-purple"><i class="bi bi-people-fill"></i></div>
            <div class="kpi-val"><?= number_format($nUsers) ?></div>
            <div class="kpi-label">Total Users</div>
            <div class="kpi-sub">
                <span class="kpi-purple"><?= $nOrganizers ?></span> active organizer<?= $nOrganizers !== 1 ? 's' : '' ?>
            </div>
            <div class="sparkline">
                <?php $spk4 = [0.3, 0.5, 0.45, 0.6, 0.7, 0.65, 0.75];
                foreach ($spk4 as $h): ?>
                <span class="spark-bar"
                      style="height:<?= round($h*26) ?>px;background:rgba(139,92,246,<?= 0.3+$h*0.6 ?>);"></span>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /kpi-grid -->

    <!-- Lower two-column section -->
    <div class="lower-grid">

        <!-- Platform Overview Bar Chart -->
        <div class="d-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                <div>
                    <div style="font-size:.875rem;font-weight:700;color:var(--text-primary);">Platform Overview</div>
                    <div style="font-size:.73rem;color:var(--text-muted);margin-top:2px;">Donation distribution by month</div>
                </div>
                <span style="font-size:.7rem;padding:3px 10px;border-radius:99px;background:var(--accent-dim);color:var(--accent);font-weight:600;">
                    <?= $donCount ?> total
                </span>
            </div>
            <div class="bar-chart-wrap">
                <?php
                $barMax = max($barCounts) ?: 1;
                foreach ($barCounts as $i => $bv):
                    $pct = round(($bv / $barMax) * 110);
                ?>
                <div class="bar-col">
                    <div class="bar-fill" style="height:<?= $pct ?>px;" data-val="<?= $bv ?>"></div>
                    <span class="bar-label"><?= $months[$i] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Campaign Status Donut -->
        <div class="d-card">
            <div style="font-size:.875rem;font-weight:700;color:var(--text-primary);margin-bottom:18px;">
                Campaign Status
            </div>
            <div class="donut-wrap">
                <div class="donut-ring">
                    <div class="donut-ring-svg"></div>
                    <div class="donut-inner">
                        <span class="donut-total"><?= $nTotalCamps ?></span>
                        <span class="donut-total-label">Total</span>
                    </div>
                </div>
                <div class="donut-legend">
                    <div class="legend-item">
                        <span class="legend-dot" style="background:var(--accent);"></span>
                        <span class="legend-label">Active</span>
                        <span class="legend-val"><?= $nActiveCamps ?></span>
                        <span class="legend-pct"><?= $activePct ?>%</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background:var(--amber);"></span>
                        <span class="legend-label">Pending</span>
                        <span class="legend-val"><?= $nPendingCamps ?></span>
                        <span class="legend-pct"><?= $pendingPct ?>%</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background:#334155;"></span>
                        <span class="legend-label">Closed</span>
                        <span class="legend-val"><?= $nClosedCamps ?></span>
                        <span class="legend-pct"><?= $closedPct ?>%</span>
                    </div>
                    <div style="margin-top:6px;padding-top:10px;border-top:1px solid var(--card-border);">
                        <div style="font-size:.72rem;color:var(--text-dim);">Total raised across all campaigns</div>
                        <div style="font-size:1rem;font-weight:700;color:var(--accent);margin-top:2px;">
                            $<?= number_format($raised, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /lower-grid -->

    <!-- Stat mini cards 2×3 -->
    <div class="stat-grid" style="margin-bottom:24px;">

        <!-- 1 — Raised -->
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-card-label">Total Raised</span>
                <div class="stat-card-icon ic-green"><i class="bi bi-currency-dollar"></i></div>
            </div>
            <div class="stat-card-val" style="color:var(--accent);">$<?= number_format($raised,0) ?></div>
            <div class="stat-card-sub"><?= $donCount ?> transactions</div>
            <div class="stat-sparkline">
                <?php foreach ([0.4,0.6,0.35,0.8,0.5,0.9,0.7] as $h): ?>
                <span class="stat-spark-bar"
                      style="height:<?= round($h*18) ?>px;background:var(--accent);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 2 — Donors -->
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-card-label">Unique Donors</span>
                <div class="stat-card-icon ic-blue"><i class="bi bi-heart-fill"></i></div>
            </div>
            <div class="stat-card-val" style="color:var(--blue);"><?= number_format($donors) ?></div>
            <div class="stat-card-sub">of <?= $nUsers ?> total users</div>
            <div class="stat-sparkline">
                <?php foreach ([0.3,0.5,0.4,0.65,0.55,0.7,0.6] as $h): ?>
                <span class="stat-spark-bar"
                      style="height:<?= round($h*18) ?>px;background:var(--blue);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3 — Active Campaigns -->
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-card-label">Active Campaigns</span>
                <div class="stat-card-icon ic-amber"><i class="bi bi-lightning-fill"></i></div>
            </div>
            <div class="stat-card-val" style="color:var(--amber);"><?= $nActiveCamps ?></div>
            <div class="stat-card-sub"><?= $nPendingCamps ?> pending &bull; <?= $nClosedCamps ?> closed</div>
            <div class="stat-sparkline">
                <?php foreach ([0.6,0.45,0.7,0.5,0.8,0.6,0.75] as $h): ?>
                <span class="stat-spark-bar"
                      style="height:<?= round($h*18) ?>px;background:var(--amber);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 4 — Users -->
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-card-label">Total Users</span>
                <div class="stat-card-icon ic-purple"><i class="bi bi-people-fill"></i></div>
            </div>
            <div class="stat-card-val" style="color:var(--purple);"><?= number_format($nUsers) ?></div>
            <div class="stat-card-sub"><?= $nOrganizers ?> organizer<?= $nOrganizers !== 1 ? 's' : '' ?></div>
            <div class="stat-sparkline">
                <?php foreach ([0.3,0.4,0.5,0.45,0.6,0.55,0.7] as $h): ?>
                <span class="stat-spark-bar"
                      style="height:<?= round($h*18) ?>px;background:var(--purple);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 5 — Pending Applications -->
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-card-label">Pending Applications</span>
                <div class="stat-card-icon ic-amber"><i class="bi bi-person-badge-fill"></i></div>
            </div>
            <div class="stat-card-val" style="color:var(--amber);"><?= $nPendingOrg ?></div>
            <div class="stat-card-sub">
                <?php if ($nPendingOrg > 0): ?>
                <button onclick="switchPanel('applications')" style="background:none;border:none;padding:0;color:var(--amber);font-size:.7rem;cursor:pointer;text-decoration:underline;">Review now</button>
                <?php else: ?>
                No pending reviews
                <?php endif; ?>
            </div>
            <div class="stat-sparkline">
                <?php foreach ([0.5,0.3,0.6,0.4,0.7,0.35,0.5] as $h): ?>
                <span class="stat-spark-bar"
                      style="height:<?= round($h*18) ?>px;background:var(--amber);"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 6 — Pending Campaigns -->
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-card-label">Campaigns to Review</span>
                <div class="stat-card-icon ic-red"><i class="bi bi-hourglass-split"></i></div>
            </div>
            <div class="stat-card-val" style="color:var(--danger);"><?= $nPendingCamps ?></div>
            <div class="stat-card-sub">
                <?php if ($nPendingCamps > 0): ?>
                <button onclick="switchPanel('campaigns'); setCampaignFilter('pending')"
                        style="background:none;border:none;padding:0;color:var(--danger);font-size:.7rem;cursor:pointer;text-decoration:underline;">Approve / reject</button>
                <?php else: ?>
                No pending campaigns
                <?php endif; ?>
            </div>
            <div class="stat-sparkline">
                <?php foreach ([0.7,0.5,0.6,0.4,0.55,0.45,0.3] as $h): ?>
                <span class="stat-spark-bar"
                      style="height:<?= round($h*18) ?>px;background:var(--danger);"></span>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /stat-grid -->

    <!-- Needs Attention quick links -->
    <div class="d-card">
        <div style="font-size:.875rem;font-weight:700;color:var(--text-primary);margin-bottom:14px;">
            <i class="bi bi-bell-fill me-2" style="color:var(--amber);"></i>Needs Attention
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;">

            <button class="alert-row" onclick="switchPanel('applications')">
                <div class="alert-row-icon ic-amber"><i class="bi bi-person-badge"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="alert-row-title"><?= $nPendingOrg ?> organizer application<?= $nPendingOrg !== 1 ? 's' : '' ?> pending</div>
                    <p class="alert-row-desc">Review identity documents, approve or reject.</p>
                </div>
                <?php if ($nPendingOrg > 0): ?>
                <span class="badge" style="background:var(--amber);color:#000;font-size:.68rem;"><?= $nPendingOrg ?></span>
                <?php endif; ?>
                <i class="bi bi-chevron-right alert-row-arrow"></i>
            </button>

            <button class="alert-row" onclick="switchPanel('campaigns'); setCampaignFilter('pending')">
                <div class="alert-row-icon ic-green"><i class="bi bi-hourglass-split"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="alert-row-title"><?= $nPendingCamps ?> campaign<?= $nPendingCamps !== 1 ? 's' : '' ?> awaiting approval</div>
                    <p class="alert-row-desc">Approve or reject new campaign submissions.</p>
                </div>
                <?php if ($nPendingCamps > 0): ?>
                <span class="badge" style="background:var(--accent);color:#fff;font-size:.68rem;"><?= $nPendingCamps ?></span>
                <?php endif; ?>
                <i class="bi bi-chevron-right alert-row-arrow"></i>
            </button>

            <a href="transactions.php" class="alert-row">
                <div class="alert-row-icon ic-blue"><i class="bi bi-clock-history"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="alert-row-title">Transaction log</div>
                    <p class="alert-row-desc">View all payment attempts and gateway references.</p>
                </div>
                <i class="bi bi-chevron-right alert-row-arrow"></i>
            </a>

            <a href="top_donors.php" class="alert-row">
                <div class="alert-row-icon ic-purple"><i class="bi bi-trophy"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="alert-row-title">Top donors leaderboard</div>
                    <p class="alert-row-desc">Public donor recognition and ranking.</p>
                </div>
                <i class="bi bi-chevron-right alert-row-arrow"></i>
            </a>

        </div>
    </div>

</div><!-- /panel-overview -->


<!-- ════════════════════════════════════════════════════════════════════════════
     PANEL: Campaigns
════════════════════════════════════════════════════════════════════════════ -->
<div id="panel-campaigns" class="dash-panel">

    <div class="page-head">
        <div>
            <h1 class="page-title">Campaigns</h1>
            <p class="page-sub">All <?= $nTotalCamps ?> campaign<?= $nTotalCamps !== 1 ? 's' : '' ?> on the platform.</p>
        </div>
    </div>

    <!-- Filter pills -->
    <div class="d-toolbar">
        <?php
        $campCounts = array_count_values(array_column($campaigns,'Status'));
        ?>
        <button class="d-filter on camp-filter" data-filter="all">
            All <span style="opacity:.6;"><?= $nTotalCamps ?></span>
        </button>
        <?php foreach (['pending'=>'Pending','active'=>'Active','closed'=>'Closed'] as $st=>$lbl):
            $n = $campCounts[$st] ?? 0;
            if (!$n) continue;
        ?>
        <button class="d-filter camp-filter" data-filter="<?= $st ?>">
            <?= $lbl ?> <span style="opacity:.6;"><?= $n ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <?php if (empty($campaigns)): ?>
    <div class="empty-state">
        <i class="bi bi-collection"></i>
        <p>No campaigns found.</p>
    </div>
    <?php else: ?>
    <div class="d-table-wrap">
        <div class="table-responsive">
        <table class="table" id="campaigns-table">
            <thead>
                <tr>
                    <th style="width:28%;">Campaign</th>
                    <th>Organizer</th>
                    <th>Progress</th>
                    <th>Donors</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campaigns as $c):
                $raised_c  = (float)$c['TotalRaised'];
                $goal_c    = (float)$c['GoalAmt'];
                $pct_c     = $goal_c > 0 ? min(($raised_c/$goal_c)*100, 100) : 0;
                $cid       = $c['CampID'];
                $campMeta  = $campaignImages[(int)$cid] ?? [];
                $thumb     = $campMeta['thumbnail']   ?? '';
                $desc      = $campMeta['description'] ?? '';
                $adminOwns = (int)($c['HostID'] ?? 0) === $userID;

                $statusCfg = match($c['Status']) {
                    'active'  => ['background:var(--accent-dim);color:var(--accent);',   'Active'],
                    'pending' => ['background:var(--amber-dim);color:var(--amber);',      'Pending'],
                    'closed'  => ['background:rgba(100,116,139,.15);color:#94a3b8;',      'Closed'],
                    default   => ['background:rgba(100,116,139,.15);color:#94a3b8;',      $c['Status']],
                };
            ?>
            <tr class="camp-row" data-status="<?= htmlspecialchars($c['Status']) ?>">
                <!-- Campaign -->
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if ($thumb): ?>
                        <img src="<?= htmlspecialchars($thumb) ?>" alt=""
                             style="width:50px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0;border:1px solid var(--card-border);">
                        <?php else: ?>
                        <div style="width:50px;height:36px;border-radius:6px;background:var(--accent-dim);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-image" style="color:var(--accent);font-size:.8rem;"></i>
                        </div>
                        <?php endif; ?>
                        <div style="min-width:0;">
                            <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                               style="color:var(--accent);text-decoration:none;font-weight:600;font-size:.82rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;">
                                <?= htmlspecialchars($c['Title']) ?>
                            </a>
                            <span style="font-size:.65rem;padding:2px 7px;border-radius:99px;background:var(--accent-dim);color:var(--accent);font-weight:600;">
                                <?= htmlspecialchars($c['Category'] ?? 'Other') ?>
                            </span>
                        </div>
                    </div>
                </td>
                <!-- Organizer -->
                <td>
                    <a href="profile.php?id=<?= (int)$c['HostID'] ?>"
                       style="color:var(--accent);text-decoration:none;font-size:.8rem;font-weight:600;">
                        <?= htmlspecialchars($c['HostName']) ?>
                    </a>
                </td>
                <!-- Progress -->
                <td style="min-width:150px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div class="prog-bg">
                            <div class="prog-fill" style="width:<?= number_format($pct_c,1) ?>%;background:var(--accent);"></div>
                        </div>
                        <span style="font-size:.7rem;color:var(--text-dim);white-space:nowrap;flex-shrink:0;">
                            $<?= number_format($raised_c,0) ?>/$<?= number_format($goal_c,0) ?>
                        </span>
                    </div>
                </td>
                <!-- Donors -->
                <td style="color:var(--text-muted);"><?= (int)$c['DonorCount'] ?></td>
                <!-- Status -->
                <td>
                    <span style="font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:99px;<?= $statusCfg[0] ?>">
                        <?= $statusCfg[1] ?>
                    </span>
                </td>
                <!-- Created -->
                <td style="color:var(--text-dim);white-space:nowrap;">
                    <?= date('M j, Y', strtotime($c['CreatedAt'])) ?>
                </td>
                <!-- Actions -->
                <td class="text-end">
                    <div style="display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap;">
                        <?php if ($c['Status'] === 'pending'): ?>
                        <button class="btn btn-sm" title="Approve"
                                style="background:var(--accent);color:#fff;border:none;border-radius:6px;padding:4px 9px;"
                                onclick="quickStatus(<?= $cid ?>,'active')">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button class="btn btn-sm" title="Reject"
                                style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger-dim);border-radius:6px;padding:4px 9px;"
                                onclick="quickStatus(<?= $cid ?>,'closed')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <?php elseif ($c['Status'] === 'active'): ?>
                        <button class="btn btn-sm" title="Close"
                                style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger-dim);border-radius:6px;padding:4px 9px;"
                                onclick="quickStatus(<?= $cid ?>,'closed')">
                            <i class="bi bi-lock"></i>
                        </button>
                        <?php elseif ($c['Status'] === 'closed'): ?>
                        <button class="btn btn-sm" title="Reactivate"
                                style="background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent-dim);border-radius:6px;padding:4px 9px;"
                                onclick="quickStatus(<?= $cid ?>,'active')">
                            <i class="bi bi-unlock"></i>
                        </button>
                        <?php endif; ?>

                        <?php if ($adminOwns): ?>
                        <button class="btn btn-sm" title="Edit"
                                style="background:rgba(255,255,255,.06);color:var(--text-muted);border:1px solid var(--card-border);border-radius:6px;padding:4px 9px;"
                                onclick="openEditModal(<?= $cid ?>,<?= json_encode($c['Title']) ?>,<?= $goal_c ?>,<?= json_encode($c['Category']??'Other') ?>,<?= json_encode($c['Status']) ?>,<?= json_encode($desc) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn btn-sm" title="Request Change"
                                style="background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2);border-radius:6px;padding:4px 9px;"
                                onclick="openRequestModal(<?= $cid ?>,'<?= htmlspecialchars(addslashes($c['Title'])) ?>')">
                            <i class="bi bi-send"></i>
                        </button>
                        <?php endif; ?>

                        <button class="btn btn-sm" title="Donations"
                                style="background:rgba(255,255,255,.06);color:var(--text-muted);border:1px solid var(--card-border);border-radius:6px;padding:4px 9px;"
                                onclick="openDonationsModal(<?= $cid ?>,<?= json_encode($c['Title']) ?>)">
                            <i class="bi bi-list-ul"></i>
                        </button>
                        <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                           class="btn btn-sm" target="_blank" title="Public page"
                           style="background:rgba(255,255,255,.06);color:var(--text-muted);border:1px solid var(--card-border);border-radius:6px;padding:4px 9px;">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /panel-campaigns -->


<!-- ════════════════════════════════════════════════════════════════════════════
     PANEL: Applications
════════════════════════════════════════════════════════════════════════════ -->
<div id="panel-applications" class="dash-panel">

    <div class="page-head">
        <div>
            <h1 class="page-title">Organizer Applications</h1>
            <p class="page-sub"><?= $nPendingOrg ?> pending review<?= $nPendingOrg !== 1 ? 's' : '' ?>.</p>
        </div>
        <?php if ($nPendingOrg > 0): ?>
        <span style="padding:6px 14px;border-radius:8px;background:var(--amber-dim);color:var(--amber);font-size:.78rem;font-weight:700;">
            <i class="bi bi-exclamation-circle me-1"></i><?= $nPendingOrg ?> awaiting decision
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($pendingOrganizers)): ?>
    <div class="empty-state">
        <i class="bi bi-check2-circle" style="color:var(--accent);"></i>
        <p>No pending applications — all organizer requests have been reviewed.</p>
    </div>
    <?php else: ?>
    <div class="d-table-wrap">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Email</th>
                    <th style="min-width:320px;">Application Details</th>
                    <th>Submitted</th>
                    <th class="text-end" style="min-width:220px;">Decision</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingOrganizers as $org):
                $app = $organizerApplications[(int)$org['UserID']] ?? null;
            ?>
            <tr>
                <!-- Applicant -->
                <td>
                    <div style="display:flex;align-items:center;gap:9px;">
                        <div style="width:34px;height:34px;border-radius:50%;background:var(--amber-dim);border:2px solid var(--amber);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:var(--amber);flex-shrink:0;">
                            <?= strtoupper(substr($org['Username'],0,1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:600;font-size:.82rem;color:var(--text-primary);">
                                <?= htmlspecialchars($org['Username']) ?>
                            </div>
                            <span style="font-size:.65rem;padding:2px 7px;border-radius:99px;background:var(--amber-dim);color:var(--amber);font-weight:600;">Pending</span>
                        </div>
                    </div>
                </td>
                <!-- Email -->
                <td style="color:var(--text-muted);font-size:.8rem;">
                    <?= htmlspecialchars($org['Email']) ?>
                </td>
                <!-- Details -->
                <td>
                    <?php if ($app): ?>
                    <div style="font-size:.8rem;">
                        <div style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($app['legal_name']) ?></div>
                        <div style="color:var(--text-muted);">
                            <?= htmlspecialchars($app['organization_type']) ?>
                            <?php if (!empty($app['organization_name'])): ?>
                            &middot; <?= htmlspecialchars($app['organization_name']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="color:var(--text-dim);font-size:.73rem;">
                            <?= htmlspecialchars($app['focus_category']) ?> &middot; <?= htmlspecialchars($app['estimated_goal_range']) ?>
                        </div>
                        <details class="mt-2">
                            <summary style="cursor:pointer;color:var(--accent);font-size:.76rem;font-weight:600;list-style:none;display:flex;align-items:center;gap:4px;">
                                <i class="bi bi-chevron-right" style="font-size:.6rem;"></i>Full application
                            </summary>
                            <div style="margin-top:8px;padding:12px;border-radius:8px;background:rgba(0,0,0,.2);border:1px solid var(--card-border);font-size:.76rem;display:flex;flex-direction:column;gap:5px;">
                                <div><span style="color:var(--text-dim);">Phone:</span> <?= htmlspecialchars($app['phone']) ?></div>
                                <div><span style="color:var(--text-dim);">DOB:</span> <?= htmlspecialchars($app['date_of_birth']) ?></div>
                                <div><span style="color:var(--text-dim);">ID type:</span> <?= htmlspecialchars($app['government_id_type']) ?></div>
                                <?php if (!empty($app['website_social'])): ?>
                                <div><span style="color:var(--text-dim);">Link:</span>
                                    <a href="<?= htmlspecialchars($app['website_social']) ?>" target="_blank"
                                       style="color:var(--accent);"><?= htmlspecialchars($app['website_social']) ?></a>
                                </div>
                                <?php endif; ?>
                                <div style="margin-top:4px;"><span style="color:var(--text-dim);">Intent:</span><br>
                                    <span style="color:var(--text-muted);"><?= nl2br(htmlspecialchars($app['campaign_intent'])) ?></span>
                                </div>
                                <?php if (!empty($app['fundraising_experience_description'])): ?>
                                <div><span style="color:var(--text-dim);">Experience:</span><br>
                                    <span style="color:var(--text-muted);"><?= nl2br(htmlspecialchars($app['fundraising_experience_description'])) ?></span>
                                </div>
                                <?php endif; ?>
                                <!-- ID photos -->
                                <div style="margin-top:6px;"><span style="color:var(--text-dim);">ID photos:</span>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:5px;">
                                        <?php foreach (['front'=>'Front','back'=>'Back'] as $side=>$lbl):
                                            $path = $app['id_image_'.$side] ?? '';
                                        ?>
                                        <?php if ($path): ?>
                                        <button type="button"
                                                onclick="showIdPhoto(<?= json_encode($path) ?>,<?= json_encode($lbl.' ID') ?>)"
                                                style="padding:0;border:1px solid var(--card-border);border-radius:6px;background:rgba(0,0,0,.3);cursor:pointer;overflow:hidden;">
                                            <img src="<?= htmlspecialchars($path) ?>" alt="<?= htmlspecialchars($lbl) ?>"
                                                 style="width:80px;height:52px;object-fit:cover;display:block;">
                                        </button>
                                        <?php else: ?>
                                        <span style="font-size:.68rem;padding:3px 8px;border-radius:6px;background:rgba(100,116,139,.15);color:var(--text-dim);"><?= htmlspecialchars($lbl) ?> missing</span>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-dim);font-style:italic;font-size:.8rem;">No application details found.</span>
                    <?php endif; ?>
                </td>
                <!-- Submitted -->
                <td style="color:var(--text-dim);font-size:.76rem;white-space:nowrap;">
                    <?= htmlspecialchars($app['submitted_at'] ?? date('M j, Y', strtotime($org['CreatedAt']))) ?>
                </td>
                <!-- Decision -->
                <td class="text-end">
                    <textarea class="form-control decision-textarea mb-2"
                              id="decision-notes-<?= (int)$org['UserID'] ?>"
                              rows="2"
                              placeholder="Notes (required to reject)"></textarea>
                    <div style="display:flex;gap:6px;justify-content:flex-end;">
                        <button class="btn btn-sm fw-semibold px-3"
                                style="background:var(--accent);color:#fff;border:none;border-radius:7px;"
                                onclick="approveOrganizer(<?= (int)$org['UserID'] ?>,'organizer',this)">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button class="btn btn-sm fw-semibold px-3"
                                style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger-dim);border-radius:7px;"
                                onclick="approveOrganizer(<?= (int)$org['UserID'] ?>,'donor',this)">
                            <i class="bi bi-x-lg me-1"></i>Reject
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /panel-applications -->


<!-- ════════════════════════════════════════════════════════════════════════════
     PANEL: Users
════════════════════════════════════════════════════════════════════════════ -->
<div id="panel-users" class="dash-panel">

    <div class="page-head">
        <div>
            <h1 class="page-title">Users</h1>
            <p class="page-sub"><?= $nUsers ?> registered account<?= $nUsers !== 1 ? 's' : '' ?>.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <input type="text" id="user-search-input" class="form-control form-control-sm"
                   style="max-width:220px;font-size:.8rem;"
                   placeholder="Search name or email…"
                   oninput="applyUserFilters()">
            <span style="font-size:.78rem;color:var(--text-muted);white-space:nowrap;" id="user-count-label">
                <?= count($allUsers) ?> users
            </span>
        </div>
    </div>

    <!-- Role filter pills -->
    <?php
    $roleCounts = array_count_values(array_column($allUsers,'Role'));
    $roleFilters = [
        'all'               => ['All',       count($allUsers)],
        'admin'             => ['Admin',     $roleCounts['admin']             ?? 0],
        'organizer'         => ['Organizer', $roleCounts['organizer']         ?? 0],
        'pending_organizer' => ['Pending',   $roleCounts['pending_organizer'] ?? 0],
        'donor'             => ['Donor',     $roleCounts['donor']             ?? 0],
    ];
    ?>
    <div class="d-toolbar">
        <?php foreach ($roleFilters as $rk => [$rl, $rc]):
            if ($rk !== 'all' && !$rc) continue;
        ?>
        <button class="d-filter role-filter <?= $rk === 'all' ? 'on' : '' ?>" data-role="<?= $rk ?>">
            <?= $rl ?> <span style="opacity:.6;"><?= $rc ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="d-table-wrap">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Donated</th>
                    <th>Campaigns</th>
                    <th>Joined</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allUsers as $u):
                $roleStyle = match($u['Role']) {
                    'admin'             => 'background:var(--danger-dim);color:var(--danger);',
                    'organizer'         => 'background:var(--purple-dim);color:var(--purple);',
                    'pending_organizer' => 'background:var(--amber-dim);color:var(--amber);',
                    'donor'             => 'background:var(--blue-dim);color:var(--blue);',
                    default             => 'background:rgba(100,116,139,.15);color:#94a3b8;',
                };
                $roleLabel = match($u['Role']) {
                    'admin'             => 'Admin',
                    'organizer'         => 'Organizer',
                    'pending_organizer' => 'Pending',
                    'donor'             => 'Donor',
                    default             => ucfirst($u['Role']),
                };
                $avatarColor = match($u['Role']) {
                    'admin'             => 'var(--danger)',
                    'organizer'         => 'var(--purple)',
                    'pending_organizer' => 'var(--amber)',
                    default             => 'var(--blue)',
                };
            ?>
            <tr class="user-row"
                data-role="<?= htmlspecialchars($u['Role']) ?>"
                data-search="<?= strtolower(htmlspecialchars($u['Username'].' '.$u['Email'])) ?>">
                <td>
                    <div style="display:flex;align-items:center;gap:9px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $avatarColor ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;color:#fff;flex-shrink:0;opacity:.9;">
                            <?= strtoupper(substr($u['Username'],0,1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($u['Username']) ?></div>
                            <?php if ($u['IsAnonymous']): ?>
                            <span style="font-size:.62rem;padding:1px 6px;border-radius:99px;background:rgba(100,116,139,.2);color:var(--text-dim);">anon</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td style="color:var(--text-muted);font-size:.79rem;"><?= htmlspecialchars($u['Email']) ?></td>
                <td>
                    <span style="font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;<?= $roleStyle ?>">
                        <?= $roleLabel ?>
                    </span>
                </td>
                <td style="font-size:.8rem;">
                    <?php if ((float)$u['TotalDonated'] > 0): ?>
                    <span style="color:var(--accent);font-weight:700;">$<?= number_format((float)$u['TotalDonated'],2) ?></span>
                    <?php if ((int)$u['DonationCount'] > 0): ?>
                    <div style="font-size:.68rem;color:var(--text-dim);"><?= $u['DonationCount'] ?> donation<?= $u['DonationCount'] != 1 ? 's' : '' ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:var(--text-dim);">—</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text-muted);font-size:.8rem;">
                    <?= (int)$u['CampaignCount'] > 0 ? $u['CampaignCount'] : '<span style="color:var(--text-dim);">—</span>' ?>
                </td>
                <td style="color:var(--text-dim);font-size:.75rem;white-space:nowrap;">
                    <?= $u['CreatedAt'] ? date('M j, Y', strtotime($u['CreatedAt'])) : '—' ?>
                </td>
                <td class="text-end">
                    <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;">
                        <a href="profile.php?id=<?= (int)$u['UserID'] ?>"
                           style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:7px;background:var(--accent-dim);color:var(--accent);text-decoration:none;font-size:.75rem;font-weight:600;border:1px solid var(--accent-dim);">
                            <i class="bi bi-person"></i>Profile
                        </a>
                        <?php if ((int)$u['UserID'] !== $userID): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm dropdown-toggle"
                                    style="background:rgba(255,255,255,.06);color:var(--text-muted);border:1px solid var(--card-border);border-radius:7px;padding:4px 10px;font-size:.75rem;"
                                    data-bs-toggle="dropdown">
                                <i class="bi bi-shield-lock me-1"></i>Role
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow"
                                style="background:var(--card-bg);border:1px solid var(--card-border);min-width:160px;">
                                <?php
                                $roleOptions = [
                                    'donor'     => ['bi-person',        'var(--blue)',   'Donor'],
                                    'organizer' => ['bi-megaphone',     'var(--purple)', 'Organizer'],
                                    'admin'     => ['bi-shield-fill',   'var(--danger)', 'Admin'],
                                ];
                                foreach ($roleOptions as $rk => [$icon, $color, $rlabel]):
                                    $isCurrent = $u['Role'] === $rk || ($rk === 'organizer' && $u['Role'] === 'pending_organizer');
                                ?>
                                <li>
                                    <button class="dropdown-item py-2"
                                            style="color:var(--text-primary);font-size:.8rem;<?= $isCurrent ? 'opacity:.4;pointer-events:none;' : '' ?>"
                                            onclick="openRoleModal(<?= (int)$u['UserID'] ?>,<?= json_encode($u['Username']) ?>,<?= json_encode($u['Role']) ?>,<?= json_encode($rk) ?>)">
                                        <i class="bi <?= $icon ?> me-2" style="color:<?= $color ?>;"></i>
                                        <?= $rlabel ?>
                                        <?php if ($isCurrent): ?><span style="font-size:.65rem;opacity:.6;"> (current)</span><?php endif; ?>
                                    </button>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

</div><!-- /panel-users -->

<!-- ════════════════════════════════════════════════════════════════════════════
     PANEL: Transactions
════════════════════════════════════════════════════════════════════════════ -->
<div id="panel-transactions" class="dash-panel">

    <div class="page-head">
        <div>
            <h1 class="page-title">Transactions</h1>
            <p class="page-sub">All payment attempts across the platform &mdash; <?= count($transactions) ?> total.</p>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="kpi-grid" style="margin-bottom:20px;">
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap ic-green" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;">$<?= number_format($txTotalAmt, 2) ?></div>
            <div class="kpi-label">Successful Volume</div>
        </div>
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap ic-green" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;"><?= $txSucceeded ?></div>
            <div class="kpi-label">Succeeded</div>
        </div>
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;background:rgba(239,68,68,.18);color:#f87171;">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;"><?= $txFailed ?></div>
            <div class="kpi-label">Failed</div>
        </div>
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;background:rgba(245,158,11,.18);color:#fbbf24;">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;"><?= $txPending ?></div>
            <div class="kpi-label">Pending</div>
        </div>
    </div>

    <!-- Filter pills -->
    <div class="d-toolbar">
        <button class="d-filter on tx-filter" data-filter="all">
            All <span style="opacity:.6;"><?= count($transactions) ?></span>
        </button>
        <?php if ($txSucceeded): ?>
        <button class="d-filter tx-filter" data-filter="success">
            Success <span style="opacity:.6;"><?= $txSucceeded ?></span>
        </button>
        <?php endif; ?>
        <?php if ($txFailed): ?>
        <button class="d-filter tx-filter" data-filter="failed">
            Failed <span style="opacity:.6;"><?= $txFailed ?></span>
        </button>
        <?php endif; ?>
        <?php if ($txPending): ?>
        <button class="d-filter tx-filter" data-filter="pending">
            Pending <span style="opacity:.6;"><?= $txPending ?></span>
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($transactions)): ?>
    <div class="empty-state">
        <i class="bi bi-clock-history"></i>
        <p>No transactions yet.</p>
    </div>
    <?php else: ?>
    <div class="d-table-wrap">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Donor</th>
                    <th>Campaign</th>
                    <th>Amount</th>
                    <th>Date (GMT+7)</th>
                    <th>Status</th>
                    <th>Reference</th>
                    <th class="text-end">Receipt</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($transactions as $t):
                $txStatusCfg = match($t['Status']) {
                    'success' => ['background:var(--accent-dim);color:var(--accent);',          '<i class="bi bi-check-circle-fill me-1"></i>Success'],
                    'failed'  => ['background:rgba(239,68,68,.15);color:#f87171;',              '<i class="bi bi-x-circle-fill me-1"></i>Failed'],
                    'pending' => ['background:rgba(245,158,11,.15);color:#fbbf24;',             '<i class="bi bi-hourglass me-1"></i>Pending'],
                    default   => ['background:rgba(100,116,139,.15);color:#94a3b8;', ucfirst($t['Status'])],
                };
                $dt = new DateTime($t['ProcessedAt'] ?: $t['CreatedAt'], new DateTimeZone('Asia/Ho_Chi_Minh'));
            ?>
            <tr class="tx-row" data-status="<?= htmlspecialchars($t['Status']) ?>">
                <td style="color:var(--text-dim);font-size:.78rem;">#<?= $t['TxID'] ?></td>
                <td style="font-size:.83rem;color:var(--text-primary);font-weight:500;">
                    <?= htmlspecialchars($t['DonorName'] ?? '—') ?>
                </td>
                <td>
                    <a href="partner/campaign/campaign-detail.php?id=<?= (int)$t['CampID'] ?>"
                       style="color:var(--accent);text-decoration:none;font-size:.83rem;font-weight:500;">
                        <?= htmlspecialchars($t['CampaignTitle']) ?>
                    </a>
                </td>
                <td>
                    <strong style="color:<?= $t['Status']==='success' ? 'var(--accent)' : 'var(--text-muted)' ?>;font-size:.88rem;">
                        $<?= number_format((float)$t['Amt'], 2) ?>
                    </strong>
                </td>
                <td style="color:var(--text-muted);font-size:.78rem;white-space:nowrap;">
                    <?= $dt->format('M j, Y') ?><br>
                    <span style="color:var(--text-dim);font-size:.72rem;"><?= $dt->format('H:i:s') ?></span>
                </td>
                <td>
                    <span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:6px;font-size:.7rem;font-weight:600;<?= $txStatusCfg[0] ?>">
                        <?= $txStatusCfg[1] ?>
                    </span>
                    <?php if ($t['Status'] === 'failed' && $t['FailReason']): ?>
                    <div style="color:var(--text-dim);font-size:.7rem;margin-top:3px;max-width:160px;">
                        <?= htmlspecialchars($t['FailReason']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="color:var(--text-dim);font-family:monospace;font-size:.72rem;word-break:break-all;">
                        <?= htmlspecialchars(substr($t['GatewayRef'], 0, 22)) ?>…
                    </span>
                </td>
                <td class="text-end">
                    <?php if ($t['Status'] === 'success'): ?>
                    <a href="payment_success.php?ref=<?= urlencode($t['GatewayRef']) ?>"
                       target="_blank"
                       style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:var(--accent-dim);color:var(--accent);text-decoration:none;font-size:.72rem;font-weight:600;border:1px solid var(--accent-dim);"
                       title="View receipt">
                        <i class="bi bi-receipt"></i>View
                    </a>
                    <?php else: ?>
                    <span style="color:var(--text-dim);">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <p style="text-align:center;color:var(--text-dim);font-size:.75rem;margin-top:14px;">
        All times shown in GMT+7 (Indochina Time). Payments processed by <strong style="color:var(--text-muted);">Stripe</strong>.
    </p>
    <?php endif; ?>

</div><!-- /panel-transactions -->


<!-- ════════════════════════════════════════════════════════════════════════════
     PANEL: Receipts
════════════════════════════════════════════════════════════════════════════ -->
<div id="panel-receipts" class="dash-panel">

    <div class="page-head">
        <div>
            <h1 class="page-title">Tax Receipts</h1>
            <p class="page-sub">Auto-issued for donations over $50 &mdash; 10% deductible.</p>
        </div>
    </div>

    <!-- Summary -->
    <div class="kpi-grid" style="margin-bottom:20px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap ic-green" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;">
                <i class="bi bi-receipt-cutoff"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;"><?= count($receipts) ?></div>
            <div class="kpi-label">Receipts Issued</div>
        </div>
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap ic-blue" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;">
                <i class="bi bi-cash-coin"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;">$<?= number_format($totalTaxIssued, 2) ?></div>
            <div class="kpi-label">Total Tax-Deductible</div>
        </div>
        <div class="kpi-card" style="padding:18px 20px;">
            <div class="kpi-icon-wrap ic-purple" style="width:36px;height:36px;font-size:.95rem;margin-bottom:10px;">
                <i class="bi bi-shield-check"></i>
            </div>
            <div class="kpi-val" style="font-size:1.4rem;">10%</div>
            <div class="kpi-label">Deductible Rate</div>
        </div>
    </div>

    <?php if (empty($receipts)): ?>
    <div class="empty-state">
        <i class="bi bi-receipt"></i>
        <p>No receipts issued yet. Receipts auto-generate for donations over $50.</p>
    </div>
    <?php else: ?>
    <div class="d-table-wrap">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Donor</th>
                    <th>Campaign</th>
                    <th>Donation</th>
                    <th>Tax-Deductible</th>
                    <th class="text-end">Issued</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($receipts as $r):
                $rcid   = (int)$r['CampID'];
                $rThumb = $campaignImages[$rcid]['thumbnail'] ?? '';
            ?>
            <tr>
                <td style="font-weight:600;color:var(--text-primary);font-size:.83rem;font-family:monospace;">
                    REC-<?= str_pad($r['ReceiptID'], 5, '0', STR_PAD_LEFT) ?>
                </td>
                <td style="font-size:.83rem;color:var(--text-primary);font-weight:500;">
                    <?= htmlspecialchars($r['DonorName']) ?>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <?php if ($rThumb): ?>
                        <img src="<?= htmlspecialchars($rThumb) ?>" alt=""
                             style="width:42px;height:30px;object-fit:cover;border-radius:5px;flex-shrink:0;">
                        <?php endif; ?>
                        <a href="partner/campaign/campaign-detail.php?id=<?= $rcid ?>"
                           style="color:var(--accent);text-decoration:none;font-size:.83rem;font-weight:500;">
                            <?= htmlspecialchars($r['CampaignTitle']) ?>
                        </a>
                    </div>
                </td>
                <td>
                    <strong style="color:var(--text-primary);font-size:.88rem;">
                        $<?= number_format($r['DonationAmt'], 2) ?>
                    </strong>
                </td>
                <td>
                    <strong style="color:var(--accent);font-size:.88rem;">
                        $<?= number_format($r['TaxAmount'], 2) ?>
                    </strong>
                </td>
                <td class="text-end" style="color:var(--text-muted);font-size:.77rem;white-space:nowrap;">
                    <?= date('M j, Y', strtotime($r['IssuedAt'])) ?><br>
                    <span style="color:var(--text-dim);font-size:.72rem;"><?= date('g:i A', strtotime($r['IssuedAt'])) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /panel-receipts -->

</main><!-- /dash-main -->
</div><!-- /dash-shell -->


<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════════════════════════ -->

<!-- ID Photo Modal -->
<div class="modal fade" id="idPhotoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="id-photo-title">ID Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <img id="id-photo-full" src="" alt="ID photo"
                     style="width:100%;max-height:70vh;object-fit:contain;border-radius:8px;border:1px solid var(--card-border);background:rgba(0,0,0,.4);">
            </div>
        </div>
    </div>
</div>

<!-- Edit Campaign Modal -->
<div class="modal fade" id="editCampModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2" style="color:var(--accent);"></i>Edit Campaign</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-camp-id">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Title</label>
                        <input type="text" id="edit-title" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Goal (USD)</label>
                        <input type="number" id="edit-goal" class="form-control" min="1" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Category</label>
                        <select id="edit-category" class="form-select">
                            <?php foreach ($CATEGORIES as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select id="edit-status" class="form-select">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea id="edit-description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Campaign image</label>
                        <input type="file" id="edit-campaign-image" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="mt-1" style="font-size:.73rem;color:var(--text-dim);">
                            Creates a 1200×630 banner and 400×300 thumbnail.
                        </div>
                    </div>
                </div>
                <div id="edit-modal-alert" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm fw-semibold px-4"
                        style="background:var(--accent);color:#fff;border:none;border-radius:7px;"
                        onclick="saveEditModal()">
                    <i class="bi bi-check2 me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Role Change Confirmation Modal -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;">
            <div class="modal-header" style="border-bottom:1px solid var(--card-border);padding:18px 22px 14px;">
                <h5 class="modal-title" style="color:var(--text-primary);font-size:.95rem;font-weight:700;">
                    <i class="bi bi-shield-lock me-2" style="color:var(--amber);"></i>Change User Role
                </h5>
                <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px 22px;">
                <div id="role-modal-summary" style="margin-bottom:16px;padding:12px 14px;border-radius:9px;background:rgba(0,0,0,.2);border:1px solid var(--card-border);font-size:.82rem;color:var(--text-muted);line-height:1.7;"></div>
                <label style="font-size:.78rem;color:var(--text-dim);font-weight:600;text-transform:uppercase;letter-spacing:.05em;">
                    Reason <span style="color:var(--danger);">*</span>
                </label>
                <textarea id="role-modal-reason" rows="3" class="form-control mt-1"
                          style="background:rgba(0,0,0,.2);border:1px solid var(--card-border);color:var(--text-primary);font-size:.82rem;resize:none;border-radius:8px;"
                          placeholder="Briefly explain why you are changing this user's role…"></textarea>
                <div id="role-modal-warn" style="display:none;margin-top:10px;padding:9px 12px;border-radius:8px;background:var(--danger-dim);border:1px solid rgba(239,68,68,.3);color:var(--danger);font-size:.78rem;">
                    <i class="bi bi-exclamation-triangle me-1"></i><span id="role-modal-warn-text"></span>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--card-border);padding:14px 22px;gap:8px;">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal"
                        style="background:rgba(255,255,255,.06);color:var(--text-muted);border:1px solid var(--card-border);border-radius:8px;padding:6px 18px;">
                    Cancel
                </button>
                <button type="button" id="role-modal-confirm" class="btn btn-sm fw-semibold"
                        style="border-radius:8px;padding:6px 20px;"
                        onclick="confirmRoleChange()">
                    Confirm Change
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Donations Modal -->
<div class="modal fade" id="donationsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="bi bi-list-ul me-2" style="color:var(--accent);"></i>Donations</h5>
                    <p style="font-size:.75rem;color:var(--text-muted);margin:0;" id="donations-modal-subtitle"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="donations-modal-body">
                <div style="text-align:center;padding:32px;color:var(--text-dim);">Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- Request Change Modal -->
<div class="modal fade" id="requestChangeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-send me-2" style="color:var(--amber);"></i>Request Change
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:14px;">
                    Send a change request to the organizer of
                    <a id="modal-camp-title" class="fw-semibold" style="color:var(--accent);text-decoration:none;" href="#"></a>.
                </p>
                <input type="hidden" id="modal-camp-id">
                <div class="mb-3">
                    <label class="form-label">What to change</label>
                    <select id="modal-change-type" class="form-select">
                        <option value="name">Campaign name</option>
                        <option value="goal">Funding goal</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Message to organizer</label>
                    <textarea id="modal-message" class="form-control" rows="4"
                              placeholder="Explain what needs to change and why…"></textarea>
                </div>
                <div id="modal-alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm fw-semibold px-4"
                        style="background:var(--amber);color:#000;border:none;border-radius:7px;"
                        onclick="sendChangeRequest()">
                    <i class="bi bi-send me-1"></i>Send Request
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     SCRIPTS
══════════════════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

// ── Panel switcher ────────────────────────────────────────────────────────────
const pageTitles = {
    overview:     'Dashboard',
    campaigns:    'Campaigns',
    applications: 'Applications',
    users:        'Users',
    transactions: 'Transactions',
    receipts:     'Tax Receipts',
};

function switchPanel(name) {
    document.querySelectorAll('.dash-link[data-panel]').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('.dash-panel').forEach(p => p.classList.remove('is-active'));

    const navEl   = document.querySelector('.dash-link[data-panel="' + name + '"]');
    const panelEl = document.getElementById('panel-' + name);
    if (navEl)   navEl.classList.add('is-active');
    if (panelEl) panelEl.classList.add('is-active');

    const titleEl = document.getElementById('topbar-page-title');
    if (titleEl) titleEl.textContent = pageTitles[name] || name;

    history.replaceState(null, '', '#' + name);
}

document.querySelectorAll('.dash-link[data-panel]').forEach(btn => {
    btn.addEventListener('click', () => switchPanel(btn.dataset.panel));
});

// Restore from URL hash
(function () {
    const h = location.hash.replace('#', '');
    if (h && document.getElementById('panel-' + h)) switchPanel(h);
})();

// ── Campaign filter pills ─────────────────────────────────────────────────────
function setCampaignFilter(status) {
    const btn = document.querySelector('.camp-filter[data-filter="' + status + '"]');
    if (btn) btn.click();
}

document.querySelectorAll('.camp-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.camp-filter').forEach(b => b.classList.remove('on'));
        this.classList.add('on');
        const f = this.dataset.filter;
        document.querySelectorAll('.camp-row').forEach(row => {
            row.style.display = (f === 'all' || row.dataset.status === f) ? '' : 'none';
        });
    });
});

// ── Transaction filter pills ──────────────────────────────────────────────────
document.querySelectorAll('.tx-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tx-filter').forEach(b => b.classList.remove('on'));
        this.classList.add('on');
        const f = this.dataset.filter;
        document.querySelectorAll('.tx-row').forEach(row => {
            row.style.display = (f === 'all' || row.dataset.status === f) ? '' : 'none';
        });
    });
});

// ── User role filter + live text search ──────────────────────────────────────
let _activeRole = 'all';

document.querySelectorAll('.role-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        _activeRole = this.dataset.role;
        document.querySelectorAll('.role-filter').forEach(b => b.classList.remove('on'));
        this.classList.add('on');
        applyUserFilters();
    });
});

function applyUserFilters() {
    const q = (document.getElementById('user-search-input')?.value || '').toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.user-row').forEach(row => {
        const show = (_activeRole === 'all' || row.dataset.role === _activeRole)
                  && (!q || row.dataset.search.includes(q));
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const lbl = document.getElementById('user-count-label');
    if (lbl) lbl.textContent = visible + ' user' + (visible !== 1 ? 's' : '');
}

// ── ID Photo modal ────────────────────────────────────────────────────────────
function showIdPhoto(path, title) {
    document.getElementById('id-photo-title').textContent = title || 'ID Photo';
    document.getElementById('id-photo-full').src = path;
    new bootstrap.Modal(document.getElementById('idPhotoModal')).show();
}

// ── Edit Campaign modal ───────────────────────────────────────────────────────
function openEditModal(campId, title, goal, category, status, description) {
    document.getElementById('edit-camp-id').value     = campId;
    document.getElementById('edit-title').value       = title;
    document.getElementById('edit-goal').value        = goal;
    document.getElementById('edit-category').value    = category;
    document.getElementById('edit-status').value      = status;
    document.getElementById('edit-description').value = description;
    document.getElementById('edit-campaign-image').value = '';
    document.getElementById('edit-modal-alert').innerHTML = '';
    new bootstrap.Modal(document.getElementById('editCampModal')).show();
}

async function uploadCampaignImage(campId, file) {
    const form = new FormData();
    form.append('camp_id', campId);
    form.append('image', file);
    const res  = await fetch('api/upload_campaign_image.php', { method: 'POST', body: form });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) throw new Error(data.error || 'Image upload failed');
    return data;
}

async function saveEditModal() {
    const campId  = document.getElementById('edit-camp-id').value;
    const alertEl = document.getElementById('edit-modal-alert');
    const image   = document.getElementById('edit-campaign-image').files[0];
    const payload = {
        camp_id:     parseInt(campId),
        title:       document.getElementById('edit-title').value.trim(),
        goal:        parseFloat(document.getElementById('edit-goal').value),
        category:    document.getElementById('edit-category').value,
        status:      document.getElementById('edit-status').value,
        description: document.getElementById('edit-description').value.trim(),
    };
    try {
        const data = await fetch('api/update_campaign.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(r => r.json());
        if (data.success) {
            if (image) {
                alertEl.innerHTML = '<div class="alert alert-info py-2 small">Saved. Uploading image…</div>';
                await uploadCampaignImage(campId, image);
            }
            alertEl.innerHTML = '<div class="alert alert-success py-2 small">Changes saved!</div>';
            setTimeout(() => location.reload(), 900);
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`;
        }
    } catch {
        alertEl.innerHTML = '<div class="alert alert-danger py-2 small">Network error.</div>';
    }
}

// ── Quick status update ───────────────────────────────────────────────────────
async function quickStatus(campID, newStatus) {
    if (!confirm(`Set campaign status to "${newStatus}"?`)) return;
    try {
        const data = await fetch('api/update_campaign.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ camp_id: campID, status: newStatus }),
        }).then(r => r.json());
        if (data.success) location.reload();
        else alert('Error: ' + (data.error || 'Update failed'));
    } catch { alert('Network error.'); }
}

// ── Role change modal ─────────────────────────────────────────────────────────
let _rolePayload = null;

function openRoleModal(userId, username, currentRole, newRole) {
    _rolePayload = { userId, username, currentRole, newRole };

    const roleLabels = {
        donor: 'Donor', organizer: 'Organizer',
        pending_organizer: 'Pending Organizer', admin: 'Admin'
    };
    const roleColors = {
        donor: 'var(--blue)', organizer: 'var(--purple)',
        pending_organizer: 'var(--amber)', admin: 'var(--danger)'
    };

    const fromLabel = roleLabels[currentRole] ?? currentRole;
    const toLabel   = roleLabels[newRole]     ?? newRole;
    const toColor   = roleColors[newRole]     ?? 'var(--text-primary)';

    const isDemotion = (currentRole === 'admin' && newRole !== 'admin') ||
                       (currentRole === 'organizer' && newRole === 'donor');
    const isPromotion = newRole === 'admin' ||
                        (newRole === 'organizer' && currentRole === 'donor');

    document.getElementById('role-modal-summary').innerHTML =
        `User: <strong style="color:var(--text-primary);">${username}</strong><br>` +
        `Current role: <strong>${fromLabel}</strong><br>` +
        `New role: <strong style="color:${toColor};">${toLabel}</strong>`;

    const warn = document.getElementById('role-modal-warn');
    const warnText = document.getElementById('role-modal-warn-text');
    if (newRole === 'admin') {
        warn.style.display = '';
        warnText.textContent = 'This grants full admin access including user management.';
    } else if (isDemotion) {
        warn.style.display = '';
        warnText.textContent = 'This will demote the user and revoke their current privileges.';
    } else {
        warn.style.display = 'none';
    }

    const confirmBtn = document.getElementById('role-modal-confirm');
    confirmBtn.style.background = isPromotion ? 'var(--accent)' : isDemotion ? 'var(--danger)' : 'var(--accent)';
    confirmBtn.style.color = '#fff';
    confirmBtn.style.border = 'none';
    confirmBtn.disabled = false;

    document.getElementById('role-modal-reason').value = '';
    new bootstrap.Modal(document.getElementById('roleModal')).show();
    setTimeout(() => document.getElementById('role-modal-reason').focus(), 400);
}

async function confirmRoleChange() {
    if (!_rolePayload) return;
    const reason = document.getElementById('role-modal-reason').value.trim();
    if (!reason) {
        document.getElementById('role-modal-reason').focus();
        document.getElementById('role-modal-reason').style.borderColor = 'var(--danger)';
        return;
    }
    document.getElementById('role-modal-reason').style.borderColor = '';

    const btn = document.getElementById('role-modal-confirm');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    try {
        const data = await fetch('api/update_user_role.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: _rolePayload.userId,
                role:    _rolePayload.newRole,
                notes:   reason,
                notify:  true,
            }),
        }).then(r => r.json());

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('roleModal')).hide();
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Update failed'));
            btn.disabled = false;
            btn.textContent = 'Confirm Change';
        }
    } catch {
        alert('Network error.');
        btn.disabled = false;
        btn.textContent = 'Confirm Change';
    }
}

// ── Approve / reject organizer ────────────────────────────────────────────────
async function approveOrganizer(userID, newRole, btn) {
    const notesEl = document.getElementById('decision-notes-' + userID);
    const notes   = notesEl ? notesEl.value.trim() : '';
    if (newRole === 'donor' && !notes) {
        alert('Please enter decision notes before rejecting.');
        notesEl?.focus();
        return;
    }
    btn.disabled = true;
    try {
        const data = await fetch('api/update_user_role.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userID, role: newRole, notes }),
        }).then(r => r.json());
        if (data.success) location.reload();
        else { alert('Error: ' + (data.error || 'Failed')); btn.disabled = false; }
    } catch { alert('Network error.'); btn.disabled = false; }
}

// ── Donations modal ───────────────────────────────────────────────────────────
async function openDonationsModal(campId, campTitle) {
    const subtitle = document.getElementById('donations-modal-subtitle');
    subtitle.innerHTML = '';
    const link = document.createElement('a');
    link.href      = 'partner/campaign/campaign-detail.php?id=' + encodeURIComponent(campId);
    link.style.cssText = 'color:var(--accent);text-decoration:none;font-weight:600;';
    link.textContent = campTitle;
    subtitle.appendChild(link);

    document.getElementById('donations-modal-body').innerHTML =
        '<div style="text-align:center;padding:32px;color:var(--text-muted);">' +
        '<div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    new bootstrap.Modal(document.getElementById('donationsModal')).show();

    try {
        const data = await fetch('api/campaign_donations.php?camp_id=' + campId).then(r => r.json());
        const body = document.getElementById('donations-modal-body');
        if (!data.success || !data.donations?.length) {
            body.innerHTML = '<p style="text-align:center;padding:24px;color:var(--text-dim);">No donations yet.</p>';
            return;
        }
        let html = `<div class="table-responsive">
            <table class="table table-sm" style="--bs-table-bg:transparent;--bs-table-hover-bg:rgba(255,255,255,.03);color:var(--text-primary);">
            <thead style="background:rgba(0,0,0,.3);">
            <tr>
                <th style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.07em;">#</th>
                <th style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.07em;">Donor</th>
                <th style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.07em;">Amount</th>
                <th style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.07em;">Date</th>
                <th style="font-size:.68rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.07em;">Receipt</th>
            </tr></thead><tbody>`;
        data.donations.forEach(d => {
            const anonBadge = d.IsAnonymous ? ' <span style="font-size:.62rem;padding:2px 6px;background:rgba(100,116,139,.2);color:var(--text-dim);border-radius:99px;">anon</span>' : '';
            const donorCell = data.isAdmin
                ? `<a href="profile.php?id=${d.DonorID}" style="color:var(--accent);text-decoration:none;font-weight:600;">${d.DonorName}</a>${anonBadge}`
                : (d.DonorID
                    ? `<a href="profile.php?id=${d.DonorID}" style="color:var(--accent);text-decoration:none;font-weight:600;">${d.DonorName}</a>`
                    : `<em style="color:var(--text-dim);">${d.DonorName}</em>`);
            html += `<tr>
                <td style="color:var(--text-dim);font-size:.75rem;">${d.ID}</td>
                <td style="font-size:.8rem;">${donorCell}</td>
                <td><strong style="color:var(--accent);">$${parseFloat(d.Amt).toFixed(2)}</strong></td>
                <td style="font-size:.75rem;color:var(--text-dim);">${d.Time}</td>
                <td>${d.HasReceipt
                    ? '<span style="font-size:.68rem;padding:2px 8px;border-radius:99px;background:var(--accent-dim);color:var(--accent);font-weight:700;">Issued</span>'
                    : '<span style="color:var(--text-dim);">—</span>'}</td>
            </tr>`;
        });
        body.innerHTML = html + '</tbody></table></div>';
    } catch {
        document.getElementById('donations-modal-body').innerHTML =
            '<p style="text-align:center;padding:24px;color:var(--danger);">Failed to load donations.</p>';
    }
}

// ── Request Change modal ──────────────────────────────────────────────────────
function openRequestModal(campId, campTitle) {
    document.getElementById('modal-camp-id').value = campId;
    const titleLink = document.getElementById('modal-camp-title');
    titleLink.href        = 'partner/campaign/campaign-detail.php?id=' + encodeURIComponent(campId);
    titleLink.textContent = campTitle;
    document.getElementById('modal-message').value  = '';
    document.getElementById('modal-alert').innerHTML = '';
    new bootstrap.Modal(document.getElementById('requestChangeModal')).show();
}

async function sendChangeRequest() {
    const campId     = document.getElementById('modal-camp-id').value;
    const changeType = document.getElementById('modal-change-type').value;
    const message    = document.getElementById('modal-message').value.trim();
    const alertDiv   = document.getElementById('modal-alert');
    if (!message) {
        alertDiv.innerHTML = '<div class="alert alert-danger py-2 small">Please enter a message.</div>';
        return;
    }
    try {
        const data = await fetch('api/request_change.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ camp_id: parseInt(campId), change_type: changeType, message }),
        }).then(r => r.json());
        if (data.success) {
            alertDiv.innerHTML = '<div class="alert alert-success py-2 small">Request sent to organizer!</div>';
            setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('requestChangeModal')).hide(), 1500);
        } else {
            alertDiv.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`;
        }
    } catch {
        alertDiv.innerHTML = '<div class="alert alert-danger py-2 small">Network error.</div>';
    }
}

// ── Details summary chevron rotation ─────────────────────────────────────────
document.addEventListener('toggle', e => {
    if (e.target.tagName !== 'DETAILS') return;
    const icon = e.target.querySelector('summary i.bi-chevron-right');
    if (icon) icon.style.transform = e.target.open ? 'rotate(90deg)' : '';
}, true);
</script>
</body>
</html>
