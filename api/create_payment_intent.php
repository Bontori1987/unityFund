<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/stripe.php';
require_once '../includes/mongo.php';

if (!isLoggedIn() || !canDonate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$input          = json_decode(file_get_contents('php://input'), true);
$campId         = (int)($input['camp_id'] ?? 0);
$amount         = (float)($input['amount'] ?? 0);
$idempotencyKey = trim($input['idempotency_key'] ?? '');

if ($campId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid campaign or amount']);
    exit;
}

// Verify campaign is active and fetch organizer
try {
    $stmt = $conn->prepare(
        "SELECT c.CampID, c.Title, c.HostID
         FROM Campaigns c
         WHERE c.CampID = ? AND c.Status = 'active'"
    );
    $stmt->execute([$campId]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

if (!$camp) {
    echo json_encode(['success' => false, 'error' => 'Campaign not found or not active']);
    exit;
}

$userId      = (int)currentUser()['id'];
$amountCents = (int)round($amount * 100);

// Check if the organizer has a connected Stripe account with transfers enabled
$hostStripe    = getStripeAccount((int)$camp['HostID']);
$connectedAcct = null;
if ($hostStripe['onboarded'] && $hostStripe['account_id'] !== '') {
    // Verify transfers capability is actually active before routing
    $acctStatus = stripeRetrieveAccount($hostStripe['account_id']);
    if (!empty($acctStatus['charges_enabled'])) {
        $connectedAcct = $hostStripe['account_id'];
    }
}

$metadata = [
    'user_id'  => $userId,
    'camp_id'  => $campId,
    'campaign' => $camp['Title'],
];

// Route to organizer's account if connected, otherwise platform account
if ($connectedAcct) {
    $intent = stripeCreateConnectIntent($amountCents, $connectedAcct, $metadata, $idempotencyKey);
} else {
    $intent = stripeCreateIntent($amountCents, $metadata, $idempotencyKey);
}

if (isset($intent['error'])) {
    echo json_encode(['success' => false, 'error' => $intent['error']['message'] ?? 'Stripe error']);
    exit;
}

// Record a pending transaction row immediately
try {
    $conn->prepare(
        "INSERT INTO Transactions (UserID, CampID, Amt, Status, GatewayRef)
         VALUES (?, ?, ?, 'pending', ?)"
    )->execute([$userId, $campId, $amount, $intent['id']]);
} catch (PDOException $e) {
    // Non-fatal — intent is created, log and continue
    error_log('Transactions insert failed: ' . $e->getMessage());
}

echo json_encode([
    'success'        => true,
    'client_secret'  => $intent['client_secret'],
    'intent_id'      => $intent['id'],
    'routed_to_org'  => $connectedAcct !== null,
]);
