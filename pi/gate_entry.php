<?php
/**
 * pi/gate_entry.php — LPR/tag device entry trigger
 *
 * Called by ESP32 LPR devices when a legal resident is identified.
 * Logs the entry and forwards trigger to the gate ESP32.
 *
 * POST fields:
 *   device  — device ID (e.g. ss1)
 *   gate    — SSgate | CSgate
 *   token   — must match PI_GATE_TOKEN
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST required']));
}

// ── IP allowlist — only LPR/tag ESP32 devices ─────────────
$allowedIps = ['192.168.0.90'];
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    error_log("gate_entry.php: blocked request from {$clientIp}");
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

// ── Auth ──────────────────────────────────────────────────
$token = $_POST['token'] ?? '';
if (!hash_equals(PI_GATE_TOKEN, $token)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$device = mb_substr(strip_tags(trim($_POST['device'] ?? 'unknown')), 0, 50);
$gate   = in_array($_POST['gate'] ?? '', ['SSgate', 'CSgate'], true)
          ? $_POST['gate'] : 'SSgate';

// ── Trigger gate (ESP32 #1) ───────────────────────────────
$ts  = time();
$sig = hash_hmac('sha256', $ts . PI_GATE_TOKEN, PI_GATE_TOKEN);
$ch  = curl_init(PI_GATE_URL);
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
]);
$resp    = curl_exec($ch);
$http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

$gateOk  = ($http === 200 && !$curlErr);
$gateMsg = $curlErr ?: ($gateOk ? 'Opened' : "HTTP {$http}");

// ── Log to Pi SQLite ──────────────────────────────────────
try {
    $db      = piDb();
    $eventId = 'LPR-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $db->prepare("
        INSERT INTO pi_access_log
          (event_id, gate, direction, entry_type, person_name,
           granted, deny_reason, notes)
        VALUES (?, ?, 'ENTRY', 'lpr', ?, ?, ?, ?)
    ")->execute([
        $eventId,
        $gate,
        'LPR Resident',
        $gateOk ? 1 : 0,
        $gateOk ? null : $gateMsg,
        '[LPR] Device: ' . $device,
    ]);
} catch (Exception $e) {
    error_log('Pi gate_entry log error: ' . $e->getMessage());
}

echo json_encode([
    'ok'       => $gateOk,
    'gate_msg' => $gateMsg,
    'event_id' => $eventId ?? null,
]);
