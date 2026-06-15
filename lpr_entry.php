<?php
/**
 * lpr_entry.php — Hikvision ANPR Entry Endpoint
 * ───────────────────────────────────────────────
 * Called by the Hikvision LPR camera when a vehicle is detected at the entry gate.
 * Parses the licence plate from the camera's HTTP event push, looks it up in the
 * residents table, opens the gate if recognised, and logs every attempt.
 *
 * ── Camera configuration ────────────────────────────────────────────────────────
 * In the Hikvision camera's web interface:
 *   Configuration → Network → Advanced Settings → HTTP Listening
 *   or: Configuration → Smart Event → ANPR → Upload Settings
 *
 *   Protocol:   HTTPS
 *   Host:       mbge.ink
 *   Port:       443
 *   URL:        /lpr_entry.php?key=<LPR_ENTRY_API_KEY>
 *   Method:     POST
 *   Data type:  XML  (or JSON — both are supported)
 *
 * ── Request formats accepted ────────────────────────────────────────────────────
 *  1. Hikvision XML event body  (Content-Type: application/xml or text/xml)
 *     <EventNotificationAlert>
 *       ...
 *       <ANPR><licensePlate>CBS75462</licensePlate>...</ANPR>
 *     </EventNotificationAlert>
 *
 *  2. Hikvision JSON event body  (Content-Type: application/json)
 *     {"ANPR":{"licensePlate":"CBS75462",...},...}
 *
 *  3. Plain POST/GET param  (for testing or custom integrations)
 *     plate=CBS75462&key=<LPR_ENTRY_API_KEY>
 *
 * ── Authentication ──────────────────────────────────────────────────────────────
 *  Append key=<LPR_ENTRY_API_KEY> to the URL query string.
 *  No HTTP Basic Auth is required (Hikvision can send Basic Auth but the API key
 *  approach is simpler and avoids credential storage on the camera).
 *
 * ── Response ────────────────────────────────────────────────────────────────────
 *  Always JSON.  HTTP 200 for accepted events (gate opened or denied — the camera
 *  only cares that it got a 200), 401 for bad API key.
 *
 *  {"ok":true,  "action":"gate_opened",    "resident":"J Smith","plate":"CBS75462"}
 *  {"ok":false, "action":"plate_not_found","plate":"XYZ000"}
 *  {"ok":false, "action":"auth_failed"}
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');

header('Content-Type: application/json; charset=utf-8');

// ── Auth ─────────────────────────────────────────────────────────────────────
$cfgKey = defined('LPR_ENTRY_API_KEY') ? LPR_ENTRY_API_KEY : '';
$apiKey = $_GET['key'] ?? $_POST['key'] ?? '';
if (!$cfgKey
    || $cfgKey === 'CHANGE_THIS_TO_A_STRONG_RANDOM_KEY'
    || !hash_equals($cfgKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'action' => 'auth_failed']);
    exit;
}

// ── Rate limit ───────────────────────────────────────────────────────────────
// Max 120 requests per minute per IP (LPR cameras can be chatty).
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
ensureLprEntryRateTable();
db()->prepare(
    "DELETE FROM rate_limit_lpr_entry WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
)->execute();
$rl = db()->prepare(
    "SELECT COUNT(*) AS cnt FROM rate_limit_lpr_entry WHERE ip = ? AND window_start >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
);
$rl->execute([$ip]);
if ((int)$rl->fetchColumn() >= 120) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'action' => 'rate_limited']);
    exit;
}
db()->prepare("INSERT INTO rate_limit_lpr_entry (ip) VALUES (?)")->execute([$ip]);

// ── Parse plate from request body ────────────────────────────────────────────
$rawBody     = file_get_contents('php://input');
$rawPlate    = '';
$direction   = '';
$confidence  = '';
$cameraIp    = $ip;
$eventType   = '';

if ($rawBody) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // ── Try XML (Hikvision standard format) ──────────────────────────────────
    if (str_contains($contentType, 'xml') || str_starts_with(trim($rawBody), '<')) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rawBody);
        if ($xml !== false) {
            // Plate location in Hikvision XML
            $rawPlate  = (string)($xml->ANPR->licensePlate
                      ?? $xml->anpr->licensePlate
                      ?? $xml->vehicleDetection->licensePlate
                      ?? '');
            $direction  = (string)($xml->ANPR->direction ?? '');
            $confidence = (string)($xml->ANPR->confidenceLevel ?? '');
            $cameraIp   = (string)($xml->ipAddress ?? $ip);
            $eventType  = (string)($xml->eventType ?? 'ANPR');
        }
    }

    // ── Try JSON (Hikvision alternate / custom format) ────────────────────────
    if (!$rawPlate && (str_contains($contentType, 'json') || str_starts_with(trim($rawBody), '{'))) {
        $json = json_decode($rawBody, true);
        if ($json) {
            $rawPlate  = $json['ANPR']['licensePlate']
                      ?? $json['anpr']['licensePlate']
                      ?? $json['licensePlate']
                      ?? $json['plate']
                      ?? '';
            $direction  = $json['ANPR']['direction'] ?? '';
            $confidence = $json['ANPR']['confidenceLevel'] ?? '';
            $cameraIp   = $json['ipAddress'] ?? $ip;
            $eventType  = $json['eventType'] ?? 'ANPR';
        }
    }
}

// ── Fall back to plain POST/GET param (testing / custom integrations) ─────────
if (!$rawPlate) {
    $rawPlate   = $_POST['plate'] ?? $_GET['plate'] ?? '';
    $direction  = $_POST['direction'] ?? $_GET['direction'] ?? 'entry';
    $confidence = $_POST['confidence'] ?? $_GET['confidence'] ?? '';
}

// ── Normalise plate ───────────────────────────────────────────────────────────
$plateClean   = strtoupper(preg_replace('/[^A-Z0-9]/', '', $rawPlate));
$plateDisplay = strtoupper(trim($rawPlate));

if (!$plateClean) {
    logLprEntryEvent('', '', 0, 'no_plate', $cameraIp, $rawPlate, $direction, $confidence, $eventType);
    echo json_encode(['ok' => false, 'action' => 'no_plate_detected']);
    exit;
}

// ── Look up resident ──────────────────────────────────────────────────────────
ensureResidentPlateColumn();

// NOTE: residents table columns are resident_name, resident_erfno,
// occupant_code, plate_number, phone — NOT full_name/unit_number/contact_number.
$stmt = db()->prepare(
    "SELECT id, resident_name, resident_erfno, occupant_code, plate_number, phone
     FROM residents
     WHERE UPPER(REPLACE(REPLACE(plate_number,' ',''),'-','')) = ?
       AND status = 'active'
     LIMIT 1"
);
$stmt->execute([$plateClean]);
$resident = $stmt->fetch();

if (!$resident) {
    logLprEntryEvent($plateClean, $plateDisplay, 0, 'plate_not_found', $cameraIp, $rawPlate, $direction, $confidence, $eventType);
    echo json_encode([
        'ok'     => false,
        'action' => 'plate_not_found',
        'plate'  => $plateDisplay,
    ]);
    exit;
}

$residentId   = (int)$resident['id'];
$residentName = $resident['resident_name'];
$unitNumber   = $resident['resident_erfno'] . $resident['occupant_code'];

// ── Trigger gate ──────────────────────────────────────────────────────────────
$gateUrl  = (defined('LPR_ENTRY_GATE_URL') && LPR_ENTRY_GATE_URL !== '')
            ? LPR_ENTRY_GATE_URL
            : (defined('GATE_ESP32_URL') ? GATE_ESP32_URL : '');
$gateMsg  = '';

if ($gateUrl) {
    $ctx = stream_context_create([
        'http' => [
            'timeout'        => defined('GATE_TIMEOUT_SEC') ? GATE_TIMEOUT_SEC : 5,
            'ignore_errors'  => true,
        ],
    ]);
    $gateResp = @file_get_contents($gateUrl, false, $ctx);
    $gateMsg  = ($gateResp !== false) ? trim($gateResp) : 'gate_unreachable';
} else {
    $gateMsg = 'no_gate_url_configured';
}

// ── Log the successful entry ───────────────────────────────────────────────────
logLprEntryEvent($plateClean, $plateDisplay, $residentId, 'gate_opened', $cameraIp, $rawPlate, $direction, $confidence, $eventType, $residentName);

// ── Optional: notify resident ────────────────────────────────────────────────
// (Uncomment and configure Twilio/SMS to send an arrival notification)
// notifyResidentArrival($resident, $plateDisplay);

echo json_encode([
    'ok'          => true,
    'action'      => 'gate_opened',
    'resident'    => $residentName,
    'unit'        => $unitNumber,
    'plate'       => $plateDisplay,
    'gate_msg'    => $gateMsg,
]);
exit;

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function ensureLprEntryRateTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS rate_limit_lpr_entry (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45) NOT NULL,
        window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_window (ip, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureResidentPlateColumn(): void {
    // Defensive: the column should already exist from residents_admin.php migrations,
    // but we re-run if needed so lpr_entry.php works standalone.
    try {
        db()->exec("ALTER TABLE residents ADD COLUMN plate_number VARCHAR(20) DEFAULT NULL");
    } catch (Throwable $e) {
        // Column already exists — ignore
    }
}

/**
 * Ensure the lpr_entry_log table exists, with all columns.
 * Uses ALTER TABLE to add columns missing from older installations.
 */
function ensureLprEntryLogTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS lpr_entry_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        plate_clean  VARCHAR(20)  NOT NULL,
        plate_raw    VARCHAR(50)  NOT NULL,
        resident_id  INT          NOT NULL DEFAULT 0,
        resident_name VARCHAR(255) NOT NULL DEFAULT '',
        status       VARCHAR(40)  NOT NULL,
        camera_ip    VARCHAR(45)  NOT NULL DEFAULT '',
        direction    VARCHAR(20)  NOT NULL DEFAULT '',
        confidence   VARCHAR(10)  NOT NULL DEFAULT '',
        event_type   VARCHAR(50)  NOT NULL DEFAULT '',
        logged_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_plate  (plate_clean),
        INDEX idx_logged (logged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate: add columns that may be missing from older installs
    $migrations = [
        "ALTER TABLE lpr_entry_log ADD COLUMN plate_raw     VARCHAR(50) NOT NULL DEFAULT '' AFTER plate_clean",
        "ALTER TABLE lpr_entry_log ADD COLUMN resident_name VARCHAR(255) NOT NULL DEFAULT '' AFTER resident_id",
        "ALTER TABLE lpr_entry_log ADD COLUMN camera_ip     VARCHAR(45) NOT NULL DEFAULT '' AFTER status",
        "ALTER TABLE lpr_entry_log ADD COLUMN direction     VARCHAR(20) NOT NULL DEFAULT '' AFTER camera_ip",
        "ALTER TABLE lpr_entry_log ADD COLUMN confidence    VARCHAR(10) NOT NULL DEFAULT '' AFTER direction",
        "ALTER TABLE lpr_entry_log ADD COLUMN event_type    VARCHAR(50) NOT NULL DEFAULT '' AFTER confidence",
    ];
    foreach ($migrations as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* already exists */ }
    }
}

function logLprEntryEvent(
    string $plateClean,
    string $plateRaw,
    int    $residentId,
    string $status,
    string $cameraIp    = '',
    string $rawPlate    = '',
    string $direction   = '',
    string $confidence  = '',
    string $eventType   = '',
    string $residentName = ''
): void {
    try {
        ensureLprEntryLogTable();
        db()->prepare(
            "INSERT INTO lpr_entry_log
             (plate_clean, plate_raw, resident_id, resident_name,
              status, camera_ip, direction, confidence, event_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $plateClean,
            $rawPlate ?: $plateRaw,
            $residentId,
            $residentName,
            $status,
            $cameraIp,
            $direction,
            $confidence,
            $eventType,
        ]);
    } catch (Throwable $e) {
        error_log('MBGE lpr_entry log error: ' . $e->getMessage());
    }
}