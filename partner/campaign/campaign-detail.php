<?php
require_once '../../includes/auth.php';
require_once '../../db.php';   // MS SQL PDO ($conn)

$isLoggedIn = isLoggedIn();
$canDonate  = canDonate();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: ../../index.php'); exit; }

try {
    $stmt = $conn->prepare(
        "SELECT c.CampID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt,
                COALESCE(u.Username, 'Unknown') AS HostName,
                COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                COUNT(DISTINCT d.DonorID) AS DonorCount
         FROM Campaigns c
         LEFT JOIN Users u ON c.HostID = u.UserID
         LEFT JOIN Donations d ON d.CampID = c.CampID
         WHERE c.CampID = ?
         GROUP BY c.CampID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt, u.Username"
    );
    $stmt->execute([$id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaign = null;
    $dbErr = $e->getMessage();
}

if (!$campaign) {
    http_response_code(404);
    $msg = isset($dbErr) ? htmlspecialchars($dbErr) : 'Campaign not found.';
    die('<p style="font-family:sans-serif;padding:2rem;">' . $msg . ' <a href="../../index.php">Go back</a></p>');
}

$raised = (float)$campaign['TotalRaised'];
$goal   = (float)$campaign['GoalAmt'];
$pct    = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;

$categoryIcons = [
    'Technology'  => 'bi-cpu',
    'Arts'        => 'bi-palette',
    'Community'   => 'bi-people',
    'Education'   => 'bi-book',
    'Environment' => 'bi-tree',
    'Health'      => 'bi-heart-pulse',
    'Food'        => 'bi-egg-fried',
    'Other'       => 'bi-grid',
];
$icon = $categoryIcons[$campaign['Category']] ?? 'bi-grid';

$statusLabel = ['active' => 'Live', 'pending' => 'Pending', 'closed' => 'Ended'][$campaign['Status']] ?? $campaign['Status'];

$basePath  = '../../';
$pageTitle = $campaign['Title'];
require_once '../../includes/header.php';
?>

<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="../../index.php" class="text-success text-decoration-none">Discover</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($campaign['Title']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">

        <!-- ── LEFT: Banner + description ───────────────────────── -->
        <div class="col-lg-8">

            <h1 class="fw-bold mb-1" style="font-size:1.6rem;">
                <?= htmlspecialchars($campaign['Title']) ?>
            </h1>

            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="badge bg-success bg-opacity-10 text-success fw-semibold">
                    <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($campaign['Category']) ?>
                </span>
                <span class="text-muted small">by <?= htmlspecialchars($campaign['HostName']) ?></span>
                <span class="text-muted small">·</span>
                <span class="text-muted small">
                    Started <?= date('M j, Y', strtotime($campaign['CreatedAt'])) ?>
                </span>
            </div>

            <!-- Campaign banner (icon-based) -->
            <div class="rounded mb-4 d-flex align-items-center justify-content-center"
                 style="height:280px;background:linear-gradient(135deg,#e6f7ef,#d4edda);">
                <i class="bi <?= $icon ?> text-success" style="font-size:6rem;opacity:.5;"></i>
            </div>

            <!-- Description -->
            <div class="card">
                <div class="card-body">
                    <h5 class="fw-bold mb-3">About this campaign</h5>
                    <?php if (!empty($campaign['Description'] ?? '')): ?>
                    <div class="text-muted" style="line-height:1.8;white-space:pre-wrap;">
                        <?= htmlspecialchars($campaign['Description']) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted fst-italic">No description provided.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT: Stats sidebar ───────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top:80px;">
                <div class="card-body">

                    <div class="mb-1">
                        <span class="fw-bold text-success" style="font-size:1.8rem;">
                            $<?= number_format($raised, 0) ?>
                        </span>
                        <span class="text-muted small"> raised of $<?= number_format($goal, 0) ?> goal</span>
                    </div>

                    <div class="progress mb-3" style="height:8px;">
                        <div class="progress-bar bg-success" style="width:<?= number_format($pct, 1) ?>%"></div>
                    </div>

                    <div class="row g-2 text-center mb-4">
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= number_format($pct, 0) ?>%</div>
                            <div class="text-muted small">funded</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $campaign['DonorCount'] ?></div>
                            <div class="text-muted small">donor<?= $campaign['DonorCount'] != 1 ? 's' : '' ?></div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $statusLabel ?></div>
                            <div class="text-muted small">status</div>
                        </div>
                    </div>

                    <?php if ($campaign['Status'] === 'active'): ?>
                        <?php if ($canDonate): ?>
                        <a href="../../donate.php?camp_id=<?= $id ?>"
                           class="btn btn-success w-100 fw-semibold py-2 mb-2">
                            <i class="bi bi-heart me-1"></i>Donate now
                        </a>
                        <?php elseif (!$isLoggedIn): ?>
                        <a href="../../login.php?redirect=<?= urlencode('partner/campaign/campaign-detail.php?id='.$id) ?>"
                           class="btn btn-outline-success w-100 fw-semibold py-2 mb-2">
                            Sign in to donate
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="alert alert-secondary text-center small py-2 mb-2">
                        This campaign is no longer accepting donations.
                    </div>
                    <?php endif; ?>

                    <button class="btn btn-outline-secondary w-100 btn-sm" onclick="handleShare()">
                        <i class="bi bi-share me-1"></i>Share
                    </button>
                    <div id="toastMsg" class="alert alert-success text-center small mt-2 py-2"
                         style="display:none;">Link copied!</div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
function handleShare() {
    navigator.clipboard.writeText(window.location.href).then(() => {
        const t = document.getElementById('toastMsg');
        t.style.display = 'block';
        setTimeout(() => t.style.display = 'none', 2500);
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
