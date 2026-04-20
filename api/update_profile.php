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

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

$userId = (int)currentUser()['id'];

// Validate website URL if provided
if (!empty($input['website'])) {
    $url = trim($input['website']);
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = 'https://' . $url;
    }
    $input['website'] = $url;
}

// Handle anonymous toggle — save to MS SQL Users table
if (array_key_exists('is_anonymous', $input)) {
    $anon = $input['is_anonymous'] ? 1 : 0;
    try {
        $conn->prepare("UPDATE Users SET IsAnonymous = ? WHERE UserID = ?")
             ->execute([$anon, $userId]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update anonymous setting.']);
        exit;
    }
    unset($input['is_anonymous']); // don't pass to MongoDB
}

// Save remaining profile fields to MongoDB
$ok = saveProfile($userId, $input);

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save profile. Is MongoDB running?']);
}
