<?php
/**
 * pi/gate_relay.php — Remote gate override relay
 *
 * Called by the cloud (security.php) when a security officer
 * remotely opens a gate. Pi triggers the ESP32 on LAN and logs
 * the override event locally.
 *
 * POST fields:
 *   gate         — SSgate | CSgate
 *   reason       — mandatory reason text
 *   officer_name — security officer's name
 *   officer_id   — security officer's ID
 *
 * Auth: Authorization: Bearer <PI_SYNC_KEY>
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────
$provided = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!defined('PI_SYNC_KEY')
    || PI_SYNC_KEY === ''
    || !hash_equals('Bearer ' . PI_SYNC_KEY, $provided)) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST required']));
}

$gate        = in_array($_POST['gate'] ?? '', ['SSgate','CSgate'], true)
               ? $_POST['gate'] : 'SSgate';
$reason      = mb_substr(strip_tags(trim($_POST['reason']      ?? '')), 0, 255);
$officerName = mb_substr(strip_tags(trim($_POST['officer_name'] ?? '')), 0, 100);
$officerId   = filter_var($_POST['officer_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

if (!$reason) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Reason is required']));
}

// ── Trigger gate (ESP32) ──────────────────────────────────
$ts      = time();
$sig     = hash_hmac('sha256', $ts . PI_GATE_TOKEN, PI_GATE_TOKEN);
$ch      = curl_init(PI_GATE_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'token' => PI_GATE_TOKEN,
        'ts'    => $ts,
        'sig'   => $sig,
    ]),
    CURLOPT_TIMEOUT        => PI_GATE_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => PI_GATE_TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$resp    = curl_exec($ch);
$http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

$gateOk  = ($http === 200 && !$curlErr);
$gateMsg = $curlErr ?: ($gateOk ? 'Opened' : "HTTP {$http}");

// ── Log to Pi SQLite ──────────────────────────────────────
try {
    $db = piDb();
    $eventId = 'PI-OVR-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $db->prepare("
        INSERT INTO pi_access_log
          (event_id, gate, direction, entry_type, person_name,
           guard_id, granted, deny_reason, notes)
        VALUES (?, ?, 'ENTRY', 'override', ?, ?, ?, ?, ?)
    ")->execute([
        $eventId, $gate,
        $officerName,
        $officerId ?: null,
        $gateOk ? 1 : 0,
        $gateOk ? null : $gateMsg,
        '[REMOTE OVERRIDE] ' . $reason,
    ]);
} catch (Exception $e) {
    error_log('Pi gate_relay log error: ' . $e->getMessage());
}

echo json_encode([
    'ok'       => $gateOk,
    'gate_msg' => $gateMsg,
    'event_id' => $eventId ?? null,
]);
