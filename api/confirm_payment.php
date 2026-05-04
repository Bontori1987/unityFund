<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/stripe.php';
require_once '../includes/mail.php';

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
$userName = (string)currentUser()['username'];

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
$metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
if ((int)($metadata['user_id'] ?? 0) !== $userId || (int)($metadata['camp_id'] ?? 0) !== $campId) {
    $succeeded = false;
    $status    = 'failed';
    error_log("Stripe metadata mismatch for intent $intentId");
}

// Use GMT+7 (Vietnam time)
$now = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');

try {
    $conn->beginTransaction();

    $txStmt = $conn->prepare(
        "SELECT TOP 1 t.TxID, t.CampID, t.Amt, t.Status, c.Title AS CampaignTitle
         FROM Transactions t
         JOIN Campaigns c ON c.CampID = t.CampID
         WHERE GatewayRef = ? AND UserID = ?
         ORDER BY TxID DESC"
    );
    $txStmt->execute([$intentId, $userId]);
    $txRow = $txStmt->fetch(PDO::FETCH_ASSOC);

    if (!$txRow) {
        throw new RuntimeException('Transaction row not found for this Stripe payment');
    }

    if ((int)$txRow['CampID'] !== $campId) {
        throw new RuntimeException('Transaction campaign mismatch');
    }

    if (abs((float)$txRow['Amt'] - $amount) > 0.00001) {
        throw new RuntimeException('Transaction amount mismatch');
    }

    $priorStatus = (string)($txRow['Status'] ?? '');
    $alreadySuccessful = $priorStatus === 'success';
    $alreadyFailed = $priorStatus === 'failed';

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
    $receiptEmailPayload = null;

    if ($succeeded && !$alreadySuccessful) {
        // Insert confirmed donation
        $conn->prepare(
            "INSERT INTO Donations (CampID, DonorID, Amt, Time, Message, IsAnonymous)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$campId, $userId, $amount, $now, $message ?: null, $anonymous]);

        // Check if trigger generated a receipt (Amt > 50)
        $receiptGenerated = $amount > 50;

        if ($receiptGenerated) {
            $receiptStmt = $conn->prepare(
                "SELECT TOP 1 r.ID AS ReceiptID, r.TaxAmount, r.IssuedAt
                 FROM Receipts r
                 JOIN Donations d ON d.ID = r.DonID
                 WHERE d.DonorID = ? AND d.CampID = ? AND d.Amt = ?
                 ORDER BY r.ID DESC"
            );
            $receiptStmt->execute([$userId, $campId, $amount]);
            $receiptRow = $receiptStmt->fetch(PDO::FETCH_ASSOC);
            if ($receiptRow) {
                $receiptEmailPayload = [
                    'receipt_id' => (string)$receiptRow['ReceiptID'],
                    'amount' => (float)$receiptRow['TaxAmount'],
                    'issued_at' => (string)$receiptRow['IssuedAt'],
                ];
            }
        }
    }

    $conn->commit();

    $mailContextStmt = $conn->prepare("SELECT Email, Username FROM Users WHERE UserID = ?");
    $mailContextStmt->execute([$userId]);
    $mailUser = $mailContextStmt->fetch(PDO::FETCH_ASSOC) ?: ['Email' => '', 'Username' => $userName];
    $toEmail = (string)($mailUser['Email'] ?? '');
    $toName = (string)($mailUser['Username'] ?? $userName);
    $campaignTitle = (string)($txRow['CampaignTitle'] ?? '');

    if ($succeeded) {
        if (!$alreadySuccessful && $toEmail !== '') {
            sendDonationStatusEmail($toEmail, $toName, $campaignTitle, $amount, $intentId, true);
            if ($receiptEmailPayload) {
                sendDonationReceiptEmail(
                    $toEmail,
                    $toName,
                    $campaignTitle,
                    (float)$receiptEmailPayload['amount'],
                    (string)$receiptEmailPayload['issued_at'],
                    (string)$receiptEmailPayload['receipt_id'],
                    $intentId
                );
            }
        }
        echo json_encode([
            'success'          => true,
            'receipt_generated'=> $receiptGenerated,
            'intent_id'        => $intentId,
            'status'           => 'success',
            'duplicate_ignored'=> $alreadySuccessful,
        ]);
    } else {
        if (!$alreadyFailed && $toEmail !== '') {
            sendDonationStatusEmail(
                $toEmail,
                $toName,
                $campaignTitle,
                $amount,
                $intentId,
                false,
                'Stripe status: ' . $status
            );
        }
        echo json_encode([
            'success' => false,
            'status'  => $status,
            'error'   => 'Payment was not completed. Status: ' . $status,
        ]);
    }

} catch (Throwable $e) {
    try { $conn->rollBack(); } catch (Exception $rb) {}
    error_log('confirm_payment DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB: ' . $e->getMessage()]);
}
