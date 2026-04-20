<?php
$pageTitle = 'Donate';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

$loggedIn = isLoggedIn();
$preselect = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

// Active campaigns for dropdown
try {
    $stmt = $conn->query(
        "SELECT CampID, Title, GoalAmt FROM Campaigns WHERE Status = 'active' ORDER BY Title"
    );
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

require_once 'includes/header.php';
?>

<div class="container py-5" style="max-width:680px;">

    <div class="mb-4">
        <h1 class="fw-bold mb-1">Make a donation</h1>
        <p class="text-muted">Every dollar is tracked and goes directly to the campaign.</p>
    </div>

    <?php if (!$loggedIn): ?>
    <!-- Guest -->
    <div class="card text-center p-5">
        <div style="font-size:3rem;" class="mb-3">🔒</div>
        <h4 class="fw-bold mb-2">Sign in to donate</h4>
        <p class="text-muted mb-4">You need an account to make a donation.<br>Guests can browse campaigns and view the leaderboard.</p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="login.php?redirect=donate.php" class="btn btn-success px-4">Sign in</a>
            <a href="register.php" class="btn btn-outline-success px-4">Register</a>
        </div>
    </div>

    <?php elseif (!canDonate()): ?>
    <div class="alert alert-warning">Your account role does not have donation permissions.</div>

    <?php else: ?>
    <div class="card p-4">
        <form id="donationForm" novalidate>

            <!-- Campaign -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Campaign</label>
                <select id="campaign" name="camp_id" class="form-select" required>
                    <option value="">— Select a campaign —</option>
                    <?php foreach ($campaigns as $c): ?>
                    <option value="<?= $c['CampID'] ?>"
                        <?= $preselect === (int)$c['CampID'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['Title']) ?>
                    </option>
                    <?php endforeach; ?>
                    <?php if (empty($campaigns)): ?>
                    <option disabled>No active campaigns</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Progress (populated via JS) -->
            <div id="campaignProgress" class="mb-3" style="display:none;">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span id="prog-raised"></span>
                    <span id="prog-donors"></span>
                </div>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar bg-success" id="prog-bar" style="width:0%"></div>
                </div>
                <div class="small text-muted mt-1" id="prog-pct"></div>
            </div>

            <!-- Amount -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Amount (USD)</label>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach ([10, 25, 50, 100, 250] as $preset): ?>
                    <button type="button" class="amount-preset" data-amount="<?= $preset ?>">$<?= $preset ?></button>
                    <?php endforeach; ?>
                </div>
                <input type="number" id="amount" name="amount"
                       class="form-control" min="1" step="0.01"
                       placeholder="Or enter custom amount" required>
                <div id="receiptHint" class="form-text">
                    Donations over $50 automatically generate a tax receipt (10% deductible).
                </div>
            </div>

            <!-- Message -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Message <span class="text-muted fw-normal">(optional)</span>
                </label>
                <textarea id="message" name="message" class="form-control" rows="3"
                          placeholder="Leave a message of support…"></textarea>
            </div>

            <!-- Anonymous -->
            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" id="anonymous" name="anonymous">
                <label class="form-check-label text-muted small" for="anonymous">
                    Donate anonymously — won't appear on the public leaderboard
                </label>
            </div>

            <button type="submit" id="submitBtn" class="btn btn-success w-100 fw-semibold py-2">
                <i class="bi bi-heart me-1"></i>Donate now
            </button>
        </form>

        <div id="resultMsg" class="mt-3" style="display:none;"></div>
    </div>
    <?php endif; ?>

</div>

<?php if ($loggedIn && canDonate()): ?>
<script>
// Preset buttons
document.querySelectorAll('.amount-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('amount').value = btn.dataset.amount;
        document.querySelectorAll('.amount-preset').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('amount').dispatchEvent(new Event('input'));
    });
});

// Receipt hint
document.getElementById('amount').addEventListener('input', function () {
    const hint = document.getElementById('receiptHint');
    const over = parseFloat(this.value) > 50;
    hint.classList.toggle('text-success', over);
    hint.classList.toggle('fw-semibold', over);
    hint.textContent = over
        ? '✓ A tax receipt will be generated automatically (10% deductible).'
        : 'Donations over $50 automatically generate a tax receipt (10% deductible).';
});

// Campaign progress
document.getElementById('campaign').addEventListener('change', async function () {
    const id  = this.value;
    const box = document.getElementById('campaignProgress');
    if (!id) { box.style.display = 'none'; return; }
    try {
        const data = await fetch(`api/campaign_progress.php?camp_id=${encodeURIComponent(id)}`).then(r => r.json());
        if (data.success) {
            const pct = Math.min((data.raised / data.goal) * 100, 100);
            document.getElementById('prog-raised').textContent =
                `$${parseFloat(data.raised).toLocaleString(undefined,{minimumFractionDigits:2})} raised of $${parseFloat(data.goal).toLocaleString(undefined,{minimumFractionDigits:2})}`;
            document.getElementById('prog-donors').textContent =
                `${data.donor_count} donor${data.donor_count !== 1 ? 's' : ''}`;
            document.getElementById('prog-bar').style.width = pct.toFixed(1) + '%';
            document.getElementById('prog-pct').textContent = pct.toFixed(1) + '% funded';
            box.style.display = 'block';
        }
    } catch { box.style.display = 'none'; }
});

if (document.getElementById('campaign').value)
    document.getElementById('campaign').dispatchEvent(new Event('change'));

// Submit
document.getElementById('donationForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const campID  = document.getElementById('campaign').value;
    const amount  = parseFloat(document.getElementById('amount').value);
    const message = document.getElementById('message').value.trim();
    const anon    = document.getElementById('anonymous').checked ? 1 : 0;

    if (!campID)       { showResult('error', 'Please select a campaign.'); return; }
    if (!(amount > 0)) { showResult('error', 'Please enter a valid amount greater than $0.'); return; }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing…';

    try {
        const data = await fetch('api/process_donation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ camp_id: campID, amount, message, anonymous: anon })
        }).then(r => r.json());

        if (data.success) {
            let html = `<div class="alert alert-success">
                <h5 class="mb-1"><i class="bi bi-check-circle me-1"></i>Thank you!</h5>
                <p class="mb-1">You donated <strong>$${amount.toFixed(2)}</strong> successfully.</p>`;
            if (data.receipt_generated) {
                html += `<p class="mb-0 small">A tax receipt was generated.
                    <a href="receipts.php" class="alert-link">View receipts &rarr;</a></p>`;
            }
            html += `</div>`;
            showResult('raw', html);
            this.reset();
            document.querySelectorAll('.amount-preset').forEach(b => b.classList.remove('active'));
            document.getElementById('campaignProgress').style.display = 'none';
        } else {
            showResult('error', data.error || 'Donation failed. Please try again.');
        }
    } catch {
        showResult('error', 'Network error. Please try again.');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-heart me-1"></i>Donate now';
});

function showResult(type, msg) {
    const div = document.getElementById('resultMsg');
    div.style.display = 'block';
    div.innerHTML = type === 'raw' ? msg
        : `<div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i>${msg}</div>`;
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
