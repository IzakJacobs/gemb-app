<?php
/**
 * pi/override_check.php — Gate override polling script (Pi side)
 *
 * Runs every 30 seconds via cron:
 *   * * * * * php /var/www/html/gemb/override_check.php >> /var/log/gemb_override.log 2>&1
 *   * * * * * sleep 30 && php /var/www/html/gemb/override_check.php >> /var/log/gemb_override.log 2>&1
 *
 * Polls cloud for pending gate overrides and executes them locally.
 */
require_once __DIR__ . '/config.php';

$ts  = date('Y-m-d H:i:s');
$log = function(string $msg) use ($ts) {
    echo "[{$ts}] {$msg}" . PHP_EOL;
};

// ── Poll cloud for pending overrides ──────────────────────
$apiUrl = rtrim(str_replace('pi_sync_api.php', '', PI_CLOUD_URL), '/')
        . '/pi_override_api.php';

$ch = curl_init($apiUrl . '?action=pending');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . PI_SYNC_KEY],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 8,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200) {
    $log("Cloud unreachable (HTTP {$http}) — skipping override check");
    exit(0);
}

$data      = json_decode($resp, true);
$overrides = $data['overrides'] ?? [];

if (empty($overrides)) {
    // No pending overrides — silent exit (don't flood log)
    exit(0);
}

$log(count($overrides) . " pending override(s) found");

foreach ($overrides as $ov) {
    $id   = (int)$ov['id'];
    $gate = $ov['gate'] ?? 'SSgate';
    $log("Processing override #{$id} — Gate: {$gate} — Officer: {$ov['officer_name']} — Reason: {$ov['reason']}");

    // ── Trigger gate (ESP32 on LAN) ───────────────────────
    $ts2     = time();
    $sig     = hash_hmac('sha256', $ts2 . PI_GATE_TOKEN, PI_GATE_TOKEN);
    $ch      = curl_init(PI_GATE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'token' => PI_GATE_TOKEN,
            'ts'    => $ts2,
            'sig'   => $sig,
        ]),
        CURLOPT_TIMEOUT        => PI_GATE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => PI_GATE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $gateResp = curl_exec($ch);
    $gateHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $gateErr  = curl_error($ch);
    curl_close($ch);

    $gateOk  = ($gateHttp === 200 && !$gateErr);
    $gateMsg = $gateErr ?: ($gateOk ? 'Opened' : "HTTP {$gateHttp}");
    $log("Gate trigger: " . ($gateOk ? "SUCCESS" : "FAILED — {$gateMsg}"));

    // ── Log to Pi SQLite ──────────────────────────────────
    try {
        $db      = piDb();
        $eventId = 'PI-OVR-' . strtoupper(substr(md5(uniqid('',true)), 0, 8));
        $db->prepare("
            INSERT INTO pi_access_log
              (event_id, gate, direction, entry_type, person_name,
               granted, deny_reason, notes)
            VALUES (?,?,?,?,?, ?,?,?)
        ")->execute([
            $eventId, $gate, 'ENTRY', 'override',
            $ov['officer_name'],
            $gateOk ? 1 : 0,
            $gateOk ? null : $gateMsg,
            '[REMOTE OVERRIDE] ' . $ov['reason'],
        ]);
    } catch (Exception $e) {
        $log("Pi log error: " . $e->getMessage());
    }

    // ── Report result back to cloud ───────────────────────
    $ch = curl_init($apiUrl . '?action=result');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'id'       => $id,
            'gate_ok'  => $gateOk ? '1' : '0',
            'gate_msg' => $gateMsg,
        ]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . PI_SYNC_KEY],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resultResp = curl_exec($ch);
    $resultHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resultHttp === 200) {
        $log("Override #{$id} result reported to cloud successfully");
    } else {
        $log("Override #{$id} result report failed (HTTP {$resultHttp})");
    }
}

$log("Override check complete");
