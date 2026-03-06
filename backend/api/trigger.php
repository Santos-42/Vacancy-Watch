<?php
// Mencegah script mati karena proses terlalu lama
set_time_limit(0);
// Tetap berjalan meskipun user menutup tab browser
ignore_user_abort(true);

// Sistem Keamanan Sederhana
$secret = getenv('CRON_SECRET');
if (!$secret || !isset($_GET['key']) || $_GET['key'] !== $secret) {
    http_response_code(403);
    die("Akses ditolak. Kunci tidak valid.");
}

echo "<pre>Memulai eksekusi pipa data secara paksa...\n\n";
ob_flush(); flush();

$baseDir = dirname(__DIR__);

echo "Tahap 1: Mengunduh data dari Montgomery...\n";
system("php " . $baseDir . "/scripts/fetch_montgomery.php 2>&1");
ob_flush(); flush();

echo "\nTahap 2: Memasukkan data ke Aiven MySQL...\n";
system("php " . $baseDir . "/scripts/etl_montgomery.php 2>&1");
ob_flush(); flush();

echo "\nTahap 3: Menghasilkan Cache Anomali...\n";
system("php -d memory_limit=512M " . $baseDir . "/scripts/generate_anomalies.php 2>&1");

echo "\n\nSEMUA PROSES SELESAI.</pre>";