<?php
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

$CATEGORIES = ['All', 'Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];
$activeCategory = $_GET['cat'] ?? 'All';
if (!in_array($activeCategory, $CATEGORIES)) $activeCategory = 'All';

$search = trim($_GET['q'] ?? '');

// Stats bar totals
try {
    $statsRow = $conn->query(
        "SELECT
            COUNT(DISTINCT c.CampID)  AS ActiveCampaigns,
            COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
            COUNT(DISTINCT d.DonorID) AS TotalDonors
         FROM Campaigns c
         LEFT JOIN Donations d ON d.CampID = c.CampID
         WHERE c.Status = 'active'"
    )->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $statsRow = ['ActiveCampaigns' => 0, 'TotalRaised' => 0, 'TotalDonors' => 0];
}

// Campaign cards — filtered by category + search
try {
    $params = [];
    $where  = ["c.Status = 'active'"];

    if ($activeCategory !== 'All') {
        $where[]  = "c.Category = ?";
        $params[] = $activeCategory;
    }
    if ($search !== '') {
        $where[]  = "c.Title LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $sql = "SELECT
                c.CampID, c.Title, c.GoalAmt, c.Category, c.CreatedAt,
                COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                COUNT(DISTINCT d.DonorID) AS DonorCount
            FROM Campaigns c
            LEFT JOIN Donations d ON d.CampID = c.CampID
            WHERE " . implode(' AND ', $where) . "
            GROUP BY c.CampID, c.Title, c.GoalAmt, c.Category, c.CreatedAt
            ORDER BY TotalRaised DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

$campaignImages = [];
try {
    $campaignIds = array_map('intval', array_column($campaigns, 'CampID'));
    if (!empty($campaignIds)) {
        foreach (mongoFind('campaign_details', ['camp_id' => ['$in' => $campaignIds]]) as $doc) {
            $campId = (int)($doc->camp_id ?? 0);
            if ($campId > 0) {
                $campaignImages[$campId] = [
                    'banner'    => $doc->banner ?? '',
                    'thumbnail' => $doc->thumbnail ?? '',
                ];
            }
        }
    }
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

$pageTitle = 'Discover Campaigns';
$basePath  = '';
require_once 'includes/header.php';
?>

<!-- ── Hero ──────────────────────────────────────────────────── -->
<section class="hero-section py-5">
    <div class="container py-3">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <p class="text-success fw-semibold mb-2 small text-uppercase">
                    Crowdfunding for good
                </p>
                <h1 class="display-5 fw-bold mb-3" style="line-height:1.2;">
                    Fund what matters.<br>
                    <span style="color:var(--uf-green);">Together.</span>
                </h1>
                <p class="text-muted mb-4" style="font-size:1.05rem;max-width:480px;">
                    UnityFund connects generous donors with campaigns that create real-world impact.
                    Browse, give, and track every dollar.
                </p>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if (!isLoggedIn()): ?>
                    <a href="register.php" class="btn btn-success btn-lg px-4 fw-semibold">Get started</a>
                    <a href="login.php"    class="btn btn-outline-success btn-lg px-4">Sign in</a>
                    <?php elseif (canDonate()): ?>
                    <a href="donate.php"      class="btn btn-success btn-lg px-4 fw-semibold">
                        <i class="bi bi-heart me-1"></i>Donate now
                    </a>
                    <?php endif; ?>
                    <?php if (isOrganizer()): ?>
                    <a href="my_campaigns.php" class="btn btn-outline-success btn-lg px-4">My Campaigns</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="text-center p-4 bg-white border rounded shadow-sm">
                    <div style="font-size:7rem;line-height:1;">💚</div>
                    <p class="text-muted small mt-2 mb-0">Every dollar tracked transparently</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Stats bar ─────────────────────────────────────────────── -->
<div class="stats-bar py-3">
    <div class="container">
        <div class="row text-center g-0">
            <div class="col-4">
                <div class="stat-val">$<?= number_format($statsRow['TotalRaised'], 0) ?></div>
                <div class="stat-lbl">Total Raised</div>
            </div>
            <div class="col-4">
                <div class="stat-val"><?= $statsRow['ActiveCampaigns'] ?></div>
                <div class="stat-lbl">Active Campaigns</div>
            </div>
            <div class="col-4">
                <div class="stat-val"><?= $statsRow['TotalDonors'] ?></div>
                <div class="stat-lbl">Donors</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Discover section ──────────────────────────────────────── -->
<div class="container py-5">

    <!-- Search + heading -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md-6">
            <h2 class="fw-bold mb-0">Discover campaigns</h2>
        </div>
        <div class="col-md-6">
            <form method="GET" class="d-flex gap-2">
                <?php if ($activeCategory !== 'All'): ?>
                    <input type="hidden" name="cat" value="<?= htmlspecialchars($activeCategory) ?>">
                <?php endif; ?>
                <input type="text" name="q" class="form-control"
                       placeholder="Search campaigns…"
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-success px-3">
                    <i class="bi bi-search"></i>
                </button>
                <?php if ($search): ?>
                <a href="?<?= $activeCategory !== 'All' ? 'cat='.urlencode($activeCategory) : '' ?>"
                   class="btn btn-outline-secondary px-3" title="Clear search">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Category filter pills -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php foreach ($CATEGORIES as $cat):
            $icon    = $cat === 'All' ? 'bi-grid-3x3-gap' : ($categoryIcons[$cat] ?? 'bi-tag');
            $isActive = $cat === $activeCategory;
            $href = '?' . ($cat !== 'All' ? 'cat='.urlencode($cat) : '') . ($search ? '&q='.urlencode($search) : '');
        ?>
        <a href="<?= $href ?>"
           class="btn btn-sm <?= $isActive ? 'btn-success' : 'btn-outline-secondary' ?> rounded-pill px-3">
            <i class="bi <?= $icon ?> me-1"></i><?= $cat ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Campaign grid -->
    <?php if (empty($campaigns)): ?>
    <div class="empty-state py-5">
        <i class="bi bi-search d-block"></i>
        <h5 class="mt-3">No campaigns found</h5>
        <p class="text-muted">
            <?= $search ? "No results for \"".htmlspecialchars($search)."\"." : "No active campaigns in this category yet." ?>
        </p>
        <?php if ($search || $activeCategory !== 'All'): ?>
        <a href="index.php" class="btn btn-outline-success mt-2">Show all campaigns</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <?php foreach ($campaigns as $c):
            $raised  = (float)$c['TotalRaised'];
            $goal    = (float)$c['GoalAmt'];
            $pct     = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;
            $icon    = $categoryIcons[$c['Category']] ?? 'bi-tag';
            $detailUrl = 'partner/campaign/campaign-detail.php?id=' . $c['CampID'];
            $thumb = $campaignImages[(int)$c['CampID']]['thumbnail'] ?? '';
        ?>
        <div class="col">
            <div class="card campaign-card h-100" onclick="location.href='<?= $detailUrl ?>'">
                <?php if ($thumb): ?>
                <img src="<?= htmlspecialchars($thumb) ?>"
                     alt="<?= htmlspecialchars($c['Title']) ?>"
                     class="card-img-top">
                <?php else: ?>
                <!-- Image placeholder with category icon -->
                <div class="img-placeholder">
                    <i class="bi <?= $icon ?> text-success" style="font-size:3.5rem;opacity:.6;"></i>
                </div>
                <?php endif; ?>
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-success bg-opacity-10 text-success fw-semibold"
                              style="font-size:.72rem;">
                            <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($c['Category']) ?>
                        </span>
                    </div>
                    <h5 class="card-title fw-bold mb-1" style="font-size:1rem;line-height:1.35;">
                        <a href="<?= $detailUrl ?>"
                           class="text-dark text-decoration-none"
                           onclick="event.stopPropagation()">
                            <?= htmlspecialchars($c['Title']) ?>
                        </a>
                    </h5>
                    <div class="mt-auto pt-3">
                        <!-- Progress -->
                        <div class="progress mb-2" style="height:6px;">
                            <div class="progress-bar bg-success"
                                 style="width:<?= number_format($pct, 1) ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center small">
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
    <?php endif; ?>

</div>

<!-- ── CTA banner ────────────────────────────────────────────── -->
<?php if (!isOrganizer() && !isAdmin()): ?>
<div class="bg-success text-white py-5 mt-3">
    <div class="container text-center">
        <h3 class="fw-bold mb-2">Have a cause worth funding?</h3>
        <p class="mb-4 opacity-75">Create a campaign and let the community back it. Admin-reviewed before going live.</p>
        <a href="<?= isLoggedIn() ? 'apply_organizer.php' : 'register.php' ?>"
           class="btn btn-light btn-lg px-5 fw-semibold text-success">
            Start a campaign
        </a>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
