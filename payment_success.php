<?php
$pageTitle = 'Payment Confirmed';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

requireLogin('payment_success.php');
$userID = (int)currentUser()['id'];

$ref = trim($_GET['ref'] ?? '');
$tx  = null;

if ($ref !== '') {
    try {
        $stmt = $conn->prepare(
            "SELECT t.TxID, t.CampID, t.Amt, t.Status, t.GatewayRef,
                    t.CreatedAt, t.ProcessedAt, t.FailReason,
                    c.Title AS CampaignTitle
             FROM Transactions t
             JOIN Campaigns c ON t.CampID = c.CampID
             WHERE t.GatewayRef = ? AND t.UserID = ?"
        );
        $stmt->execute([$ref, $userID]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Check if receipt was auto-generated (trigger fires when Amt > 50)
$receipt = null;
if ($tx && $tx['Status'] === 'succeeded') {
    try {
        $stmt = $conn->prepare(
            "SELECT r.ID AS ReceiptID, r.TaxAmount, r.IssuedAt
             FROM Receipts r
             JOIN Donations d ON r.DonID = d.ID
             WHERE d.DonorID = ? AND d.CampID = ? AND d.Amt = ?
             ORDER BY r.ID DESC"
        );
        $stmt->execute([$userID, $tx['CampID'], $tx['Amt']]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

require_once 'includes/header.php';

$succeeded = $tx && $tx['Status'] === 'success';
$failed    = $tx && $tx['Status'] === 'failed';
?>

<div class="container py-5" style="max-width:680px;">

<?php if (!$tx): ?>
    <!-- No transaction found -->
    <div class="card text-center p-5">
        <div style="font-size:3rem;color:#adb5bd;" class="mb-3"><i class="bi bi-receipt"></i></div>
        <h4 class="fw-bold mb-2">No transaction found</h4>
        <p class="text-muted mb-4">We couldn't locate a transaction matching that reference.</p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="transactions.php" class="btn btn-outline-secondary">Transaction history</a>
            <a href="donate.php" class="btn btn-success">Donate</a>
        </div>
    </div>

<?php elseif ($failed): ?>
    <!-- Failed transaction -->
    <div class="card border-danger overflow-hidden">
        <div style="background:#fef2f2;border-bottom:1px solid #fca5a5;" class="p-4 text-center">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                 style="width:64px;height:64px;background:#fee2e2;">
                <i class="bi bi-x-circle-fill text-danger" style="font-size:2rem;"></i>
            </div>
            <h3 class="fw-bold mb-1 text-danger">Payment Failed</h3>
            <p class="text-muted mb-0 small">The payment was not completed.</p>
        </div>
        <div class="p-4">
            <div class="receipt-row">
                <span class="receipt-label">Reference</span>
                <span class="receipt-value receipt-mono"><?= htmlspecialchars($tx['GatewayRef']) ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Amount</span>
                <span class="receipt-value fw-bold"><?= '$' . number_format((float)$tx['Amt'], 2) ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Campaign</span>
                <span class="receipt-value"><?= htmlspecialchars($tx['CampaignTitle']) ?></span>
            </div>
            <?php if ($tx['FailReason']): ?>
            <div class="receipt-row">
                <span class="receipt-label">Reason</span>
                <span class="receipt-value text-danger small"><?= htmlspecialchars($tx['FailReason']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="px-4 pb-4 d-flex gap-2">
            <a href="donate.php?camp_id=<?= (int)$tx['CampID'] ?>" class="btn btn-success fw-semibold flex-grow-1">
                <i class="bi bi-arrow-repeat me-1"></i>Try again
            </a>
            <a href="transactions.php" class="btn btn-outline-secondary">History</a>
        </div>
    </div>

<?php else: ?>
    <!-- Success -->

    <!-- Header card -->
    <div class="card overflow-hidden mb-3">
        <div class="success-receipt-header text-center py-4 px-3">
            <div class="check-circle mb-3">
                <i class="bi bi-check-lg"></i>
            </div>
            <h2 class="fw-bold text-white mb-1">Payment Confirmed</h2>
            <p class="mb-0" style="color:rgba(255,255,255,.75);font-size:.95rem;">
                Thank you for your donation to UnityFund.
            </p>
        </div>

        <!-- Reference badge -->
        <div class="px-4 pt-3 pb-2 text-center" style="background:#f8f9fa;border-bottom:1px solid #e9ecef;">
            <div class="text-muted small mb-1 text-uppercase fw-semibold" style="letter-spacing:.06em;">Transaction Reference</div>
            <div class="receipt-mono fw-bold" style="font-size:.9rem;color:#1a202c;word-break:break-all;">
                <?= htmlspecialchars($tx['GatewayRef']) ?>
            </div>
        </div>

        <!-- Core details grid -->
        <div class="p-4">
            <div class="row g-3 mb-3">
                <div class="col-sm-4 text-center text-sm-start">
                    <div class="text-muted small mb-1">Amount</div>
                    <div class="fw-bold text-success" style="font-size:1.75rem;line-height:1;">
                        $<?= number_format((float)$tx['Amt'], 2) ?>
                    </div>
                    <div class="text-muted small">USD</div>
                </div>
                <div class="col-sm-4 text-center">
                    <div class="text-muted small mb-1">Status</div>
                    <div>
                        <span class="badge bg-success px-3 py-2" style="font-size:.85rem;">
                            <i class="bi bi-check-circle me-1"></i>Succeeded
                        </span>
                    </div>
                </div>
                <div class="col-sm-4 text-center text-sm-end">
                    <div class="text-muted small mb-1">Date (GMT+7)</div>
                    <div class="fw-semibold small">
                        <?php
                        $processedAt = $tx['ProcessedAt'] ?: $tx['CreatedAt'];
                        $dt = new DateTime($processedAt, new DateTimeZone('Asia/Ho_Chi_Minh'));
                        echo $dt->format('M j, Y');
                        ?>
                    </div>
                    <div class="text-muted small">
                        <?= $dt->format('H:i:s T') ?>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="receipt-row">
                <span class="receipt-label">Campaign</span>
                <span class="receipt-value fw-semibold">
                    <a href="partner/campaign/campaign-detail.php?id=<?= (int)$tx['CampID'] ?>"
                       class="text-success text-decoration-none">
                        <?= htmlspecialchars($tx['CampaignTitle']) ?>
                    </a>
                </span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Payment method</span>
                <span class="receipt-value d-flex align-items-center gap-2">
                    <i class="bi bi-credit-card text-muted"></i> Card via Stripe
                </span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Transaction ID</span>
                <span class="receipt-value receipt-mono text-muted" style="font-size:.82rem;">
                    #<?= (int)$tx['TxID'] ?>
                </span>
            </div>

            <?php if ($receipt): ?>
            <div class="mt-3 p-3 rounded-3 d-flex align-items-start gap-3"
                 style="background:#f0fdf4;border:1px solid #bbf7d0;">
                <i class="bi bi-receipt-cutoff text-success mt-1" style="font-size:1.3rem;flex-shrink:0;"></i>
                <div>
                    <div class="fw-semibold text-success small">Tax Receipt Generated</div>
                    <div class="text-muted small">
                        Your full donation of <strong>$<?= number_format((float)$receipt['TaxAmount'], 2) ?></strong>
                        is tax-deductible. No amount was deducted from your donation.
                    </div>
                    <a href="receipts.php" class="small text-success fw-semibold text-decoration-none">
                        View receipt &rarr;
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="mt-3 p-3 rounded-3 text-muted small d-flex align-items-center gap-2"
                 style="background:#f8f9fa;border:1px solid #e9ecef;">
                <i class="bi bi-info-circle"></i>
                Donations over $50 qualify for a tax receipt.
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer actions -->
        <div class="px-4 pb-4 d-flex gap-2 flex-wrap">
            <a href="transactions.php"
               class="btn btn-outline-secondary flex-grow-1">
                <i class="bi bi-clock-history me-1"></i>Transaction History
            </a>
            <a href="donate.php"
               class="btn btn-success fw-semibold flex-grow-1">
                <i class="bi bi-heart me-1"></i>Donate Again
            </a>
        </div>
    </div>

    <!-- Print hint -->
    <p class="text-center text-muted small">
        <i class="bi bi-shield-check me-1 text-success"></i>
        Secured by Stripe &mdash;
        <a href="javascript:window.print()" class="text-muted">Print this page</a>
    </p>

<?php endif; ?>

</div>

<style>
.success-receipt-header {
    background: linear-gradient(135deg, #064e3b, #065f46 60%, #047857);
}
.check-circle {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(255,255,255,.15);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    color: #fff;
    border: 3px solid rgba(255,255,255,.3);
}
.receipt-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: .55rem 0;
    border-bottom: 1px solid #f1f5f9;
    gap: 1rem;
}
.receipt-row:last-child { border-bottom: 0; }
.receipt-label {
    color: #6b7280;
    font-size: .85rem;
    flex-shrink: 0;
    min-width: 130px;
}
.receipt-value {
    font-size: .9rem;
    text-align: right;
    word-break: break-all;
}
.receipt-mono { font-family: 'Courier New', monospace; }
@media print {
    .navbar, footer, .d-flex.gap-2.flex-wrap { display: none !important; }
}
</style>

<?php require_once 'includes/footer.php'; ?>
