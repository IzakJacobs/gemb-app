<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
echo "config.php loaded OK<br>";

// Test DB directly — show the real error
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Connected to database OK<br>";
} catch (PDOException $e) {
    echo "<strong>DB Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
}