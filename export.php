<?php
require_once __DIR__ . '/db.php';
requireAdmin();

$residents   = db()->query('SELECT id,name,unit,email,status,created_at FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$invitations = db()->query('SELECT * FROM invitations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$log         = db()->query('SELECT * FROM access_log ORDER BY id')->fetch_all(MYSQLI_ASSOC);

$filename = 'mbge-export-' . date('Y-m-d') . '.json';
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode(['exported_at' => date('c'), 'residents' => $residents, 'invitations' => $invitations, 'access_log' => $log], JSON_PRETTY_PRINT);
