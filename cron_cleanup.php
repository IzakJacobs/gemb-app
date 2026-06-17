<?php
// ============================================================
// GEMB Access Control — cron_cleanup.php
// Run nightly via cPanel cron: 0 2 * * * php /path/to/cron_cleanup.php
// Or test via browser: cron_cleanup.php?cron_key=your-key
// ============================================================
require_once __DIR__ . '/config.php';

// CLI or valid key required
$isCli    = (PHP_SAPI === 'cli');
$cronKey  = db()->query("SELECT setting_value FROM settings WHERE setting_key='cron_key'")->fetchColumn();
$keyValid = isset($_GET['cron_key']) && $_GET['cron_key'] === $cronKey;

if (!$isCli && !$keyValid) {
    http_response_code(403);
    die('Forbidden.');
}

$retentionDays = (int)(db()->query("SELECT setting_value FROM settings WHERE setting_key='log_retention_days'")->fetchColumn() ?: 90);

$results = [];

// Delete expired visitors
$stmt = db()->prepare("DELETE FROM visitors WHERE visit_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$results[] = "Visitors deleted: " . $stmt->rowCount();

// Mark overdue visitors as expired
$stmt = db()->prepare("UPDATE visitors SET status='expired' WHERE status='active' AND valid_until < NOW()");
$stmt->execute();
$results[] = "Visitors expired: " . $stmt->rowCount();

// Delete old service providers
$stmt = db()->prepare("DELETE FROM service_providers WHERE valid_until < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$results[] = "Service providers deleted: " . $stmt->rowCount();

// Mark overdue SPs as expired
$stmt = db()->prepare("UPDATE service_providers SET status='expired' WHERE status='active' AND valid_until < CURDATE()");
$stmt->execute();
$results[] = "Service providers expired: " . $stmt->rowCount();

// Delete old access logs
$stmt = db()->prepare("DELETE FROM access_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$results[] = "Access log rows deleted: " . $stmt->rowCount();

// Delete old notifications
$stmt = db()->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
$stmt->execute();
$results[] = "Notifications deleted: " . $stmt->rowCount();

// Clear active_sessions older than 24h (stale records)
$stmt = db()->prepare("DELETE FROM active_sessions WHERE entered_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute();
$results[] = "Stale active sessions cleared: " . $stmt->rowCount();

$log = date('Y-m-d H:i:s') . " — Cron cleanup complete.\n" . implode("\n", $results) . "\n";

if ($isCli) {
    echo $log;
} else {
    header('Content-Type: text/plain');
    echo $log;
}
