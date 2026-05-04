<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/mongo.php';
require_once '../includes/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = trim((string)($input['action'] ?? ''));
$campId = (int)($input['camp_id'] ?? 0);

if ($campId <= 0 || !in_array($action, ['submit', 'review'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid appeal request']);
    exit;
}

try {
    if ($action === 'submit') {
        if (!isOrganizer() || isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Organizer access required']);
            exit;
        }

        $message = trim((string)($input['message'] ?? ''));
        if ($message === '') {
            echo json_encode(['success' => false, 'error' => 'Appeal message is required']);
            exit;
        }

        $stmt = $conn->prepare("SELECT CampID, Title, Status FROM Campaigns WHERE CampID = ? AND HostID = ?");
        $stmt->execute([$campId, (int)currentUser()['id']]);
        $camp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$camp) {
            echo json_encode(['success' => false, 'error' => 'Campaign not found']);
            exit;
        }
        if (($camp['Status'] ?? '') !== 'closed') {
            echo json_encode(['success' => false, 'error' => 'Only closed campaigns can be appealed']);
            exit;
        }

        $meta = getCampaignDetails($campId) ?? [];
        if (empty($meta['force_closed_by_admin'])) {
            echo json_encode(['success' => false, 'error' => 'This campaign was not force closed by admin. You can reopen it yourself.']);
            exit;
        }
        if (($meta['appeal_status'] ?? 'none') === 'pending') {
            echo json_encode(['success' => false, 'error' => 'An appeal is already pending for this campaign']);
            exit;
        }

        if (!submitCampaignAppeal($campId, (int)currentUser()['id'], $message)) {
            echo json_encode(['success' => false, 'error' => 'Could not submit the appeal']);
            exit;
        }

        $admins = $conn->query("SELECT UserID FROM Users WHERE Role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $admin) {
            sendCampaignAppealSubmittedNotification((int)currentUser()['id'], (int)$admin['UserID'], $campId, (string)$camp['Title'], $message);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    $decision = trim((string)($input['decision'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid decision']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT c.CampID, c.Title, c.Status, c.HostID, u.Username, u.Email
         FROM Campaigns c
         JOIN Users u ON u.UserID = c.HostID
         WHERE c.CampID = ?"
    );
    $stmt->execute([$campId]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$camp) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit;
    }

    $meta = getCampaignDetails($campId) ?? [];
    if (($meta['force_closed_by_admin'] ?? false) !== true || ($meta['appeal_status'] ?? 'none') !== 'pending') {
        echo json_encode(['success' => false, 'error' => 'No pending appeal found for this campaign']);
        exit;
    }

    if ($decision === 'approved') {
        $conn->prepare("UPDATE Campaigns SET Status = 'active' WHERE CampID = ?")->execute([$campId]);
        reviewCampaignAppeal($campId, (int)currentUser()['id'], 'approved', $notes);
        clearCampaignForceClosedState($campId);
    } else {
        reviewCampaignAppeal($campId, (int)currentUser()['id'], 'rejected', $notes);
    }

    sendCampaignAppealResultNotification((int)currentUser()['id'], (int)$camp['HostID'], $campId, (string)$camp['Title'], $decision, $notes);
    if (!empty($camp['Email'])) {
        sendCampaignAppealResultEmail((string)$camp['Email'], (string)($camp['Username'] ?? 'Organizer'), (string)$camp['Title'], $decision, $notes);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('campaign_appeal failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
