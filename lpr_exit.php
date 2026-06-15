<?php
/**
 * lpr_exit.php — LPR Camera Exit Endpoint
 * ─────────────────────────────────────────
 * Called by the LPR camera system when a vehicle is detected at the exit gate.
 * Looks up the plate in active_vehicle_visits, validates the visit, opens the gate.
 * If no active visitor visit is found, falls back to checking whether the plate
 * belongs to a registered resident — residents are always permitted to exit.
 *
 * Authentication: shared API key via LPR_API_KEY in config.php
 *
 * Request (GET or POST):
 *   plate   = vehicle registration number (required)
 *   key     = LPR_API_KEY (required)
 *   gate    = gate identifier, e.g. "exit" (optional, for logging)
 *
 * Response: JSON
 *   { "ok": true,  "action": "gate_opened",  "visitor": "John Smith", "plate": "CA 123 ABC" }
 *   { "ok": true,  "action": "gate_opened",  "resident": "J Smith", "unit": "E15227A", "plate": "CA 123 ABC" }
 *   { "ok": false, "action": "no_active_visit", "plate": "CA 999 ZZZ" }
 *   { "ok": false, "action": "auth_failed" }
 *
 * Config required in config.php:
 *   define('LPR_API_KEY',    'your-strong-secret-key');
 *   define('GATE_ESP32_URL', 'http://192.168.x.x/open');   // same as entry gate or separate exit URL
 *   define('LPR_EXIT_GATE_URL', 'http://192.168.x.x/open'); // optional: separate exit gate controller
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');

header('Content-Type: application/json; charset=utf-8');

// ── Auth — API key ─────────────────────────────────────────
$apiKey = trim($_REQUEST['key'] ?? '');
$cfgKey = defined('LPR_API_KEY') ? LPR_API_KEY : '';

if (!$cfgKey || !hash_equals($cfgKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'action' => 'auth_failed']);
    exit;
}

// ── Rate limit — 60 requests/minute per IP ────────────────
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipHash = hash('sha256', $ip);
try {
    db()->exec("CREATE TABLE IF NOT EXISTS rate_limit_lpr (
        id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash  VARCHAR(64) NOT NULL,
        hit_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx (ip_hash, hit_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cnt = db()->prepare(
        "SELECT COUNT(*) FROM rate_limit_lpr
         WHERE ip_hash = ? AND hit_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
    );
    $cnt->execute([$ipHash]);
    if ((int)$cnt->fetchColumn() > 60) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'action' => 'rate_limited']);
        exit;
    }
    db()->prepare("INSERT INTO rate_limit_lpr (ip_hash) VALUES (?)")->execute([$ipHash]);
    db()->exec("DELETE FROM rate_limit_lpr WHERE hit_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
} catch (Exception $e) { /* fail open */ }

// ── Input ─────────────────────────────────────────────────
$rawPlate  = strtoupper(trim($_REQUEST['plate'] ?? ''));
$plate     = preg_replace('/[^A-Z0-9]/', '', $rawPlate);  // clean for DB lookup
$gateLabel = preg_replace('/[^\w\- ]/', '', $_REQUEST['gate'] ?? 'exit');

if (strlen($plate) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'action' => 'invalid_plate']);
    exit;
}

