<?php
require_once __DIR__ . '/includes/time.php';

$serverName = getenv('DB_SERVER')   ?: "localhost";
$database   = getenv('DB_NAME')     ?: "UnityFindDB";
$username   = getenv('DB_USERNAME') ?: "sa";
$password   = getenv('DB_PASSWORD') ?: "";

try {
    $conn = new PDO(
        "sqlsrv:Server=$serverName;Database=$database;Encrypt=true;TrustServerCertificate=false",
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
