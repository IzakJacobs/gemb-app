<?php
// Self-contained — works with both repo db.php and live config.php
if (file_exists(__DIR__ . '/config.php') && !defined('DB_HOST')) {
    // Suppress brute_force_helper.php missing on repo clone; it exists on live server
    @require_once __DIR__ . '/config.php';
} elseif (!defined('DB_HOST')) {
    require_once __DIR__ . '/db.php';
}

// Start / validate session (works whether config.php or db.php was included)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

// Admin auth — supports both session structures
$isAdmin = (!empty($_SESSION['admin_id']))                                       // live config.php
        || (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'); // repo db.php

if (!$isAdmin) {
    $loginPage = file_exists(__DIR__ . '/admin.php') ? 'admin.php?action=login' : 'admin_login.php';
    header('Location: ' . $loginPage);
    exit;
}

// CSRF — supports both naming conventions
function emailtest_csrfToken(): string {
    if (empty($_SESSION['_et_csrf'])) {
        $_SESSION['_et_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_et_csrf'];
}
function emailtest_verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['_et_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid CSRF token. Please go back and try again.');
    }
    $_SESSION['_et_csrf'] = bin2hex(random_bytes(32)); // rotate
}
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── Constants ──────────────────────────────────────────────
$TO   = 'imjac123@gmail.com';
$FROM = 'admin@gemb.co.za';

// ── Handle send ───────────────────────────────────────────
$result  = null;
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    emailtest_verifyCsrf();

    $subject = 'gemB Estate — Email Delivery Test ' . date('d M Y H:i');
    $body    = "This is a test email from the gemB Access Control System.\r\n\r\n"
             . "From:    {$FROM}\r\n"
             . "To:      {$TO}\r\n"
             . "Sent at: " . date('Y-m-d H:i:s') . "\r\n"
             . "Server:  " . ($_SERVER['SERVER_NAME'] ?? php_uname('n')) . "\r\n\r\n"
             . "If you received this message, email delivery from gemb.co.za is working correctly.\r\n";

    $headers  = "From: gemB Estate <{$FROM}>\r\n";
    $headers .= "Reply-To: {$FROM}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $ok = mail($TO, $subject, $body, $headers, "-f{$FROM}");

    $result  = $ok ? 'success' : 'fail';
    $details = [
        'mail() returned' => $ok ? 'true  ✓' : 'false ✗',
        'Envelope sender' => "-f{$FROM}",
        'PHP sendmail_path' => ini_get('sendmail_path') ?: '(not set)',
        'SMTP (php.ini)'    => ini_get('SMTP') ?: '(not set)',
    ];
}

$csrf = emailtest_csrfToken();

// Back-link: use whichever admin page exists
$backLink  = file_exists(__DIR__ . '/admin.php')          ? 'admin.php'
           : (file_exists(__DIR__ . '/admin_dashboard.php') ? 'admin_dashboard.php' : '#');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Test — gemB</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
    .card{background:#fff;border-radius:14px;padding:28px 24px;width:100%;max-width:500px;box-shadow:0 2px 12px rgba(0,0,0,.1)}
    h2{color:#002855;font-size:18px;margin-bottom:6px}
    p{font-size:13px;color:#555;margin-bottom:20px;line-height:1.5}
    table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px}
    td{padding:8px 10px;border-bottom:1px solid #eee;vertical-align:top}
    td:first-child{font-weight:700;color:#555;width:140px;white-space:nowrap}
    code{background:#f0f4f8;padding:1px 5px;border-radius:3px;font-size:12px}
    .btn{width:100%;padding:13px;background:#002855;color:#fff;font-size:15px;font-weight:700;border:none;border-radius:9px;cursor:pointer}
    .btn:hover{background:#001a3a}
    .ok {background:#d4edda;color:#155724;border:1px solid #b7dfbb;border-radius:8px;padding:12px 14px;font-size:14px;margin-bottom:18px}
    .err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:12px 14px;font-size:14px;margin-bottom:18px}
    .back{display:block;text-align:center;margin-top:16px;font-size:13px;color:#002855;text-decoration:none}
    .note{background:#fff3cd;color:#856404;border:1px solid #ffeeba;border-radius:8px;padding:10px 14px;font-size:12px;margin-top:18px;line-height:1.6}
    .detail-table{margin-top:10px;margin-bottom:0}
    .detail-table td{font-size:12px;padding:5px 8px;font-weight:normal;color:#333}
    .detail-table td:first-child{color:#555;font-weight:700;width:160px}
  </style>
</head>
<body>
<div class="card">
  <h2>Email Delivery Test</h2>
  <p>Sends a test message from <strong><?= esc($FROM) ?></strong> to <strong><?= esc($TO) ?></strong> using PHP <code>mail()</code>.</p>

  <?php if ($result === 'success'): ?>
  <div class="ok">
    <strong>Accepted for delivery.</strong><br>
    <code>mail()</code> returned <strong>true</strong> — the server has queued the message.<br>
    Check <strong><?= esc($TO) ?></strong> (including spam / junk) within a few minutes.
    <table class="detail-table" style="margin-top:10px">
      <?php foreach ($details as $k => $v): ?>
      <tr><td><?= esc($k) ?></td><td><code><?= esc($v) ?></code></td></tr>
      <?php endforeach ?>
    </table>
  </div>
  <?php elseif ($result === 'fail'): ?>
  <div class="err">
    <strong>Delivery failed.</strong><br>
    <code>mail()</code> returned <strong>false</strong>. The server rejected the message before sending.
    <table class="detail-table" style="margin-top:10px">
      <?php foreach ($details as $k => $v): ?>
      <tr><td><?= esc($k) ?></td><td><code><?= esc($v) ?></code></td></tr>
      <?php endforeach ?>
    </table>
  </div>
  <?php endif ?>

  <table>
    <tr><td>From</td><td><?= esc($FROM) ?></td></tr>
    <tr><td>To</td><td><?= esc($TO) ?></td></tr>
    <tr><td>Method</td><td>PHP <code>mail()</code> + <code>-f</code> envelope</td></tr>
    <tr><td>PHP version</td><td><?= phpversion() ?></td></tr>
    <tr><td>Server</td><td><?= esc($_SERVER['SERVER_NAME'] ?? php_uname('n')) ?></td></tr>
    <tr><td>sendmail_path</td><td><code><?= esc(ini_get('sendmail_path') ?: '(not configured)') ?></code></td></tr>
  </table>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
    <button class="btn" type="submit">Send Test Email Now</button>
  </form>

  <div class="note">
    <strong>Email arrived?</strong> Great — delete this file, it's only needed once.<br><br>
    <strong>Didn't arrive?</strong><br>
    1. cPanel &rarr; <em>Email &rarr; Track Delivery</em> — see the exact bounce reason.<br>
    2. Check <em>Email &rarr; MX Entry</em> — routing should be <em>Local Mail Exchanger</em>.<br>
    3. Make sure <strong>admin@gemb.co.za</strong> exists as a mailbox in cPanel.<br>
    4. If <code>mail()</code> keeps returning <em>false</em>, ask your host to enable PHP sendmail or use PHPMailer with SMTP.
  </div>

  <a class="back" href="<?= esc($backLink) ?>">&larr; Back to Admin</a>
</div>
</body>
</html>
