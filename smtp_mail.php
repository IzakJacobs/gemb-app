<?php
// ============================================================
// smtp_mail.php — Authenticated SMTP mailer (SSL port 465)
// Uses constants from config.php: SMTP_HOST, SMTP_PORT,
// SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_NAME
// ============================================================

function smtpSend(string $to, string $subject, string $html): bool {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $from = defined('SMTP_FROM') ? SMTP_FROM : $user;
    $name = defined('SMTP_NAME') ? SMTP_NAME : 'MBGE Estate';

    if (!$host || !$user || !$pass) {
        error_log('MBGE SMTP: credentials not configured in config.php');
        return false;
    }

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

    $errno = 0; $errstr = '';
    $sock = @stream_socket_client(
        "ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$sock) {
        error_log("MBGE SMTP: connect failed to {$host}:{$port} — {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($sock, 15);

    $read = static function () use ($sock): string {
        $r = '';
        while ($line = fgets($sock, 512)) {
            $r .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $r;
    };
    $cmd = static function (string $c) use ($sock, $read): string {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $read();                                            // 220 banner
    $cmd('EHLO ' . (gethostname() ?: 'localhost'));
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $resp = $cmd(base64_encode($pass));

    if (strpos($resp, '235') === false) {
        error_log('MBGE SMTP: AUTH failed — ' . trim($resp));
        fclose($sock);
        return false;
    }

    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$to}>");
    $cmd('DATA');

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName    = '=?UTF-8?B?' . base64_encode($name)    . '?=';

    $body = "From: {$encodedName} <{$from}>\r\n"
          . "To: <{$to}>\r\n"
          . "Subject: {$encodedSubject}\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n"
          . "\r\n"
          . chunk_split(base64_encode($html));

    $resp2 = $cmd($body . "\r\n.");
    $cmd('QUIT');
    fclose($sock);

    if (strpos($resp2, '250') === false) {
        error_log('MBGE SMTP: send failed — ' . trim($resp2));
        return false;
    }
    return true;
}
