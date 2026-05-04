param(
    [string]$ServerInstance = "",
    [string]$DatabaseName = "",
    [string]$Username = "",
    [string]$Password = "",
    [string]$OutputPath = "unityfinddb_current_snapshot.sql"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

[void][System.Reflection.Assembly]::LoadWithPartialName('Microsoft.SqlServer.Smo')
[void][System.Reflection.Assembly]::LoadWithPartialName('Microsoft.SqlServer.ConnectionInfo')
[void][System.Reflection.Assembly]::LoadWithPartialName('Microsoft.SqlServer.SmoExtended')

function Get-DbConfigFromPhp {
    $dbPhpPath = Join-Path (Get-Location) 'db.php'
    if (-not (Test-Path $dbPhpPath)) {
        return $null
    }

    $raw = Get-Content $dbPhpPath -Raw
    $map = @{}
    foreach ($key in @('serverName', 'database', 'username', 'password')) {
        if ($raw -match ('\$' + [regex]::Escape($key) + '\s*=\s*"([^"]*)"')) {
            $map[$key] = $Matches[1]
        }
    }
    return $map
}

function New-ServerConnection {
    param(
        [string]$Instance,
        [string]$User,
        [string]$Pass
    )

    $server = New-Object Microsoft.SqlServer.Management.Smo.Server($Instance)
    $server.ConnectionContext.LoginSecure = $false
    $server.ConnectionContext.Login = $User
    $server.ConnectionContext.Password = $Pass
    return $server
}

function Get-SqlLiteral {
    param([object]$Value)

    if ($null -eq $Value -or $Value -is [System.DBNull]) { return "NULL" }

    if ($Value -is [string]) {
        return "N'" + ($Value -replace "'", "''") + "'"
    }

    if ($Value -is [char]) {
        return "N'" + ($Value.ToString() -replace "'", "''") + "'"
    }

    if ($Value -is [datetime] -or $Value -is [datetimeoffset]) {
        return "'" + ([datetime]$Value).ToString("yyyy-MM-dd HH:mm:ss.fff") + "'"
    }

    if ($Value -is [bool]) {
        return $(if ($Value) { "1" } else { "0" })
    }

    if ($Value -is [byte[]]) {
        return "0x" + ([System.BitConverter]::ToString($Value).Replace('-', ''))
    }

    if ($Value -is [decimal] -or $Value -is [double] -or $Value -is [single]) {
        return ([System.Convert]::ToString($Value, [System.Globalization.CultureInfo]::InvariantCulture))
    }

    if ($Value -is [int16] -or $Value -is [int32] -or $Value -is [int64] -or
        $Value -is [uint16] -or $Value -is [uint32] -or $Value -is [uint64] -or
        $Value -is [byte] -or $Value -is [sbyte]) {
        return $Value.ToString()
    }

    return "N'" + ($Value.ToString() -replace "'", "''") + "'"
}

function Add-GoBlock {
    param(
        [System.Collections.Generic.List[string]]$Lines,
        [string[]]$Block
    )

    foreach ($line in $Block) {
        $Lines.Add($line)
    }
    $Lines.Add("GO")
    $Lines.Add("")
}

$config = Get-DbConfigFromPhp
if ($ServerInstance -eq '' -and $config) { $ServerInstance = $config['serverName'] }
if ($DatabaseName -eq '' -and $config) { $DatabaseName = $config['database'] }
if ($Username -eq '' -and $config) { $Username = $config['username'] }
if ($Password -eq '' -and $config) { $Password = $config['password'] }

if ($ServerInstance -eq '' -or $DatabaseName -eq '' -or $Username -eq '' -or $Password -eq '') {
    throw "Missing SQL connection settings. Pass parameters or keep a local db.php file."
}

$server = New-ServerConnection -Instance $ServerInstance -User $Username -Pass $Password
$db = $server.Databases[$DatabaseName]
if (-not $db) {
    throw "Database '$DatabaseName' was not found on '$ServerInstance'."
}

$outputFullPath = [System.IO.Path]::GetFullPath((Join-Path (Get-Location) $OutputPath))
$outDir = Split-Path -Parent $outputFullPath
if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Force -Path $outDir | Out-Null
}

$lines = New-Object 'System.Collections.Generic.List[string]'
$lines.Add("-- UnityFund SQL snapshot generated from live database")
$lines.Add("-- Source database: [$DatabaseName] on [$ServerInstance]")
$lines.Add("-- Generated at: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')")
$lines.Add("")
$lines.Add("IF DB_ID(N'$DatabaseName') IS NULL")
$lines.Add("BEGIN")
$lines.Add("    CREATE DATABASE [$DatabaseName];")
$lines.Add("END")
$lines.Add("GO")
$lines.Add("")
$lines.Add("USE [$DatabaseName];")
$lines.Add("GO")
$lines.Add("")

