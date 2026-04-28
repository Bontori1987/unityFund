<?php
$basePath  = '../../';
$pageTitle = 'Home';
require_once '../../includes/auth.php';
require_once '../../db.php';
require_once '../../includes/mongo.php';

try {
    $stmt = $conn->query(
        "SELECT
            c.CampID, c.Title, c.GoalAmt, c.Category, c.CreatedAt,
            COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
            COUNT(DISTINCT d.DonorID) AS DonorCount
         FROM Campaigns c
         LEFT JOIN Donations d ON d.CampID = c.CampID
         WHERE c.Status = 'active'
         GROUP BY c.CampID, c.Title, c.GoalAmt, c.Category, c.CreatedAt
         ORDER BY TotalRaised DESC"
    );
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

$campaignImages = [];
try {
    $campaignImages = getCampaignDetailsMap(array_column($campaigns, 'CampID'));
} catch (Exception $e) {
    $campaignImages = [];
}

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

require_once '../../includes/header.php';
?>

<!-- Hero -->
<section class="hero-section py-5">
    <div class="container py-3 text-center">
        <p class="text-success fw-semibold mb-2 small text-uppercase">Every dollar makes a difference</p>
        <h1 class="display-5 fw-bold mb-3">Support a cause you believe in</h1>
        <p class="text-muted mb-4" style="max-width:520px;margin:0 auto;">
            Browse active campaigns, track progress in real time, and make your contribution count.
        </p>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
            <?php if (isOrganizer() || isAdmin()): ?>
            <a href="../../my_campaigns.php" class="btn btn-success btn-lg px-4 fw-semibold">
                Manage My Campaigns
            </a>
            <?php elseif (!isLoggedIn()): ?>
            <a href="../../register.php" class="btn btn-success btn-lg px-4 fw-semibold">Get started</a>
            <a href="../../login.php"    class="btn btn-outline-success btn-lg px-4">Sign in</a>
            <?php elseif (canDonate()): ?>
            <a href="../../donate.php" class="btn btn-success btn-lg px-4 fw-semibold">
                <i class="bi bi-heart me-1"></i>Donate now
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Campaign list -->
<div class="container py-5">
    <h2 class="fw-bold mb-4">Active Campaigns</h2>

    <?php if (empty($campaigns)): ?>
    <div class="empty-state">
        <i class="bi bi-search d-block"></i>
        <p class="mt-2">No active campaigns right now.</p>
    </div>
    <?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <?php foreach ($campaigns as $c):
            $raised  = (float)$c['TotalRaised'];
            $goal    = (float)$c['GoalAmt'];
            $pct     = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;
            $icon    = $categoryIcons[$c['Category'] ?? 'Other'] ?? 'bi-grid';
            $detailUrl = '../campaign/campaign-detail.php?id=' . $c['CampID'];
            $thumb = $campaignImages[(int)$c['CampID']]['thumbnail'] ?? '';
        ?>
        <div class="col">
            <div class="card campaign-card h-100"
                 onclick="location.href='<?= $detailUrl ?>'">
                <?php if ($thumb): ?>
                <img src="../../<?= htmlspecialchars($thumb) ?>"
                     alt="<?= htmlspecialchars($c['Title']) ?>"
                     class="card-img-top">
                <?php else: ?>
                <div class="img-placeholder">
                    <i class="bi <?= $icon ?> text-success" style="font-size:3.5rem;opacity:.6;"></i>
                </div>
                <?php endif; ?>
                <div class="card-body d-flex flex-column">
                    <span class="badge bg-success bg-opacity-10 text-success fw-semibold mb-2"
                          style="font-size:.72rem;align-self:flex-start;">
                        <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($c['Category'] ?? 'Other') ?>
                    </span>
                    <h5 class="card-title fw-bold mb-0" style="font-size:1rem;line-height:1.35;">
                        <a href="<?= $detailUrl ?>"
                           class="text-dark text-decoration-none"
                           onclick="event.stopPropagation()">
                            <?= htmlspecialchars($c['Title']) ?>
                        </a>
                    </h5>
                    <div class="mt-auto pt-3">
                        <div class="progress mb-2" style="height:6px;">
                            <div class="progress-bar bg-success" style="width:<?= number_format($pct,1) ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <div>
                                <span class="fw-bold text-success">$<?= number_format($raised, 0) ?></span>
                                <span class="text-muted"> of $<?= number_format($goal, 0) ?></span>
                            </div>
                            <span class="text-muted">
                                <?= $c['DonorCount'] ?> donor<?= $c['DonorCount'] != 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="text-muted small"><?= number_format($pct, 0) ?>% funded</span>
                            <a href="<?= $detailUrl ?>"
                               class="btn btn-sm btn-outline-success"
                               onclick="event.stopPropagation()">View &rarr;</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mt-4">
        <a href="../../index.php" class="btn btn-outline-success px-4">
            <i class="bi bi-grid me-1"></i>Browse all campaigns
        </a>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
