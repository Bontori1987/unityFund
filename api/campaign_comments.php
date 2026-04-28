<?php
session_start();
header('Content-Type: application/json');

require_once '../db.php';
require_once '../includes/auth.php';
require_once '../includes/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in to comment.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$campId   = isset($input['camp_id']) ? (int)$input['camp_id'] : 0;
$body     = trim((string)($input['body'] ?? ''));
$parentId = trim((string)($input['parent_id'] ?? ''));
$parentId = $parentId === '' ? null : $parentId;

if ($campId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid campaign.']);
    exit;
}
if ($body === '') {
    echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
    exit;
}
if (strlen($body) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Comment must be 1000 characters or fewer.']);
    exit;
}

try {
    $chk = $conn->prepare('SELECT CampID FROM Campaigns WHERE CampID = ?');
    $chk->execute([$campId]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not verify campaign.']);
    exit;
}

$user = currentUser();
$commentId = addCampaignComment(
    $campId,
    (int)$user['id'],
    (string)$user['username'],
    $body,
    $parentId
);

if (!$commentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Could not save comment.']);
    exit;
}

echo json_encode([
    'success'    => true,
    'comment_id' => $commentId,
    'comment'    => [
        'id'         => $commentId,
        'camp_id'    => $campId,
        'user_id'    => (int)$user['id'],
        'username'   => (string)$user['username'],
        'body'       => $body,
        'parent_id'  => $parentId,
        'created_at' => date('M j, Y g:i A'),
    ],
]);
