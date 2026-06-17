<?php
// Simple JSON endpoint for guard access log (guard session required)
require_once __DIR__ . '/db.php';
sessionStart();
requireGuard();

header('Content-Type: application/json');

$date   = preg_replace('/[^0-9\-]/', '', $_GET['date'] ?? '');
$action = in_array($_GET['action_filter'] ?? '', ['granted','denied']) ? $_GET['action_filter'] : '';

$where = []; $params = []; $types = '';
if ($date)   { $where[] = 'DATE(logged_at) = ?'; $params[] = $date;   $types .= 's'; }
if ($action) { $where[] = 'action = ?';           $params[] = $action; $types .= 's'; }

$sql  = 'SELECT * FROM access_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY logged_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['ok' => true, 'entries' => $rows]);
