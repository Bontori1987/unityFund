<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';

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
$role   = $input['role'] ?? '';

$allowed = ['donor', 'organizer', 'pending_organizer', 'admin'];

if ($userID <= 0)               { echo json_encode(['success' => false, 'error' => 'Invalid user ID']); exit; }
if (!in_array($role, $allowed)) { echo json_encode(['success' => false, 'error' => 'Invalid role']); exit; }

try {
    $conn->prepare("UPDATE Users SET Role = ? WHERE UserID = ?")
         ->execute([$role, $userID]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
