<?php
// Mencegah akses publik (menggunakan kunci rahasia yang sama dengan trigger)
$secret = getenv('CRON_SECRET');
if (!$secret || !isset($_GET['key']) || $_GET['key'] !== $secret) {
    http_response_code(403);
    die("Akses ditolak.");
}

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'vacancy_watch';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5 // Maksimal tunggu 5 detik
    ]);
    
    // Kueri paling ringan di SQL untuk membuktikan database hidup
    $pdo->query("SELECT 1"); 
    
    echo "PONG: Aiven Database is awake and breathing.";
} catch (Exception $e) {
    http_response_code(500);
    echo "FAIL: " . $e->getMessage();
}