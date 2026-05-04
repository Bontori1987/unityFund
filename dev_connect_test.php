<?php
/**
 * DEV ONLY - links/onboards a Stripe connected account for an organizer.
 * Modes:
 *   ?user_id=6               -> create new Express account + get onboarding link
 *   ?user_id=6&fast=1        -> create fresh sandbox Custom account with test KYC + payout details
 *   ?user_id=6&acct=acct_xxx -> link existing account + get onboarding link
 *   ?user_id=6&link_only=1   -> re-generate onboarding link for already-saved account
 * Delete this file before any real deployment.
 */
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';
require_once 'includes/stripe.php';

$userId    = (int)($_GET['user_id'] ?? 0);
$forceAcct = trim($_GET['acct'] ?? '');
$linkOnly  = !empty($_GET['link_only']);
$fastTrack = !empty($_GET['fast']);
if ($userId <= 0) die('Provide ?user_id=X');

$stmt = $conn->prepare("SELECT UserID, Username, Email, Role FROM Users WHERE UserID = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User $userId not found");

echo "<pre style='font-family:monospace;padding:20px;font-size:14px;'>";
echo "User: {$user['Username']} ({$user['Email']}) - Role: {$user['Role']}\n\n";

$existing = getStripeAccount($userId);
if ($existing['account_id'] !== '') {
    echo "Current saved account: {$existing['account_id']}\n";
    echo "Onboarded flag: " . ($existing['onboarded'] ? 'YES' : 'NO') . "\n\n";
}

if ($fastTrack) {
    if (!stripeIsTestMode()) {
        die('Fast-track mode only works with Stripe test keys.');
    }

    echo "Creating fresh sandbox Custom connected account with prefilled test values...\n";
    $account = stripeCreateFastTestConnectAccount(
        (string)$user['Email'],
        (string)$user['Username'],
        (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
    );
    if (isset($account['error'])) {
        echo "ERROR: " . ($account['error']['message'] ?? 'Stripe error') . "\n";
        if (!empty($account['error']['param'])) {
            echo "FIELD: " . $account['error']['param'] . "\n";
        }
        exit;
    }
    $accountId = $account['id'];
    echo "Created: $accountId\n";
    saveStripeAccountId($userId, $accountId);
    echo "Saved to MongoDB.\n";
    echo "Polling Stripe for capability activation...\n\n";
} elseif ($linkOnly) {
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
        echo "ERROR: " . ($account['error']['message'] ?? 'Stripe error') . "\n";
        if (!empty($account['error']['param'])) {
            echo "FIELD: " . $account['error']['param'] . "\n";
        }
        exit;
    }
    $accountId = $account['id'];
    echo "Created: $accountId\n";
    saveStripeAccountId($userId, $accountId);
    echo "Saved to MongoDB.\n\n";
}

if ($fastTrack) {
    $verify = [];
    for ($i = 0; $i < 10; $i++) {
        $verify = stripeRetrieveAccount($accountId);
        if (($verify['charges_enabled'] ?? false) && ($verify['payouts_enabled'] ?? false)) {
            break;
        }
        usleep(1500000);
    }
} else {
    $verify = stripeRetrieveAccount($accountId);
}

echo "--- Stripe Account Status ---\n";
echo "charges_enabled:  " . (($verify['charges_enabled'] ?? false) ? 'true' : 'false') . "\n";
echo "payouts_enabled:  " . (($verify['payouts_enabled'] ?? false) ? 'true' : 'false') . "\n";
echo "details_submitted: " . (($verify['details_submitted'] ?? false) ? 'true' : 'false') . "\n";

if (!empty($verify['requirements']['currently_due'])) {
    echo "\nRequirements still due:\n";
    foreach ($verify['requirements']['currently_due'] as $req) {
        echo "  - $req\n";
    }
}

echo "\n";

if (
    (($verify['charges_enabled'] ?? false) && ($verify['payouts_enabled'] ?? false))
    || (
        $fastTrack
        && stripeIsTestMode()
        && (($verify['metadata']['unityfund_fasttrack'] ?? '') === '1')
        && (($verify['capabilities']['transfers'] ?? '') === 'active')
    )
) {
    markStripeOnboarded($userId);
    if (($verify['charges_enabled'] ?? false) && ($verify['payouts_enabled'] ?? false)) {
        echo "charges_enabled and payouts_enabled are true -> marked as onboarded in MongoDB.\n";
    } else {
        echo "Sandbox fast-track accepted for demo routing -> marked as onboarded in MongoDB.\n";
    }
    echo "Done! User {$user['Username']} is fully connected.\n";
} else {
    if ($fastTrack) {
        echo "Fast-track account still has outstanding Stripe requirements.\n";
        echo "Falling back to hosted onboarding for any remaining fields.\n\n";
    }

    $baseUrl    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $returnUrl  = $baseUrl . '/unityfund/stripe_connect_return.php';
    $refreshUrl = $baseUrl . '/unityfund/dev_connect_test.php?user_id=' . $userId . '&link_only=1';

    $link = stripeCreateAccountLink($accountId, $returnUrl, $refreshUrl);
    if (isset($link['error'])) {
        echo "ERROR generating link: " . ($link['error']['message'] ?? 'Stripe error') . "\n";
        exit;
    }

    echo "Onboarding not complete. Open this link to finish setup:\n\n";
    echo $link['url'] . "\n\n";
    echo "In Stripe test mode, click through the remaining form with Stripe test data.\n";
    echo "After finishing, Stripe will redirect to stripe_connect_return.php.\n";
    echo "</pre>";
    echo "<p><strong>Click to open Stripe onboarding:</strong><br>";
    echo "<a href='" . htmlspecialchars($link['url']) . "' class='btn btn-success mt-2' style='font-family:sans-serif;padding:10px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;display:inline-block;'>Complete Stripe Onboarding &rarr;</a></p>";
    exit;
}

echo "</pre>";
echo "<p><a href='profile.php' style='font-family:sans-serif;'>Go to Profile</a> | <a href='admin.php#users' style='font-family:sans-serif;'>Admin Dashboard</a></p>";
