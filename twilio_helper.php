<?php
/**
 * twilio_helper.php — MBGE WhatsApp messaging via Twilio
 *
 * Add these constants to config.php before using:
 *
 *   define('TWILIO_SID',     'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
 *   define('TWILIO_TOKEN',   'your_auth_token_here');
 *   define('TWILIO_WA_FROM', '+14155238886'); // sandbox, or your approved number
 *
 * Twilio sandbox: https://console.twilio.com/us1/develop/sms/try-it-out/whatsapp-learn
 * Production:     purchase a WhatsApp-enabled number in the Twilio console.
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

// ── Normalise a SA phone number to E.164 (27XXXXXXXXX) ───────────────────────
function normalisePhone(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);
    if (substr($phone, 0, 1) === '0')  $phone = '27' . substr($phone, 1);
    if (substr($phone, 0, 2) !== '27') $phone = '27' . $phone;
    return $phone;
}

// ── Send a WhatsApp message via Twilio ───────────────────────────────────────
function sendWhatsApp(string $toPhone, string $message): bool {
    if (!defined('TWILIO_SID') || !defined('TWILIO_TOKEN') || !defined('TWILIO_WA_FROM')) {
        error_log('MBGE Twilio: constants not defined in config.php');
        return false;
    }

    $sid  = TWILIO_SID;
    $tok  = TWILIO_TOKEN;
    $from = 'whatsapp:+' . normalisePhone(TWILIO_WA_FROM);
    $to   = 'whatsapp:+' . normalisePhone($toPhone);
    $url  = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $to, 'Body' => $message]),
        CURLOPT_USERPWD        => "{$sid}:{$tok}",
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        error_log("MBGE Twilio: HTTP {$httpCode} sending to {$to} — {$response}");
        return false;
    }
    return true;
}

// ── Generate and send a 6-digit OTP ──────────────────────────────────────────
function generateOtp(string $phone): bool {
    ensureOtpTable();
    $phone = normalisePhone($phone);

    // Invalidate any unexpired OTPs for this number (prevent brute force)
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

    return sendWhatsApp($phone, $message);
}

// ── Verify an OTP ─────────────────────────────────────────────────────────────
// Returns true and marks used if valid; false if expired/wrong/already used.
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

    $icon = $category === 'service_provider' ? '🔧' : '👤';
    $label = $category === 'service_provider' ? 'Service Provider' : 'Visitor';

    $message = "🏡 *MBGE Access Control*\n\n"
             . "{$icon} *{$label} Arrived*\n\n"
             . "Name: {$visitorName}\n"
             . "Gate: {$gate}\n"
             . "Time: {$timestamp}\n\n"
             . "MBGE HOA";

    sendWhatsApp($residentPhone, $message);
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

    sendWhatsApp($residentPhone, $message);
}
