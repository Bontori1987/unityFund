<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../db.php';

if (!isOrganizer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$campID = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
if ($campID <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid campaign ID']);
    exit;
}

$userID = (int)currentUser()['id'];

try {
    // Organizers can only view their own campaign's donations; admins see all
    if (!isAdmin()) {
        $own = $conn->prepare("SELECT CampID FROM Campaigns WHERE CampID = ? AND HostID = ?");
        $own->execute([$campID, $userID]);
        if (!$own->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
    }

    $stmt = $conn->prepare(
        "SELECT
            d.ID,
            CASE WHEN d.IsAnonymous = 1 THEN 'Anonymous' ELSE u.Username END AS DonorName,
            d.Amt,
            CONVERT(VARCHAR, d.Time, 107) AS Time,
            CASE WHEN r.ID IS NOT NULL THEN 1 ELSE 0 END AS HasReceipt
         FROM Donations d
         JOIN Users u ON d.DonorID = u.UserID
         LEFT JOIN Receipts r ON r.DonID = d.ID
         WHERE d.CampID = ?
         ORDER BY d.Time DESC"
    );
    $stmt->execute([$campID]);
    $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'donations' => $donations]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed']);
}
