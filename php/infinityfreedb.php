<?php
// db.php
// Returns $pdo
$host = getenv('DB_HOST') ?: 'sql208.infinityfree.com';
$db   = getenv('DB_NAME') ?: 'if0_39898000_donations_db';
$user = getenv('DB_USER') ?: 'if0_39898000';
$pass = getenv('DB_PASS') ?: 'SeXtM7Zdj5a';
$dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("DB connection error: " . $e->getMessage());
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}
