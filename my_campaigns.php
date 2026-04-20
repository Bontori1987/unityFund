<?php
$pageTitle = 'Campaigns';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

requireRole(['organizer', 'admin']);

$userID  = (int)currentUser()['id'];
$isAdmin = isAdmin();

$CATEGORIES = ['Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];

// ── Admin: stats + pending applications ──────────────────────────────────────
$adminStats        = [];
$pendingOrganizers = [];

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
    } catch (PDOException $e) {}

    try {
        $pendingOrganizers = $conn->query(
            "SELECT UserID, Username, Email, CreatedAt
             FROM Users WHERE Role = 'pending_organizer'
             ORDER BY CreatedAt ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// ── Organizer: unread notifications ──────────────────────────────────────────
$notifications = [];
if (!$isAdmin) {
    $notifications = getNotifications($userID, true);
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

$pageTitle = $isAdmin ? 'Admin Dashboard' : 'My Campaigns';
require_once 'includes/header.php';
?>

<?php if ($isAdmin): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     ADMIN DASHBOARD
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Top bar -->
<div style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 60%,#0f3460 100%);"
     class="py-4 mb-0">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h1 class="fw-bold text-white mb-0" style="font-size:1.5rem;">
                    <i class="bi bi-speedometer2 me-2 text-success"></i>Admin Dashboard
                </h1>
                <p class="text-white-50 small mb-0 mt-1">
                    Platform overview &amp; management
                </p>
            </div>
            <div class="text-white-50 small">
                Logged in as <span class="text-white fw-semibold"><?= htmlspecialchars(currentUser()['username']) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Stats bar -->
<div style="background:#0f3460;border-bottom:1px solid rgba(255,255,255,.08);" class="py-3 mb-4">
    <div class="container">
        <div class="row g-3 text-center">
            <?php
            $stats = [
                ['val' => $adminStats['TotalCampaigns'] ?? 0,                       'lbl' => 'Total Campaigns',  'icon' => 'bi-grid',          'color' => 'text-white'],
                ['val' => $adminStats['ActiveCamps']    ?? 0,                       'lbl' => 'Active',           'icon' => 'bi-lightning',     'color' => 'text-success'],
                ['val' => $adminStats['PendingCamps']   ?? 0,                       'lbl' => 'Pending Review',   'icon' => 'bi-hourglass',     'color' => 'text-warning'],
                ['val' => '$'.number_format($adminStats['TotalRaised'] ?? 0, 0),   'lbl' => 'Total Raised',     'icon' => 'bi-currency-dollar','color' => 'text-success'],
                ['val' => $adminStats['TotalUsers']     ?? 0,                       'lbl' => 'Registered Users', 'icon' => 'bi-people',        'color' => 'text-info'],
                ['val' => count($pendingOrganizers),                                'lbl' => 'Pending Organizers','icon'=> 'bi-person-badge',  'color' => count($pendingOrganizers) > 0 ? 'text-warning' : 'text-white'],
            ];
            foreach ($stats as $s): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="<?= $s['color'] ?> fw-bold" style="font-size:1.3rem;">
                    <?= $s['val'] ?>
                </div>
                <div class="text-white-50 small">
                    <i class="bi <?= $s['icon'] ?> me-1"></i><?= $s['lbl'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="container pb-5">

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-0" id="adminTabs" style="border-bottom:2px solid #dee2e6;">
        <li class="nav-item">
            <a class="nav-link active fw-semibold" data-bs-toggle="tab" href="#tab-campaigns">
                <i class="bi bi-grid me-1"></i>Campaigns
                <span class="badge bg-secondary ms-1" style="font-size:.7rem;"><?= count($campaigns) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold" data-bs-toggle="tab" href="#tab-applications">
                <i class="bi bi-person-badge me-1"></i>Applications
                <?php if (!empty($pendingOrganizers)): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem;">
                    <?= count($pendingOrganizers) ?>
                </span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link fw-semibold" data-bs-toggle="tab" href="#tab-users">
                <i class="bi bi-search me-1"></i>User Search
            </a>
        </li>
    </ul>

    <div class="tab-content pt-4">

        <!-- ── Tab: Campaigns ──────────────────────────────────── -->
        <div class="tab-pane fade show active" id="tab-campaigns">

            <!-- Filter pills -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="text-muted small fw-semibold me-1">Filter:</span>
                <button class="btn btn-sm btn-success rounded-pill px-3 camp-filter active" data-filter="all">
                    All <span class="badge bg-white text-success ms-1"><?= count($campaigns) ?></span>
                </button>
                <?php
                $counts = array_count_values(array_column($campaigns, 'Status'));
                foreach (['pending'=>'warning','active'=>'success','closed'=>'secondary'] as $st => $col):
                    $n = $counts[$st] ?? 0;
                    if (!$n) continue;
                ?>
                <button class="btn btn-sm btn-outline-<?= $col ?> rounded-pill px-3 camp-filter" data-filter="<?= $st ?>">
                    <?= ucfirst($st) ?>
                    <span class="badge bg-<?= $col ?> <?= $col==='warning'?'text-dark':'' ?> ms-1"><?= $n ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Campaign table -->
            <?php if (empty($campaigns)): ?>
            <div class="empty-state py-5">
                <i class="bi bi-grid d-block"></i>
                <p class="mt-3 text-muted">No campaigns found.</p>
            </div>
            <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="campaigns-table">
                        <thead style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                            <tr>
                                <th class="ps-4" style="width:30%;">Campaign</th>
                                <th>Organizer</th>
                                <th>Progress</th>
                                <th>Donors</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($campaigns as $c):
                            $raised    = (float)$c['TotalRaised'];
                            $goal      = (float)$c['GoalAmt'];
                            $pct       = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;
                            $cid       = $c['CampID'];
                            $adminOwns = (int)($c['HostID'] ?? 0) === $userID;
                            $canEdit   = $adminOwns;

                            $statusCfg = [
                                'active'  => ['bg-success',               'Active'],
                                'pending' => ['bg-warning text-dark',      'Pending'],
                                'closed'  => ['bg-secondary',              'Closed'],
                            ][$c['Status']] ?? ['bg-secondary', $c['Status']];
                        ?>
                        <tr class="camp-row" data-status="<?= $c['Status'] ?>">
                            <td class="ps-4">
                                <div class="fw-semibold" style="max-width:220px;">
                                    <?= htmlspecialchars($c['Title']) ?>
                                </div>
                                <span class="badge bg-success bg-opacity-10 text-success" style="font-size:.68rem;">
                                    <?= htmlspecialchars($c['Category'] ?? 'Other') ?>
                                </span>
                            </td>
                            <td>
                                <a href="profile.php?id=<?= $c['HostID'] ?>"
                                   class="text-success text-decoration-none small fw-semibold">
                                    <?= htmlspecialchars($c['HostName']) ?>
                                </a>
                            </td>
                            <td style="min-width:160px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="flex-grow-1" style="background:#e9ecef;border-radius:4px;height:6px;min-width:80px;">
                                        <div style="width:<?= number_format($pct,1) ?>%;height:100%;background:#198754;border-radius:4px;"></div>
                                    </div>
                                    <span class="small text-muted" style="white-space:nowrap;">
                                        $<?= number_format($raised,0) ?> / $<?= number_format($goal,0) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="small"><?= $c['DonorCount'] ?></td>
                            <td>
                                <span class="badge <?= $statusCfg[0] ?>"><?= $statusCfg[1] ?></span>
                            </td>
                            <td class="text-muted small">
                                <?= date('M j, Y', strtotime($c['CreatedAt'])) ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    <!-- Status actions -->
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
                                    <button class="btn btn-sm btn-outline-success" title="Reactivate"
                                            onclick="quickStatus(<?= $cid ?>,'active')">
                                        <i class="bi bi-unlock"></i>
                                    </button>
                                    <?php endif; ?>

                                    <!-- Edit (own only) or Request Change -->
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

                                    <!-- Donations -->
                                    <button class="btn btn-sm btn-outline-secondary" title="View Donations"
                                            onclick="openDonationsModal(<?= $cid ?>, <?= json_encode($c['Title']) ?>)">
                                        <i class="bi bi-list-ul"></i>
                                    </button>

                                    <!-- View public page -->
                                    <a href="partner/campaign/campaign-detail.php?id=<?= $cid ?>"
                                       class="btn btn-sm btn-outline-secondary" title="View public page" target="_blank">
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

        <!-- ── Tab: Pending Applications ──────────────────────── -->
        <div class="tab-pane fade" id="tab-applications">
            <?php if (empty($pendingOrganizers)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-check2-circle d-block" style="font-size:2.5rem;color:#198754;"></i>
                    <p class="mt-3 mb-0 fw-semibold">No pending applications</p>
                    <p class="small">All organizer requests have been reviewed.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                            <tr>
                                <th class="ps-4">Applicant</th>
                                <th>Email</th>
                                <th>Applied</th>
                                <th class="text-end pe-4">Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pendingOrganizers as $org): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-warning d-flex align-items-center justify-content-center text-dark fw-bold"
                                         style="width:36px;height:36px;font-size:.9rem;flex-shrink:0;">
                                        <?= strtoupper(substr($org['Username'],0,1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($org['Username']) ?></div>
                                        <span class="badge bg-warning text-dark" style="font-size:.65rem;">Pending Organizer</span>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($org['Email']) ?></td>
                            <td class="text-muted small"><?= date('M j, Y', strtotime($org['CreatedAt'])) ?></td>
                            <td class="text-end pe-4">
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

        <!-- ── Tab: User Search ───────────────────────────────── -->
        <div class="tab-pane fade" id="tab-users">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h6 class="fw-semibold mb-3">Search by email</h6>
                    <div class="d-flex gap-2 mb-4" style="max-width:500px;">
                        <input type="text" id="user-search-input" class="form-control"
                               placeholder="Enter full or partial email address…">
                        <button class="btn btn-success px-4 fw-semibold" onclick="searchUsers()">
                            <i class="bi bi-search me-1"></i>Search
                        </button>
                    </div>
                    <div id="user-search-results"></div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
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
                data-bs-toggle="collapse" data-bs-target="#newCampPanel">
            <i class="bi bi-plus-lg me-1"></i>New Campaign
        </button>
    </div>

    <!-- Notifications from admin -->
    <?php if (!empty($notifications)): ?>
    <div class="card border-0 mb-4" style="border-left:4px solid #f59e0b!important;background:#fffbeb;">
        <div class="card-body">
            <h6 class="fw-bold mb-3" style="color:#92400e;">
                <i class="bi bi-bell-fill me-2" style="color:#f59e0b;"></i>
                Messages from Admin <span class="badge bg-warning text-dark ms-1"><?= count($notifications) ?></span>
            </h6>
            <?php foreach ($notifications as $n): ?>
            <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                <div class="flex-shrink-0 mt-1">
                    <span class="badge <?= $n['change_type']==='name' ? 'bg-info' : 'bg-warning text-dark' ?>">
                        <?= $n['change_type']==='name' ? 'Name' : 'Goal' ?>
                    </span>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small mb-1"><?= htmlspecialchars($n['camp_title']) ?></div>
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

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- New campaign form -->
    <div class="collapse mb-4" id="newCampPanel">
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
        $statusClass = ['active'=>'camp-status-active','pending'=>'camp-status-pending','closed'=>'camp-status-closed'][$c['Status']] ?? '';
        $statusLabel = ['active'=>'Active','pending'=>'Pending Approval','closed'=>'Closed'][$c['Status']] ?? $c['Status'];
    ?>
    <div class="card mb-3 border-0 shadow-sm" id="card-<?= $cid ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($c['Title']) ?></h5>
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
                            <button type="submit" class="btn btn-success btn-sm px-4">Save Changes</button>
                            <span class="ms-2 small" id="save-msg-<?= $cid ?>"></span>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-body" id="don-<?= $cid ?>">
            <div class="card-body border-top" style="background:#fafafa;">
                <h6 class="section-title mb-3">Donations — <?= htmlspecialchars($c['Title']) ?></h6>
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
                    Send a change request to the organizer of <strong id="modal-camp-title"></strong>.
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
// ── Admin: filter pills ───────────────────────────────────────────────────────
document.querySelectorAll('.camp-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.camp-filter').forEach(b => {
            b.classList.remove('active', 'btn-success', 'btn-warning', 'btn-secondary');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary', 'btn-outline-warning', 'btn-outline-success');
        this.classList.add('active', 'btn-success');

        const f = this.dataset.filter;
        document.querySelectorAll('.camp-row').forEach(row => {
            row.style.display = (f === 'all' || row.dataset.status === f) ? '' : 'none';
        });
    });
});

// ── Admin: edit modal ─────────────────────────────────────────────────────────
function openEditModal(campId, title, goal, category, status, description) {
    document.getElementById('edit-camp-id').value     = campId;
    document.getElementById('edit-title').value       = title;
    document.getElementById('edit-goal').value        = goal;
    document.getElementById('edit-category').value    = category;
    document.getElementById('edit-status').value      = status;
    document.getElementById('edit-description').value = description;
    document.getElementById('edit-modal-alert').innerHTML = '';
    new bootstrap.Modal(document.getElementById('editCampModal')).show();
}

async function saveEditModal() {
    const campId = document.getElementById('edit-camp-id').value;
    const alert  = document.getElementById('edit-modal-alert');
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
    document.getElementById('donations-modal-subtitle').textContent = campTitle;
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
    const data = await fetch('api/update_campaign.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ camp_id: campID, status: newStatus })
    }).then(r => r.json());
    if (data.success) location.reload();
    else alert('Error: ' + (data.error || 'Update failed'));
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
                setTimeout(() => location.reload(), 900);
            } else {
                msg.textContent = '✗ ' + (data.error || 'Failed'); msg.className = 'small text-danger';
            }
        } catch { msg.textContent = '✗ Network error'; msg.className = 'small text-danger'; }
    });
}

