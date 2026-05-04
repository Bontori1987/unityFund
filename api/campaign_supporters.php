<?php
header('Content-Type: application/json');

require_once '../db.php';

$campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
if ($campId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid campaign']);
    exit;
}

try {
    $statsStmt = $conn->prepare(
        "SELECT
            c.CampID,
            c.Title,
            c.GoalAmt,
            COALESCE(SUM(d.Amt), 0) AS TotalRaised,
            COUNT(DISTINCT d.DonorID) AS DonorCount
         FROM Campaigns c
         LEFT JOIN Donations d ON d.CampID = c.CampID
         WHERE c.CampID = ?
         GROUP BY c.CampID, c.Title, c.GoalAmt"
    );
    $statsStmt->execute([$campId]);
    $campaign = $statsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit;
    }

    $supportersStmt = $conn->prepare(
        "SELECT DonorID, DonorName, Amt, RankInCampaign
         FROM vw_DonationRunningTotal
         WHERE CampID = ? AND RankInCampaign <= 3
         ORDER BY RankInCampaign, Amt DESC, Time ASC"
    );
    $supportersStmt->execute([$campId]);
    $supporters = $supportersStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'campaign' => [
            'id' => (int)$campaign['CampID'],
            'title' => (string)$campaign['Title'],
            'goal' => (float)$campaign['GoalAmt'],
            'raised' => (float)$campaign['TotalRaised'],
            'donor_count' => (int)$campaign['DonorCount'],
        ],
        'supporters' => array_map(static function (array $row): array {
            return [
                'donor_id' => $row['DonorID'] !== null ? (int)$row['DonorID'] : null,
                'donor_name' => (string)$row['DonorName'],
                'amount' => (float)$row['Amt'],
                'rank' => (int)$row['RankInCampaign'],
            ];
        }, $supporters),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load supporters']);
}
