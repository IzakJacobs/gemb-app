<?php
// ============================================================
// smtp_mail.php — gemB unified mailer
// Called by commsSendEmail() in comms_core.php
//            and sendEmail()      in twilio_helper.php
//
// Uses PHPMailer with SMTP AUTH. Credentials come from
// SMTP_* constants defined in config.php:
//   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS,
//   SMTP_FROM, SMTP_NAME
//
// PHPMailer files must be in:  public_html/phpmailer/
//   - Exception.php  |  PHPMailer.php  |  SMTP.php
// Download from: https://github.com/PHPMailer/PHPMailer/tree/master/src
// ============================================================

// Namespace aliases — resolved after PHPMailer is require_once'd inside smtpSend()
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

function smtpSend(string $to, string $subject, string $html): bool {

    $host = defined('SMTP_HOST') ? SMTP_HOST : 'mail.gemb.co.za';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $from = defined('SMTP_FROM') ? SMTP_FROM : $user;
    $name = defined('SMTP_NAME') ? SMTP_NAME : 'gemB Estate';

    if (!$user || !$pass) {
        error_log('smtpSend: SMTP_USER / SMTP_PASS not defined in config.php');
        return false;
    }

    // ── Load PHPMailer (manual install, no Composer needed) ──
    $dir = __DIR__ . '/phpmailer/';
    if (!file_exists($dir . 'PHPMailer.php')) {
        error_log('smtpSend: PHPMailer not found at ' . $dir . ' — falling back to mail()');
        return _smtpFallback($to, $subject, $html, $from, $name);
    }
    require_once $dir . 'Exception.php';
    require_once $dir . 'PHPMailer.php';
    require_once $dir . 'SMTP.php';

    // ── Sanitise subject ─────────────────────────────────────
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
        $mail->Host     = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->CharSet  = 'UTF-8';

        // Port 465 → implicit SSL (SMTPS); anything else → STARTTLS
        if ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port = $port;

        $mail->setFrom($from, $name);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $plain;

        $mail->send();
        return true;

    } catch (MailerException $e) {
        error_log('smtpSend SMTP failed to ' . $to . ': ' . $mail->ErrorInfo);
        return _smtpFallback($to, $subject, $html, $from, $name);
    }
}

// ── Fallback: PHP mail() with multipart body ─────────────────
function _smtpFallback(
    string $to, string $subject, string $html,
    string $from = 'admin@gemb.co.za', string $name = 'gemB Estate'
): bool {
    $subject = preg_replace('/[^\x20-\x7E]/', '', $subject);
    $subject = trim($subject);
    if ($subject === '') $subject = 'Estate Communication';

    $plain = html_entity_decode(
        strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>'], "\n", $html)),
        ENT_QUOTES | ENT_HTML5, 'UTF-8'
    );
    $plain = preg_replace('/\n{3,}/', "\n\n", trim($plain));

    $boundary = '==gemb_' . md5(uniqid('', true));
    $headers  = "From: {$name} <{$from}>\r\n"
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
