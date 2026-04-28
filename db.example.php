<?php
require_once __DIR__ . '/includes/time.php';

// Copy this file to db.php and fill in your credentials.
// db.php is listed in .gitignore — never commit real credentials.
$serverName = "YOUR_SERVER_NAME";   // e.g. LAPTOP-XXXXX or localhost\SQLEXPRESS
$database   = "UnityFindDB";
$username   = "your_sql_username";
$password   = "your_sql_password";

try {
    $conn = new PDO(
        "sqlsrv:Server=$serverName;Database=$database;Encrypt=true;TrustServerCertificate=true",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("DB connection failed: " . $e->getMessage());
    if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }
    die('<p style="color:red;padding:20px;">Database connection failed. Please try again later.</p>');
}
?>
