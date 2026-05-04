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

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sign in required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = (string)($input['action'] ?? 'submit');
$decisionId = trim((string)($input['decision_id'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$decision = trim((string)($input['decision'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));
$otpChallengeId = trim((string)($input['otp_challenge_id'] ?? ''));

if ($decisionId === '') {
    echo json_encode(['success' => false, 'error' => 'Decision ID is required']);
    exit;
}

if ($action === 'submit') {
    $userId = (int)currentUser()['id'];
    if ($message === '') {
        echo json_encode(['success' => false, 'error' => 'Appeal message is required']);
        exit;
    }

    $decisionMap = getRoleChangeDecisionsMap([$decisionId]);
    $record = $decisionMap[$decisionId] ?? null;
    if (!$record || (int)$record['user_id'] !== $userId) {
        echo json_encode(['success' => false, 'error' => 'Role decision not found']);
        exit;
    }
    $otp = getVerifiedEmailOtpChallenge($userId, $otpChallengeId, 'role_appeal_auth');
    if (!$otp) {
        echo json_encode(['success' => false, 'error' => 'Appeal verification expired. Request a new Gmail OTP.']);
        exit;
    }
    if ((string)($otp['payload']['decision_id'] ?? '') !== $decisionId) {
        echo json_encode(['success' => false, 'error' => 'The OTP does not match this role decision.']);
        exit;
    }

    if (!submitRoleChangeAppeal($decisionId, $userId, $message)) {
        echo json_encode(['success' => false, 'error' => 'Could not submit the appeal']);
        exit;
    }

    consumeEmailOtpChallenge($userId, $otpChallengeId, 'role_appeal_auth');

    try {
        $admins = $conn->query("SELECT UserID FROM Users WHERE Role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            mongoInsert('notifications', [
                'to_user_id' => (int)$adminId,
                'from_user_id' => $userId,
                'type' => 'role_appeal',
                'decision_id' => $decisionId,
                'message' => 'A user submitted a role-change appeal that needs admin review.',
                'read' => false,
                'created_at' => mongoNow(),
            ]);
        }
    } catch (Throwable $e) {}

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'review') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid review decision']);
        exit;
    }
    if ($notes === '') {
        echo json_encode(['success' => false, 'error' => 'Review notes are required']);
        exit;
    }

    $decisionMap = getRoleChangeDecisionsMap([$decisionId]);
    $record = $decisionMap[$decisionId] ?? null;
    if (!$record || ($record['appeal_status'] ?? 'none') !== 'pending') {
        echo json_encode(['success' => false, 'error' => 'No pending role appeal found']);
        exit;
    }

    try {
        if ($decision === 'approved') {
            $stmt = $conn->prepare("UPDATE Users SET Role = ? WHERE UserID = ?");
            $stmt->execute([(string)$record['old_role'], (int)$record['user_id']]);
        }

        if (!reviewRoleChangeAppeal($decisionId, (int)currentUser()['id'], $decision, $notes)) {
            echo json_encode(['success' => false, 'error' => 'Could not update appeal record']);
            exit;
        }

        sendRoleAppealResultNotification((int)currentUser()['id'], (int)$record['user_id'], $decisionId, $decision, $notes);

        $uStmt = $conn->prepare("SELECT Username, Email FROM Users WHERE UserID = ?");
        $uStmt->execute([(int)$record['user_id']]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($user && !empty($user['Email'])) {
            sendRoleAppealResultEmail(
                (string)$user['Email'],
                (string)($user['Username'] ?? 'User'),
                (string)$record['old_role'],
                (string)$record['new_role'],
                $decision,
                $notes
            );
        }

        echo json_encode(['success' => true]);
        exit;
    } catch (Throwable $e) {
        error_log('role_change_appeal review failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not review appeal']);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
