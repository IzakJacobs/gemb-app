<?php
/**
 * pi_notify_api.php — Pi notification relay (cloud side)
 *
 * Called by the Pi after a successful gate grant.
 * Looks up the resident email and sends an entry/exit notification.
 *
 * POST fields:
 *   resident_erfno  — erf number to look up resident email
 *   visitor_name    — name of visitor/SP
 *   entry_type      — visitor | service_provider | resident
 *   direction       — ENTRY | EXIT
 *   gate_label      — human-readable gate name
 *   timestamp       — local time string
 *
 * Auth: Authorization: Bearer <PI_SYNC_KEY>
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/twilio_helper.php';
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

$erfno      = trim($_POST['resident_erfno'] ?? '');
$visName    = trim($_POST['visitor_name']   ?? '');
$entryType  = trim($_POST['entry_type']     ?? 'visitor');
$direction  = strtoupper(trim($_POST['direction'] ?? 'ENTRY'));
$gateLabel  = trim($_POST['gate_label']    ?? 'GEMB Gate');
$timestamp  = trim($_POST['timestamp']     ?? date('d M Y H:i'));

if (!$erfno || !$visName) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing required fields']));
}

// ── Look up resident email ─────────────────────────────────
$stmt = db()->prepare(
    "SELECT email, resident_name FROM residents
     WHERE UPPER(resident_erfno) = UPPER(?) AND is_primary = 1
     LIMIT 1"
);
$stmt->execute([$erfno]);
$resident = $stmt->fetch();

if (!$resident || empty($resident['email'])) {
    exit(json_encode(['sent' => false, 'reason' => 'No email found for erf ' . $erfno]));
}

$email = $resident['email'];

// ── Send notification ──────────────────────────────────────
try {
    if ($direction === 'EXIT') {
        notifyResidentExit($email, $visName, $entryType, $gateLabel);
    } else {
        notifyResidentEntry($email, $visName, $entryType, $gateLabel, $timestamp);
    }
    echo json_encode(['sent' => true, 'to' => $email]);
} catch (Exception $e) {
    error_log('pi_notify_api error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sent' => false, 'error' => $e->getMessage()]);
}
