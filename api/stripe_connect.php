<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/mongo.php';
require_once '../includes/stripe.php';

if (!isLoggedIn() || (!isOrganizer() && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Organizer access required']);
    exit;
}

$userId = (int)currentUser()['id'];

// Fetch organizer email from SQL
try {
    $stmt = $conn->prepare("SELECT Email FROM Users WHERE UserID = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Check if already has a Stripe account
$stripe    = getStripeAccount($userId);
$accountId = $stripe['account_id'];

// If already onboarded, nothing to do
if ($stripe['onboarded'] && $accountId !== '') {
    echo json_encode(['success' => false, 'error' => 'Already connected']);
    exit;
}

// Create a new account if none exists
if ($accountId === '') {
    if (stripeIsTestMode()) {
        $account = stripeCreateFastTestConnectAccount(
            (string)$row['Email'],
            (string)(currentUser()['username'] ?? 'Test Organizer'),
            (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
        );
    } else {
        $account = stripeCreateConnectAccount($row['Email']);
    }
    if (isset($account['error'])) {
        echo json_encode(['success' => false, 'error' => $account['error']['message'] ?? 'Stripe error']);
        exit;
    }
    $accountId = $account['id'];
    saveStripeAccountId($userId, $accountId);
}

// In test mode, fast-track accounts can be treated as ready for demo routing
if (stripeIsTestMode() && $accountId !== '') {
    $verify = stripeRetrieveAccount($accountId);
    $sandboxFastTrack = (($verify['metadata']['unityfund_fasttrack'] ?? '') === '1')
        && (($verify['capabilities']['transfers'] ?? '') === 'active');

    if (
        ((!isset($verify['error'])) && ($verify['charges_enabled'] ?? false) && ($verify['payouts_enabled'] ?? false))
        || $sandboxFastTrack
    ) {
        markStripeOnboarded($userId);
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        echo json_encode([
            'success' => true,
            'url' => $baseUrl . '/my_campaigns.php?stripe_connected=1',
            'fast_track' => true,
        ]);
        exit;
    }
}

// Generate onboarding link
$baseUrl     = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$returnUrl   = $baseUrl . '/stripe_connect_return.php';
$refreshUrl  = $baseUrl . '/profile.php';

$link = stripeCreateAccountLink($accountId, $returnUrl, $refreshUrl);
if (isset($link['error'])) {
    echo json_encode(['success' => false, 'error' => $link['error']['message'] ?? 'Could not create onboarding link']);
    exit;
}

echo json_encode(['success' => true, 'url' => $link['url']]);
