<?php
session_start();
header('Content-Type: application/json');

require_once '../db.php';
require_once '../includes/time.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$donorID    = (int)$_SESSION['user_id'];
$campID     = isset($input['camp_id'])   ? (int)$input['camp_id']          : 0;
$amount     = isset($input['amount'])    ? round((float)$input['amount'], 2) : 0.0;
$message    = isset($input['message'])   ? trim($input['message'])          : '';
$anonymous  = isset($input['anonymous']) ? (int)(bool)$input['anonymous']   : 0;

// ── Validation ──
if ($campID <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid campaign.']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Donation amount must be greater than $0.']);
    exit;
}
if (strlen($message) > 500) {
    echo json_encode(['success' => false, 'error' => 'Message must be 500 characters or fewer.']);
    exit;
}

try {
    // Verify campaign exists and is active
    $chk = $conn->prepare(
        "SELECT CampID FROM Campaigns WHERE CampID = ? AND Status = 'active'"
    );
    $chk->execute([$campID]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found or no longer active.']);
        exit;
    }

    // Insert donation — the trigger fires automatically for Amt > 50
    $ins = $conn->prepare(
        "INSERT INTO Donations (CampID, DonorID, Amt, Time, Message, IsAnonymous)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $now = sqlNow();
    $ins->execute([$campID, $donorID, $amount, $now, $message ?: null, $anonymous]);

    // Get the new donation ID (T-SQL SCOPE_IDENTITY is safest with sqlsrv PDO)
    $idRow    = $conn->query("SELECT SCOPE_IDENTITY() AS id")->fetch(PDO::FETCH_ASSOC);
    $donationID = (int)$idRow['id'];

    // Check whether the trigger created a receipt
    $recChk = $conn->prepare("SELECT ID FROM Receipts WHERE DonID = ?");
    $recChk->execute([$donationID]);
    $receiptGenerated = (bool)$recChk->fetch();
    if ($receiptGenerated) {
        $conn->prepare("UPDATE Receipts SET IssuedAt = ? WHERE DonID = ?")
             ->execute([$now, $donationID]);
    }

    echo json_encode([
        'success'           => true,
        'donation_id'       => $donationID,
        'receipt_generated' => $receiptGenerated,
    ]);

} catch (PDOException $e) {
    error_log('Donation insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to process donation. Please try again.']);
}
