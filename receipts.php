<?php
$pageTitle = 'Receipts';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

requireLogin('receipts.php');

$userID  = (int)currentUser()['id'];
$isAdmin = isAdmin();

try {
    if ($isAdmin) {
        $stmt = $conn->query(
            "SELECT r.ID AS ReceiptID, r.DonID, u.Username AS DonorName,
                    c.Title AS CampaignTitle, d.Amt AS DonationAmt,
                    r.TaxAmount, r.IssuedAt
             FROM Receipts r
             JOIN Donations d ON r.DonID   = d.ID
             JOIN Campaigns c ON d.CampID  = c.CampID
             JOIN Users     u ON d.DonorID = u.UserID
             ORDER BY r.IssuedAt DESC"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT r.ID AS ReceiptID, r.DonID, c.Title AS CampaignTitle,
                    d.Amt AS DonationAmt, r.TaxAmount, r.IssuedAt
             FROM Receipts r
             JOIN Donations d ON r.DonID  = d.ID
             JOIN Campaigns c ON d.CampID = c.CampID
             WHERE d.DonorID = ?
             ORDER BY r.IssuedAt DESC"
        );
        $stmt->execute([$userID]);
    }
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $receipts = [];
    $dbError  = $e->getMessage();
}

try {
    $histStmt = $conn->prepare(
        "SELECT d.ID, c.Title AS CampaignTitle, d.Amt, d.Time,
                CASE WHEN r.ID IS NOT NULL THEN 1 ELSE 0 END AS HasReceipt
         FROM Donations d
         JOIN Campaigns c ON d.CampID = c.CampID
         LEFT JOIN Receipts r ON r.DonID = d.ID
         WHERE d.DonorID = ?
         ORDER BY d.Time DESC"
    );
    $histStmt->execute([$userID]);
    $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $history = [];
}

require_once 'includes/header.php';
?>

<div class="container py-5">

    <div class="mb-4">
        <h1 class="fw-bold mb-1"><?= $isAdmin ? 'All Receipts' : 'My Receipts' ?></h1>
        <p class="text-muted">Tax receipts are auto-generated for donations over $50 (10% deductible).</p>
    </div>

    <?php if (!empty($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- Tax receipts -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="fw-bold mb-3"><i class="bi bi-receipt me-2 text-success"></i>Tax Receipts</h5>
            <?php if (empty($receipts)): ?>
            <div class="empty-state py-4">
                <i class="bi bi-receipt"></i>
                <p class="mt-2 mb-0">No receipts yet. Receipts are auto-generated for donations over $50.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <?php if ($isAdmin): ?><th>Donor</th><?php endif; ?>
                            <th>Campaign</th>
                            <th>Donation</th>
                            <th>Tax-Deductible (10%)</th>
                            <th>Issued</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipts as $r): ?>
                        <tr>
                            <td class="fw-semibold">REC-<?= str_pad($r['ReceiptID'], 5, '0', STR_PAD_LEFT) ?></td>
                            <?php if ($isAdmin): ?>
                            <td><?= htmlspecialchars($r['DonorName']) ?></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($r['CampaignTitle']) ?></td>
                            <td><strong>$<?= number_format($r['DonationAmt'], 2) ?></strong></td>
                            <td class="text-success fw-semibold">$<?= number_format($r['TaxAmount'], 2) ?></td>
                            <td class="text-muted small"><?= date('M j, Y g:i A', strtotime($r['IssuedAt'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Donation history -->
    <?php if (!$isAdmin): ?>
    <div class="card">
        <div class="card-body">
            <h5 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-muted"></i>Donation History</h5>
            <?php if (empty($history)): ?>
            <div class="empty-state py-4">
                <i class="bi bi-heart"></i>
                <p class="mt-2">No donations yet.</p>
                <a href="donate.php" class="btn btn-success btn-sm">Make your first donation</a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Campaign</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $d): ?>
                        <tr>
                            <td class="text-muted small"><?= $d['ID'] ?></td>
                            <td><?= htmlspecialchars($d['CampaignTitle']) ?></td>
                            <td><strong>$<?= number_format($d['Amt'], 2) ?></strong></td>
                            <td class="text-muted small"><?= date('M j, Y', strtotime($d['Time'])) ?></td>
                            <td>
                                <?php if ($d['HasReceipt']): ?>
                                <span class="badge bg-success bg-opacity-10 text-success fw-semibold">
                                    <i class="bi bi-check-circle me-1"></i>Issued
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>
