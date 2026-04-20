<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admins only']);
    exit;
}

$email = trim($_GET['email'] ?? '');
if ($email === '') {
    echo json_encode(['success' => false, 'error' => 'Email required']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT UserID, Username, Email, Role, IsAnonymous, CreatedAt
         FROM Users
         WHERE Email LIKE ?
         ORDER BY CreatedAt DESC"
    );
    $stmt->execute(['%' . $email . '%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'users' => $users]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed']);
}
