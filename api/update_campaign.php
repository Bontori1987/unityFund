<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isOrganizer()) {
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

$userID  = (int)currentUser()['id'];
$action  = $input['action'] ?? 'update';
$allowed = ['active', 'pending', 'closed'];

try {
    // ── CREATE ────────────────────────────────────────────────────
    if ($action === 'create') {
        $title = trim($input['title'] ?? '');
        $goal  = (float)($input['goal'] ?? 0);
        // Organizers always submit as pending — admin approves
        $status = 'pending';

        if (!$title)    { echo json_encode(['success' => false, 'error' => 'Title is required']); exit; }
        if ($goal <= 0) { echo json_encode(['success' => false, 'error' => 'Goal must be > 0']); exit; }

        $category = trim($input['category'] ?? 'Other');
        $validCats = ['Technology','Arts','Community','Education','Environment','Health','Food','Other'];
        if (!in_array($category, $validCats)) $category = 'Other';

        $description = trim($input['description'] ?? '');

        $conn->prepare(
            "INSERT INTO Campaigns (Title, GoalAmt, HostID, Status, Category, Description) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$title, $goal, $userID, $status, $category, $description ?: null]);

        $idRow = $conn->query("SELECT SCOPE_IDENTITY() AS id")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'camp_id' => (int)$idRow['id']]);
        exit;
    }

    // ── UPDATE ────────────────────────────────────────────────────
    $campID = (int)($input['camp_id'] ?? 0);
    if ($campID <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid campaign ID']); exit; }

    // Verify ownership (admins bypass this)
    if (!isAdmin()) {
        $own = $conn->prepare("SELECT CampID FROM Campaigns WHERE CampID = ? AND HostID = ?");
        $own->execute([$campID, $userID]);
        if (!$own->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Campaign not found or access denied']);
            exit;
        }
    }

    // Build dynamic UPDATE — only set fields that were sent
    $sets   = [];
    $params = [];

    if (isset($input['title']) && trim($input['title']) !== '') {
        $sets[]   = 'Title = ?';
        $params[] = trim($input['title']);
    }
    if (isset($input['goal']) && (float)$input['goal'] > 0) {
        $sets[]   = 'GoalAmt = ?';
        $params[] = (float)$input['goal'];
    }
    // Only admins can change status — organizers cannot
    if (isAdmin() && isset($input['status']) && in_array($input['status'], $allowed)) {
        $sets[]   = 'Status = ?';
        $params[] = $input['status'];
    }
    if (isset($input['category'])) {
        $validCats = ['Technology','Arts','Community','Education','Environment','Health','Food','Other'];
        $cat = in_array($input['category'], $validCats) ? $input['category'] : 'Other';
        $sets[]   = 'Category = ?';
        $params[] = $cat;
    }
    if (array_key_exists('description', $input)) {
        $sets[]   = 'Description = ?';
        $params[] = trim($input['description']) ?: null;
    }

    if (empty($sets)) {
        echo json_encode(['success' => false, 'error' => 'Nothing to update']);
        exit;
    }

    $params[] = $campID;
    $conn->prepare("UPDATE Campaigns SET " . implode(', ', $sets) . " WHERE CampID = ?")
         ->execute($params);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('Campaign update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
