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
$purpose = trim((string)($input['purpose'] ?? ''));
$userId = (int)currentUser()['id'];

if (!in_array($purpose, ['donation_auth', 'campaign_close_auth', 'role_appeal_auth'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid verification purpose']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT Username, Email FROM Users WHERE UserID = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    $email = strtolower(trim((string)$user['Email']));
    if (!preg_match('/@gmail\.com$/i', $email)) {
        echo json_encode(['success' => false, 'error' => 'Your account must use Gmail for email verification']);
        exit;
    }

    $payload = [];
    $subject = 'Verify your UnityFund action';
    $title = 'Confirm this action';
    $subtitle = 'Use the OTP below to continue inside UnityFund.';
    $summary = ['Account email' => $email];
    $bullets = ['Enter the code in UnityFund before it expires.'];

    if ($purpose === 'campaign_close_auth') {
        $campId = (int)($input['camp_id'] ?? 0);
        if ($campId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Campaign is required']);
            exit;
        }
        $campStmt = $conn->prepare("SELECT Title FROM Campaigns WHERE CampID = ? AND HostID = ?");
        $campStmt->execute([$campId, $userId]);
        $camp = $campStmt->fetch(PDO::FETCH_ASSOC);
        if (!$camp) {
            echo json_encode(['success' => false, 'error' => 'Campaign not found']);
            exit;
        }
        $payload['camp_id'] = $campId;
        $payload['campaign_title'] = (string)$camp['Title'];
        $subject = 'OTP required to close a campaign';
        $title = 'Close campaign verification';
        $subtitle = 'Confirm that you want to close an active campaign.';
        $summary['Campaign'] = (string)$camp['Title'];
        $bullets[] = 'The campaign can be reopened later only by an admin.';
    } elseif ($purpose === 'role_appeal_auth') {
        $decisionId = trim((string)($input['decision_id'] ?? ''));
        if ($decisionId === '') {
            echo json_encode(['success' => false, 'error' => 'Role decision is required']);
            exit;
        }
        $decisionMap = getRoleChangeDecisionsMap([$decisionId]);
        $roleDecision = $decisionMap[$decisionId] ?? null;
        if (!$roleDecision || (int)$roleDecision['user_id'] !== $userId) {
            echo json_encode(['success' => false, 'error' => 'Role decision not found']);
            exit;
        }
        if (($roleDecision['appeal_status'] ?? 'none') === 'pending') {
            echo json_encode(['success' => false, 'error' => 'This decision already has a pending appeal']);
            exit;
        }
        $payload['decision_id'] = $decisionId;
        $payload['old_role'] = (string)$roleDecision['old_role'];
        $payload['new_role'] = (string)$roleDecision['new_role'];
        $subject = 'OTP required to submit a role appeal';
        $title = 'Role appeal verification';
        $subtitle = 'Verify your Gmail before sending this role appeal.';
        $summary['Previous role'] = (string)$roleDecision['old_role'];
        $summary['Changed role'] = (string)$roleDecision['new_role'];
        $bullets[] = 'Enter the code, then submit the appeal from the same inbox message.';
    } else {
        $subject = 'OTP required before donating';
        $title = 'Donation verification';
        $subtitle = 'Verify your Gmail before charging the card.';
        $bullets[] = 'Once verified, complete the donation on the same page.';
    }

    $challenge = createEmailOtpChallenge($userId, $email, $purpose, $payload);
    if (!$challenge) {
        echo json_encode(['success' => false, 'error' => 'Could not generate verification code']);
        exit;
    }

    $mail = sendOtpEmail($email, (string)$user['Username'], $subject, $title, $subtitle, $challenge['code'], $summary, $bullets);
    if (!$mail['success']) {
        echo json_encode(['success' => false, 'error' => 'Could not send verification email']);
        exit;
    }

    echo json_encode(['success' => true, 'challenge_id' => $challenge['challenge_id']]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
