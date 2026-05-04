<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sign in required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$purpose = trim((string)($input['purpose'] ?? ''));
$challengeId = trim((string)($input['challenge_id'] ?? ''));
$code = preg_replace('/\D+/', '', (string)($input['code'] ?? ''));

if (!in_array($purpose, ['donation_auth', 'campaign_close_auth', 'role_appeal_auth'], true) || $challengeId === '' || strlen($code) !== 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid verification request']);
    exit;
}

$result = verifyEmailOtpChallenge((int)currentUser()['id'], $challengeId, $purpose, $code);
echo json_encode($result);
