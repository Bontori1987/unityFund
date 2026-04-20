-- ============================================================
-- UnityFund Database Schema  (T-SQL / MS SQL Server)
-- ============================================================
USE UnityFindDB;
GO

-- Users
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Users' AND xtype='U')
CREATE TABLE Users (
    UserID      INT           PRIMARY KEY IDENTITY(1,1),
    Username    NVARCHAR(100) NOT NULL,
    Email       NVARCHAR(255) NOT NULL UNIQUE,
    Password    NVARCHAR(255) NOT NULL,            -- bcrypt hash
    Role        NVARCHAR(20)  NOT NULL DEFAULT 'donor',
    IsAnonymous BIT           NOT NULL DEFAULT 0,  -- user-level anonymous mode
    CreatedAt   DATETIME      NOT NULL DEFAULT GETDATE()
);
GO

-- Campaigns (SQL mirror of MongoDB document for JOIN purposes)
-- Status values: 'pending' (awaiting admin approval), 'active', 'closed'
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Campaigns' AND xtype='U')
CREATE TABLE Campaigns (
    CampID    INT             PRIMARY KEY IDENTITY(1,1),
    Title     NVARCHAR(255)   NOT NULL,
    GoalAmt   DECIMAL(10,2)   NOT NULL,
    HostID    INT             FOREIGN KEY REFERENCES Users(UserID),
    Status      NVARCHAR(20)    NOT NULL DEFAULT 'pending',
    Category    NVARCHAR(50)    NOT NULL DEFAULT 'Other',
    Description NVARCHAR(MAX)   NULL,
    CreatedAt   DATETIME        NOT NULL DEFAULT GETDATE()
);
GO

-- Donations  (spec: Donations(ID, CampID, DonorID, Amt, Time))
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Donations' AND xtype='U')
CREATE TABLE Donations (
    ID          INT           PRIMARY KEY IDENTITY(1,1),
    CampID      INT           NOT NULL,
    DonorID     INT           NOT NULL,
    Amt         DECIMAL(10,2) NOT NULL CHECK (Amt > 0),
    Time        DATETIME      NOT NULL DEFAULT GETDATE(),
    Message     NVARCHAR(500) NULL,
    IsAnonymous BIT           NOT NULL DEFAULT 0,
    CONSTRAINT FK_Don_Camp  FOREIGN KEY (CampID)  REFERENCES Campaigns(CampID),
    CONSTRAINT FK_Don_Donor FOREIGN KEY (DonorID) REFERENCES Users(UserID)
);
GO

-- Receipts  (spec: Receipts(ID, DonID))
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Receipts' AND xtype='U')
CREATE TABLE Receipts (
    ID        INT           PRIMARY KEY IDENTITY(1,1),
    DonID     INT           NOT NULL UNIQUE,
    IssuedAt  DATETIME      NOT NULL DEFAULT GETDATE(),
    TaxAmount DECIMAL(10,2) NOT NULL,
    CONSTRAINT FK_Rec_Don FOREIGN KEY (DonID) REFERENCES Donations(ID)
);
GO

-- ============================================================
-- TRIGGER: auto-generate tax receipt when donation > $50
-- ============================================================
IF OBJECT_ID('trg_GenerateTaxReceipt', 'TR') IS NOT NULL
    DROP TRIGGER trg_GenerateTaxReceipt;
GO

CREATE TRIGGER trg_GenerateTaxReceipt
ON Donations
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    -- For every newly inserted row where Amt > 50, create one receipt.
    -- TaxAmount = 10% of the donation (tax-deductible portion).
    INSERT INTO Receipts (DonID, IssuedAt, TaxAmount)
    SELECT
        i.ID,
        GETDATE(),
        ROUND(i.Amt * 0.10, 2)
    FROM INSERTED i
    WHERE i.Amt > 50;
END;
GO

