<?php
header('Content-Type: application/json');

require_once '../db.php';
require_once '../includes/mongo.php';

$campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
if ($campId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid campaign']);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT CampID FROM Campaigns WHERE CampID = ?');
    $stmt->execute([$campId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'thread' => getCampaignCommentsFeed($campId),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load comments']);
}
