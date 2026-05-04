<?php
$pageTitle = 'Stripe Connect';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';
require_once 'includes/stripe.php';

requireLogin('stripe_connect_return.php');

$userId  = (int)currentUser()['id'];
$stripe  = getStripeAccount($userId);
$success = false;
$message = '';

if ($stripe['account_id'] !== '') {
    // Verify with Stripe that charges are actually enabled
    $account = stripeRetrieveAccount($stripe['account_id']);
    if (!isset($account['error']) && ($account['charges_enabled'] ?? false) && ($account['payouts_enabled'] ?? false)) {
        markStripeOnboarded($userId);
        $success = true;
        $message = 'Your Stripe account is connected and ready to receive donations.';
    } else {
        $message = 'Your Stripe account is connected but onboarding is not yet complete. Please finish all required steps.';
    }
} else {
    $message = 'No Stripe account found. Please try connecting again from your profile.';
}

require_once 'includes/header.php';
?>

<div class="container py-5" style="max-width:560px;">
    <div class="card border-0 shadow-sm text-center p-5">
        <?php if ($success): ?>
        <div class="mb-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                 style="width:72px;height:72px;background:#f0fdf4;">
                <i class="bi bi-check-circle-fill text-success" style="font-size:2.2rem;"></i>
            </div>
            <h3 class="fw-bold mb-2 text-success">Stripe Connected!</h3>
            <p class="text-muted"><?= htmlspecialchars($message) ?></p>
            <div class="mt-3 p-3 rounded-3 text-start" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                <div class="small text-success fw-semibold mb-1">
                    <i class="bi bi-info-circle me-1"></i>What this means
                </div>
                <ul class="small text-muted mb-0 ps-3">
                    <li>Donors who donate to your campaigns will transfer funds directly to your Stripe account.</li>
                    <li>UnityFund retains a 5% platform fee automatically.</li>
                    <li>You can view payouts in your <a href="https://dashboard.stripe.com" target="_blank" class="text-success">Stripe Dashboard</a>.</li>
                </ul>
            </div>
        </div>
        <?php else: ?>
        <div class="mb-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                 style="width:72px;height:72px;background:#fffbeb;">
                <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2.2rem;"></i>
            </div>
            <h3 class="fw-bold mb-2">Onboarding Incomplete</h3>
            <p class="text-muted"><?= htmlspecialchars($message) ?></p>
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a href="profile.php" class="btn btn-success fw-semibold px-4">
                <i class="bi bi-person me-1"></i>Go to Profile
            </a>
            <?php if (!$success): ?>
            <a href="profile.php" class="btn btn-outline-secondary px-4">
                <i class="bi bi-arrow-repeat me-1"></i>Try Again
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
