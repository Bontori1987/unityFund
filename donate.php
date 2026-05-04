<?php
$pageTitle = 'Donate';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

$loggedIn = isLoggedIn();
$preselect = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
$userEmail = '';

// Active campaigns for dropdown
try {
    $stmt = $conn->query(
        "SELECT CampID, Title, GoalAmt FROM Campaigns WHERE Status = 'active' ORDER BY Title"
    );
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

if ($loggedIn) {
    try {
        $userStmt = $conn->prepare("SELECT Email FROM Users WHERE UserID = ?");
        $userStmt->execute([(int)currentUser()['id']]);
        $userEmail = (string)($userStmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $userEmail = '';
    }
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
                <div class="d-flex align-items-center gap-3 mb-2">
                    <img id="campaignThumb" src="" alt=""
                         style="width:74px;height:54px;object-fit:cover;border-radius:6px;display:none;">
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span id="prog-raised"></span>
                            <span id="prog-donors"></span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-success" id="prog-bar" style="width:0%"></div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <div class="small text-muted" id="prog-pct"></div>
                    <a href="#" id="campaignLink" class="small text-success text-decoration-none fw-semibold">
                        View campaign
                    </a>
                </div>
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
                    Donations over $50 automatically generate a tax receipt for your records.
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

            <div class="mb-4 p-3 rounded" style="border:1px solid #d8e6f7;background:#f8fbff;">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <div class="fw-semibold mb-1">Gmail verification before payment</div>
                        <div class="text-muted small">A one-time code is required before the card is charged.</div>
                        <div class="small mt-2">
                            <span class="text-muted">Account Gmail:</span>
                            <span class="fw-semibold"><?= htmlspecialchars($userEmail) ?></span>
                        </div>
                    </div>
                    <button type="button" id="sendDonationOtpBtn" class="btn btn-outline-success btn-sm fw-semibold">Send code</button>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-sm-7">
                        <input type="text" id="donationOtpCode" class="form-control" maxlength="6" inputmode="numeric"
                               placeholder="Enter the 6-digit code from Gmail">
                    </div>
                    <div class="col-sm-5 d-grid">
                        <button type="button" id="verifyDonationOtpBtn" class="btn btn-success fw-semibold">Verify code</button>
                    </div>
                </div>
                <div id="donationOtpMsg" class="small mt-2 text-muted"></div>
                <input type="hidden" id="donationOtpChallengeId" value="">
            </div>

            <!-- Card details (Stripe Elements) -->
            <div class="mb-4">
                <label class="form-label fw-semibold">Card details</label>
                <div id="card-element" class="form-control py-2" style="height:auto;min-height:42px;"></div>
                <div id="card-errors" class="text-danger small mt-1" role="alert"></div>
            </div>

            <button type="submit" id="submitBtn" class="btn btn-success w-100 fw-semibold py-2">
                <i class="bi bi-lock me-1"></i>Pay securely with Stripe
            </button>

            <p class="text-center text-muted small mt-2 mb-0">
                <i class="bi bi-shield-lock me-1"></i>
                Card details never touch our server — processed by Stripe
            </p>
        </form>

        <div id="resultMsg" class="mt-3" style="display:none;"></div>
    </div>
    <?php endif; ?>

</div>

<?php if ($loggedIn && canDonate()):
    require_once 'includes/stripe.php';
?>
<script src="https://js.stripe.com/v3/"></script>
<script>
const STRIPE_PK = <?= json_encode(STRIPE_PUBLISHABLE_KEY) ?>;
</script>
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
        ? '✓ A tax receipt will be generated automatically for your records.'
        : 'Donations over $50 automatically generate a tax receipt for your records.';
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
            document.getElementById('campaignLink').href = `partner/campaign/campaign-detail.php?id=${encodeURIComponent(id)}`;
            const thumb = document.getElementById('campaignThumb');
            if (data.thumbnail) {
                thumb.src = data.thumbnail;
                thumb.alt = data.title || 'Campaign image';
                thumb.style.display = '';
            } else {
                thumb.removeAttribute('src');
                thumb.alt = '';
                thumb.style.display = 'none';
            }
            box.style.display = 'block';
        }
    } catch { box.style.display = 'none'; }
});

if (document.getElementById('campaign').value)
    document.getElementById('campaign').dispatchEvent(new Event('change'));

// ── Stripe setup ─────────────────────────────────────────────────────────────
const stripe      = Stripe(STRIPE_PK);
const elements    = stripe.elements();
const cardElement = elements.create('card', {
    style: {
        base:    { fontFamily: 'Inter, sans-serif', fontSize: '15px', color: '#212529' },
        invalid: { color: '#dc3545' }
    }
});
cardElement.mount('#card-element');
cardElement.on('change', e => {
    document.getElementById('card-errors').textContent = e.error ? e.error.message : '';
});

let donationOtpVerified = false;
const donationOtpMsg = document.getElementById('donationOtpMsg');
const donationOtpSendBtn = document.getElementById('sendDonationOtpBtn');
const donationOtpVerifyBtn = document.getElementById('verifyDonationOtpBtn');
const donationOtpCodeInput = document.getElementById('donationOtpCode');
const donationOtpChallengeInput = document.getElementById('donationOtpChallengeId');
const donationUserEmail = <?= json_encode($userEmail) ?>;

