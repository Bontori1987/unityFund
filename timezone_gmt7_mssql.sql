USE UnityFindDB;
GO

-- SQL Server DATETIME does not store timezone metadata.
-- UnityFund stores GMT+7 wall time in DATETIME columns.

DECLARE @sql NVARCHAR(MAX) = N'';

SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) +
               N'.' + QUOTENAME(OBJECT_NAME(parent_object_id)) +
               N' DROP CONSTRAINT ' + QUOTENAME(name) + N';' + CHAR(13)
FROM sys.default_constraints
WHERE parent_object_id = OBJECT_ID('Users')
  AND COL_NAME(parent_object_id, parent_column_id) = 'CreatedAt';
EXEC sp_executesql @sql;
ALTER TABLE Users ADD CONSTRAINT DF_Users_CreatedAt_GMT7
DEFAULT DATEADD(HOUR, 7, SYSUTCDATETIME()) FOR CreatedAt;
GO

DECLARE @sql NVARCHAR(MAX) = N'';
SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) +
               N'.' + QUOTENAME(OBJECT_NAME(parent_object_id)) +
               N' DROP CONSTRAINT ' + QUOTENAME(name) + N';' + CHAR(13)
FROM sys.default_constraints
WHERE parent_object_id = OBJECT_ID('Campaigns')
  AND COL_NAME(parent_object_id, parent_column_id) = 'CreatedAt';
EXEC sp_executesql @sql;
ALTER TABLE Campaigns ADD CONSTRAINT DF_Campaigns_CreatedAt_GMT7
DEFAULT DATEADD(HOUR, 7, SYSUTCDATETIME()) FOR CreatedAt;
GO

DECLARE @sql NVARCHAR(MAX) = N'';
SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) +
               N'.' + QUOTENAME(OBJECT_NAME(parent_object_id)) +
               N' DROP CONSTRAINT ' + QUOTENAME(name) + N';' + CHAR(13)
FROM sys.default_constraints
WHERE parent_object_id = OBJECT_ID('Donations')
  AND COL_NAME(parent_object_id, parent_column_id) = 'Time';
EXEC sp_executesql @sql;
ALTER TABLE Donations ADD CONSTRAINT DF_Donations_Time_GMT7
DEFAULT DATEADD(HOUR, 7, SYSUTCDATETIME()) FOR Time;
GO

DECLARE @sql NVARCHAR(MAX) = N'';
SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) +
               N'.' + QUOTENAME(OBJECT_NAME(parent_object_id)) +
               N' DROP CONSTRAINT ' + QUOTENAME(name) + N';' + CHAR(13)
FROM sys.default_constraints
WHERE parent_object_id = OBJECT_ID('Receipts')
  AND COL_NAME(parent_object_id, parent_column_id) = 'IssuedAt';
EXEC sp_executesql @sql;
ALTER TABLE Receipts ADD CONSTRAINT DF_Receipts_IssuedAt_GMT7
DEFAULT DATEADD(HOUR, 7, SYSUTCDATETIME()) FOR IssuedAt;
GO

IF OBJECT_ID('trg_GenerateTaxReceipt', 'TR') IS NOT NULL
    DROP TRIGGER trg_GenerateTaxReceipt;
GO

CREATE TRIGGER trg_GenerateTaxReceipt
ON Donations
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO Receipts (DonID, IssuedAt, TaxAmount)
    SELECT
        i.ID,
        DATEADD(HOUR, 7, SYSUTCDATETIME()),
        ROUND(i.Amt * 0.10, 2)
    FROM INSERTED i
    WHERE i.Amt > 50;
END;
GO
