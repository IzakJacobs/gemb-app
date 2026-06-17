<?php
/**
 * pi_update_pin_api.php — Pi guard PIN update relay (cloud side)
 *
 * Called by the Pi after a guard sets a new PIN on a new device.
 * Updates the guard's PIN hash in the cloud database.
 *
 * POST fields:
 *   guard_id   — guard ID
 *   pin_hash   — bcrypt hash of the new PIN
 *
 * Auth: Authorization: Bearer <PI_SYNC_KEY>
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────
$provided = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!defined('PI_SYNC_KEY')
    || PI_SYNC_KEY === ''
    || !hash_equals('Bearer ' . PI_SYNC_KEY, $provided)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'POST required']));
}

$guardId = filter_var($_POST['guard_id'] ?? 0, FILTER_VALIDATE_INT);
$pinHash = trim($_POST['pin_hash'] ?? '');

if (!$guardId || !$pinHash) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing guard_id or pin_hash']));
}

// Validate it's a bcrypt hash
if (strlen($pinHash) < 60 || $pinHash[0] !== '$') {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid pin_hash format']));
}

try {
    db()->prepare(
        "UPDATE guards SET pin = ?, device_token = NULL WHERE id = ?"
    )->execute([$pinHash, $guardId]);

    exit(json_encode(['updated' => true]));
} catch (Exception $e) {
    error_log('pi_update_pin_api error: ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['error' => $e->getMessage()]));
}
