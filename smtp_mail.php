<?php
// ============================================================
// smtp_mail.php — gemB unified mailer
// Called by commsSendEmail() in comms_core.php
//
// Sends multipart HTML + plain-text email via PHP mail().
// Subject is sanitised to strip non-ASCII characters that
// cause the out.tld-mx.com relay to reject with 550/451.
// ============================================================

function smtpSend(string $to, string $subject, string $html): bool {

    $from     = 'admin@gemb.co.za';
    $fromName = 'gemB Estate';

    // ── Sanitise subject ──────────────────────────────────
    // Strip every character outside printable ASCII (0x20–0x7E).
    // Em dashes (—), curly quotes, etc. cause "550 Subject contains
    // invalid characters" or "451 account locked" from this relay.
    $subject = preg_replace('/[^\x20-\x7E]/', '', $subject);
    $subject = trim($subject);
    if ($subject === '') $subject = 'Estate Communication';

    // ── Build plain-text fallback from HTML ───────────────
    $plain = str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>', '</li>'],
        "\n",
        $html
    );
    $plain = strip_tags($plain);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/[ \t]+/', ' ', $plain);
    $plain = preg_replace('/\n{3,}/', "\n\n", trim($plain));

    // ── MIME boundary ─────────────────────────────────────
    $boundary = '==gemb_' . md5(uniqid('', true));

    // ── Headers ───────────────────────────────────────────
    $headers  = "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: gemB-Mailer/1.0\r\n";

    // ── Body: plain-text part ─────────────────────────────
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n";

    // ── Body: HTML part ───────────────────────────────────
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n";

    $body .= "--{$boundary}--";

    return mail($to, $subject, $body, $headers, "-f{$from}");
}
