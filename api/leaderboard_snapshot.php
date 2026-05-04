<?php
header('Content-Type: application/json');

require_once '../db.php';
require_once '../includes/mongo.php';

try {
    $overallStmt = $conn->query(
        "SELECT TOP 100 UserID, Username, TotalDonated, DonationCount, LastDonation, OverallRank
         FROM vw_TopDonors
         ORDER BY OverallRank, LastDonation DESC"
    );
    $overall = $overallStmt->fetchAll(PDO::FETCH_ASSOC);

    $campaignStmt = $conn->query(
        "SELECT CampID, CampaignTitle, DonorID, DonorName, Amt, RankInCampaign
         FROM vw_DonationRunningTotal
         WHERE RankInCampaign <= 3
         ORDER BY CampID, RankInCampaign, Amt DESC, Time ASC"
    );
    $perCampaignRows = $campaignStmt->fetchAll(PDO::FETCH_ASSOC);

    $campaignImages = getCampaignDetailsMap(array_column($perCampaignRows, 'CampID'));
    $perCampaign = [];
    foreach ($perCampaignRows as $row) {
        $campId = (int)$row['CampID'];
        if (!isset($perCampaign[$campId])) {
            $perCampaign[$campId] = [
                'camp_id' => $campId,
                'campaign_title' => (string)$row['CampaignTitle'],
                'thumbnail' => (string)($campaignImages[$campId]['thumbnail'] ?? ''),
                'supporters' => [],
            ];
        }
        $perCampaign[$campId]['supporters'][] = [
            'donor_id' => $row['DonorID'] !== null ? (int)$row['DonorID'] : null,
            'donor_name' => (string)$row['DonorName'],
            'amount' => (float)$row['Amt'],
            'rank' => (int)$row['RankInCampaign'],
        ];
    }

    echo json_encode([
        'success' => true,
        'generated_at' => date('c'),
        'overall' => array_map(static function (array $row): array {
            return [
                'user_id' => (int)$row['UserID'],
                'username' => (string)$row['Username'],
                'total_donated' => (float)$row['TotalDonated'],
                'donation_count' => (int)$row['DonationCount'],
                'last_donation' => (string)$row['LastDonation'],
                'overall_rank' => (int)$row['OverallRank'],
            ];
        }, $overall),
        'per_campaign' => array_values($perCampaign),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load leaderboard']);
}
