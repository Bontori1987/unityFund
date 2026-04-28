<?php
$pageTitle = 'Transaction History';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

requireLogin('transactions.php');
$userID  = (int)currentUser()['id'];
$isAdmin = isAdmin();

try {
    if ($isAdmin) {
        $stmt = $conn->query(
            "SELECT t.TxID, t.UserID, t.CampID, t.Amt, t.Status, t.GatewayRef,
                    t.CreatedAt, t.ProcessedAt, t.FailReason,
                    c.Title AS CampaignTitle,
                    u.Username AS DonorName
             FROM Transactions t
             JOIN Campaigns c ON t.CampID = c.CampID
             JOIN Users     u ON t.UserID = u.UserID
             ORDER BY t.CreatedAt DESC"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT t.TxID, t.CampID, t.Amt, t.Status, t.GatewayRef,
                    t.CreatedAt, t.ProcessedAt, t.FailReason,
                    c.Title AS CampaignTitle
             FROM Transactions t
             JOIN Campaigns c ON t.CampID = c.CampID
             WHERE t.UserID = ?
             ORDER BY t.CreatedAt DESC"
        );
        $stmt->execute([$userID]);
    }
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    $dbError = $e->getMessage();
}

// Receipts: keyed by CampID_Amt for lookup
$receiptMap = [];
try {
    $rStmt = $isAdmin
        ? $conn->query(
            "SELECT r.ID AS ReceiptID, d.CampID, d.DonorID, d.Amt, r.TaxAmount
             FROM Receipts r JOIN Donations d ON r.DonID = d.ID")
        : $conn->prepare(
            "SELECT r.ID AS ReceiptID, d.CampID, d.Amt, r.TaxAmount
             FROM Receipts r JOIN Donations d ON r.DonID = d.ID WHERE d.DonorID = ?");
    if (!$isAdmin) $rStmt->execute([$userID]);
    foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = $isAdmin ? $row['DonorID'] : $userID;
        $key = $uid . '_' . $row['CampID'] . '_' . rtrim(rtrim(number_format((float)$row['Amt'], 2), '0'), '.');
        $receiptMap[$key] = $row;
    }
} catch (PDOException $e) {}

// Summary stats
$totalAmt  = 0;
$succeeded = 0;
$failed    = 0;
$pending   = 0;
foreach ($transactions as $t) {
    if ($t['Status'] === 'success') { $totalAmt += (float)$t['Amt']; $succeeded++; }
    elseif ($t['Status'] === 'failed') $failed++;
    else $pending++;
}

require_once 'includes/header.php';
?>

<div class="container py-5" style="max-width:960px;">

    <!-- Page header -->
    <div class="d-flex align-items-end justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <p class="text-muted small mb-1 text-uppercase fw-semibold" style="letter-spacing:.06em;">
                <?= $isAdmin ? 'Platform' : 'Your' ?> Transactions
            </p>
            <h1 class="fw-bold mb-0">Transaction History</h1>
        </div>
        <?php if (!$isAdmin): ?>
        <a href="donate.php" class="btn btn-success px-4 fw-semibold">
            <i class="bi bi-heart me-1"></i>Donate
        </a>
        <?php endif; ?>
    </div>

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="text-muted small mb-1">Total donated</div>
                <div class="fw-bold text-success" style="font-size:1.4rem;">$<?= number_format($totalAmt, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="text-muted small mb-1">Successful</div>
                <div class="fw-bold" style="font-size:1.4rem;"><?= $succeeded ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="text-muted small mb-1">Failed</div>
                <div class="fw-bold text-danger" style="font-size:1.4rem;"><?= $failed ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="text-muted small mb-1">Pending</div>
                <div class="fw-bold text-warning" style="font-size:1.4rem;"><?= $pending ?></div>
            </div>
        </div>
    </div>

    <!-- Filter pills -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="text-muted small fw-semibold me-1">Show:</span>
        <button class="btn btn-sm btn-success rounded-pill px-3 tx-filter active" data-filter="all">
            All <span class="badge bg-white text-success ms-1"><?= count($transactions) ?></span>
        </button>
        <?php if ($succeeded): ?>
        <button class="btn btn-sm btn-outline-success rounded-pill px-3 tx-filter" data-filter="success">
            Success <span class="badge bg-success ms-1"><?= $succeeded ?></span>
        </button>
        <?php endif; ?>
        <?php if ($failed): ?>
        <button class="btn btn-sm btn-outline-danger rounded-pill px-3 tx-filter" data-filter="failed">
            Failed <span class="badge bg-danger ms-1"><?= $failed ?></span>
        </button>
        <?php endif; ?>
        <?php if ($pending): ?>
        <button class="btn btn-sm btn-outline-warning rounded-pill px-3 tx-filter" data-filter="pending">
            Pending <span class="badge bg-warning text-dark ms-1"><?= $pending ?></span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Transactions table -->
    <?php if (empty($transactions)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-clock-history d-block text-muted" style="font-size:2.5rem;"></i>
            <p class="mt-3 mb-0 text-muted fw-semibold">No transactions yet</p>
            <p class="small text-muted">Your payment history will appear here.</p>
            <a href="donate.php" class="btn btn-success btn-sm mt-1">Make your first donation</a>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tx-table">
                <thead style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                    <tr>
                        <?php if ($isAdmin): ?>
                        <th class="ps-4" style="width:60px;">ID</th>
                        <th>Donor</th>
                        <?php else: ?>
                        <th class="ps-4" style="width:60px;">ID</th>
                        <?php endif; ?>
                        <th>Campaign</th>
                        <th>Amount</th>
                        <th>Date (GMT+7)</th>
                        <th>Status</th>
                        <th>Reference</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $t):
                    $statusCfg = [
                        'success' => ['bg-success',            '<i class="bi bi-check-circle-fill me-1"></i>Success'],
                        'failed'  => ['bg-danger',             '<i class="bi bi-x-circle-fill me-1"></i>Failed'],
                        'pending' => ['bg-warning text-dark',  '<i class="bi bi-hourglass me-1"></i>Pending'],
                    ][$t['Status']] ?? ['bg-secondary', ucfirst($t['Status'])];

                    $dt = new DateTime($t['ProcessedAt'] ?: $t['CreatedAt'], new DateTimeZone('Asia/Ho_Chi_Minh'));

                    // Receipt lookup
                    $uid       = $isAdmin ? $t['UserID'] : $userID;
                    $rKey      = $uid . '_' . $t['CampID'] . '_' . rtrim(rtrim(number_format((float)$t['Amt'], 2), '0'), '.');
                    $hasReceipt = isset($receiptMap[$rKey]) && $t['Status'] === 'succeeded';
                ?>
                <tr class="tx-row" data-status="<?= htmlspecialchars($t['Status']) ?>">
                    <td class="ps-4 text-muted small">#<?= $t['TxID'] ?></td>
                    <?php if ($isAdmin): ?>
                    <td class="small fw-semibold"><?= htmlspecialchars($t['DonorName'] ?? '—') ?></td>
                    <?php endif; ?>
                    <td>
                        <a href="partner/campaign/campaign-detail.php?id=<?= (int)$t['CampID'] ?>"
                           class="text-success text-decoration-none fw-semibold small">
                            <?= htmlspecialchars($t['CampaignTitle']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="fw-bold <?= $t['Status'] === 'succeeded' ? 'text-success' : 'text-muted' ?>">
                            $<?= number_format((float)$t['Amt'], 2) ?>
                        </span>
                    </td>
                    <td class="text-muted small" style="white-space:nowrap;">
                        <?= $dt->format('M j, Y') ?><br>
                        <span class="text-muted" style="font-size:.78rem;"><?= $dt->format('H:i:s') ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $statusCfg[0] ?>" style="font-size:.78rem;">
                            <?= $statusCfg[1] ?>
                        </span>
                        <?php if ($t['Status'] === 'failed' && $t['FailReason']): ?>
                        <div class="text-muted small mt-1" style="font-size:.73rem;max-width:160px;">
                            <?= htmlspecialchars($t['FailReason']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="text-muted" style="font-family:monospace;font-size:.78rem;word-break:break-all;">
                            <?= htmlspecialchars(substr($t['GatewayRef'], 0, 24)) ?>…
                        </span>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if ($t['Status'] === 'success'): ?>
                            <a href="payment_success.php?ref=<?= urlencode($t['GatewayRef']) ?>"
                               class="btn btn-sm btn-outline-success" title="View receipt">
                                <i class="bi bi-receipt"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($hasReceipt): ?>
                            <a href="receipts.php" class="btn btn-sm btn-outline-secondary" title="Tax receipt">
                                <i class="bi bi-file-earmark-text"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted small text-center mt-3">
        All times shown in GMT+7 (Indochina Time).
        Payments processed securely by <strong>Stripe</strong>.
    </p>

</div>

<script>
document.querySelectorAll('.tx-filter').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tx-filter').forEach(b => {
            b.classList.remove('active','btn-success','btn-danger','btn-warning');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary','btn-outline-success','btn-outline-danger','btn-outline-warning');
        this.classList.add('active', 'btn-' + (
            this.dataset.filter === 'success' ? 'success' :
            this.dataset.filter === 'failed'  ? 'danger'  :
            this.dataset.filter === 'pending' ? 'warning' : 'success'
        ));
        const f = this.dataset.filter;
        document.querySelectorAll('.tx-row').forEach(row => {
            row.style.display = (f === 'all' || row.dataset.status === f) ? '' : 'none';
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
