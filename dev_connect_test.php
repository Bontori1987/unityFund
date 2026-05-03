<?php
/**
 * DEV ONLY — links/onboards a Stripe connected account for an organizer.
 * Modes:
 *   ?user_id=6               → create new Express account + get onboarding link
 *   ?user_id=6&acct=acct_xxx → link existing account + get onboarding link
 *   ?user_id=6&link_only=1   → re-generate onboarding link for already-saved account
 * Delete this file before any real deployment.
 */
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';
require_once 'includes/stripe.php';

$userId   = (int)($_GET['user_id'] ?? 0);
$forceAcct = trim($_GET['acct'] ?? '');
$linkOnly  = !empty($_GET['link_only']);
if ($userId <= 0) die('Provide ?user_id=X');

$stmt = $conn->prepare("SELECT UserID, Username, Email, Role FROM Users WHERE UserID = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User $userId not found");

echo "<pre style='font-family:monospace;padding:20px;font-size:14px;'>";
echo "User: {$user['Username']} ({$user['Email']}) — Role: {$user['Role']}\n\n";

$existing = getStripeAccount($userId);
if ($existing['account_id'] !== '') {
    echo "Current saved account: {$existing['account_id']}\n";
    echo "Onboarded flag: " . ($existing['onboarded'] ? 'YES' : 'NO') . "\n\n";
}

// Determine account ID to use
if ($linkOnly) {
    if ($existing['account_id'] === '') die("No account saved for user $userId. Run without link_only first.\n");
    $accountId = $existing['account_id'];
    echo "Re-generating onboarding link for: $accountId\n\n";
} elseif ($forceAcct !== '') {
    $accountId = $forceAcct;
    echo "Linking existing Stripe account: $accountId\n";
    saveStripeAccountId($userId, $accountId);
    echo "Saved to MongoDB.\n\n";
} else {
    echo "Creating new Express connected account...\n";
    $account = stripeCreateConnectAccount($user['Email']);
    if (isset($account['error'])) {
        echo "ERROR: " . $account['error']['message'] . "\n";
        exit;
    }
    $accountId = $account['id'];
    echo "Created: $accountId\n";
    saveStripeAccountId($userId, $accountId);
    echo "Saved to MongoDB.\n\n";
}

// Check current Stripe status
$verify = stripeRetrieveAccount($accountId);
echo "--- Stripe Account Status ---\n";
echo "charges_enabled:  " . ($verify['charges_enabled'] ?? false ? 'true' : 'false') . "\n";
echo "payouts_enabled:  " . ($verify['payouts_enabled'] ?? false ? 'true' : 'false') . "\n";
echo "details_submitted: " . ($verify['details_submitted'] ?? false ? 'true' : 'false') . "\n";

if (!empty($verify['requirements']['currently_due'])) {
    echo "\nRequirements still due:\n";
    foreach ($verify['requirements']['currently_due'] as $req) {
        echo "  - $req\n";
    }
}

echo "\n";

if ($verify['charges_enabled'] ?? false) {
    // Already fully onboarded — just mark it in MongoDB
    markStripeOnboarded($userId);
    echo "charges_enabled = true → marked as onboarded in MongoDB.\n";
    echo "Done! User {$user['Username']} is fully connected.\n";
} else {
    // Generate onboarding link so the organizer can complete setup on Stripe's hosted UI
    $baseUrl    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $returnUrl  = $baseUrl . '/unityfund/stripe_connect_return.php';
    $refreshUrl = $baseUrl . '/unityfund/dev_connect_test.php?user_id=' . $userId . '&link_only=1';

    $link = stripeCreateAccountLink($accountId, $returnUrl, $refreshUrl);
    if (isset($link['error'])) {
        echo "ERROR generating link: " . $link['error']['message'] . "\n";
        exit;
    }

    echo "Onboarding not complete. Open this link to finish setup:\n\n";
    echo $link['url'] . "\n\n";
    echo "In Stripe test mode: click through the form and use the 'Skip this form' button\n";
    echo "when available, or fill with any test data. After finishing, you'll be redirected\n";
    echo "to stripe_connect_return.php which will mark the account as onboarded.\n";
    echo "</pre>";
    echo "<p><strong>Click to open Stripe onboarding:</strong><br>";
    echo "<a href='" . htmlspecialchars($link['url']) . "' class='btn btn-success mt-2' style='font-family:sans-serif;padding:10px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;display:inline-block;'>Complete Stripe Onboarding &rarr;</a></p>";
    exit;
}

echo "</pre>";
echo "<p><a href='profile.php' style='font-family:sans-serif;'>Go to Profile</a> | <a href='admin.php#users' style='font-family:sans-serif;'>Admin Dashboard</a></p>";
