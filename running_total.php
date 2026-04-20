<?php
$pageTitle = 'Progress';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

try {
    $stmt = $conn->query(
        "SELECT CampID, CampaignTitle, DonorName, Amt, Time, RunningTotal, RankInCampaign
         FROM vw_DonationRunningTotal
         ORDER BY CampID, Time"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows    = [];
    $dbError = $e->getMessage();
}

$campaigns = [];
foreach ($rows as $row) $campaigns[$row['CampID']][] = $row;

$totals = [];
foreach ($campaigns as $cid => $crows) {
    $last = end($crows);
    $totals[$cid] = $last['RunningTotal'];
}

try {
    $gStmt = $conn->query("SELECT CampID, GoalAmt FROM Campaigns WHERE Status = 'active'");
    $goals = [];
    foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) $goals[$g['CampID']] = $g['GoalAmt'];
} catch (PDOException $e) {
    $goals = [];
}

require_once 'includes/header.php';
?>

<div class="container py-5">

    <div class="mb-4">
        <h1 class="fw-bold mb-1">Donation Progress</h1>
        <p class="text-muted">
            Cumulative totals powered by SQL Window Functions
            <code>SUM() OVER (PARTITION BY CampID ORDER BY Time)</code>.
        </p>
    </div>

    <?php if (!empty($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php elseif (empty($campaigns)): ?>
    <div class="empty-state">
        <i class="bi bi-bar-chart"></i>
        <p class="mt-2">No donation data available yet.</p>
    </div>
    <?php else: ?>

    <?php foreach ($campaigns as $cid => $crows):
        $title  = htmlspecialchars($crows[0]['CampaignTitle']);
        $raised = $totals[$cid];
        $goal   = $goals[$cid] ?? null;
        $pct    = $goal ? min(($raised / $goal) * 100, 100) : 0;
    ?>
    <div class="card mb-4">
        <div class="card-body">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0"><?= $title ?></h5>
                    <?php if ($goal): ?>
                    <p class="text-muted small mb-0">
                        Goal: <strong>$<?= number_format($goal, 2) ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="fw-bold text-success fs-5">$<?= number_format($raised, 2) ?></div>
                    <div class="text-muted small">total raised</div>
                </div>
            </div>

            <!-- Overall progress -->
            <?php if ($goal): ?>
            <div class="mb-4">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span><?= number_format($pct, 1) ?>% funded</span>
                    <span>$<?= number_format($goal - $raised, 2) ?> to go</span>
                </div>
                <div class="progress" style="height:10px;">
                    <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Running total bar chart -->
            <p class="section-title mb-2">Running total per donation</p>
            <div class="mb-3">
                <?php foreach ($crows as $row):
                    $barPct = $raised > 0 ? ($row['RunningTotal'] / $raised) * 100 : 0;
                ?>
                <div class="d-flex align-items-center gap-2 mb-2" style="font-size:.86rem;">
                    <span class="text-muted text-truncate" style="min-width:90px;max-width:130px;">
                        <?= htmlspecialchars($row['DonorName']) ?>
                    </span>
                    <div class="flex-grow-1" style="background:#e9ecef;border-radius:4px;height:16px;position:relative;">
                        <div style="width:<?= number_format($barPct,1) ?>%;height:100%;background:var(--uf-green);border-radius:4px;opacity:.8;"></div>
                    </div>
                    <span class="fw-semibold text-success" style="min-width:80px;text-align:right;">
                        $<?= number_format($row['RunningTotal'], 2) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Detail table (collapsible) -->
            <details>
                <summary class="text-success" style="cursor:pointer;font-size:.9rem;user-select:none;">
                    Show donation detail
                </summary>
                <div class="table-responsive mt-2">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Donor</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Running Total</th>
                                <th>Rank</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($crows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['DonorName']) ?></td>
                                <td>$<?= number_format($row['Amt'], 2) ?></td>
                                <td class="text-muted small"><?= date('M j, Y g:i A', strtotime($row['Time'])) ?></td>
                                <td><strong class="text-success">$<?= number_format($row['RunningTotal'], 2) ?></strong></td>
                                <td>#<?= $row['RankInCampaign'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>

        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
