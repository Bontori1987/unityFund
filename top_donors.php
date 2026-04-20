<?php
$pageTitle = 'Top Donors';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

try {
    $stmt = $conn->query(
        "SELECT TOP 10 UserID, Username, TotalDonated, DonationCount, LastDonation, OverallRank
         FROM vw_TopDonors ORDER BY OverallRank"
    );
    $topDonors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $topDonors = [];
    $dbError   = $e->getMessage();
}

try {
    $stmt2 = $conn->query(
        "SELECT CampID, CampaignTitle, DonorName, Amt, RankInCampaign
         FROM vw_DonationRunningTotal
         WHERE RankInCampaign <= 3
         ORDER BY CampID, RankInCampaign"
    );
    $perCampaign = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $perCampaign = [];
}

$medals  = ['1' => '🥇', '2' => '🥈', '3' => '🥉'];
$podium  = array_slice($topDonors, 0, 3);
$rest    = array_slice($topDonors, 3);

require_once 'includes/header.php';
?>

<div class="container py-5">

    <div class="mb-5">
        <h1 class="fw-bold mb-1"><i class="bi bi-trophy text-warning me-2"></i>Top Supporters</h1>
        <p class="text-muted">Our most generous donors — ranked by total contributions across all campaigns.</p>
    </div>

    <?php if (!empty($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php elseif (empty($topDonors)): ?>
    <div class="empty-state">
        <i class="bi bi-trophy"></i>
        <p>No donations yet. Be the first to donate!</p>
        <a href="donate.php" class="btn btn-success mt-2">Donate now</a>
    </div>
    <?php else: ?>

    <!-- ── Podium ───────────────────────────────────────────── -->
    <?php if (!empty($podium)):
        // Reorder: 2nd, 1st, 3rd
        $display = [];
        if (isset($podium[1])) $display[] = ['d' => $podium[1], 'r' => 2];
        if (isset($podium[0])) $display[] = ['d' => $podium[0], 'r' => 1];
        if (isset($podium[2])) $display[] = ['d' => $podium[2], 'r' => 3];
    ?>
    <div class="podium-wrap mb-5">
        <?php foreach ($display as $item):
            $d = $item['d']; $r = $item['r'];
        ?>
        <div class="podium-item rank-<?= $r ?>">
            <div style="font-size:2.2rem;"><?= $medals[(string)$r] ?? '#'.$r ?></div>
            <div class="fw-bold mt-2"><?= htmlspecialchars($d['Username']) ?></div>
            <div class="text-success fw-bold fs-5 mt-1">$<?= number_format($d['TotalDonated'], 2) ?></div>
            <div class="text-muted small mt-1">
                <?= $d['DonationCount'] ?> donation<?= $d['DonationCount'] != 1 ? 's' : '' ?>
            </div>
            <span class="badge mt-2 <?= $r===1 ? 'bg-warning text-dark' : ($r===2 ? 'bg-secondary' : 'bg-danger bg-opacity-75') ?>">
                Rank #<?= $r ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Full leaderboard ─────────────────────────────────── -->
    <?php if (!empty($rest)): ?>
    <div class="card mb-5">
        <div class="card-body">
            <h5 class="fw-bold mb-3">Full Leaderboard</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Donor</th>
                            <th>Total Donated</th>
                            <th>Donations</th>
                            <th>Last Donation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rest as $d): ?>
                        <tr>
                            <td><strong>#<?= $d['OverallRank'] ?></strong></td>
                            <td><?= htmlspecialchars($d['Username']) ?></td>
                            <td><strong class="text-success">$<?= number_format($d['TotalDonated'], 2) ?></strong></td>
                            <td><?= $d['DonationCount'] ?></td>
                            <td class="text-muted small"><?= date('M j, Y', strtotime($d['LastDonation'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Per-campaign top donors ──────────────────────────── -->
    <?php if (!empty($perCampaign)):
        $grouped = [];
        foreach ($perCampaign as $row) $grouped[$row['CampID']][] = $row;
    ?>
    <div class="card">
        <div class="card-body">
            <h5 class="fw-bold mb-4">Top Donors by Campaign</h5>
            <?php foreach ($grouped as $campID => $rows): ?>
            <div class="mb-4">
                <p class="section-title mb-2"><?= htmlspecialchars($rows[0]['CampaignTitle']) ?></p>
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Rank</th><th>Donor</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $medals[(string)$r['RankInCampaign']] ?? '#'.$r['RankInCampaign'] ?></td>
                            <td><?= htmlspecialchars($r['DonorName']) ?></td>
                            <td class="fw-semibold text-success">$<?= number_format($r['Amt'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
