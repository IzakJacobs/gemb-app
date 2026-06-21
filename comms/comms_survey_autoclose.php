<?php
// ============================================================
// comms_survey_autoclose.php — GEMB Communications Portal
//
// Scheduled maintenance: auto-closes any survey that has been
// 'active' for 30+ days without being closed manually, and
// purges its device_response_locks rows.
//
// type='survey' scoping is critical — device_response_locks
// also holds 'vote' rows (one-per-device lock for online
// votes), which must NEVER be touched by this script. Votes
// keep their full audit trail indefinitely, per policy.
//
// "30 days active" is measured from updated_at, since that's
// set the moment a survey transitions draft → active (and
// nothing else touches an active survey's updated_at except
// this script or a manual close).
//
// Intended to run via cPanel Cron Job, CLI only — not reachable
// over HTTP:
//   php /home/gembcoza/public_html/comms/comms_survey_autoclose.php
// ============================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/cfg.php';

$stmt = db()->prepare(
    "SELECT id, title FROM surveys
     WHERE status = 'active' AND updated_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
$stmt->execute();
$expired = $stmt->fetchAll();

if (!$expired) {
    echo date('Y-m-d H:i:s') . " — no surveys due for auto-close.\n";
    exit;
}

foreach ($expired as $s) {
    db()->prepare(
        "UPDATE surveys SET status='closed', updated_at=NOW() WHERE id=? AND status='active'"
    )->execute([$s['id']]);

    db()->prepare(
        "DELETE FROM device_response_locks WHERE type='survey' AND target_id=?"
    )->execute([$s['id']]);

    echo date('Y-m-d H:i:s') . " — auto-closed survey #{$s['id']} (\"{$s['title']}\") and purged its device locks.\n";
}
