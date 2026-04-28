<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/stripe.php';

if (!isLoggedIn() || !canDonate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true);
$intentId  = trim($input['intent_id']  ?? '');
$campId    = (int)($input['camp_id']   ?? 0);
$amount    = (float)($input['amount']  ?? 0);
$message   = trim($input['message']    ?? '');
$anonymous = (int)(bool)($input['anonymous'] ?? false);

if ($intentId === '' || $campId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$userId = (int)currentUser()['id'];

// ── CRITICAL: always verify status from Stripe, never trust the client ────────
$intent = stripeRetrieveIntent($intentId);

if (isset($intent['error'])) {
    echo json_encode(['success' => false, 'error' => 'Could not verify payment with Stripe']);
    exit;
}

$status    = $intent['status'] ?? 'unknown';
$succeeded = $status === 'succeeded';

// Verify the amount and metadata match what we expect (prevent tampering)
$expectedCents = (int)round($amount * 100);
if ((int)($intent['amount'] ?? 0) !== $expectedCents) {
    $succeeded = false;
    $status    = 'failed';
    error_log("Stripe amount mismatch: expected $expectedCents, got " . ($intent['amount'] ?? 'null'));
}

// Use GMT+7 (Vietnam time)
$now = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');

try {
    $conn->beginTransaction();

    // Update Transactions row to final status
    $conn->prepare(
        "UPDATE Transactions
         SET Status = ?, ProcessedAt = ?, FailReason = ?
         WHERE GatewayRef = ? AND UserID = ?"
    )->execute([
        $succeeded ? 'success' : 'failed',
        $now,
        $succeeded ? null : ('Stripe status: ' . $status),
        $intentId,
        $userId,
    ]);

    $receiptGenerated = false;

    if ($succeeded) {
        // Insert confirmed donation
        $conn->prepare(
            "INSERT INTO Donations (CampID, DonorID, Amt, Time, Message, IsAnonymous)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$campId, $userId, $amount, $now, $message ?: null, $anonymous]);

        // Check if trigger generated a receipt (Amt > 50)
        $receiptGenerated = $amount > 50;
    }

    $conn->commit();

    if ($succeeded) {
        echo json_encode([
            'success'          => true,
            'receipt_generated'=> $receiptGenerated,
            'intent_id'        => $intentId,
            'status'           => 'success',
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'status'  => $status,
            'error'   => 'Payment was not completed. Status: ' . $status,
        ]);
    }

} catch (PDOException $e) {
    try { $conn->rollBack(); } catch (Exception $rb) {}
    error_log('confirm_payment DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB: ' . $e->getMessage()]);
}
