<?php
/**
 * wiegand_gate.php — ZKTeco UHF5Pro Wiegand Tag-Reader Endpoint
 * ───────────────────────────────────────────────────────────────
 * Receives decoded Wiegand tag numbers from an ESP32 or Raspberry Pi
 * controller connected to the UHF5Pro's Wiegand D0/D1 output.
 * Looks up the tag in residents.tag_number and triggers the gate.
 *
 * ── Hardware wiring ─────────────────────────────────────────────────────────
 *  ZKTeco UHF5Pro (Wiegand slave output)
 *    DATA0 (D0) ──► ESP32 GPIO pin (e.g. GPIO 26, 3.3 V logic)
 *    DATA1 (D1) ──► ESP32 GPIO pin (e.g. GPIO 27, 3.3 V logic)
 *    GND        ──► Common GND
 *    12 V / GND ──► UHF5Pro power supply
 *
 *  The UHF5Pro outputs standard 26-bit or 34-bit Wiegand.
 *  The ESP32 decodes the pulse train and posts the hex/decimal tag
 *  number to this endpoint.
 *
 * ── ESP32 firmware ──────────────────────────────────────────────────────────
 *  Sample Arduino / ESP-IDF pseudocode (not part of this file):
 *
 *  void onTagRead(uint64_t tagId) {
 *      char url[256];
 *      snprintf(url, sizeof(url),
 *          "https://mbge.ink/wiegand_gate.php"
 *          "?action=gate&key=%s&tag=%llu&bits=%d",
 *          WIEGAND_API_KEY, tagId, wiegandBits);
 *      httpGet(url);   // or POST with body
 *  }
 *
 *  For tag enrollment (called from a separate admin trigger):
 *      POST action=enroll&key=<WIEGAND_API_KEY>&tag=<id>&resident_id=<id>&admin_key=<WIEGAND_API_KEY>
 *
 * ── Actions ─────────────────────────────────────────────────────────────────
 *  action=gate   (default)
 *    tag         = Wiegand tag decimal or hex value (required)
 *    bits        = Wiegand bit width, 26 or 34 (optional, for logging)
 *    key         = WIEGAND_API_KEY
 *
 *  action=enroll  (admin-only — assign a tag to a resident)
 *    tag         = tag value just read by the reader (required)
 *    resident_id = residents.id to assign this tag to (required)
 *    key         = WIEGAND_API_KEY
 *    admin_key   = WIEGAND_API_KEY (same key required again as a second factor)
 *
 *  action=status  (health check — returns last 10 access events)
 *    key         = WIEGAND_API_KEY
 *
 * ── Responses (JSON) ────────────────────────────────────────────────────────
 *  {"ok":true,  "action":"gate_opened",   "resident":"J Smith","tag":"00A1B2C3"}
 *  {"ok":false, "action":"tag_not_found", "tag":"DEADBEEF"}
 *  {"ok":false, "action":"auth_failed"}
 *  {"ok":true,  "action":"enrolled",      "resident":"J Smith","tag":"00A1B2C3"}
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');

header('Content-Type: application/json; charset=utf-8');

// ── Auth ─────────────────────────────────────────────────────────────────────
$cfgKey = defined('WIEGAND_API_KEY') ? WIEGAND_API_KEY : '';
$apiKey = $_GET['key'] ?? $_POST['key'] ?? '';
if (!$cfgKey
    || $cfgKey === 'CHANGE_THIS_TO_A_STRONG_RANDOM_KEY'
    || !hash_equals($cfgKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'action' => 'auth_failed']);
    exit;
}

// ── Rate limit ───────────────────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
ensureWiegandRateTable();
db()->prepare(
    "DELETE FROM rate_limit_wiegand WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
)->execute();
$rl = db()->prepare(
    "SELECT COUNT(*) FROM rate_limit_wiegand WHERE ip = ? AND window_start >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
);
$rl->execute([$ip]);
if ((int)$rl->fetchColumn() >= 60) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'action' => 'rate_limited']);
    exit;
}
db()->prepare("INSERT INTO rate_limit_wiegand (ip) VALUES (?)")->execute([$ip]);

// ── Parse params ─────────────────────────────────────────────────────────────
$action     = strtolower($_GET['action'] ?? $_POST['action'] ?? 'gate');
$rawTag     = trim($_GET['tag']  ?? $_POST['tag']  ?? '');
$bits       = (int)($_GET['bits'] ?? $_POST['bits'] ?? 0);  // 26 or 34

// Normalise tag: accept decimal or hex (0x prefix optional), store as uppercase hex
$tagNorm    = normaliseTag($rawTag);

if ($action !== 'status' && !$tagNorm) {
    echo json_encode(['ok' => false, 'action' => 'no_tag_provided']);
    exit;
}

// ── Route actions ─────────────────────────────────────────────────────────────
switch ($action) {

    // ── Gate open ─────────────────────────────────────────────────────────────
    case 'gate':
        handleGate($tagNorm, $rawTag, $bits, $ip);
        break;

    // ── Tag enrollment (admin assigns tag to a resident) ──────────────────────
    case 'enroll':
        handleEnroll($tagNorm, $rawTag, $ip);
        break;

    // ── Status / health check ─────────────────────────────────────────────────
    case 'status':
        handleStatus();
        break;

    default:
        echo json_encode(['ok' => false, 'action' => 'unknown_action']);
}
exit;

// =============================================================================
// ACTION HANDLERS
// =============================================================================

function handleGate(string $tagNorm, string $rawTag, int $bits, string $ip): void
{
    ensureResidentTagColumn();

    // Look up resident by tag_number.
    // tag_number is stored as the normalised uppercase hex value.
    $stmt = db()->prepare(
        "SELECT id, full_name, unit_number, contact_number
         FROM residents
         WHERE UPPER(TRIM(tag_number)) = ?
           AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$tagNorm]);
    $resident = $stmt->fetch();

    if (!$resident) {
        logWiegandEvent($tagNorm, $rawTag, 0, '', 'tag_not_found', $ip, $bits);
        echo json_encode([
            'ok'     => false,
            'action' => 'tag_not_found',
            'tag'    => $tagNorm,
        ]);
        return;
    }

    $residentId   = (int)$resident['id'];
    $residentName = $resident['full_name'];
    $unitNumber   = $resident['unit_number'];

    // ── Trigger gate ──────────────────────────────────────────────────────────
    $gateUrl = (defined('WIEGAND_GATE_URL') && WIEGAND_GATE_URL !== '')
               ? WIEGAND_GATE_URL
               : (defined('GATE_ESP32_URL') ? GATE_ESP32_URL : '');
    $gateMsg = '';

    if ($gateUrl) {
        $ctx = stream_context_create([
            'http' => [
                'timeout'       => defined('GATE_TIMEOUT_SEC') ? GATE_TIMEOUT_SEC : 5,
                'ignore_errors' => true,
            ],
        ]);
        $resp    = @file_get_contents($gateUrl, false, $ctx);
        $gateMsg = ($resp !== false) ? trim($resp) : 'gate_unreachable';
    } else {
        $gateMsg = 'no_gate_url_configured';
    }

    logWiegandEvent($tagNorm, $rawTag, $residentId, $residentName, 'gate_opened', $ip, $bits);

    echo json_encode([
        'ok'       => true,
        'action'   => 'gate_opened',
        'resident' => $residentName,
        'unit'     => $unitNumber,
        'tag'      => $tagNorm,
        'gate_msg' => $gateMsg,
    ]);
}

function handleEnroll(string $tagNorm, string $rawTag, string $ip): void
{
    // Enrollment requires admin_key as a second factor (same key value, sent
    // separately so accidental GET requests can't silently enroll tags).
    $adminKey = $_GET['admin_key'] ?? $_POST['admin_key'] ?? '';
    $cfgKey   = defined('WIEGAND_API_KEY') ? WIEGAND_API_KEY : '';
    if (!hash_equals($cfgKey, $adminKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'action' => 'admin_auth_failed']);
        return;
    }

    $residentId = (int)($_GET['resident_id'] ?? $_POST['resident_id'] ?? 0);
    if ($residentId <= 0) {
        echo json_encode(['ok' => false, 'action' => 'missing_resident_id']);
        return;
    }

    ensureResidentTagColumn();

    $stmt = db()->prepare("SELECT id, full_name FROM residents WHERE id = ? LIMIT 1");
    $stmt->execute([$residentId]);
    $resident = $stmt->fetch();
    if (!$resident) {
        echo json_encode(['ok' => false, 'action' => 'resident_not_found']);
        return;
    }

    // Check the tag is not already assigned to someone else
    $chk = db()->prepare(
        "SELECT id, full_name FROM residents WHERE UPPER(TRIM(tag_number)) = ? AND id != ? LIMIT 1"
    );
    $chk->execute([$tagNorm, $residentId]);
    $conflict = $chk->fetch();
    if ($conflict) {
        logWiegandEvent($tagNorm, $rawTag, $residentId, $resident['full_name'], 'enroll_conflict', $ip, 0);
        echo json_encode([
            'ok'       => false,
            'action'   => 'tag_already_assigned',
            'tag'      => $tagNorm,
            'assigned_to' => $conflict['full_name'],
        ]);
        return;
    }

    // Assign tag
    db()->prepare("UPDATE residents SET tag_number = ? WHERE id = ?")->execute([$tagNorm, $residentId]);

    logWiegandEvent($tagNorm, $rawTag, $residentId, $resident['full_name'], 'enrolled', $ip, 0);

    echo json_encode([
        'ok'       => true,
        'action'   => 'enrolled',
        'resident' => $resident['full_name'],
        'tag'      => $tagNorm,
    ]);
}

function handleStatus(): void
{
    ensureWiegandLogTable();
    $rows = db()->query(
        "SELECT tag_norm, resident_name, status, logged_at
         FROM wiegand_access_log
         ORDER BY logged_at DESC
         LIMIT 10"
    )->fetchAll();

    echo json_encode(['ok' => true, 'action' => 'status', 'recent' => $rows]);
}

// =============================================================================
// UTILITY FUNCTIONS
// =============================================================================

/**
 * Normalise a Wiegand tag value to uppercase hex (no 0x prefix).
 * Accepts decimal integers or hex strings (with or without 0x prefix).
 * Returns empty string if the input is not a valid tag.
 */
