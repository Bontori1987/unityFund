USE UnityFindDB;
GO

IF OBJECT_ID('vw_DonationRunningTotal', 'V') IS NOT NULL
    DROP VIEW vw_DonationRunningTotal;
GO

CREATE VIEW vw_DonationRunningTotal AS
SELECT
    d.ID,
    d.CampID,
    c.Title AS CampaignTitle,
    CASE WHEN d.IsAnonymous = 1 OR u.IsAnonymous = 1 THEN NULL
         ELSE d.DonorID
    END AS DonorID,
    CASE WHEN d.IsAnonymous = 1 OR u.IsAnonymous = 1 THEN 'Anonymous'
         ELSE u.Username
    END AS DonorName,
    d.Amt,
    d.Time,
    SUM(d.Amt) OVER (
        PARTITION BY d.CampID
        ORDER BY d.Time
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS RunningTotal,
    DENSE_RANK() OVER (
        PARTITION BY d.CampID
        ORDER BY d.Amt DESC
    ) AS RankInCampaign
FROM Donations d
JOIN Campaigns c ON d.CampID = c.CampID
JOIN Users u ON d.DonorID = u.UserID;
GO

IF OBJECT_ID('vw_TopDonors', 'V') IS NOT NULL
    DROP VIEW vw_TopDonors;
GO

CREATE VIEW vw_TopDonors AS
WITH DonorTotals AS (
    SELECT
        u.UserID,
        u.Username,
        SUM(d.Amt) AS TotalDonated,
        COUNT(d.ID) AS DonationCount,
        MAX(d.Time) AS LastDonation
    FROM Donations d
    JOIN Users u ON d.DonorID = u.UserID
    WHERE d.IsAnonymous = 0 AND u.IsAnonymous = 0
    GROUP BY u.UserID, u.Username
)
SELECT
    UserID,
    Username,
    TotalDonated,
    DonationCount,
    LastDonation,
    DENSE_RANK() OVER (ORDER BY TotalDonated DESC) AS OverallRank
FROM DonorTotals;
GO
