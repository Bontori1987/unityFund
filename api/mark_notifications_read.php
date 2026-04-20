<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/mongo.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

$userId = (int)currentUser()['id'];
$input  = json_decode(file_get_contents('php://input'), true);
$id     = trim($input['id'] ?? '');

if ($id !== '') {
    markOneNotificationRead($id);
} else {
    markNotificationsRead($userId);
}

echo json_encode(['success' => true]);