function normaliseTag(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    // Hex string with optional 0x prefix
    if (preg_match('/^0x([0-9A-Fa-f]+)$/i', $raw, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/^[0-9A-Fa-f]{4,16}$/i', $raw)) {
        return strtoupper($raw);
    }
    // Decimal integer — convert to hex
    if (ctype_digit($raw)) {
        return strtoupper(dechex((int)$raw));
    }
    return '';
}

function ensureWiegandRateTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS rate_limit_wiegand (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45) NOT NULL,
        window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_window (ip, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureResidentTagColumn(): void {
    try {
        db()->exec("ALTER TABLE residents ADD COLUMN tag_number VARCHAR(50) DEFAULT NULL");
    } catch (Throwable $e) {
        // Already exists — ignore
    }
}

function ensureWiegandLogTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS wiegand_access_log (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tag_norm      VARCHAR(32)  NOT NULL,
        tag_raw       VARCHAR(64)  NOT NULL DEFAULT '',
        resident_id   INT          NOT NULL DEFAULT 0,
        resident_name VARCHAR(255) NOT NULL DEFAULT '',
        status        VARCHAR(40)  NOT NULL,
        ip            VARCHAR(45)  NOT NULL DEFAULT '',
        wiegand_bits  TINYINT      NOT NULL DEFAULT 0,
        logged_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tag    (tag_norm),
        INDEX idx_logged (logged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $migrations = [
        "ALTER TABLE wiegand_access_log ADD COLUMN tag_raw       VARCHAR(64)  NOT NULL DEFAULT '' AFTER tag_norm",
        "ALTER TABLE wiegand_access_log ADD COLUMN resident_name VARCHAR(255) NOT NULL DEFAULT '' AFTER resident_id",
        "ALTER TABLE wiegand_access_log ADD COLUMN ip            VARCHAR(45)  NOT NULL DEFAULT '' AFTER status",
        "ALTER TABLE wiegand_access_log ADD COLUMN wiegand_bits  TINYINT      NOT NULL DEFAULT 0  AFTER ip",
    ];
    foreach ($migrations as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* already exists */ }
    }
}

function logWiegandEvent(
    string $tagNorm,
    string $tagRaw,
    int    $residentId,
    string $residentName,
    string $status,
    string $ip,
    int    $bits = 0
): void {
    try {
        ensureWiegandLogTable();
        db()->prepare(
            "INSERT INTO wiegand_access_log
             (tag_norm, tag_raw, resident_id, resident_name, status, ip, wiegand_bits)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$tagNorm, $tagRaw, $residentId, $residentName, $status, $ip, $bits]);
    } catch (Throwable $e) {
        error_log('MBGE wiegand log error: ' . $e->getMessage());
    }
}