// ── Look up active visit ───────────────────────────────────
$visit = null;
try {
    $stmt = db()->prepare("
        SELECT * FROM active_vehicle_visits
        WHERE plate_clean = ?
          AND exited_at  IS NULL
          AND valid_until >= CURDATE()
        ORDER BY entered_at DESC
        LIMIT 1
    ");
    $stmt->execute([$plate]);
    $visit = $stmt->fetch();
} catch (Exception $e) {
    error_log('MBGE lpr_exit lookup: ' . $e->getMessage());
}

if (!$visit) {
    // Not a registered visitor visit — check if this is a resident's
    // own vehicle (residents always permitted, no visit record needed).
    $resident = null;
    try {
        $rstmt = db()->prepare(
            "SELECT id, resident_name, resident_erfno, occupant_code
             FROM residents
             WHERE UPPER(REPLACE(REPLACE(plate_number,' ',''),'-','')) = ?
               AND status = 'active'
             LIMIT 1"
        );
        $rstmt->execute([$plate]);
        $resident = $rstmt->fetch();
    } catch (Exception $e) {
        error_log('MBGE lpr_exit resident lookup: ' . $e->getMessage());
    }

    if ($resident) {
        $gateResult = triggerExitGate();
        $unitNumber = $resident['resident_erfno'] . $resident['occupant_code'];

        logLprEvent(
            $plate, $rawPlate,
            $gateResult['ok'] ? 'resident_exit_gate_opened' : 'resident_exit_gate_failed',
            $gateLabel,
            'Resident: ' . $resident['resident_name'] . ' (' . $unitNumber . '). ' . $gateResult['msg']
        );

        echo json_encode([
            'ok'       => $gateResult['ok'],
            'action'   => $gateResult['ok'] ? 'gate_opened' : 'gate_trigger_failed',
            'resident' => $resident['resident_name'],
            'unit'     => $unitNumber,
            'plate'    => $rawPlate,
            'gate_msg' => $gateResult['msg'],
        ]);
        exit;
    }

    // Not a registered visitor or resident — log the attempt and deny
    logLprEvent($plate, $rawPlate, 'denied_no_visit', $gateLabel, 'No active visit or resident match found');
    echo json_encode([
        'ok'     => false,
        'action' => 'no_active_visit',
        'plate'  => $rawPlate,
    ]);
    exit;
}

// ── Open the exit gate ─────────────────────────────────────
$gateResult = triggerExitGate();

// ── Mark visit as exited ───────────────────────────────────
try {
    db()->prepare(
        "UPDATE active_vehicle_visits SET exited_at = NOW() WHERE id = ?"
    )->execute([(int)$visit['id']]);
} catch (Exception $e) {
    error_log('MBGE lpr_exit update: ' . $e->getMessage());
}

// ── Log the exit event ─────────────────────────────────────
logLprEvent(
    $plate, $rawPlate,
    $gateResult['ok'] ? 'exit_gate_opened' : 'exit_gate_failed',
    $gateLabel,
    'Visitor: ' . ($visit['visitor_name'] ?? '') . '. ' . $gateResult['msg']
);

// ── Notify resident (visitor has left) ────────────────────
try {
    $resEmail = getResEmailByCode($visit['visit_code'] ?? '');
    if ($resEmail) {
        require_once __DIR__ . '/twilio_helper.php';
        notifyResidentExit(
            $resEmail,
            $visit['visitor_name'] ?? 'Visitor',
            'visitor',
            'MBGE Exit Gate'
        );
    }
} catch (Exception $e) {}

echo json_encode([
    'ok'          => $gateResult['ok'],
    'action'      => $gateResult['ok'] ? 'gate_opened' : 'gate_trigger_failed',
    'visitor'     => $visit['visitor_name'] ?? '',
    'plate'       => $visit['plate_display'] ?? $rawPlate,
    'valid_until' => $visit['valid_until']   ?? '',
    'gate_msg'    => $gateResult['msg'],
]);
exit;

// ══════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════

function triggerExitGate(): array {
    // Use a dedicated exit gate URL if configured, otherwise fall back to entry gate URL
    $url     = defined('LPR_EXIT_GATE_URL') ? LPR_EXIT_GATE_URL
             : (defined('GATE_ESP32_URL')   ? GATE_ESP32_URL : '');
    $token   = defined('GATE_ESP32_TOKEN') ? GATE_ESP32_TOKEN : '';
    $timeout = defined('GATE_TIMEOUT_SEC') ? (int)GATE_TIMEOUT_SEC : 5;

    if (!$url) return ['ok' => false, 'msg' => 'Exit gate controller not configured'];

    $timestamp = time();
    $sig       = hash_hmac('sha256', $timestamp . $token, $token);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'token' => $token, 'ts' => $timestamp, 'sig' => $sig,
        ]),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)          return ['ok' => false, 'msg' => 'cURL: ' . $err];
    if ($http === 200) return ['ok' => true,  'msg' => 'Exit gate opened'];
    return ['ok' => false, 'msg' => "HTTP {$http}"];
}

function logLprEvent(string $plateClean, string $plateRaw,
                     string $status, string $gate, string $note): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS lpr_exit_log (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            plate_clean  VARCHAR(20)  NOT NULL,
            plate_raw    VARCHAR(25)  NOT NULL DEFAULT '',
            status       VARCHAR(40)  NOT NULL,
            gate         VARCHAR(50)  NOT NULL DEFAULT '',
            note         VARCHAR(255) NOT NULL DEFAULT '',
            logged_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plate (plate_clean),
            INDEX idx_time  (logged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->prepare(
            "INSERT INTO lpr_exit_log (plate_clean, plate_raw, status, gate, note)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$plateClean, $plateRaw, $status, $gate, $note]);
    } catch (Exception $e) {
        error_log('MBGE logLprEvent: ' . $e->getMessage());
    }
}

function getResEmailByCode(string $code): string {
    try {
        $stmt = db()->prepare("
            SELECT r.email
            FROM visitors v
            JOIN residents r ON UPPER(r.resident_erfno) = UPPER(v.resident_erfno)
                             AND r.is_primary = 1
            WHERE v.code = ? LIMIT 1
        ");
        $stmt->execute([$code]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}