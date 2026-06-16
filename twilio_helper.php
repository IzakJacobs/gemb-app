<?php
/**
 * twilio_helper.php — MBGE notifications and OTP via PHP mail()
 *
 * Uses the server's built-in mail() function — no external API needed.
 * Sending address: noreply@mbge.ink (set via MAIL_FROM in config, or hardcoded below).
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

// ── Send email via authenticated SMTP (smtp_mail.php) ────────────────────────
function sendEmail(string $toEmail, string $subject, string $body): bool {
    if (!$toEmail) {
        error_log('MBGE Email: no recipient address');
        return false;
    }
    require_once __DIR__ . '/smtp_mail.php';
    // Wrap plain-text body in minimal HTML so smtpSend() renders cleanly
    $html = nl2br(htmlspecialchars($body));
    $html = "<html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>"
          . $html . "</body></html>";
    return smtpSend($toEmail, $subject, $html);
}

// ── Generate and send a 6-digit OTP via email ─────────────────────────────────
function generateOtp(string $phone, string $email): bool {
    ensureOtpTable();
    $phone = normalisePhone($phone);

    // Invalidate any unexpired OTPs for this number
    db()->prepare("UPDATE otp_tokens SET used=1 WHERE phone=? AND used=0")
        ->execute([$phone]);

    $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes

    db()->prepare("INSERT INTO otp_tokens (phone, otp, expires_at) VALUES (?, ?, ?)")
        ->execute([$phone, $otp, $expires]);

    $subject = 'MBGE Access Control - Your Login Code';
    $body    = "MBGE Access Control\n\n"
             . "Your login code: {$otp}\n\n"
             . "Valid for 5 minutes. Do not share this code.\n\n"
             . "MBGE HOA | POPIA Act 4 of 2013";

    return sendEmail($email, $subject, $body);
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
    string $residentEmail,
    string $visitorName,
    string $category,
    string $gate,
    string $timestamp = ''
): void {
    if (!$residentEmail) return;
    if (!$timestamp) $timestamp = date('d M Y H:i');

    $label   = $category === 'service_provider' ? 'Service Provider' : 'Visitor';
    $subject = "MBGE - {$label} Arrived";
    $body    = "MBGE Access Control\n\n"
             . "{$label} ARRIVED\n\n"
             . "Name:  {$visitorName}\n"
             . "Gate:  {$gate}\n"
             . "Time:  {$timestamp}\n\n"
             . "MBGE HOA";

    sendEmail($residentEmail, $subject, $body);
}

// ── Resident exit notification ────────────────────────────────────────────────
function notifyResidentExit(
    string $residentEmail,
    string $visitorName,
    string $category,
    string $gate,
    string $timestamp = ''
): void {
    if (!$residentEmail) return;
    if (!$timestamp) $timestamp = date('d M Y H:i');

    $label   = $category === 'service_provider' ? 'Service Provider' : 'Visitor';
    $subject = "MBGE - {$label} Departed";
    $body    = "MBGE Access Control\n\n"
             . "{$label} DEPARTED\n\n"
             . "Name:  {$visitorName}\n"
             . "Gate:  {$gate}\n"
             . "Time:  {$timestamp}\n\n"
             . "MBGE HOA";

    sendEmail($residentEmail, $subject, $body);
}

// ── EMAIL-KEYED OTP (for admins / email-first accounts) ───────────────────────
// The phone-keyed generateOtp()/verifyOtp() above store the code under a
// normalised phone number. Admins are email-first and may have no phone, so
// these variants key the OTP on the email address instead. The key is a short,
// deterministic hash that fits otp_tokens.phone (VARCHAR(20)) and begins with a
// non-digit 'E', so it can never collide with a real normalised phone number.
function emailOtpKey(string $email): string {
    return 'E' . substr(md5(strtolower(trim($email))), 0, 15); // 16 chars
}

function generateEmailOtp(string $email): bool {
    ensureOtpTable();
    $email = trim($email);
    if ($email === '') {
        error_log('MBGE Email OTP: no email address supplied');
        return false;
    }
    $key = emailOtpKey($email);

    // Invalidate any unexpired OTPs for this email key
    db()->prepare("UPDATE otp_tokens SET used=1 WHERE phone=? AND used=0")
        ->execute([$key]);

    $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes

    db()->prepare("INSERT INTO otp_tokens (phone, otp, expires_at) VALUES (?, ?, ?)")
        ->execute([$key, $otp, $expires]);

    $subject = 'MBGE Access Control - Your Login Code';
    $body    = "MBGE Access Control\n\n"
             . "Your login code: {$otp}\n\n"
             . "Valid for 5 minutes. Do not share this code.\n\n"
             . "MBGE HOA | POPIA Act 4 of 2013";

    return sendEmail($email, $subject, $body);
}

function verifyEmailOtp(string $email, string $otp): bool {
    ensureOtpTable();
    $key = emailOtpKey($email);
    $otp = preg_replace('/\D/', '', trim($otp));

    $stmt = db()->prepare(
        "SELECT id FROM otp_tokens
         WHERE phone=? AND otp=? AND used=0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$key, $otp]);
    $row = $stmt->fetch();

    if (!$row) return false;

    db()->prepare("UPDATE otp_tokens SET used=1 WHERE id=?")->execute([$row['id']]);
    return true;
}
