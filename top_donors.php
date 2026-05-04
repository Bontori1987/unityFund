<?php
$pageTitle = 'Top Donors';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

try {
    $stmt = $conn->query(
        "SELECT TOP 100 UserID, Username, TotalDonated, DonationCount, LastDonation, OverallRank
         FROM vw_TopDonors ORDER BY OverallRank"
    );
    $topDonors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $topDonors = [];
    $dbError   = $e->getMessage();
}

try {
    $stmt2 = $conn->query(
        "SELECT CampID, CampaignTitle, DonorID, DonorName, Amt, RankInCampaign
         FROM vw_DonationRunningTotal
         WHERE RankInCampaign <= 3
         ORDER BY CampID, RankInCampaign, Amt DESC, Time ASC"
    );
    $perCampaign = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $perCampaign = [];
}

$campaignImages = [];
try {
    $campaignImages = getCampaignDetailsMap(array_column($perCampaign, 'CampID'));
} catch (Exception $e) {
    $campaignImages = [];
}

$medals  = ['1' => '🥇', '2' => '🥈', '3' => '🥉'];
$podium  = array_slice($topDonors, 0, 3);
$rest    = array_slice($topDonors, 3);

require_once 'includes/header.php';
?>

<div class="container py-5">

    <div class="mb-5">
        <h1 class="fw-bold mb-1"><i class="bi bi-trophy text-warning me-2"></i>Top Supporters</h1>
        <p class="text-muted">Our most generous donors, ranked by total contributions across all campaigns.</p>
        <div class="small text-muted" id="leaderboardMeta">Updates automatically every 5 seconds.</div>
    </div>

    <?php if (!empty($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php elseif (empty($topDonors)): ?>
    <div class="empty-state">
        <i class="bi bi-trophy"></i>
        <p>No donations yet. Be the first to donate.</p>
        <a href="donate.php" class="btn btn-success mt-2">Donate now</a>
    </div>
    <?php else: ?>

    <?php if (!empty($podium)):
        $display = [];
        if (isset($podium[1])) $display[] = ['d' => $podium[1], 'r' => 2];
        if (isset($podium[0])) $display[] = ['d' => $podium[0], 'r' => 1];
        if (isset($podium[2])) $display[] = ['d' => $podium[2], 'r' => 3];
    ?>
    <div class="podium-wrap mb-5" id="podiumWrap">
        <?php foreach ($display as $item):
            $d = $item['d'];
            $r = $item['r'];
        ?>
        <div class="podium-item rank-<?= $r ?>">
            <div style="font-size:2.2rem;"><?= $medals[(string)$r] ?? '#' . $r ?></div>
            <div class="fw-bold mt-2">
                <a href="profile.php?id=<?= $d['UserID'] ?>" class="text-dark text-decoration-none">
                    <?= htmlspecialchars($d['Username']) ?>
                </a>
            </div>
            <div class="text-success fw-bold fs-5 mt-1">$<?= number_format($d['TotalDonated'], 2) ?></div>
            <div class="text-muted small mt-1">
                <?= $d['DonationCount'] ?> donation<?= $d['DonationCount'] != 1 ? 's' : '' ?>
            </div>
            <span class="badge mt-2 <?= $r === 1 ? 'bg-warning text-dark' : ($r === 2 ? 'bg-secondary' : 'bg-danger bg-opacity-75') ?>">
                Rank #<?= $r ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

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
                    <tbody id="fullLeaderboardBody">
                        <?php foreach ($rest as $d): ?>
                        <tr>
                            <td><strong>#<?= $d['OverallRank'] ?></strong></td>
                            <td>
                                <a href="profile.php?id=<?= $d['UserID'] ?>" class="text-success text-decoration-none fw-semibold">
                                    <?= htmlspecialchars($d['Username']) ?>
                                </a>
                            </td>
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

    <?php if (!empty($perCampaign)):
        $grouped = [];
        foreach ($perCampaign as $row) {
            $grouped[$row['CampID']][] = $row;
        }
    ?>
    <div class="card">
        <div class="card-body">
            <h5 class="fw-bold mb-4">Top Donors by Campaign</h5>
            <div id="perCampaignWrap">
                <?php foreach ($grouped as $campID => $rows): ?>
                <div class="mb-4">
                    <?php $thumb = $campaignImages[(int)$campID]['thumbnail'] ?? ''; ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <?php if ($thumb): ?>
                        <img src="<?= htmlspecialchars($thumb) ?>"
                             alt="<?= htmlspecialchars($rows[0]['CampaignTitle']) ?>"
                             style="width:52px;height:38px;object-fit:cover;border-radius:4px;">
                        <?php endif; ?>
                        <a href="partner/campaign/campaign-detail.php?id=<?= (int)$campID ?>"
                           class="section-title text-success text-decoration-none mb-0">
                            <?= htmlspecialchars($rows[0]['CampaignTitle']) ?>
                        </a>
                    </div>
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>Rank</th><th>Donor</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $medals[(string)$r['RankInCampaign']] ?? '#' . $r['RankInCampaign'] ?></td>
                                <td>
                                    <?php if ($r['DonorID']): ?>
                                        <a href="profile.php?id=<?= $r['DonorID'] ?>" class="text-success text-decoration-none fw-semibold">
                                            <?= htmlspecialchars($r['DonorName']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted"><?= htmlspecialchars($r['DonorName']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-success">$<?= number_format($r['Amt'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
const LEADERBOARD_MEDALS = {1: '🥇', 2: '🥈', 3: '🥉'};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatMoney(value) {
    return '$' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderPodium(overall) {
    const node = document.getElementById('podiumWrap');
    if (!node) return;
    const top = overall.slice(0, 3);
    const display = [];
    if (top[1]) display.push({ donor: top[1], rank: 2 });
    if (top[0]) display.push({ donor: top[0], rank: 1 });
    if (top[2]) display.push({ donor: top[2], rank: 3 });
    node.innerHTML = display.map(({ donor, rank }) => `
        <div class="podium-item rank-${rank}">
            <div style="font-size:2.2rem;">${LEADERBOARD_MEDALS[rank] || '#' + rank}</div>
            <div class="fw-bold mt-2"><a href="profile.php?id=${Number(donor.user_id)}" class="text-dark text-decoration-none">${escapeHtml(donor.username)}</a></div>
            <div class="text-success fw-bold fs-5 mt-1">${formatMoney(donor.total_donated)}</div>
            <div class="text-muted small mt-1">${Number(donor.donation_count)} donation${Number(donor.donation_count) !== 1 ? 's' : ''}</div>
            <span class="badge mt-2 ${rank === 1 ? 'bg-warning text-dark' : (rank === 2 ? 'bg-secondary' : 'bg-danger bg-opacity-75')}">Rank #${rank}</span>
        </div>
    `).join('');
}

function renderFullLeaderboard(overall) {
    const node = document.getElementById('fullLeaderboardBody');
    if (!node) return;
    node.innerHTML = overall.slice(3).map((donor) => `
        <tr>
            <td><strong>#${Number(donor.overall_rank)}</strong></td>
            <td><a href="profile.php?id=${Number(donor.user_id)}" class="text-success text-decoration-none fw-semibold">${escapeHtml(donor.username)}</a></td>
            <td><strong class="text-success">${formatMoney(donor.total_donated)}</strong></td>
            <td>${Number(donor.donation_count)}</td>
            <td class="text-muted small">${escapeHtml(new Date(donor.last_donation).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }))}</td>
        </tr>
    `).join('');
}

function renderPerCampaign(groups) {
    const node = document.getElementById('perCampaignWrap');
    if (!node) return;
    node.innerHTML = groups.map((group) => `
        <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                ${group.thumbnail ? `<img src="${escapeHtml(group.thumbnail)}" alt="${escapeHtml(group.campaign_title)}" style="width:52px;height:38px;object-fit:cover;border-radius:4px;">` : ''}
                <a href="partner/campaign/campaign-detail.php?id=${Number(group.camp_id)}" class="section-title text-success text-decoration-none mb-0">${escapeHtml(group.campaign_title)}</a>
            </div>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Rank</th><th>Donor</th><th>Amount</th></tr></thead>
                <tbody>
                    ${group.supporters.map((supporter) => `
                        <tr>
                            <td>${LEADERBOARD_MEDALS[Number(supporter.rank)] || '#' + Number(supporter.rank)}</td>
                            <td>${supporter.donor_id ? `<a href="profile.php?id=${Number(supporter.donor_id)}" class="text-success text-decoration-none fw-semibold">${escapeHtml(supporter.donor_name)}</a>` : `<span class="text-muted">${escapeHtml(supporter.donor_name)}</span>`}</td>
                            <td class="fw-semibold text-success">${formatMoney(supporter.amount)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `).join('');
}

async function refreshLeaderboard() {
    try {
        const data = await fetch('api/leaderboard_snapshot.php', { cache: 'no-store' }).then((r) => r.json());
        if (!data.success) return;
        renderPodium(data.overall || []);
        renderFullLeaderboard(data.overall || []);
        renderPerCampaign(data.per_campaign || []);
        const meta = document.getElementById('leaderboardMeta');
        if (meta) {
            meta.textContent = `Last synced ${new Date(data.generated_at).toLocaleTimeString()} - refreshes every 5 seconds.`;
        }
    } catch (e) {}
}

setInterval(refreshLeaderboard, 5000);
</script>
