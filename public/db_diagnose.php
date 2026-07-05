<?php
header('Content-Type: text/plain');
echo "=== NEXFOOD DATABASE DIAGNOSTIC ===\n\n";

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_DATABASE') ?: 'forge';
$user = getenv('DB_USERNAME') ?: 'forge';
$pass = getenv('DB_PASSWORD') ?: '';

echo "DB Host: $host\n";
echo "DB Port: $port\n";
echo "DB Name: $db\n";
echo "DB User: $user\n\n";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "✅ Database connection successful.\n\n";

    // 1. Check vendors
    $vendors = $pdo->query("SELECT id, f_name, l_name, email, firebase_token FROM vendors")->fetchAll();
    echo "=== VENDORS IN DATABASE (" . count($vendors) . ") ===\n";
    foreach ($vendors as $v) {
        echo sprintf("ID: %d | Name: %s %s | Email: %s | Token: %s\n",
            $v['id'],
            $v['f_name'],
            $v['l_name'],
            $v['email'],
            $v['firebase_token'] ? substr($v['firebase_token'], 0, 20) . "..." : "NULL"
        );
    }

    // 2. Check stores
    $stores = $pdo->query("SELECT id, name, vendor_id, email, status FROM stores")->fetchAll();
    echo "\n=== STORES IN DATABASE (" . count($stores) . ") ===\n";
    foreach ($stores as $s) {
        echo sprintf("ID: %d | Store: %s | Vendor ID: %d | Email: %s | Status: %d\n",
            $s['id'],
            $s['name'],
            $s['vendor_id'],
            $s['email'],
            $s['status']
        );
    }

} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
}
