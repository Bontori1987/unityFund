<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/mongo.php';

if (!isLoggedIn() || (!isOrganizer() && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Organizer access required']);
    exit;
}

$userId = (int)currentUser()['id'];
$stripe = getStripeAccount($userId);

if ($stripe['account_id'] === '') {
    echo json_encode([
        'success' => true,
        'cleared' => false,
        'message' => 'No Stripe account was linked',
    ]);
    exit;
}

if (!clearStripeAccount($userId)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not clear saved Stripe connection']);
    exit;
}

echo json_encode([
    'success' => true,
    'cleared' => true,
    'old_account_id' => $stripe['account_id'],
    'message' => 'Saved Stripe linkage cleared. Reconnect to create a fresh onboarding flow.',
]);