$dropObjects = @(
    "IF OBJECT_ID(N'[vw_TopDonors]', 'V') IS NOT NULL DROP VIEW [vw_TopDonors];",
    "IF OBJECT_ID(N'[vw_DonationRunningTotal]', 'V') IS NOT NULL DROP VIEW [vw_DonationRunningTotal];",
    "IF OBJECT_ID(N'[trg_GenerateTaxReceipt]', 'TR') IS NOT NULL DROP TRIGGER [trg_GenerateTaxReceipt];",
    "IF OBJECT_ID(N'[Receipts]', 'U') IS NOT NULL DROP TABLE [Receipts];",
    "IF OBJECT_ID(N'[Donations]', 'U') IS NOT NULL DROP TABLE [Donations];",
    "IF OBJECT_ID(N'[Transactions]', 'U') IS NOT NULL DROP TABLE [Transactions];",
    "IF OBJECT_ID(N'[Campaigns]', 'U') IS NOT NULL DROP TABLE [Campaigns];",
    "IF OBJECT_ID(N'[Users]', 'U') IS NOT NULL DROP TABLE [Users];"
)
Add-GoBlock -Lines $lines -Block $dropObjects

$schemaOptions = New-Object Microsoft.SqlServer.Management.Smo.ScriptingOptions
$schemaOptions.DriAll = $true
$schemaOptions.Indexes = $true
$schemaOptions.Triggers = $false
$schemaOptions.NoFileGroup = $true
$schemaOptions.IncludeHeaders = $false
$schemaOptions.SchemaQualify = $false
$schemaOptions.ExtendedProperties = $false

$orderedTables = @('Users', 'Campaigns', 'Donations', 'Receipts', 'Transactions')

foreach ($tableName in $orderedTables) {
    $table = $db.Tables[$tableName, 'dbo']
    if (-not $table) { throw "Missing table '$tableName' in live database." }
    Add-GoBlock -Lines $lines -Block ($table.Script($schemaOptions))
}

foreach ($tableName in $orderedTables) {
    $table = $db.Tables[$tableName, 'dbo']
    $columnNames = @($table.Columns | ForEach-Object { $_.Name })
    $quotedColumns = $columnNames | ForEach-Object { '[' + $_ + ']' }
    $identityColumns = @($table.Columns | Where-Object { $_.Identity })
    $orderColumn = $columnNames[0]
    $rows = $server.ConnectionContext.ExecuteWithResults("USE [$DatabaseName]; SELECT * FROM [$tableName] ORDER BY [$orderColumn] ASC;").Tables[0].Rows

    if ($rows.Count -eq 0) { continue }

    if ($identityColumns.Count -gt 0) {
        $lines.Add("SET IDENTITY_INSERT [$tableName] ON;")
        $lines.Add("GO")
        $lines.Add("")
    }

    foreach ($row in $rows) {
        $values = for ($i = 0; $i -lt $columnNames.Count; $i++) {
            Get-SqlLiteral $row[$i]
        }
        $lines.Add("INSERT INTO [$tableName] (" + ($quotedColumns -join ', ') + ") VALUES (" + ($values -join ', ') + ");")
    }
    $lines.Add("GO")
    $lines.Add("")

    if ($identityColumns.Count -gt 0) {
        $lines.Add("SET IDENTITY_INSERT [$tableName] OFF;")
        $lines.Add("GO")
        $lines.Add("")
    }
}

$viewOptions = New-Object Microsoft.SqlServer.Management.Smo.ScriptingOptions
$viewOptions.IncludeHeaders = $false
$viewOptions.SchemaQualify = $false

foreach ($viewName in @('vw_DonationRunningTotal', 'vw_TopDonors')) {
    $view = $db.Views[$viewName, 'dbo']
    if ($view) {
        Add-GoBlock -Lines $lines -Block ($view.Script($viewOptions))
    }
}

$trigger = $db.Tables['Donations', 'dbo'].Triggers['trg_GenerateTaxReceipt']
if ($trigger) {
    Add-GoBlock -Lines $lines -Block ($trigger.Script($viewOptions))
}

[System.IO.File]::WriteAllLines($outputFullPath, $lines, [System.Text.UTF8Encoding]::new($false))
Write-Host "Wrote SQL snapshot to $outputFullPath"
