<?php
$pageTitle = 'Campaigns';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';
require_once 'includes/stripe.php';

requireRole(['organizer', 'admin']);

$userID  = (int)currentUser()['id'];
$isAdmin = isAdmin();

// Admins get their own dedicated dashboard
if ($isAdmin) {
    header('Location: admin.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$CATEGORIES = ['Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];

// ── Admin: stats + pending applications ──────────────────────────────────────
$adminStats        = [];
$pendingOrganizers = [];
$organizerApplications = [];

if ($isAdmin) {
    try {
        $adminStats = $conn->query(
            "SELECT
                COUNT(*)                                          AS TotalCampaigns,
                SUM(CASE WHEN Status='active'  THEN 1 ELSE 0 END) AS ActiveCamps,
                SUM(CASE WHEN Status='pending' THEN 1 ELSE 0 END) AS PendingCamps,
                SUM(CASE WHEN Status='closed'  THEN 1 ELSE 0 END) AS ClosedCamps
             FROM Campaigns"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    try {
        $adminStats['TotalUsers']  = $conn->query("SELECT COUNT(*) FROM Users")->fetchColumn();
        $adminStats['TotalRaised'] = $conn->query("SELECT COALESCE(SUM(Amt),0) FROM Donations")->fetchColumn();
        $adminStats['DonationCount'] = $conn->query("SELECT COUNT(*) FROM Donations")->fetchColumn();
        $adminStats['TotalDonors'] = $conn->query("SELECT COUNT(DISTINCT DonorID) FROM Donations WHERE DonorID IS NOT NULL")->fetchColumn();
        $adminStats['ActiveOrganizers'] = $conn->query("SELECT COUNT(*) FROM Users WHERE Role = 'organizer'")->fetchColumn();
    } catch (PDOException $e) {}

    // All users for Users tab
    $allUsers = [];
    try {
        $allUsers = $conn->query(
            "SELECT u.UserID, u.Username, u.Email, u.Role, u.IsAnonymous, u.CreatedAt,
                    COALESCE(SUM(d.Amt), 0)  AS TotalDonated,
                    COUNT(DISTINCT d.ID)      AS DonationCount,
                    COUNT(DISTINCT c.CampID)  AS CampaignCount
             FROM Users u
             LEFT JOIN Donations d ON d.DonorID = u.UserID
             LEFT JOIN Campaigns c ON c.HostID = u.UserID
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
    } catch (PDOException $e) {}

    try {
        $pendingOrganizers = $conn->query(
            "SELECT UserID, Username, Email, CreatedAt
             FROM Users WHERE Role = 'pending_organizer'
             ORDER BY CreatedAt ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $organizerApplications = getOrganizerApplicationsForUsers(array_column($pendingOrganizers, 'UserID'));
    } catch (PDOException $e) {}
}

// ── Organizer: unread notifications ──────────────────────────────────────────
$notifications = [];
$systemNotifications = [];
if (!$isAdmin) {
    $allNotifications = getNotifications($userID, true);
    $notifications = array_values(array_filter($allNotifications, fn($n) => ($n['type'] ?? '') === 'change_request'));
    $systemNotifications = array_values(array_filter(
        $allNotifications,
        fn($n) => in_array(($n['type'] ?? ''), ['role_change', 'stripe_required', 'campaign_appeal_result'], true)
    ));
}

$stripeAccount = ['account_id' => '', 'onboarded' => false];
$organizerStripeReady = true;
if (!$isAdmin) {
    $stripeAccount = getStripeAccount($userID);
    $organizerStripeReady = (bool)($stripeAccount['onboarded'] ?? false);

    if (($stripeAccount['account_id'] ?? '') !== '' && !$organizerStripeReady) {
        $acct = stripeRetrieveAccount($stripeAccount['account_id']);
        $sandboxFastTrack = stripeIsTestMode()
            && (($acct['metadata']['unityfund_fasttrack'] ?? '') === '1')
            && (($acct['capabilities']['transfers'] ?? '') === 'active');

        if (
            (!isset($acct['error']) && ($acct['charges_enabled'] ?? false) && ($acct['payouts_enabled'] ?? false))
            || $sandboxFastTrack
        ) {
            markStripeOnboarded($userID);
            $stripeAccount['onboarded'] = true;
            $organizerStripeReady = true;
        }
    }

    if (($stripeAccount['account_id'] ?? '') === '' || empty($stripeAccount['onboarded'])) {
        $organizerStripeReady = false;
    }
}

// ── Campaigns ─────────────────────────────────────────────────────────────────
try {
    if ($isAdmin) {
        $stmt = $conn->query(
            "SELECT c.CampID, c.HostID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt,
                    COALESCE(u.Username, 'Unknown') AS HostName,
                    COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                    COUNT(DISTINCT d.ID)       AS DonationCount,
                    COUNT(DISTINCT d.DonorID)  AS DonorCount
             FROM Campaigns c
             LEFT JOIN Users u ON c.HostID = u.UserID
             LEFT JOIN Donations d ON d.CampID = c.CampID
             GROUP BY c.CampID, c.HostID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt, u.Username
             ORDER BY CASE c.Status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, c.CreatedAt DESC"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT c.CampID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt,
                    COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                    COUNT(DISTINCT d.ID)       AS DonationCount,
                    COUNT(DISTINCT d.DonorID)  AS DonorCount
             FROM Campaigns c
             LEFT JOIN Donations d ON d.CampID = c.CampID
             WHERE c.HostID = ?
             GROUP BY c.CampID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt
             ORDER BY c.CreatedAt DESC"
        );
        $stmt->execute([$userID]);
    }
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
    $dbError   = $e->getMessage();
}

// Descriptions (graceful — column may not exist)
$descriptions = [];
try {
    $ids = array_column($campaigns, 'CampID');
    if (!empty($ids)) {
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $dStmt = $conn->prepare("SELECT CampID, Description FROM Campaigns WHERE CampID IN ($ph)");
        $dStmt->execute($ids);
        foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $descriptions[$row['CampID']] = $row['Description'] ?? '';
        }
    }
} catch (PDOException $e) {}

$campaignImages = [];
try {
    $campaignImages = getCampaignDetailsMap(array_column($campaigns, 'CampID'));
} catch (Exception $e) {
    $campaignImages = [];
}

$activeCommenters = [];
try {
    $activeCommenters = getMostActiveCommentersForCampaigns(array_column($campaigns, 'CampID'));
} catch (Exception $e) {
    $activeCommenters = [];
}

$pageTitle = $isAdmin ? 'Admin Dashboard' : 'My Campaigns';
require_once 'includes/header.php';
?>

<?php if ($isAdmin): ?>
<?php
$_cu          = currentUser();
$_nPending    = count($pendingOrganizers);
$_nCamps      = count($campaigns);
$_nUsers      = (int)($adminStats['TotalUsers']      ?? 0);
$_raised      = (float)($adminStats['TotalRaised']   ?? 0);
$_donCount    = (int)($adminStats['DonationCount']   ?? 0);
$_donors      = (int)($adminStats['TotalDonors']     ?? 0);
$_organizers  = (int)($adminStats['ActiveOrganizers']?? 0);
$_activeCamps = (int)($adminStats['ActiveCamps']     ?? 0);
$_pendingCamps= (int)($adminStats['PendingCamps']    ?? 0);
$_closedCamps = (int)($adminStats['ClosedCamps']     ?? 0);
?>
<!-- ══════════════════════ ADMIN — VERTICAL LAYOUT ══════════════════════ -->
<div class="admin-layout">

<!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
<aside class="admin-sidebar">

    <div class="adm-brand">
        <div class="adm-brand-mark"><i class="bi bi-speedometer2"></i></div>
        <div>
            <div class="adm-brand-name">Admin Panel</div>
            <div class="adm-brand-label">UnityFund</div>
        </div>
    </div>

    <nav class="adm-nav">
        <div class="adm-section">Menu</div>

        <button class="adm-link is-active" data-panel="overview">
            <i class="bi bi-grid-1x2 adm-icon"></i>
            <span class="adm-text">Overview</span>
        </button>

        <button class="adm-link" data-panel="campaigns">
            <i class="bi bi-collection adm-icon"></i>
            <span class="adm-text">Campaigns</span>
            <span class="adm-badge"><?= $_nCamps ?></span>
        </button>

        <button class="adm-link" data-panel="applications">
            <i class="bi bi-person-badge adm-icon"></i>
            <span class="adm-text">Applications</span>
            <?php if ($_nPending > 0): ?>
            <span class="adm-badge alert"><?= $_nPending ?></span>
            <?php endif; ?>
        </button>

        <button class="adm-link" data-panel="users">
            <i class="bi bi-people adm-icon"></i>
            <span class="adm-text">Users</span>
            <span class="adm-badge"><?= $_nUsers ?></span>
        </button>

        <hr class="adm-sep">
        <div class="adm-section">Finance</div>

        <a href="transactions.php" class="adm-link">
            <i class="bi bi-clock-history adm-icon"></i>
            <span class="adm-text">Transactions</span>
            <i class="bi bi-arrow-up-right-square" style="font-size:.7rem;opacity:.3;flex-shrink:0;"></i>
        </a>

        <a href="receipts.php" class="adm-link">
            <i class="bi bi-receipt adm-icon"></i>
            <span class="adm-text">Receipts</span>
            <i class="bi bi-arrow-up-right-square" style="font-size:.7rem;opacity:.3;flex-shrink:0;"></i>
        </a>
    </nav>

    <div class="adm-footer">
        <div class="adm-avatar"><?= strtoupper(substr($_cu['username'], 0, 1)) ?></div>
        <div style="min-width:0;flex:1;">
            <div class="adm-footer-name"><?= htmlspecialchars($_cu['username']) ?></div>
            <div class="adm-footer-role">Administrator</div>
        </div>
        <a href="logout.php" class="adm-signout" title="Sign out">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</aside>

<!-- ── Content area ──────────────────────────────────────────────────── -->
<div class="adm-body">

<?php if (isset($dbError)): ?>
<div class="alert alert-danger m-4"><?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     PANEL: Overview
     ════════════════════════════════════════════════ -->
<div id="panel-overview" class="adm-panel is-active">

    <div class="adm-page-head">
        <div>
            <h1 class="adm-page-title">Overview</h1>
            <p class="adm-page-sub"><?= date('l, F j, Y') ?> — Platform health snapshot</p>
        </div>
        <a href="donate.php" class="btn btn-success btn-sm px-4 fw-semibold">
            <i class="bi bi-heart me-1"></i>Donate
        </a>
    </div>

    <!-- KPI Cards — TailAdmin style -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="adm-kpi-card">
                <div class="adm-kpi-icon ic-g"><i class="bi bi-currency-dollar"></i></div>
                <div class="adm-kpi-val">$<?= number_format($_raised, 0) ?></div>
                <div class="adm-kpi-lbl">Total Raised</div>
                <div class="adm-kpi-trend">
                    <span class="adm-kpi-change up"><i class="bi bi-arrow-up-short"></i><?= $_donCount ?></span>
                    <span class="adm-kpi-trend-sub">donations received</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="adm-kpi-card">
                <div class="adm-kpi-icon ic-b"><i class="bi bi-people-fill"></i></div>
                <div class="adm-kpi-val"><?= $_donors ?></div>
                <div class="adm-kpi-lbl">Unique Donors</div>
                <div class="adm-kpi-trend">
                    <span class="adm-kpi-change up"><i class="bi bi-arrow-up-short"></i><?= $_nUsers ?></span>
                    <span class="adm-kpi-trend-sub">total users</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="adm-kpi-card">
                <div class="adm-kpi-icon ic-a"><i class="bi bi-lightning-fill"></i></div>
                <div class="adm-kpi-val"><?= $_activeCamps ?></div>
                <div class="adm-kpi-lbl">Active Campaigns</div>
                <div class="adm-kpi-trend">
                    <?php if ($_pendingCamps > 0): ?>
                    <span class="adm-kpi-change dn"><i class="bi bi-hourglass-split"></i><?= $_pendingCamps ?></span>
                    <span class="adm-kpi-trend-sub">pending review</span>
                    <?php else: ?>
                    <span class="adm-kpi-change up"><i class="bi bi-check-circle"></i>All clear</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="adm-kpi-card">
                <div class="adm-kpi-icon ic-p"><i class="bi bi-person-check-fill"></i></div>
                <div class="adm-kpi-val"><?= $_organizers ?></div>
                <div class="adm-kpi-lbl">Active Organizers</div>
                <div class="adm-kpi-trend">
                    <?php if ($_nPending > 0): ?>
                    <span class="adm-kpi-change dn"><i class="bi bi-clock"></i><?= $_nPending ?></span>
                    <span class="adm-kpi-trend-sub">awaiting approval</span>
                    <?php else: ?>
                    <span class="adm-kpi-change up"><i class="bi bi-check-circle"></i>No pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Lower section: chart-style platform activity + quick links -->
    <div class="row g-4">

        <!-- Campaign breakdown card -->
        <div class="col-lg-5">
            <div class="adm-kpi-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <div class="fw-bold" style="color:#1C2434;font-size:.95rem;">Campaign Breakdown</div>
                        <div style="font-size:.78rem;color:#64748B;"><?= $_nCamps ?> total campaigns</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" onclick="switchPanel('campaigns')" style="font-size:.78rem;">
                        View all <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
                <?php
                $bTotal = max($_nCamps, 1);
                $bars = [
                    ['label' => 'Active',  'val' => $_activeCamps,  'color' => '#02a95c', 'pct' => round(($_activeCamps/$bTotal)*100)],
                    ['label' => 'Pending', 'val' => $_pendingCamps, 'color' => '#f59e0b', 'pct' => round(($_pendingCamps/$bTotal)*100)],
                    ['label' => 'Closed',  'val' => $_closedCamps,  'color' => '#94A3B8', 'pct' => round(($_closedCamps/$bTotal)*100)],
                ];
                foreach ($bars as $bar): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:.8rem;color:#475569;font-weight:500;"><?= $bar['label'] ?></span>
                        <span style="font-size:.8rem;font-weight:600;color:#1C2434;"><?= $bar['val'] ?> <span style="color:#94A3B8;font-weight:400;">(<?= $bar['pct'] ?>%)</span></span>
                    </div>
                    <div style="height:7px;background:#F1F5F9;border-radius:99px;overflow:hidden;">
                        <div style="height:100%;width:<?= $bar['pct'] ?>%;background:<?= $bar['color'] ?>;border-radius:99px;transition:width .4s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="mt-4 pt-3" style="border-top:1px solid #F1F5F9;">
                    <div class="d-flex justify-content-between text-center">
                        <div>
                            <div class="fw-bold" style="color:#1C2434;font-size:1.1rem;">$<?= number_format($_raised,0) ?></div>
                            <div style="font-size:.7rem;color:#64748B;">Total Raised</div>
                        </div>
                        <div>
                            <div class="fw-bold" style="color:#1C2434;font-size:1.1rem;"><?= $_donCount ?></div>
                            <div style="font-size:.7rem;color:#64748B;">Donations</div>
                        </div>
                        <div>
                            <div class="fw-bold" style="color:#1C2434;font-size:1.1rem;"><?= $_nUsers ?></div>
                            <div style="font-size:.7rem;color:#64748B;">Users</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Needs attention -->
        <div class="col-lg-7">
            <div class="adm-kpi-card h-100">
                <div class="fw-bold mb-4" style="color:#1C2434;font-size:.95rem;">
                    <i class="bi bi-bell-fill me-2 text-warning"></i>Needs Attention
                </div>
                <div class="d-flex flex-column gap-2">

                    <button class="adm-alert-row amber" onclick="switchPanel('applications')">
                        <div class="adm-alert-icon ic-a"><i class="bi bi-person-badge"></i></div>
                        <div class="flex-grow-1 text-start">
                            <div class="adm-alert-title"><?= $_nPending ?> organizer application<?= $_nPending !== 1 ? 's' : '' ?> pending</div>
                            <p class="adm-alert-desc">Review identity docs and approve or reject.</p>
                        </div>
                        <?php if ($_nPending > 0): ?>
                        <span class="badge bg-warning text-dark ms-2"><?= $_nPending ?></span>
                        <?php endif; ?>
                        <i class="bi bi-chevron-right adm-alert-arrow ms-2"></i>
                    </button>

                    <button class="adm-alert-row green" onclick="switchPanel('campaigns'); setCampaignFilter('pending')">
                        <div class="adm-alert-icon ic-g"><i class="bi bi-hourglass-split"></i></div>
                        <div class="flex-grow-1 text-start">
                            <div class="adm-alert-title"><?= $_pendingCamps ?> campaign<?= $_pendingCamps !== 1 ? 's' : '' ?> awaiting approval</div>
                            <p class="adm-alert-desc">Approve or reject new campaign submissions.</p>
                        </div>
                        <?php if ($_pendingCamps > 0): ?>
                        <span class="badge bg-success ms-2"><?= $_pendingCamps ?></span>
                        <?php endif; ?>
                        <i class="bi bi-chevron-right adm-alert-arrow ms-2"></i>
                    </button>

                    <a href="transactions.php" class="adm-alert-row blue">
                        <div class="adm-alert-icon ic-b"><i class="bi bi-clock-history"></i></div>
                        <div class="flex-grow-1">
                            <div class="adm-alert-title">Transaction log</div>
                            <p class="adm-alert-desc">View all payment attempts and gateway references.</p>
                        </div>
                        <i class="bi bi-chevron-right adm-alert-arrow ms-2"></i>
                    </a>

                    <a href="top_donors.php" class="adm-alert-row gray">
                        <div class="adm-alert-icon ic-s"><i class="bi bi-trophy"></i></div>
                        <div class="flex-grow-1">
                            <div class="adm-alert-title">Top donors leaderboard</div>
                            <p class="adm-alert-desc">Public donor recognition and ranking.</p>
                        </div>
                        <i class="bi bi-chevron-right adm-alert-arrow ms-2"></i>
                    </a>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════
     PANEL: Campaigns
     ════════════════════════════════════════════════ -->
<div id="panel-campaigns" class="adm-panel">

    <div class="adm-page-head">
        <div>
            <h1 class="adm-page-title">Campaigns</h1>
            <p class="adm-page-sub">All <?= $_nCamps ?> campaign<?= $_nCamps !== 1 ? 's' : '' ?> on the platform.</p>
        </div>
    </div>

    <!-- Filter pills -->
    <div class="adm-toolbar">
        <button class="adm-filter on camp-filter" data-filter="all">
            All <span style="opacity:.6;"><?= $_nCamps ?></span>
        </button>
        <?php
        $counts = array_count_values(array_column($campaigns, 'Status'));
        foreach (['pending' => 'Pending', 'active' => 'Active', 'closed' => 'Closed'] as $st => $lbl):
            $n = $counts[$st] ?? 0;
            if (!$n) continue;
        ?>
        <button class="adm-filter camp-filter" data-filter="<?= $st ?>">
            <?= $lbl ?> <span style="opacity:.6;"><?= $n ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <?php if (empty($campaigns)): ?>
    <div class="adm-card text-center py-5 px-3">
        <i class="bi bi-collection d-block text-muted mb-3" style="font-size:2.5rem;"></i>
        <p class="text-muted mb-0">No campaigns found.</p>
    </div>
    <?php else: ?>
    <div class="adm-table-wrap">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="campaigns-table">
            <thead>
                <tr>
                    <th style="width:30%;">Campaign</th>
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
                $raised    = (float)$c['TotalRaised'];
                $goal      = (float)$c['GoalAmt'];
                $pct       = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;
                $cid       = $c['CampID'];
                $meta      = $campaignImages[(int)$cid] ?? [];
                $thumb     = $meta['thumbnail'] ?? '';
                $forcedClosed = !empty($meta['force_closed_by_admin']);
                $appealStatus = $meta['appeal_status'] ?? 'none';
                $appealNotes = $meta['appeal_review_notes'] ?? '';
                $adminOwns = (int)($c['HostID'] ?? 0) === $userID;
                $canEdit   = $adminOwns;
                $statusCfg = [
                    'active'  => ['bg-success',          'Active'],
                    'pending' => ['bg-warning text-dark', 'Pending'],
                    'closed'  => ['bg-secondary',         'Closed'],
                ][$c['Status']] ?? ['bg-secondary', $c['Status']];
            ?>
            <tr class="camp-row" data-status="<?= $c['Status'] ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($thumb): ?>
                        <img src="<?= htmlspecialchars($thumb) ?>" alt=""
                             style="width:52px;height:38px;object-fit:cover;border-radius:6px;flex-shrink:0;">
                        <?php endif; ?>
                        <div style="max-width:220px;min-width:0;">
                            <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                               class="text-success text-decoration-none fw-semibold" style="font-size:.85rem;">
                                <?= htmlspecialchars($c['Title']) ?>
                            </a>
                            <div>
                                <span class="badge bg-success bg-opacity-10 text-success" style="font-size:.63rem;">
                                    <?= htmlspecialchars($c['Category'] ?? 'Other') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <a href="profile.php?id=<?= $c['HostID'] ?>"
                       class="text-success text-decoration-none fw-semibold" style="font-size:.83rem;">
                        <?= htmlspecialchars($c['HostName']) ?>
                    </a>
                </td>
                <td style="min-width:160px;">
                    <div class="d-flex align-items-center gap-2">
                        <div class="flex-grow-1" style="background:#e9ecef;border-radius:4px;height:5px;min-width:70px;">
                            <div style="width:<?= number_format($pct,1) ?>%;height:100%;background:#02a95c;border-radius:4px;"></div>
                        </div>
                        <span style="font-size:.75rem;color:#9ca3af;white-space:nowrap;">
                            $<?= number_format($raised,0) ?> / $<?= number_format($goal,0) ?>
                        </span>
                    </div>
                </td>
                <td style="font-size:.83rem;"><?= $c['DonorCount'] ?></td>
                <td>
                    <span class="badge <?= $statusCfg[0] ?>"><?= $statusCfg[1] ?></span>
                    <?php if ($forcedClosed): ?>
                    <div class="small text-danger mt-1">Force closed by admin</div>
                    <?php if (!empty($meta['force_closed_reason'])): ?>
                    <div class="small text-muted"><?= htmlspecialchars($meta['force_closed_reason']) ?></div>
                    <?php endif; ?>
                    <?php if ($appealStatus === 'pending'): ?>
                    <div class="small text-warning">Appeal pending</div>
                    <?php elseif ($appealStatus === 'rejected' && $appealNotes !== ''): ?>
                    <div class="small text-muted">Last decision: <?= htmlspecialchars($appealNotes) ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#9ca3af;"><?= date('M j, Y', strtotime($c['CreatedAt'])) ?></td>
                <td class="text-end">
                    <div class="d-flex gap-1 justify-content-end flex-wrap">
                        <?php if ($c['Status'] === 'pending'): ?>
                        <button class="btn btn-sm btn-success" title="Approve"
                                onclick="quickStatus(<?= $cid ?>,'active')">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" title="Reject"
                                onclick="quickStatus(<?= $cid ?>,'closed')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <?php elseif ($c['Status'] === 'active'): ?>
                        <button class="btn btn-sm btn-outline-danger" title="Close"
                                onclick="quickStatus(<?= $cid ?>,'closed')">
                            <i class="bi bi-lock"></i>
                        </button>
                        <?php elseif ($c['Status'] === 'closed'): ?>
                            <?php if ($forcedClosed): ?>
                                <?php if ($appealStatus === 'pending'): ?>
                                <button class="btn btn-sm btn-outline-secondary" title="Appeal pending" disabled>
                                    <i class="bi bi-hourglass-split"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-outline-warning"
                                        title="<?= $appealStatus === 'rejected' ? 'Appeal again' : 'Send appeal' ?>"
                                        onclick="sendCampaignAppeal(<?= $cid ?>, <?= json_encode($c['Title']) ?>)">
                                    <i class="bi bi-send"></i>
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-success" title="Reactivate"
                                    onclick="quickStatus(<?= $cid ?>,'active')">
                                <i class="bi bi-unlock"></i>
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                onclick="openEditModal(<?= $cid ?>, <?= json_encode($c['Title']) ?>, <?= $goal ?>, <?= json_encode($c['Category'] ?? 'Other') ?>, <?= json_encode($c['Status']) ?>, <?= json_encode($descriptions[$cid] ?? '') ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-warning" title="Request Change"
                                onclick="openRequestModal(<?= $cid ?>, '<?= htmlspecialchars(addslashes($c['Title'])) ?>')">
                            <i class="bi bi-send"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-secondary" title="View Donations"
                                onclick="openDonationsModal(<?= $cid ?>, <?= json_encode($c['Title']) ?>)">
                            <i class="bi bi-list-ul"></i>
                        </button>
                        <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                           class="btn btn-sm btn-outline-secondary" title="Public page" target="_blank">
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
</div>

<!-- ════════════════════════════════════════════════
     PANEL: Applications
     ════════════════════════════════════════════════ -->
<div id="panel-applications" class="adm-panel">

    <div class="adm-page-head">
        <div>
            <h1 class="adm-page-title">Organizer Applications</h1>
            <p class="adm-page-sub"><?= $_nPending ?> pending review<?= $_nPending !== 1 ? 's' : '' ?>.</p>
        </div>
        <?php if ($_nPending > 0): ?>
        <span class="badge bg-warning text-dark px-3 py-2" style="font-size:.85rem;">
            <i class="bi bi-exclamation-circle me-1"></i><?= $_nPending ?> awaiting decision
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($pendingOrganizers)): ?>
    <div class="adm-card text-center py-5 px-3">
        <i class="bi bi-check2-circle d-block text-success mb-3" style="font-size:2.5rem;"></i>
        <p class="fw-semibold mb-1">No pending applications</p>
        <p class="text-muted small mb-0">All organizer requests have been reviewed.</p>
    </div>
    <?php else: ?>
    <div class="adm-table-wrap">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Email</th>
                    <th>Application Details</th>
                    <th>Submitted</th>
                    <th class="text-end">Decision</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingOrganizers as $org):
                $app = $organizerApplications[(int)$org['UserID']] ?? null;
            ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-warning d-flex align-items-center justify-content-center text-dark fw-bold flex-shrink-0"
                             style="width:34px;height:34px;font-size:.85rem;">
                            <?= strtoupper(substr($org['Username'],0,1)) ?>
                        </div>
                        <div>
                            <div class="fw-semibold" style="font-size:.875rem;"><?= htmlspecialchars($org['Username']) ?></div>
                            <span class="badge bg-warning text-dark" style="font-size:.6rem;">Pending</span>
                        </div>
                    </div>
                </td>
                <td style="font-size:.83rem;color:#6b7280;"><?= htmlspecialchars($org['Email']) ?></td>
                <td style="min-width:300px;">
                    <?php if ($app): ?>
                    <div style="font-size:.83rem;">
                        <div class="fw-semibold"><?= htmlspecialchars($app['legal_name']) ?></div>
                        <div style="color:#6b7280;">
                            <?= htmlspecialchars($app['organization_type']) ?>
                            <?php if (!empty($app['organization_name'])): ?>
                                &middot; <?= htmlspecialchars($app['organization_name']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="color:#6b7280;">
                            <?= htmlspecialchars($app['focus_category']) ?> &middot; <?= htmlspecialchars($app['estimated_goal_range']) ?>
                        </div>
                        <details class="mt-1">
                            <summary class="text-success" style="cursor:pointer;font-size:.8rem;">Review full application</summary>
                            <div class="mt-2 p-2 rounded bg-light border" style="font-size:.8rem;">
                                <div><strong>Phone:</strong> <?= htmlspecialchars($app['phone']) ?></div>
                                <div><strong>DOB:</strong> <?= htmlspecialchars($app['date_of_birth']) ?></div>
                                <div><strong>ID type:</strong> <?= htmlspecialchars($app['government_id_type']) ?></div>
                                <div><strong>Proof link:</strong>
                                    <a href="<?= htmlspecialchars($app['website_social']) ?>" target="_blank" rel="noopener" class="text-success">
                                        <?= htmlspecialchars($app['website_social']) ?>
                                    </a>
                                </div>
                                <div class="mt-2"><strong>Intent:</strong><br><?= nl2br(htmlspecialchars($app['campaign_intent'])) ?></div>
                                <div class="mt-2"><strong>Prior fundraising:</strong>
                                    <?= $app['has_fundraising_experience'] ? 'Yes' : 'No' ?>
                                    <?php if (!empty($app['fundraising_experience_description'])): ?>
                                    <br><?= nl2br(htmlspecialchars($app['fundraising_experience_description'])) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2">
                                    <strong>ID photos:</strong>
                                    <div class="d-flex gap-2 flex-wrap mt-1">
                                        <?php foreach (['front' => 'Front', 'back' => 'Back'] as $side => $label):
                                            $path = $app['id_image_' . $side] ?? '';
                                        ?>
                                        <?php if ($path): ?>
                                        <button type="button" class="btn p-0 border bg-white"
                                                onclick="showIdPhoto(<?= json_encode($path) ?>, <?= json_encode($label . ' ID photo') ?>)"
                                                title="View <?= htmlspecialchars($label) ?> ID photo">
                                            <img src="<?= htmlspecialchars($path) ?>"
                                                 alt="<?= htmlspecialchars($label) ?> ID"
                                                 style="width:88px;height:56px;object-fit:cover;border-radius:4px;">
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($label) ?> missing</span>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:.83rem;font-style:italic;">No application details found.</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#9ca3af;">
                    <?= htmlspecialchars($app['submitted_at'] ?? date('M j, Y', strtotime($org['CreatedAt']))) ?>
                </td>
                <td class="text-end">
                    <textarea class="form-control form-control-sm mb-2"
                              id="decision-notes-<?= $org['UserID'] ?>"
                              rows="2"
                              placeholder="Notes (required to reject)"></textarea>
                    <div class="d-flex gap-2 justify-content-end">
                        <button class="btn btn-sm btn-success fw-semibold px-3"
                                onclick="approveOrganizer(<?= $org['UserID'] ?>, 'organizer', this)">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button class="btn btn-sm btn-outline-danger px-3"
                                onclick="approveOrganizer(<?= $org['UserID'] ?>, 'donor', this)">
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
</div>

<!-- ════════════════════════════════════════════════
     PANEL: Users
     ════════════════════════════════════════════════ -->
<div id="panel-users" class="adm-panel">

    <div class="adm-page-head">
        <div>
            <h1 class="adm-page-title">Users</h1>
            <p class="adm-page-sub"><?= $_nUsers ?> registered account<?= $_nUsers !== 1 ? 's' : '' ?> on the platform.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <input type="text" id="user-search-input" class="form-control form-control-sm"
                   style="max-width:220px;"
                   placeholder="Search name or email…"
                   oninput="filterUsersTable(this.value)">
            <span style="font-size:.8rem;color:#9ca3af;white-space:nowrap;" id="user-count-label"><?= count($allUsers) ?> users</span>
        </div>
    </div>

    <!-- Role filter pills -->
    <?php
    $roleCounts = array_count_values(array_column($allUsers, 'Role'));
    $roleFilters = [
        'all'               => ['All',       count($allUsers)],
        'admin'             => ['Admin',     $roleCounts['admin'] ?? 0],
        'organizer'         => ['Organizer', $roleCounts['organizer'] ?? 0],
        'pending_organizer' => ['Pending',   $roleCounts['pending_organizer'] ?? 0],
        'donor'             => ['Donor',     $roleCounts['donor'] ?? 0],
    ];
    ?>
    <div class="adm-toolbar">
        <?php foreach ($roleFilters as $rk => [$rl, $rc]):
            if ($rk !== 'all' && !$rc) continue;
        ?>
        <button class="adm-filter role-filter <?= $rk === 'all' ? 'on' : '' ?>" data-role="<?= $rk ?>">
            <?= $rl ?> <span style="opacity:.6;"><?= $rc ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="adm-table-wrap">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
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
                $roleDisplay = [
                    'admin'             => ['bg-danger',            'Admin'],
                    'organizer'         => ['bg-purple',            'Organizer'],
                    'pending_organizer' => ['bg-warning text-dark', 'Pending'],
                    'donor'             => ['bg-primary',           'Donor'],
                ][$u['Role']] ?? ['bg-secondary', ucfirst($u['Role'])];
                $avatarColor = ['admin'=>'#dc3545','organizer'=>'#6f42c1','pending_organizer'=>'#f59e0b','donor'=>'#0d6efd'][$u['Role']] ?? '#6c757d';
            ?>
            <tr class="user-row"
                data-role="<?= htmlspecialchars($u['Role']) ?>"
                data-search="<?= strtolower(htmlspecialchars($u['Username'] . ' ' . $u['Email'])) ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0"
                             style="width:32px;height:32px;font-size:.8rem;background:<?= $avatarColor ?>;">
                            <?= strtoupper(substr($u['Username'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-semibold" style="font-size:.85rem;"><?= htmlspecialchars($u['Username']) ?></div>
                            <?php if ($u['IsAnonymous']): ?>
                            <span class="badge bg-secondary" style="font-size:.58rem;">anon</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td style="font-size:.8rem;color:#6b7280;"><?= htmlspecialchars($u['Email']) ?></td>
                <td><span class="badge <?= $roleDisplay[0] ?>"><?= $roleDisplay[1] ?></span></td>
                <td class="fw-semibold <?= (float)$u['TotalDonated'] > 0 ? 'text-success' : 'text-muted' ?>" style="font-size:.83rem;">
                    <?= (float)$u['TotalDonated'] > 0 ? '$'.number_format((float)$u['TotalDonated'],2) : '—' ?>
                    <?php if ((int)$u['DonationCount'] > 0): ?>
                    <div style="font-size:.7rem;color:#9ca3af;font-weight:400;"><?= $u['DonationCount'] ?> donation<?= $u['DonationCount'] != 1 ? 's' : '' ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.83rem;color:#6b7280;"><?= (int)$u['CampaignCount'] > 0 ? $u['CampaignCount'] : '—' ?></td>
                <td style="font-size:.78rem;color:#9ca3af;"><?= $u['CreatedAt'] ? date('M j, Y', strtotime($u['CreatedAt'])) : '—' ?></td>
                <td class="text-end">
                    <a href="profile.php?id=<?= (int)$u['UserID'] ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-person me-1"></i>Profile
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div><!-- /adm-body -->
</div><!-- /admin-layout -->

<!-- ID Photo Modal -->
<div class="modal fade" id="idPhotoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="id-photo-title">ID photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <img id="id-photo-full" src="" alt="ID photo" class="w-100 rounded border"
                     style="max-height:70vh;object-fit:contain;background:#f8f9fa;">
            </div>
        </div>
    </div>
</div>

<!-- Edit Campaign Modal (admin own campaigns) -->
<div class="modal fade" id="editCampModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Campaign</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-camp-id">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label small fw-semibold">Title</label>
                        <input type="text" id="edit-title" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Goal (USD)</label>
                        <input type="number" id="edit-goal" class="form-control" min="1" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Category</label>
                        <select id="edit-category" class="form-select">
                            <?php foreach ($CATEGORIES as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Status</label>
                        <select id="edit-status" class="form-select">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea id="edit-description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Campaign image</label>
                        <input type="file" id="edit-campaign-image" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="text-muted small mt-1">Creates a 1200x630 banner and 400x300 thumbnail.</div>
                    </div>
                </div>
                <div id="edit-modal-alert" class="mt-3"></div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success fw-semibold px-4" onclick="saveEditModal()">
                    <i class="bi bi-check2 me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Donations Modal -->
<div class="modal fade" id="donationsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-list-ul me-2"></i>Donations</h5>
                    <p class="text-muted small mb-0" id="donations-modal-subtitle"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="donations-modal-body">
                <div class="text-center py-4 text-muted">Loading…</div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     ORGANIZER VIEW
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="container py-5">

    <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">My Campaigns</h1>
            <p class="text-muted mb-0">Create and manage your fundraising campaigns.</p>
        </div>
        <button class="btn btn-success px-4 fw-semibold"
                <?= $organizerStripeReady ? 'data-bs-toggle="collapse" data-bs-target="#newCampPanel"' : 'type="button" disabled' ?>>
            <i class="bi bi-plus-lg me-1"></i>New Campaign
        </button>
    </div>

    <?php if (!$organizerStripeReady): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #f59e0b!important;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <h5 class="fw-bold mb-2">Connect Stripe before creating campaigns</h5>
                    <p class="text-muted mb-2">
                        Approved organizers must connect a Stripe payout account first. Donations flow from donor to UnityFund, UnityFund keeps the 5% platform fee, and the rest routes to your connected Stripe account.
                    </p>
                    <p class="small text-muted mb-0">
                        <?= ($stripeAccount['account_id'] ?? '') !== '' ? 'Your Stripe account is linked but setup is incomplete.' : 'No Stripe payout account is linked to your organizer profile yet.' ?>
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-success fw-semibold px-4" id="campaign-stripe-btn" onclick="connectStripeForCampaigns()">
                        <i class="bi bi-lightning-fill me-1"></i><?= ($stripeAccount['account_id'] ?? '') !== '' ? 'Resume Stripe Setup' : 'Connect Stripe' ?>
                    </button>
                    <a href="profile.php" class="btn btn-outline-secondary px-4">Open Profile</a>
                </div>
            </div>
            <div id="campaign-stripe-msg" class="small mt-3"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($systemNotifications)): ?>
    <div class="card border-0 mb-4" style="border-left:4px solid #0d6efd!important;background:#eff6ff;">
        <div class="card-body">
            <h6 class="fw-bold mb-3" style="color:#1d4ed8;">
                <i class="bi bi-info-circle-fill me-2"></i>Account updates
            </h6>
            <?php foreach ($systemNotifications as $n): ?>
            <div class="d-flex justify-content-between gap-3 mb-3 pb-3 border-bottom">
                <div>
                    <div class="fw-semibold small mb-1">
                        <?= ($n['type'] ?? '') === 'stripe_required' ? 'Stripe connection required' : 'Role updated' ?>
                    </div>
                    <p class="mb-0 small text-muted"><?= htmlspecialchars($n['message']) ?></p>
                </div>
                <div class="small text-muted flex-shrink-0"><?= htmlspecialchars($n['created_at']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Notifications from admin -->
    <?php if (!empty($notifications)): ?>
    <div class="card border-0 mb-4" style="border-left:4px solid #f59e0b!important;background:#fffbeb;">
        <div class="card-body">
            <h6 class="fw-bold mb-3" style="color:#92400e;">
                <i class="bi bi-bell-fill me-2" style="color:#f59e0b;"></i>
                Messages from Admin <span class="badge bg-warning text-dark ms-1"><?= count($notifications) ?></span>
            </h6>
            <?php foreach ($notifications as $n): ?>
            <?php $noteThumb = $campaignImages[(int)$n['camp_id']]['thumbnail'] ?? ''; ?>
            <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                <div class="flex-shrink-0 mt-1">
                    <span class="badge <?= $n['change_type']==='name' ? 'bg-info' : 'bg-warning text-dark' ?>">
                        <?= $n['change_type']==='name' ? 'Name' : 'Goal' ?>
                    </span>
                </div>
                <?php if ($noteThumb): ?>
                <img src="<?= htmlspecialchars($noteThumb) ?>"
                     alt="<?= htmlspecialchars($n['camp_title']) ?>"
                     style="width:44px;height:32px;object-fit:cover;border-radius:4px;">
                <?php endif; ?>
                <div class="flex-grow-1">
                    <a href="partner/campaign/campaign-detail.php?id=<?= (int)$n['camp_id'] ?>"
                       class="fw-semibold small mb-1 text-success text-decoration-none d-inline-block">
                        <?= htmlspecialchars($n['camp_title']) ?>
                    </a>
                    <p class="mb-0 small text-muted"><?= htmlspecialchars($n['message']) ?></p>
                </div>
                <div class="text-muted small flex-shrink-0"><?= $n['created_at'] ?></div>
            </div>
            <?php endforeach; ?>
            <button class="btn btn-sm btn-outline-secondary" onclick="markAllRead()">
                <i class="bi bi-check2-all me-1"></i>Mark all as read
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h6 class="fw-bold mb-1">Most Active Commenters</h6>
                    <p class="text-muted small mb-0">Top people engaging across your campaign discussions.</p>
                </div>
                <a href="partner/campaign/campaign-detail.php?id=<?= !empty($campaigns) ? (int)$campaigns[0]['CampID'] : 0 ?>#comments"
                   class="btn btn-sm btn-outline-secondary <?= empty($campaigns) ? 'disabled' : '' ?>">Open discussions</a>
            </div>
            <?php if (empty($activeCommenters)): ?>
            <p class="text-muted small mb-0">No discussion activity yet across your campaigns.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Commenter</th>
                            <th>Comments</th>
                            <th>Campaigns</th>
                            <th>Latest activity</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activeCommenters as $commenter): ?>
                        <tr>
                            <td>
                                <a href="profile.php?id=<?= (int)$commenter['user_id'] ?>"
                                   class="text-success text-decoration-none fw-semibold">
                                    <?= htmlspecialchars($commenter['username']) ?>
                                </a>
                            </td>
                            <td class="fw-semibold"><?= (int)$commenter['comment_count'] ?></td>
                            <td><?= (int)$commenter['campaign_count'] ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($commenter['latest_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- New campaign form -->
    <div class="collapse mb-4 <?= $organizerStripeReady ? '' : 'd-none' ?>" id="newCampPanel">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1">Create New Campaign</h5>
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Campaigns start as <strong>Pending</strong> and go live after admin approval.
                </p>
                <form id="newCampForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Campaign Title</label>
                            <input type="text" name="title" class="form-control"
                                   placeholder="e.g. Clean Water for Village A" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Goal (USD)</label>
                            <input type="number" name="goal" class="form-control"
                                   min="1" step="0.01" placeholder="5000.00" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($CATEGORIES as $cat): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">
                                Description <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <textarea name="description" class="form-control" rows="3"
                                      placeholder="Tell donors what this campaign is about…"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">
                                Campaign image <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input type="file" name="campaign_image" class="form-control mb-2"
                                   accept="image/jpeg,image/png,image/webp">
                            <div class="text-muted small mb-3">JPG, PNG, or WebP under 2 MB.</div>
                            <button type="submit" class="btn btn-success fw-semibold px-4">
                                Submit for Approval
                            </button>
                            <span class="ms-3 small" id="newCampMsg"></span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Campaign cards -->
    <?php if (empty($campaigns)): ?>
    <div class="empty-state">
        <i class="bi bi-grid"></i>
        <p class="mt-3">No campaigns yet. Click "New Campaign" to get started.</p>
    </div>
    <?php else: ?>
    <?php foreach ($campaigns as $c):
        $raised      = (float)$c['TotalRaised'];
        $goal        = (float)$c['GoalAmt'];
        $pct         = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;
        $cid         = $c['CampID'];
        $thumb       = $campaignImages[(int)$cid]['thumbnail'] ?? '';
        $statusClass = ['active'=>'camp-status-active','pending'=>'camp-status-pending','closed'=>'camp-status-closed'][$c['Status']] ?? '';
        $statusLabel = ['active'=>'Active','pending'=>'Pending Approval','closed'=>'Closed'][$c['Status']] ?? $c['Status'];
    ?>
    <div class="card mb-3 border-0 shadow-sm" id="card-<?= $cid ?>">
        <?php if ($thumb): ?>
        <img src="<?= htmlspecialchars($thumb) ?>"
             alt="<?= htmlspecialchars($c['Title']) ?>"
             class="card-img-top"
             style="height:180px;object-fit:cover;">
        <?php endif; ?>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">
                        <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                           class="text-dark text-decoration-none">
                            <?= htmlspecialchars($c['Title']) ?>
                        </a>
                    </h5>
                    <span class="badge bg-success bg-opacity-10 text-success fw-semibold small mt-1">
                        <?= htmlspecialchars($c['Category'] ?? 'Other') ?>
                    </span>
                </div>
                <span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span>
            </div>
            <div class="row g-3 mb-3 text-center">
                <div class="col-6 col-sm-3">
                    <div class="fw-bold text-success fs-5">$<?= number_format($raised,0) ?></div>
                    <div class="section-title">Raised</div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="fw-bold fs-5">$<?= number_format($goal,0) ?></div>
                    <div class="section-title">Goal</div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="fw-bold fs-5"><?= $c['DonorCount'] ?></div>
                    <div class="section-title">Donors</div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="fw-bold fs-5"><?= number_format($pct,1) ?>%</div>
                    <div class="section-title">Funded</div>
                </div>
            </div>
            <div class="progress mb-3" style="height:6px;">
                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="collapse" data-bs-target="#edit-<?= $cid ?>">
                    <i class="bi bi-pencil me-1"></i>Edit
                </button>
                <button class="btn btn-sm btn-outline-secondary"
                        onclick="loadDonations(<?= $cid ?>)">
                    <i class="bi bi-list-ul me-1"></i>Donations
                </button>
            </div>
        </div>

        <div class="collapse" id="edit-<?= $cid ?>">
            <div class="card-body border-top" style="background:#fafafa;">
                <h6 class="section-title mb-3">Edit Campaign</h6>
                <?php if ($c['Status'] !== 'active'): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Status: <strong><?= $statusLabel ?></strong>.
                    <?= $c['Status'] === 'pending' ? 'You can still edit while waiting for approval.' : 'Contact admin to reactivate.' ?>
                </div>
                <?php endif; ?>
                <form onsubmit="saveCampaign(event, <?= $cid ?>)">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Title</label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= htmlspecialchars($c['Title']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Goal (USD)</label>
                            <input type="number" name="goal" class="form-control"
                                   min="1" step="0.01" value="<?= $goal ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($CATEGORIES as $cat): ?>
                                <option value="<?= $cat ?>" <?= ($c['Category']??'Other')===$cat?'selected':'' ?>><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($descriptions[$cid] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Campaign image</label>
                            <input type="file" name="campaign_image" class="form-control mb-2"
                                   accept="image/jpeg,image/png,image/webp">
                            <div class="text-muted small mb-3">Uploading replaces the current banner and thumbnail.</div>
                            <button type="submit" class="btn btn-success btn-sm px-4">Save Changes</button>
                            <span class="ms-2 small" id="save-msg-<?= $cid ?>"></span>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-body" id="don-<?= $cid ?>">
            <div class="card-body border-top" style="background:#fafafa;">
                <h6 class="section-title mb-3">
                    Donations -
                    <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                       class="text-success text-decoration-none">
                        <?= htmlspecialchars($c['Title']) ?>
                    </a>
                </h6>
                <div id="don-content-<?= $cid ?>">
                    <p class="text-muted small">Click "Donations" to load.</p>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- Request Change Modal -->
<div class="modal fade" id="requestChangeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-send me-2 text-warning"></i>Request Change
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Send a change request to the organizer of
                    <a id="modal-camp-title" class="text-success text-decoration-none fw-semibold" href="#"></a>.
                </p>
                <input type="hidden" id="modal-camp-id">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">What to change</label>
                    <select id="modal-change-type" class="form-select">
                        <option value="name">Campaign name</option>
                        <option value="goal">Funding goal</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Message to organizer</label>
                    <textarea id="modal-message" class="form-control" rows="4"
                              placeholder="Explain what needs to change and why…"></textarea>
                </div>
                <div id="modal-alert"></div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning fw-semibold" onclick="sendChangeRequest()">
                    <i class="bi bi-send me-1"></i>Send Request
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Admin: panel switching (vertical tabs) ────────────────────────────────────
function switchPanel(name) {
    document.querySelectorAll('.adm-link[data-panel]').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('.adm-panel').forEach(p => p.classList.remove('is-active'));
    const navEl   = document.querySelector('.adm-link[data-panel="' + name + '"]');
    const panelEl = document.getElementById('panel-' + name);
    if (navEl)   navEl.classList.add('is-active');
    if (panelEl) panelEl.classList.add('is-active');
    history.replaceState(null, '', '#' + name);
}

document.querySelectorAll('.adm-link[data-panel]').forEach(btn => {
    btn.addEventListener('click', () => switchPanel(btn.dataset.panel));
});

// Restore panel from URL hash on load
(function () {
    const h = location.hash.replace('#', '');
    if (h && document.getElementById('panel-' + h)) switchPanel(h);
})();

function setCampaignFilter(status) {
    const btn = document.querySelector('.camp-filter[data-filter="' + status + '"]');
    if (btn) btn.click();
}

// ── Admin: campaign filter pills ──────────────────────────────────────────────
document.querySelectorAll('.camp-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.camp-filter').forEach(b => b.classList.remove('on', 'on-success', 'on-warn'));
        this.classList.add('on');
        const f = this.dataset.filter;
        document.querySelectorAll('.camp-row').forEach(row => {
            row.style.display = (f === 'all' || row.dataset.status === f) ? '' : 'none';
        });
    });
});

// ── Admin: edit modal ─────────────────────────────────────────────────────────
function showIdPhoto(path, title) {
    document.getElementById('id-photo-title').textContent = title || 'ID photo';
    document.getElementById('id-photo-full').src = path;
    new bootstrap.Modal(document.getElementById('idPhotoModal')).show();
}

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
    const response = await fetch('api/upload_campaign_image.php', {
        method: 'POST',
        body: form
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
        throw new Error(data.error || 'Image upload failed');
    }
    return data;
}

async function saveEditModal() {
    const campId = document.getElementById('edit-camp-id').value;
    const alert  = document.getElementById('edit-modal-alert');
    const image  = document.getElementById('edit-campaign-image').files[0];
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
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        }).then(r => r.json());
        if (data.success) {
            if (image) {
                alert.innerHTML = '<div class="alert alert-info py-2 small">Campaign saved. Uploading image...</div>';
                await uploadCampaignImage(campId, image);
            }
            alert.innerHTML = '<div class="alert alert-success py-2 small">Saved!</div>';
            setTimeout(() => location.reload(), 800);
        } else {
            alert.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`;
        }
    } catch {
        alert.innerHTML = '<div class="alert alert-danger py-2 small">Network error</div>';
    }
}

// ── Admin: donations modal ────────────────────────────────────────────────────
async function openDonationsModal(campId, campTitle) {
    const subtitle = document.getElementById('donations-modal-subtitle');
    subtitle.textContent = '';
    const link = document.createElement('a');
    link.href = 'partner/campaign/campaign-detail.php?id=' + encodeURIComponent(campId);
    link.className = 'text-success text-decoration-none fw-semibold';
    link.textContent = campTitle;
    subtitle.appendChild(link);
    document.getElementById('donations-modal-body').innerHTML =
        '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    new bootstrap.Modal(document.getElementById('donationsModal')).show();

    try {
        const data = await fetch('api/campaign_donations.php?camp_id=' + campId).then(r => r.json());
        const body = document.getElementById('donations-modal-body');
        if (!data.success || !data.donations.length) {
            body.innerHTML = '<p class="text-muted text-center py-3">No donations yet.</p>';
            return;
        }
        let html = `<div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
                <th>#</th><th>Donor</th><th>Amount</th><th>Date</th><th>Receipt</th>
            </tr></thead><tbody>`;
        data.donations.forEach(d => {
            const anonBadge = d.IsAnonymous
                ? ' <span class="badge bg-secondary" style="font-size:.65rem;">anon</span>' : '';
            const donorCell = data.isAdmin
                ? `<a href="profile.php?id=${d.DonorID}" class="text-success text-decoration-none fw-semibold">${d.DonorName}</a>${anonBadge}`
                : (d.DonorID
                    ? `<a href="profile.php?id=${d.DonorID}" class="text-success text-decoration-none fw-semibold">${d.DonorName}</a>`
                    : `<span class="text-muted fst-italic">${d.DonorName}</span>`);
            html += `<tr>
                <td class="text-muted small">${d.ID}</td>
                <td>${donorCell}</td>
                <td><strong class="text-success">$${parseFloat(d.Amt).toFixed(2)}</strong></td>
                <td class="text-muted small">${d.Time}</td>
                <td>${d.HasReceipt
                    ? '<span class="badge bg-success bg-opacity-10 text-success">Issued</span>'
                    : '<span class="text-muted">—</span>'}</td>
            </tr>`;
        });
        body.innerHTML = html + '</tbody></table></div>';
    } catch {
        document.getElementById('donations-modal-body').innerHTML =
            '<p class="text-danger text-center py-3">Failed to load donations.</p>';
    }
}

// ── Organizer: inline donations panel ────────────────────────────────────────
async function loadDonations(campID) {
    const panel   = document.getElementById('don-' + campID);
    const content = document.getElementById('don-content-' + campID);
    if (panel.classList.contains('open')) { panel.classList.remove('open'); return; }
    content.innerHTML = '<p class="text-muted small">Loading…</p>';
    panel.classList.add('open');
    try {
        const data = await fetch('api/campaign_donations.php?camp_id=' + campID).then(r => r.json());
        if (!data.success || !data.donations.length) {
            content.innerHTML = '<p class="text-muted small">No donations yet.</p>'; return;
        }
        let html = `<div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>Donor</th><th>Amount</th><th>Date</th><th>Receipt</th></tr></thead><tbody>`;
        data.donations.forEach(d => {
            const donorCell = d.DonorID
                ? `<a href="profile.php?id=${d.DonorID}" class="text-success text-decoration-none fw-semibold">${d.DonorName}</a>`
                : `<span class="text-muted fst-italic">${d.DonorName}</span>`;
            html += `<tr>
                <td class="text-muted small">${d.ID}</td><td>${donorCell}</td>
                <td><strong>$${parseFloat(d.Amt).toFixed(2)}</strong></td>
                <td class="text-muted small">${d.Time}</td>
                <td>${d.HasReceipt
                    ? '<span class="badge bg-success bg-opacity-10 text-success">Issued</span>'
                    : '<span class="text-muted">—</span>'}</td></tr>`;
        });
        content.innerHTML = html + '</tbody></table></div>';
    } catch { content.innerHTML = '<p class="text-danger small">Failed to load.</p>'; }
}

// ── Status change ─────────────────────────────────────────────────────────────
async function quickStatus(campID, newStatus) {
    if (!confirm(`Set campaign status to "${newStatus}"?`)) return;
    const payload = { camp_id: campID, status: newStatus };

    if (newStatus === 'closed' && <?= json_encode(!isAdmin()) ?>) {
        const send = await fetch('api/send_email_otp.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ purpose: 'campaign_close_auth', camp_id: campID })
        }).then(r => r.json()).catch(() => ({ success: false, error: 'Could not send Gmail verification code.' }));
        if (!send.success) {
            alert('Error: ' + (send.error || 'Could not send Gmail verification code.'));
            return;
        }

        const code = prompt('A 6-digit verification code was sent to your Gmail. Enter it to close the campaign:');
        if (!code) return;

        const verify = await fetch('api/verify_email_otp.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ purpose: 'campaign_close_auth', challenge_id: send.challenge_id, code })
        }).then(r => r.json()).catch(() => ({ success: false, error: 'Could not verify the Gmail code.' }));
        if (!verify.success) {
            alert('Error: ' + (verify.error || 'Could not verify the Gmail code.'));
            return;
        }

        payload.otp_challenge_id = send.challenge_id;
    }

    const data = await fetch('api/update_campaign.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    }).then(r => r.json());
    if (data.success) location.reload();
    else alert('Error: ' + (data.error || 'Update failed'));
}

async function sendCampaignAppeal(campID, campTitle) {
    const message = prompt(`Explain why "${campTitle}" should be reopened:`);
    if (!message || !message.trim()) return;
    const data = await fetch('api/campaign_appeal.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'submit', camp_id: campID, message: message.trim() })
    }).then(r => r.json()).catch(() => ({ success: false, error: 'Network error' }));
    if (data.success) {
        alert('Appeal sent to admin. You will receive the decision by Gmail and in UnityFund.');
        location.reload();
    } else {
        alert('Error: ' + (data.error || 'Could not send appeal'));
    }
}

// ── Organizer: save campaign inline ──────────────────────────────────────────
async function saveCampaign(e, campID) {
    e.preventDefault();
    const form  = e.target;
    const msgEl = document.getElementById('save-msg-' + campID);
    const payload = {
        camp_id: campID, title: form.title.value.trim(),
        goal: parseFloat(form.goal.value), category: form.category.value,
        description: form.description.value.trim(),
    };
    if (form.status) payload.status = form.status.value;
    msgEl.textContent = 'Saving…'; msgEl.className = 'small';
    try {
        const data = await fetch('api/update_campaign.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        }).then(r => r.json());
        if (data.success) {
            msgEl.textContent = '✓ Saved!'; msgEl.className = 'small text-success fw-semibold';
            if (form.campaign_image?.files[0]) {
                msgEl.textContent = 'Uploading image...';
                await uploadCampaignImage(campID, form.campaign_image.files[0]);
            }
            setTimeout(() => location.reload(), 600);
        } else {
            msgEl.textContent = '✗ ' + (data.error || 'Failed'); msgEl.className = 'small text-danger';
        }
    } catch { msgEl.textContent = '✗ Network error'; msgEl.className = 'small text-danger'; }
}

// ── New campaign ──────────────────────────────────────────────────────────────
const newForm = document.getElementById('newCampForm');
if (newForm) {
    newForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const msg = document.getElementById('newCampMsg');
        msg.textContent = 'Submitting…'; msg.className = 'small';
        try {
            const data = await fetch('api/update_campaign.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    action: 'create', title: this.title.value.trim(),
                    goal: parseFloat(this.goal.value), category: this.category.value,
                    description: this.description.value.trim(),
                })
            }).then(r => r.json());
            if (data.success) {
                msg.textContent = '✓ Submitted! Waiting for admin approval.';
                msg.className   = 'small text-success fw-semibold';
                if (this.campaign_image?.files[0]) {
                    msg.textContent = 'Campaign created. Uploading image...';
                    await uploadCampaignImage(data.camp_id, this.campaign_image.files[0]);
                }
                setTimeout(() => location.reload(), 900);
            } else {
                msg.textContent = '✗ ' + (data.error || 'Failed'); msg.className = 'small text-danger';
            }
        } catch { msg.textContent = '✗ Network error'; msg.className = 'small text-danger'; }
    });
}

// ── Approve organizer ─────────────────────────────────────────────────────────
async function connectStripeForCampaigns() {
    const btn = document.getElementById('campaign-stripe-btn');
    const msg = document.getElementById('campaign-stripe-msg');
    if (!btn || !msg) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Redirecting...';
    msg.textContent = '';
    msg.className = 'small mt-3';
    try {
        const data = await fetch('api/stripe_connect.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        }).then(r => r.json());
        if (data.success) {
            window.location.href = data.url;
            return;
        }
        msg.textContent = data.error || 'Could not start Stripe onboarding.';
        msg.className = 'small mt-3 text-danger';
    } catch {
        msg.textContent = 'Network error. Please try again.';
        msg.className = 'small mt-3 text-danger';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-lightning-fill me-1"></i>Connect Stripe';
}

async function approveOrganizer(userID, newRole, btn) {
    const notesEl = document.getElementById('decision-notes-' + userID);
    let notes = notesEl ? notesEl.value.trim() : '';
    if (newRole === 'donor') {
        if (!notes) {
            alert('Please enter decision notes before rejecting.');
            notesEl?.focus();
            return;
        }
    }
    btn.disabled = true;
    const data = await fetch('api/update_user_role.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ user_id: userID, role: newRole, notes })
    }).then(r => r.json());
    if (data.success) location.reload();
    else { alert('Error: ' + (data.error || 'Failed')); btn.disabled = false; }
}

// ── Request Change modal ──────────────────────────────────────────────────────
function openRequestModal(campId, campTitle) {
    document.getElementById('modal-camp-id').value         = campId;
    const titleLink = document.getElementById('modal-camp-title');
    titleLink.href = 'partner/campaign/campaign-detail.php?id=' + encodeURIComponent(campId);
    titleLink.textContent = campTitle;
    document.getElementById('modal-message').value         = '';
    document.getElementById('modal-alert').innerHTML       = '';
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
    const data = await fetch('api/request_change.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ camp_id: parseInt(campId), change_type: changeType, message })
    }).then(r => r.json());
    if (data.success) {
        alertDiv.innerHTML = '<div class="alert alert-success py-2 small">Request sent to organizer!</div>';
        setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('requestChangeModal')).hide(), 1500);
    } else {
        alertDiv.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`;
    }
}

// ── Mark notifications read ───────────────────────────────────────────────────
async function markAllRead() {
    await fetch('api/mark_notifications_read.php', { method: 'POST' });
    location.reload();
}

// ── Users tab: role filter + live text search ─────────────────────────────────
let _activeRole = 'all';

document.querySelectorAll('.role-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        _activeRole = this.dataset.role;
        document.querySelectorAll('.role-filter').forEach(b => b.classList.remove('on'));
        this.classList.add('on');
        applyUserFilters();
    });
});

function filterUsersTable(q) { applyUserFilters(q); }

function applyUserFilters(q) {
    q = (q !== undefined ? q : (document.getElementById('user-search-input')?.value || '')).toLowerCase();
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
</script>

<?php require_once 'includes/footer.php'; ?>
