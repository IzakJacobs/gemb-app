<?php
// ============================================================
// smtp_mail.php — gemB unified mailer
// Called by commsSendEmail() in comms_core.php
//
// Uses PHPMailer with SMTP AUTH to send as admin@gemb.co.za.
// PHPMailer files must be in:  public_html/phpmailer/
//   - Exception.php
//   - PHPMailer.php
//   - SMTP.php
// Download from: https://github.com/PHPMailer/PHPMailer/tree/master/src
//
// SMTP credentials are read from config.php constants.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

function smtpSend(string $to, string $subject, string $html): bool {

    // ── Load PHPMailer (manual install, no Composer needed) ──
    $dir = __DIR__ . '/phpmailer/';
    if (!file_exists($dir . 'PHPMailer.php')) {
        error_log('smtpSend: PHPMailer not found at ' . $dir . ' — falling back to mail()');
        return _smtpFallback($to, $subject, $html);
    }
    require_once $dir . 'Exception.php';
    require_once $dir . 'PHPMailer.php';
    require_once $dir . 'SMTP.php';

    // ── Sanitise subject ─────────────────────────────────────
    // Strip non-ASCII — the relay rejects em dashes, curly quotes, etc.
    $subject = preg_replace('/[^\x20-\x7E]/', '', $subject);
    $subject = trim($subject);
    if ($subject === '') $subject = 'Estate Communication';

    // ── Plain-text fallback from HTML ────────────────────────
    $plain = str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>', '</li>'],
        "\n", $html
    );
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/[ \t]+/', ' ', $plain);
    $plain = preg_replace('/\n{3,}/', "\n\n", trim($plain));

    // ── Send via PHPMailer SMTP AUTH ─────────────────────────
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.gemb.co.za';   // cPanel mail server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'admin@gemb.co.za';
        $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('admin@gemb.co.za', 'gemB Estate');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $plain;

        $mail->send();
        return true;

    } catch (MailerException $e) {
        error_log('smtpSend SMTP failed to ' . $to . ': ' . $mail->ErrorInfo);
        // Fall back to PHP mail() if SMTP fails
        return _smtpFallback($to, $subject, $html);
    }
}

// ── Fallback: PHP mail() with multipart body ─────────────────
// Used when PHPMailer is not installed or SMTP auth fails.
function _smtpFallback(string $to, string $subject, string $html): bool {
    $from     = 'admin@gemb.co.za';
    $fromName = 'gemB Estate';

    $subject = preg_replace('/[^\x20-\x7E]/', '', $subject);
    $subject = trim($subject);
    if ($subject === '') $subject = 'Estate Communication';

    $plain = html_entity_decode(
        strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>'], "\n", $html)),
        ENT_QUOTES | ENT_HTML5, 'UTF-8'
    );
    $plain = preg_replace('/\n{3,}/', "\n\n", trim($plain));

    $boundary = '==gemb_' . md5(uniqid('', true));
    $headers  = "From: {$fromName} <{$from}>\r\n"
              . "Reply-To: {$from}\r\n"
              . "MIME-Version: 1.0\r\n"
              . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
          . quoted_printable_encode($plain) . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
          . quoted_printable_encode($html) . "\r\n"
          . "--{$boundary}--";

    return mail($to, $subject, $body, $headers, "-f{$from}");
}
