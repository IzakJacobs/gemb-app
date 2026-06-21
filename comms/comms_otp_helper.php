<?php
// ============================================================
// GEMB Communications Portal — comms_otp_helper.php
//
// Email OTP for new-device verification on comms_users login,
// plus the device-token hashing helper.
//
// Fully independent of the access control system's
// twilio_helper.php — built only on comms' own smtp_mail.php
// and its own COMMS_HMAC_SECRET constant.
// ============================================================

require_once __DIR__ . '/smtp_mail.php';

define('COMMS_OTP_TTL', 600); // 10 minutes

function generateCommsEmailOtp(string $email): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['comms_otp_hash']    = password_hash($otp, PASSWORD_BCRYPT);
    $_SESSION['comms_otp_expires'] = time() + COMMS_OTP_TTL;

    $html = '<p>A new device is attempting to sign in to the GEMB Communications Portal.</p>'
          . '<p style="font-size:28px;font-weight:700;letter-spacing:4px;">' . $otp . '</p>'
          . '<p>This code expires in 10 minutes. If this wasn\'t you, ignore this email '
          . 'and contact your system administrator.</p>';

    smtpSend($email, 'GEMB Comms — Verification Code', $html);
}

function verifyCommsEmailOtp(string $email, string $otp): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $hash    = $_SESSION['comms_otp_hash']    ?? '';
    $expires = $_SESSION['comms_otp_expires'] ?? 0;

    if (!$hash || time() > $expires) {
        unset($_SESSION['comms_otp_hash'], $_SESSION['comms_otp_expires']);
        return false;
    }
    $ok = password_verify($otp, $hash);
    if ($ok) {
        unset($_SESSION['comms_otp_hash'], $_SESSION['comms_otp_expires']);
    }
    return $ok;
}

function commsHashDeviceToken(string $token): string {
    $secret = defined('COMMS_HMAC_SECRET') ? COMMS_HMAC_SECRET : 'gemb-comms-fallback';
    return hash_hmac('sha256', $token, $secret);
}
