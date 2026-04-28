<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$userID = (int)($input['user_id'] ?? 0);
$role   = $input['role']  ?? '';
$notes  = trim((string)($input['notes'] ?? ''));
$notify = (bool)($input['notify'] ?? false);

$allowed = ['donor', 'organizer', 'pending_organizer', 'admin'];

if ($userID <= 0)               { echo json_encode(['success' => false, 'error' => 'Invalid user ID']); exit; }
if (!in_array($role, $allowed)) { echo json_encode(['success' => false, 'error' => 'Invalid role']); exit; }

// Prevent admin from demoting themselves
if ($userID === (int)currentUser()['id']) {
    echo json_encode(['success' => false, 'error' => 'You cannot change your own role']);
    exit;
}

try {
    // Fetch current role before changing it
    $stmt = $conn->prepare("SELECT Role FROM Users WHERE UserID = ?");
    $stmt->execute([$userID]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) { echo json_encode(['success' => false, 'error' => 'User not found']); exit; }

    $oldRole = $target['Role'];

    $conn->prepare("UPDATE Users SET Role = ? WHERE UserID = ?")
         ->execute([$role, $userID]);

    // Update organizer application decision if applicable
    if ($role === 'organizer' || ($role === 'donor' && in_array($oldRole, ['organizer', 'pending_organizer']))) {
        $decision = $role === 'organizer' ? 'approved' : 'rejected';
        updateOrganizerApplicationDecision($userID, $decision, $notes, (int)currentUser()['id']);
    }

    // Send notification to the affected user
    if ($notify && $oldRole !== $role) {
        $reason = $notes ?: 'No reason provided';
        sendRoleChangeNotification((int)currentUser()['id'], $userID, $oldRole, $role, $reason);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Role update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
