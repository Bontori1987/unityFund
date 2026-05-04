<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';
require_once '../includes/mongo.php';
require_once '../includes/stripe.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isOrganizer() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

$userID = (int)currentUser()['id'];
$action = $input['action'] ?? 'update';
$allowed = ['active', 'pending', 'closed'];
$otpChallengeId = trim((string)($input['otp_challenge_id'] ?? ''));

try {
    if ($action === 'create') {
        if (!isAdmin()) {
            $stripe = getStripeAccount($userID);
            $stripeReady = (bool)($stripe['onboarded'] ?? false);

            if (!$stripeReady && ($stripe['account_id'] ?? '') !== '') {
                $acctStatus = stripeRetrieveAccount($stripe['account_id']);
                $sandboxFastTrack = stripeIsTestMode()
                    && (($acctStatus['metadata']['unityfund_fasttrack'] ?? '') === '1')
                    && (($acctStatus['capabilities']['transfers'] ?? '') === 'active');

                if (
                    (!empty($acctStatus['charges_enabled']) && !empty($acctStatus['payouts_enabled']))
                    || $sandboxFastTrack
                ) {
                    markStripeOnboarded($userID);
                    $stripeReady = true;
                }
            }

            if (!$stripeReady) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Connect your Stripe payout account before creating a campaign.',
                    'requires_stripe' => true,
                ]);
                exit;
            }
        }

        $title = trim($input['title'] ?? '');
        $goal = (float)($input['goal'] ?? 0);
        $status = 'pending';

        if ($title === '') {
            echo json_encode(['success' => false, 'error' => 'Title is required']);
            exit;
        }
        if ($goal <= 0) {
            echo json_encode(['success' => false, 'error' => 'Goal must be > 0']);
            exit;
        }

        $category = trim($input['category'] ?? 'Other');
        $validCats = ['Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];
        if (!in_array($category, $validCats, true)) $category = 'Other';

        $description = trim($input['description'] ?? '');

        $ins = $conn->prepare(
            "INSERT INTO Campaigns (Title, GoalAmt, HostID, Status, Category, CreatedAt)
             OUTPUT INSERTED.CampID
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$title, $goal, $userID, $status, $category, sqlNow()]);

        $newCampId = (int)$ins->fetchColumn();

        if ($description !== '') {
            saveCampaignDescription($newCampId, $description);
        }

        echo json_encode(['success' => true, 'camp_id' => $newCampId]);
        exit;
    }

    $campID = (int)($input['camp_id'] ?? 0);
    if ($campID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid campaign ID']);
        exit;
    }

    $campStmt = $conn->prepare("SELECT CampID, HostID, Status, Title FROM Campaigns WHERE CampID = ?");
    $campStmt->execute([$campID]);
    $campRow = $campStmt->fetch(PDO::FETCH_ASSOC);
    if (!$campRow) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit;
    }

    if (!isAdmin()) {
        if ((int)$campRow['HostID'] !== $userID) {
            echo json_encode(['success' => false, 'error' => 'Campaign not found or access denied']);
            exit;
        }
    }

    $sets = [];
    $params = [];

    if (isset($input['title']) && trim($input['title']) !== '') {
        $sets[] = 'Title = ?';
        $params[] = trim($input['title']);
    }
    if (isset($input['goal']) && (float)$input['goal'] > 0) {
        $sets[] = 'GoalAmt = ?';
        $params[] = (float)$input['goal'];
    }
    if (isset($input['status']) && in_array($input['status'], $allowed, true)) {
        $requestedStatus = $input['status'];
        if (isAdmin()) {
            $sets[] = 'Status = ?';
            $params[] = $requestedStatus;
            if ($requestedStatus === 'closed' && ($campRow['Status'] ?? '') === 'active') {
                markCampaignForceClosedByAdmin($campID, $userID, trim((string)($input['admin_reason'] ?? '')));
            } elseif ($requestedStatus === 'active') {
                clearCampaignForceClosedState($campID);
            }
        } elseif ($requestedStatus === 'closed') {
            if (!$campRow || $campRow['Status'] !== 'active') {
                echo json_encode(['success' => false, 'error' => 'Only active campaigns can be closed by the organizer.']);
                exit;
            }

            $otp = getVerifiedEmailOtpChallenge($userID, $otpChallengeId, 'campaign_close_auth');
            if (!$otp) {
                echo json_encode(['success' => false, 'error' => 'Closing the campaign requires a fresh Gmail OTP.']);
                exit;
            }
            if ((int)($otp['payload']['camp_id'] ?? 0) !== $campID) {
                echo json_encode(['success' => false, 'error' => 'The OTP does not match this campaign.']);
                exit;
            }

            $sets[] = 'Status = ?';
            $params[] = 'closed';
        } elseif ($requestedStatus === 'active') {
            if (($campRow['Status'] ?? '') !== 'closed') {
                echo json_encode(['success' => false, 'error' => 'Only closed campaigns can be reopened.']);
                exit;
            }
            $meta = getCampaignDetails($campID) ?? [];
            if (!empty($meta['force_closed_by_admin'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'This campaign was force closed by an admin. Send an appeal to request reopening.',
                    'requires_appeal' => true,
                    'appeal_status' => $meta['appeal_status'] ?? 'none',
                ]);
                exit;
            }
            $sets[] = 'Status = ?';
            $params[] = 'active';
        }
    }
    if (isset($input['category'])) {
        $validCats = ['Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];
        $cat = in_array($input['category'], $validCats, true) ? $input['category'] : 'Other';
        $sets[] = 'Category = ?';
        $params[] = $cat;
    }
    if (empty($sets) && !array_key_exists('description', $input)) {
        echo json_encode(['success' => false, 'error' => 'Nothing to update']);
        exit;
    }

    if (!empty($sets)) {
        $updateParams = $params;
        $updateParams[] = $campID;
        $conn->prepare("UPDATE Campaigns SET " . implode(', ', $sets) . " WHERE CampID = ?")->execute($updateParams);
    }

    if (array_key_exists('description', $input)) {
        saveCampaignDescription($campID, (string)($input['description'] ?? ''));
    }

    if (!isAdmin() && (($input['status'] ?? '') === 'closed')) {
        consumeEmailOtpChallenge($userID, $otpChallengeId, 'campaign_close_auth');
        clearCampaignForceClosedState($campID);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Campaign update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
