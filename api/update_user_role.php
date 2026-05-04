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

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$userID = (int)($input['user_id'] ?? 0);
$role   = $input['role']  ?? '';
$notes  = trim((string)($input['notes'] ?? ''));
$notify = (bool)($input['notify'] ?? false);

$allowed = ['donor', 'organizer', 'pending_organizer', 'admin'];

if ($userID <= 0)               { echo json_encode(['success' => false, 'error' => 'Invalid user ID']); exit; }
if (!in_array($role, $allowed)) { echo json_encode(['success' => false, 'error' => 'Invalid role']); exit; }
if ($notes === '')              { echo json_encode(['success' => false, 'error' => 'A reason is required for every role change']); exit; }

// Prevent admin from demoting themselves
if ($userID === (int)currentUser()['id']) {
    echo json_encode(['success' => false, 'error' => 'You cannot change your own role']);
    exit;
}

try {
    // Fetch current role before changing it
    $stmt = $conn->prepare("SELECT Role, Username, Email FROM Users WHERE UserID = ?");
    $stmt->execute([$userID]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) { echo json_encode(['success' => false, 'error' => 'User not found']); exit; }

    $oldRole = $target['Role'];

    $decisionId = null;
    if ($oldRole !== $role) {
        $decisionId = createRoleChangeDecision($userID, (int)currentUser()['id'], $oldRole, $role, $notes);
    }

    $conn->prepare("UPDATE Users SET Role = ? WHERE UserID = ?")
         ->execute([$role, $userID]);

    // Update organizer application decision if applicable
    if ($role === 'organizer' || ($role === 'donor' && in_array($oldRole, ['organizer', 'pending_organizer']))) {
        $decision = $role === 'organizer' ? 'approved' : 'rejected';
        updateOrganizerApplicationDecision($userID, $decision, $notes, (int)currentUser()['id']);
    }

    // Send notification to the affected user
    if ($oldRole !== $role) {
        $reason = $notes ?: 'No reason provided';
        if ($notify || $role === 'organizer' || $role === 'donor') {
            sendRoleChangeNotificationWithDecision((int)currentUser()['id'], $userID, $oldRole, $role, $reason, (string)($decisionId ?? ''));
        }
        if ($role === 'organizer') {
            sendStripeRequiredNotification((int)currentUser()['id'], $userID);
        }

        $email = strtolower(trim((string)($target['Email'] ?? '')));
        if ($email !== '') {
            $labels = [
                'donor' => 'Donor',
                'pending_organizer' => 'Pending Organizer',
                'organizer' => 'Organizer',
                'admin' => 'Admin',
            ];
            $summary = [
                'Previous role' => $labels[$oldRole] ?? ucfirst($oldRole),
                'New role' => $labels[$role] ?? ucfirst($role),
                'Decision notes' => $reason,
            ];
            $bullets = [];
            $subject = 'UnityFund account role updated';
            $title = 'Account role updated';
            $intro = 'An admin updated your UnityFund role and attached the decision notes below.';

            if ($role === 'organizer') {
                $subject = 'Organizer application approved';
                $title = 'Organizer access approved';
                $intro = 'Your organizer application was approved. Connect Stripe before creating your first campaign.';
                $bullets[] = 'Open My Campaigns and click Connect Stripe.';
                $bullets[] = 'After Stripe is connected, campaign creation will unlock.';
            } elseif ($role === 'donor' && in_array($oldRole, ['pending_organizer', 'organizer'], true)) {
                $subject = 'Organizer application not approved';
                $title = 'Organizer access not approved';
                $intro = 'Your organizer request was not approved at this time. Review the admin notes before applying again.';
                $bullets[] = 'You can still use the account as a donor.';
                $bullets[] = 'Apply again after correcting the requested issues.';
            }

            sendRoleChangeEmail(
                $email,
                (string)($target['Username'] ?? 'User'),
                $subject,
                $title,
                $intro,
                $summary,
                $bullets
            );
        }
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Role update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
