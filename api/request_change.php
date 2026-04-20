<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/mongo.php';
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admins only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$campId     = (int)($input['camp_id']     ?? 0);
$changeType = $input['change_type'] ?? '';
$message    = trim($input['message']    ?? '');

if ($campId <= 0 || !in_array($changeType, ['name', 'goal']) || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Fetch campaign title + organizer ID
try {
    $stmt = $conn->prepare("SELECT Title, HostID FROM Campaigns WHERE CampID = ?");
    $stmt->execute([$campId]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

if (!$camp || !$camp['HostID']) {
    echo json_encode(['success' => false, 'error' => 'Campaign not found']);
    exit;
}

$adminId = (int)currentUser()['id'];
$ok = sendChangeRequest(
    $adminId,
    (int)$camp['HostID'],
    $campId,
    $camp['Title'],
    $changeType,
    $message
);

echo json_encode(['success' => $ok, 'error' => $ok ? null : 'Failed to send — is MongoDB running?']);