-- ============================================================
-- VIEW: Running total of donations per campaign (Window Function)
-- ============================================================
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
    END     AS DonorID,
    CASE WHEN d.IsAnonymous = 1 OR u.IsAnonymous = 1 THEN 'Anonymous'
         ELSE u.Username
    END     AS DonorName,
    d.Amt,
    d.Time,
    -- Cumulative sum per campaign, ordered by donation time
    SUM(d.Amt) OVER (
        PARTITION BY d.CampID
        ORDER BY d.Time
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS RunningTotal,
    -- Rank each donation within its campaign by size
    RANK() OVER (
        PARTITION BY d.CampID
        ORDER BY d.Amt DESC
    ) AS RankInCampaign
FROM Donations d
JOIN Campaigns c ON d.CampID  = c.CampID
JOIN Users     u ON d.DonorID = u.UserID;
GO

-- ============================================================
-- VIEW: Top donors overall (Window Function + CTE aggregation)
-- ============================================================
IF OBJECT_ID('vw_TopDonors', 'V') IS NOT NULL
    DROP VIEW vw_TopDonors;
GO

CREATE VIEW vw_TopDonors AS
WITH DonorTotals AS (
    SELECT
        u.UserID,
        u.Username,
        SUM(d.Amt)  AS TotalDonated,
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
    RANK() OVER (ORDER BY TotalDonated DESC) AS OverallRank
FROM DonorTotals;
GO

-- ============================================================
-- Sample data (safe to run multiple times)
-- ============================================================
IF NOT EXISTS (SELECT 1 FROM Users)
BEGIN
    INSERT INTO Users (Username, Email, Password, Role) VALUES
        ('Alice Chen',  'alice@example.com',   '$2y$10$KY4GnyJG7LduYNztxucH8.BkLcoFxeKwOCRlP/DqWne7rzmJK5YDO', 'donor'),
        ('Bob Torres',  'bob@example.com',     '$2y$10$gvck5Cwl.yI2ELMpE80U7.rp42D5jH5NdJHhkO1Ceh4HpTgEzfYkC', 'donor'),
        ('Carol White', 'carol@example.com',   '$2y$10$jyNQtHBAXhYcrfij/P/J7uH5LhTfIZIi0cffLmXrLrDH8/8JJvNpK', 'donor'),
        ('Admin',       'admin@unityfund.com', '$2y$10$U0imT1oxpZ8kc7oSTL1im.rkLwbVjZ7FlZFK6Rmiu4eDgO/uAClL6', 'admin'),
        ('FundCreator', 'host@example.com',    '$2y$10$cUQvXVcTOGegTkW4A.9boOnCKClrOPJCMJEcxECT0LEVaQeoQ4TMW', 'organizer');
END;
GO

-- Migrations: safe to run on existing DB
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('Users') AND name = 'IsAnonymous'
)
    ALTER TABLE Users ADD IsAnonymous BIT NOT NULL DEFAULT 0;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('Campaigns') AND name = 'Category'
)
    ALTER TABLE Campaigns ADD Category NVARCHAR(50) NOT NULL DEFAULT 'Other';
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('Campaigns') AND name = 'Description'
)
    ALTER TABLE Campaigns ADD Description NVARCHAR(MAX) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM Campaigns)
BEGIN
    INSERT INTO Campaigns (Title, GoalAmt, HostID, Status, Category) VALUES
        ('Clean Water Initiative', 5000.00, 5, 'active', 'Environment'),
        ('Education for All',      8000.00, 5, 'active', 'Education'),
        ('Disaster Relief Fund',   3000.00, 5, 'active', 'Community');
END;
GO

-- Donations > $50 will automatically fire the trigger and create Receipts
IF NOT EXISTS (SELECT 1 FROM Donations)
BEGIN
    INSERT INTO Donations (CampID, DonorID, Amt, Message, IsAnonymous) VALUES
        (1, 1, 100.00, 'Keep up the great work!', 0),
        (1, 2,  25.00, 'Happy to help!',           0),
        (1, 3,  75.00, NULL,                        0),
        (2, 1, 200.00, 'Education matters!',        0),
        (2, 2,  50.00, NULL,                        1),
        (2, 3,  30.00, 'Small but meaningful',      0),
        (3, 1, 150.00, 'Stay strong!',              0),
        (3, 2,  45.00, NULL,                        0),
        (3, 3,  80.00, NULL,                        1);
END;
GO