// ── Approve organizer ─────────────────────────────────────────────────────────
async function approveOrganizer(userID, newRole, btn) {
    btn.disabled = true;
    const data = await fetch('api/update_user_role.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ user_id: userID, role: newRole })
    }).then(r => r.json());
    if (data.success) location.reload();
    else { alert('Error: ' + (data.error || 'Failed')); btn.disabled = false; }
}

// ── Request Change modal ──────────────────────────────────────────────────────
function openRequestModal(campId, campTitle) {
    document.getElementById('modal-camp-id').value         = campId;
    document.getElementById('modal-camp-title').textContent = campTitle;
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

// ── User search ───────────────────────────────────────────────────────────────
document.getElementById('user-search-input')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') searchUsers();
});

async function searchUsers() {
    const q       = document.getElementById('user-search-input').value.trim();
    const results = document.getElementById('user-search-results');
    if (!q) return;
    results.innerHTML = '<p class="text-muted small">Searching…</p>';
    const data = await fetch('api/search_users.php?email=' + encodeURIComponent(q)).then(r => r.json());
    if (!data.success) {
        results.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`; return;
    }
    if (!data.users.length) {
        results.innerHTML = '<p class="text-muted small">No users found.</p>'; return;
    }
    const roleBadge = r => ({'admin':'bg-danger','organizer':'bg-purple','donor':'bg-primary','pending_organizer':'bg-warning text-dark'}[r]||'bg-secondary');
    let html = `<div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>Name</th><th>Email</th><th>Role</th><th>Anonymous</th><th>Joined</th><th></th>
        </tr></thead><tbody>`;
    data.users.forEach(u => {
        html += `<tr>
            <td class="fw-semibold">${u.Username}</td>
            <td class="text-muted small">${u.Email}</td>
            <td><span class="badge ${roleBadge(u.Role)}">${u.Role}</span></td>
            <td>${u.IsAnonymous ? '<span class="badge bg-secondary">Yes</span>' : '<span class="text-muted small">No</span>'}</td>
            <td class="text-muted small">${u.CreatedAt ? u.CreatedAt.substring(0,10) : '—'}</td>
            <td><a href="profile.php?id=${u.UserID}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-person me-1"></i>View Profile</a></td>
        </tr>`;
    });
    results.innerHTML = html + '</tbody></table></div>';
}
</script>

<?php require_once 'includes/footer.php'; ?>
