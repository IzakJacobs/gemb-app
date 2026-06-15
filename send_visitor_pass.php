<?php
/**
 * send_visitor_pass.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Sends the visitor pass URL to the visitor via:
 *   • WhatsApp  (Twilio WhatsApp sandbox  OR  WA Business API — see config)
 *   • SMS       (Twilio SMS  OR  BulkSMS South Africa)
 *
 * Called after a visitor record + QR have been created:
 *   POST  send_visitor_pass.php
 *   Body: visitor_id=42&channel=whatsapp   (channel: whatsapp | sms | both)
 *
 * Returns JSON: { "ok": true, "sent": ["whatsapp"] }
 *
 * ─── CONFIGURATION ──────────────────────────────────────────────────────────
 * Add the following constants to config.php (or a secrets file):
 *
 *   // Twilio (used for both WhatsApp and SMS)
 *   define('TWILIO_SID',        'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
 *   define('TWILIO_AUTH_TOKEN', 'your_auth_token');
 *   define('TWILIO_FROM_SMS',   '+27XXXXXXXXXX');     // your Twilio SMS number
 *   define('TWILIO_FROM_WA',    'whatsapp:+14155238886'); // sandbox OR your approved WA number
 *
 *   // BulkSMS (alternative SMS gateway — South Africa, cheaper)
 *   define('BULKSMS_API_TOKEN', 'your_bulksms_token');
 *   define('BULKSMS_FROM',      'MBGE');              // alphanumeric sender (max 11 chars)
 *
 *   // Choose gateway: 'twilio' | 'bulksms'
 *   define('SMS_GATEWAY',  'bulksms');
 *   define('WA_GATEWAY',   'twilio');                 // currently only twilio supported
 *
 *   define('MBGE_HOST',    'https://mbge.ink');
 * ─────────────────────────────────────────────────────────────────────────────
 */

session_start();
require_once __DIR__ . '/config.php';
// Note: uses $conn (MySQLi) for visitor lookup — config.php provides both PDO db() and MySQLi $conn

header('Content-Type: application/json');

