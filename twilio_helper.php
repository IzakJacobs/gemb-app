<?php
/**
 * twilio_helper.php — MBGE messaging: WhatsApp (Twilio) with SMS fallback (BulkSMS)
 *
 * Required in config.php:
 *   define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // Account SID
 *   define('TWILIO_SID',         'SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // API Key SID
 *   define('TWILIO_TOKEN',       'your_api_key_secret');
 *   define('TWILIO_WA_FROM',     '+14155238886');  // sandbox or approved WA number
 *
 *   define('BULKSMS_API_TOKEN',  'token_id:token_secret'); // from bulksms.com portal
 *   define('BULKSMS_FROM',       'MBGE');
 *
 * Send flow: WhatsApp first → SMS fallback if WhatsApp fails or is unconfigured.
 */

// ── Ensure OTP table exists ───────────────────────────────────────────────────
function ensureOtpTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS otp_tokens (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        phone      VARCHAR(20)  NOT NULL,
        otp        CHAR(6)      NOT NULL,
        expires_at DATETIME     NOT NULL,
        used       TINYINT(1)   DEFAULT 0,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone_otp (phone, otp)
    )");
}

// ── Normalise a SA phone number to E.164 digits only (27XXXXXXXXX) ───────────
function normalisePhone(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);
    if (substr($phone, 0, 1) === '0')  $phone = '27' . substr($phone, 1);
    if (substr($phone, 0, 2) !== '27') $phone = '27' . $phone;
    return $phone;
}

// ── Strip WhatsApp markdown for plain-text SMS ────────────────────────────────
function stripWaMarkdown(string $msg): string {
    return preg_replace('/\*([^*]+)\*/', '$1', $msg); // *bold* → bold
}

// ── Send a WhatsApp message via Twilio ───────────────────────────────────────
function sendWhatsApp(string $toPhone, string $message): bool {
    if (!defined('TWILIO_ACCOUNT_SID') || !defined('TWILIO_SID') ||
        !defined('TWILIO_TOKEN')       || !defined('TWILIO_WA_FROM')) {
        return false;
    }

    $accountSid = TWILIO_ACCOUNT_SID;
    $keySid     = TWILIO_SID;
    $keySecret  = TWILIO_TOKEN;
    // Do NOT run normalisePhone() on TWILIO_WA_FROM — it's a US number, not SA.
    $from = 'whatsapp:+' . preg_replace('/\D/', '', TWILIO_WA_FROM);
    $to   = 'whatsapp:+' . normalisePhone($toPhone);
    $url  = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $to, 'Body' => $message]),
        CURLOPT_USERPWD        => "{$keySid}:{$keySecret}",
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        error_log("MBGE Twilio WA: HTTP {$httpCode} to {$to} — {$response}");
        return false;
    }
    return true;
}

// ── Send an SMS via BulkSMS ───────────────────────────────────────────────────
function sendBulkSms(string $toPhone, string $message): bool {
    if (!defined('BULKSMS_API_TOKEN')
        || BULKSMS_API_TOKEN === 'YOUR_BULKSMS_TOKEN_HERE'
        || BULKSMS_API_TOKEN === '') {
        return false;
    }

    $to      = '+' . normalisePhone($toPhone);
    $from    = defined('BULKSMS_FROM') ? BULKSMS_FROM : 'MBGE';
    $payload = json_encode(['to' => $to, 'from' => $from, 'body' => stripWaMarkdown($message)]);

    $ch = curl_init('https://api.bulksms.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => BULKSMS_API_TOKEN, // token_id:token_secret
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("MBGE BulkSMS: HTTP {$httpCode} to {$to} — {$response}");
        return false;
    }
    return true;
}

// ── Send via WhatsApp, fall back to SMS ──────────────────────────────────────
// Returns true if either channel succeeded.
function sendMessage(string $toPhone, string $message): bool {
    if (sendWhatsApp($toPhone, $message)) return true;
    return sendBulkSms($toPhone, $message);
}

// ── Generate and send a 6-digit OTP ──────────────────────────────────────────
function generateOtp(string $phone): bool {
    ensureOtpTable();
    $phone = normalisePhone($phone);

    db()->prepare("UPDATE otp_tokens SET used=1 WHERE phone=? AND used=0")
        ->execute([$phone]);

    $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes

    db()->prepare("INSERT INTO otp_tokens (phone, otp, expires_at) VALUES (?, ?, ?)")
        ->execute([$phone, $otp, $expires]);

    $message = "🏡 *MBGE Access Control*\n\n"
             . "Your one-time login code:\n\n"
             . "*{$otp}*\n\n"
             . "Valid for 5 minutes. Do not share this code.\n"
             . "MBGE HOA | POPIA Act 4 of 2013";

    return sendMessage($phone, $message);
}

// ── Verify an OTP ─────────────────────────────────────────────────────────────
function verifyOtp(string $phone, string $otp): bool {
    ensureOtpTable();
    $phone = normalisePhone($phone);
    $otp   = preg_replace('/\D/', '', trim($otp));

    $stmt = db()->prepare(
        "SELECT id FROM otp_tokens
         WHERE phone=? AND otp=? AND used=0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$phone, $otp]);
    $row = $stmt->fetch();

    if (!$row) return false;

    db()->prepare("UPDATE otp_tokens SET used=1 WHERE id=?")->execute([$row['id']]);
    return true;
}

// ── Resident entry notification ───────────────────────────────────────────────
function notifyResidentEntry(
    string $residentPhone,
    string $visitorName,
    string $category,      // 'visitor' | 'service_provider'
    string $gate,
    string $timestamp = ''
): void {
    if (!$residentPhone) return;
    if (!$timestamp) $timestamp = date('d M Y H:i');

    $icon  = $category === 'service_provider' ? '🔧' : '👤';
    $label = $category === 'service_provider' ? 'Service Provider' : 'Visitor';

    $message = "🏡 *MBGE Access Control*\n\n"
             . "{$icon} *{$label} Arrived*\n\n"
             . "Name: {$visitorName}\n"
             . "Gate: {$gate}\n"
             . "Time: {$timestamp}\n\n"
             . "MBGE HOA";

    sendMessage($residentPhone, $message);
}

// ── Resident exit notification ────────────────────────────────────────────────
function notifyResidentExit(
    string $residentPhone,
    string $visitorName,
    string $category,
    string $gate,
    string $timestamp = ''
): void {
    if (!$residentPhone) return;
    if (!$timestamp) $timestamp = date('d M Y H:i');

    $icon  = $category === 'service_provider' ? '🔧' : '👤';
    $label = $category === 'service_provider' ? 'Service Provider' : 'Visitor';

    $message = "🏡 *MBGE Access Control*\n\n"
             . "{$icon} *{$label} Departed*\n\n"
             . "Name: {$visitorName}\n"
             . "Gate: {$gate}\n"
             . "Time: {$timestamp}\n\n"
             . "MBGE HOA";

    sendMessage($residentPhone, $message);
}