function setOtpMessage(text, kind = 'muted') {
    donationOtpMsg.textContent = text;
    donationOtpMsg.className = `small mt-2 text-${kind}`;
}

if (!/@gmail\.com$/i.test(donationUserEmail)) {
    donationOtpSendBtn.disabled = true;
    donationOtpVerifyBtn.disabled = true;
    setOtpMessage('This account must use Gmail to pass donation verification.', 'danger');
}

donationOtpSendBtn?.addEventListener('click', async () => {
    donationOtpVerified = false;
    donationOtpSendBtn.disabled = true;
    donationOtpSendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending';
    setOtpMessage('Sending verification code to Gmail...');
    try {
        const data = await fetch('api/send_email_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ purpose: 'donation_auth' })
        }).then(r => r.json());
        if (!data.success) {
            setOtpMessage(data.error || 'Could not send code.', 'danger');
            return;
        }
        donationOtpChallengeInput.value = data.challenge_id;
        setOtpMessage('Verification code sent. Check Gmail and enter the code here.', 'success');
    } catch {
        setOtpMessage('Network error while sending code.', 'danger');
    } finally {
        donationOtpSendBtn.disabled = false;
        donationOtpSendBtn.textContent = 'Send code';
    }
});

donationOtpVerifyBtn?.addEventListener('click', async () => {
    const code = donationOtpCodeInput.value.trim();
    const challengeId = donationOtpChallengeInput.value;
    if (!challengeId) {
        setOtpMessage('Send a verification code first.', 'warning');
        return;
    }
    if (!/^\d{6}$/.test(code)) {
        setOtpMessage('Enter the 6-digit code exactly as sent.', 'warning');
        return;
    }
    donationOtpVerifyBtn.disabled = true;
    donationOtpVerifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying';
    try {
        const data = await fetch('api/verify_email_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ purpose: 'donation_auth', challenge_id: challengeId, code })
        }).then(r => r.json());
        if (!data.success) {
            donationOtpVerified = false;
            setOtpMessage(data.error || 'Verification failed.', 'danger');
            return;
        }
        donationOtpVerified = true;
        setOtpMessage('Gmail verified. You can now complete the donation.', 'success');
    } catch {
        donationOtpVerified = false;
        setOtpMessage('Network error while verifying code.', 'danger');
    } finally {
        donationOtpVerifyBtn.disabled = false;
        donationOtpVerifyBtn.textContent = 'Verify code';
    }
});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('donationForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const campID  = document.getElementById('campaign').value;
    const amount  = parseFloat(document.getElementById('amount').value);
    const message = document.getElementById('message').value.trim();
    const anon    = document.getElementById('anonymous').checked ? 1 : 0;

    if (!campID)       { showResult('error', 'Please select a campaign.'); return; }
    if (!(amount > 0)) { showResult('error', 'Please enter a valid amount greater than $0.'); return; }
    if (!stripe)       { showResult('error', 'Payment system not loaded. Please refresh.'); return; }
    if (!donationOtpVerified || !donationOtpChallengeInput.value) {
        showResult('error', 'Verify the Gmail OTP before donating.');
        return;
    }

    const btn = document.getElementById('submitBtn');
    // Disable immediately — prevents multiple PaymentIntents on repeated clicks
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating payment…';

    try {
        // Step 1: Server creates PaymentIntent → get client_secret
        // idempotency_key prevents duplicate intents if request is retried
        const idempotencyKey = `donate-${campID}-${amount}-${Date.now()}`;
        const intentData = await fetch('api/create_payment_intent.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                camp_id: campID,
                amount,
                idempotency_key: idempotencyKey,
                otp_challenge_id: donationOtpChallengeInput.value
            })
        }).then(r => r.json());

        if (!intentData.success) {
            showResult('error', intentData.error || 'Could not initiate payment.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lock me-1"></i>Pay securely with Stripe';
            return;
        }
        donationOtpVerified = false;

        // Step 2: Stripe.js confirms card with client_secret (card never hits our server)
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing card…';
        const { paymentIntent, error } = await stripe.confirmCardPayment(
            intentData.client_secret,
            { payment_method: { card: cardElement } }
        );

        if (error) {
            showResult('error', error.message || 'Card was declined.');
            // Step 3a: Tell server it failed so Transaction row is updated
            await fetch('api/confirm_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ intent_id: intentData.intent_id, camp_id: campID, amount, failed: true })
            });
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lock me-1"></i>Pay securely with Stripe';
            return;
        }

        // Step 3b: Server verifies PaymentIntent status directly with Stripe API
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying…';
        const confirm = await fetch('api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                intent_id: paymentIntent.id,
                camp_id:   campID,
                amount,
                message,
                anonymous: anon
            })
        }).then(r => r.json());

        if (confirm.success) {
            // Redirect to banking-style receipt page
            window.location.href = 'payment_success.php?ref=' + encodeURIComponent(paymentIntent.id);
            return;
        } else {
            showResult('error', confirm.error || 'Payment could not be verified. Contact support with ref: ' + paymentIntent.id);
        }
    } catch (err) {
        showResult('error', 'Network error. Please try again.');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-lock me-1"></i>Pay securely with Stripe';
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