/* ── auth ── */
// Auth check — supports both admin and resident sessions
if (empty($_SESSION['admin_id']) && empty($_SESSION['resident_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

/* ── CSRF verification ── */
verifyCsrfToken();

/* ── input ── */
$visitorId = filter_input(INPUT_POST, 'visitor_id', FILTER_VALIDATE_INT);
$channel   = trim($_POST['channel'] ?? 'both');   // whatsapp | sms | both

if (!$visitorId) {
    echo json_encode(['ok' => false, 'error' => 'Missing visitor_id']);
    exit;
}

/* ── fetch visitor ── */
$stmt = $conn->prepare("
    SELECT v.*, r.resident_name
    FROM visitors v
    LEFT JOIN residents r ON v.resident_erfno = r.resident_erfno
    WHERE v.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $visitorId);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$v) {
    echo json_encode(['ok' => false, 'error' => 'Visitor not found']);
    exit;
}

/* ── build message ── */
$host    = defined('MBGE_HOST') ? MBGE_HOST : 'https://mbge.ink';
$passUrl = $host . '/visitor_qr.php?code=' . urlencode($v['code']);

$fromDate = date('d M Y', strtotime($v['visit_date']));
$toDate   = date('d M Y', strtotime($v['visit_date_to']));
$resident = $v['resident_name'] ?? $v['resident_name'] ?? 'a resident';

$message = "🏡 *MBGE Visitor Pass*\n"
         . "Hi {$v['visitor_name']},\n\n"
         . "Your access pass for Mossel Bay Golf Estate is ready.\n\n"
         . "📅 Valid: {$fromDate} – {$toDate}\n"
         . "🏠 Visiting: {$resident}\n\n"
         . "👇 Tap the link below at the gate:\n"
         . $passUrl . "\n\n"
         . "Show this screen to the guard. The guard will scan your QR or enter "
         . "code *{$v['code']}*.\n\n"
         . "_MBGE HOA | POPIA Act 4 of 2013_";

/* normalise phone: strip spaces, ensure +27 prefix */
$rawPhone = preg_replace('/\s+/', '', $v['visitor_phone'] ?? '');
if (substr($rawPhone, 0, 1) === '0') {
    $rawPhone = '+27' . substr($rawPhone, 1);
}
if (!preg_match('/^\+27\d{9}$/', $rawPhone)) {
    echo json_encode(['ok' => false, 'error' => "Invalid phone number: {$rawPhone}"]);
    exit;
}

$sent   = [];
$errors = [];

/* ════════════════════════════════════════════════════════════════════════════
   WHATSAPP  (Twilio)
   ════════════════════════════════════════════════════════════════════════════ */
if (in_array($channel, ['whatsapp', 'both']) && defined('WA_GATEWAY') && WA_GATEWAY === 'twilio') {
    $result = twilioSend(
        'whatsapp:' . $rawPhone,
        defined('TWILIO_FROM_WA') ? TWILIO_FROM_WA : '',
        $message
    );
    if ($result['ok']) {
        $sent[] = 'whatsapp';
        // log
        logDispatch($conn, $visitorId, 'whatsapp', $rawPhone, 'sent', $result['sid'] ?? '');
    } else {
        $errors[] = 'WhatsApp: ' . $result['error'];
        logDispatch($conn, $visitorId, 'whatsapp', $rawPhone, 'failed', $result['error']);
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   SMS
   ════════════════════════════════════════════════════════════════════════════ */
if (in_array($channel, ['sms', 'both'])) {
    $gateway = defined('SMS_GATEWAY') ? SMS_GATEWAY : 'bulksms';

    // Plain-text version for SMS (strip markdown asterisks)
    $smsText = preg_replace('/\*([^*]+)\*/', '$1', $message);

    if ($gateway === 'bulksms') {
        $result = bulkSmsSend($rawPhone, $smsText);
    } else {
        $result = twilioSend($rawPhone, defined('TWILIO_FROM_SMS') ? TWILIO_FROM_SMS : '', $smsText);
    }

    if ($result['ok']) {
        $sent[] = 'sms';
        logDispatch($conn, $visitorId, 'sms', $rawPhone, 'sent', $result['sid'] ?? '');
    } else {
        $errors[] = 'SMS: ' . $result['error'];
        logDispatch($conn, $visitorId, 'sms', $rawPhone, 'failed', $result['error']);
    }
}

if (empty($sent) && !empty($errors)) {
    echo json_encode(['ok' => false, 'error' => implode('; ', $errors)]);
} else {
    echo json_encode(['ok' => true, 'sent' => $sent, 'errors' => $errors]);
}
exit;

/* ════════════════════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════════════════════ */

/**
 * Send via Twilio REST API (SMS or WhatsApp).
 */
function twilioSend(string $to, string $from, string $body): array
{
    if (!defined('TWILIO_SID') || !defined('TWILIO_AUTH_TOKEN') || !$from) {
        return ['ok' => false, 'error' => 'Twilio not configured'];
    }
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_POSTFIELDS     => http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($httpCode === 201 && !empty($data['sid'])) {
        return ['ok' => true, 'sid' => $data['sid']];
    }
    $errMsg = $data['message'] ?? ($resp ?: 'HTTP ' . $httpCode);
    return ['ok' => false, 'error' => $errMsg];
}

/**
 * Send SMS via BulkSMS (https://www.bulksms.com) — good SA coverage, cheap.
 * Docs: https://www.bulksms.com/developer/json/v1/
 */
function bulkSmsSend(string $to, string $body): array
{
    if (!defined('BULKSMS_API_TOKEN')) {
        return ['ok' => false, 'error' => 'BulkSMS not configured'];
    }
    $payload = json_encode([
        'to'   => $to,
        'body' => $body,
        // 'from' => defined('BULKSMS_FROM') ? BULKSMS_FROM : 'MBGE',  // uncomment if approved
    ]);
    $ch = curl_init('https://api.bulksms.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . BULKSMS_API_TOKEN,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT    => 15,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // BulkSMS returns 201 on success
    if ($httpCode === 201) {
        $data = json_decode($resp, true);
        $sid  = is_array($data) && isset($data[0]['id']) ? $data[0]['id'] : '';
        return ['ok' => true, 'sid' => $sid];
    }
    $data   = json_decode($resp, true);
    $errMsg = $data['detail'] ?? $data['title'] ?? ('HTTP ' . $httpCode);
    return ['ok' => false, 'error' => $errMsg];
}

/**
 * Log dispatch attempt to visitor_pass_log table (created if absent).
 */
function logDispatch($conn, int $visitorId, string $channel, string $phone,
                     string $status, string $ref): void
{
    // Auto-create log table on first use
    $conn->query("CREATE TABLE IF NOT EXISTS visitor_pass_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        visitor_id  INT         NOT NULL,
        channel     VARCHAR(20) NOT NULL,
        phone       VARCHAR(30) NOT NULL,
        status      VARCHAR(20) NOT NULL,
        ref         VARCHAR(120),
        sent_at     DATETIME    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_visitor (visitor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $conn->prepare(
        "INSERT INTO visitor_pass_log (visitor_id, channel, phone, status, ref)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $visitorId, $channel, $phone, $status, $ref);
    $stmt->execute();
    $stmt->close();
}
