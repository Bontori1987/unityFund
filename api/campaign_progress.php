<?php
header('Content-Type: application/json');
require_once '../db.php';
require_once '../includes/mongo.php';

$campID = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

if ($campID <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid campaign ID']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT
            c.GoalAmt,
            c.Title,
            COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
            COUNT(DISTINCT d.DonorID) AS DonorCount
         FROM Campaigns c
         LEFT JOIN Donations d ON d.CampID = c.CampID
         WHERE c.CampID = ?
         GROUP BY c.GoalAmt, c.Title"
    );
    $stmt->execute([$campID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit;
    }

    $details = getCampaignDetails($campID) ?? [];

    echo json_encode([
        'success'     => true,
        'title'       => $row['Title'],
        'goal'        => (float)$row['GoalAmt'],
        'raised'      => (float)$row['TotalRaised'],
        'donor_count' => (int)$row['DonorCount'],
        'thumbnail'   => $details['thumbnail'] ?? '',
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed']);
}
