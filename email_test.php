<?php
require_once __DIR__ . '/db.php';
requireAdmin();

$result  = null;
$method  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $to      = 'imjac123@gmail.com';
    $from    = 'admin@gemb.co.za';
    $subject = 'MBGE Email Test — ' . date('d M Y H:i');
    $body    = "This is a test email from the MBGE Estate Access System.\n\n"
             . "Sent from: {$from}\n"
             . "Sent at:   " . date('Y-m-d H:i:s') . "\n"
             . "Server:    " . ($_SERVER['SERVER_NAME'] ?? 'unknown') . "\n\n"
             . "If you received this, email delivery is working correctly.";

    $headers  = "From: MBGE Estate <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $ok     = mail($to, $subject, $body, $headers, "-f{$from}");
    $method = 'PHP mail()';
    $result = $ok ? 'success' : 'fail';
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Test — MBGE</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
    .card{background:#fff;border-radius:14px;padding:28px 24px;width:100%;max-width:480px;box-shadow:0 2px 12px rgba(0,0,0,.1)}
    h2{color:#002855;font-size:18px;margin-bottom:6px}
    p{font-size:13px;color:#555;margin-bottom:20px;line-height:1.5}
    table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px}
    td{padding:8px 10px;border-bottom:1px solid #eee}
    td:first-child{font-weight:700;color:#555;width:110px}
    .btn{width:100%;padding:13px;background:#002855;color:#fff;font-size:15px;font-weight:700;border:none;border-radius:9px;cursor:pointer}
    .btn:hover{background:#001a3a}
    .ok{background:#d4edda;color:#155724;border:1px solid #b7dfbb;border-radius:8px;padding:12px 14px;font-size:14px;margin-bottom:18px}
    .err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:12px 14px;font-size:14px;margin-bottom:18px}
    .back{display:block;text-align:center;margin-top:16px;font-size:13px;color:#002855;text-decoration:none}
    .note{background:#fff3cd;color:#856404;border:1px solid #ffeeba;border-radius:8px;padding:10px 14px;font-size:12px;margin-top:18px;line-height:1.5}
  </style>
</head>
<body>
<div class="card">
  <h2>Email Delivery Test</h2>
  <p>Sends a test email using PHP <code>mail()</code> to verify that your cPanel server can deliver email.</p>

  <?php if ($result === 'success'): ?>
  <div class="ok">
    <strong>mail() returned true.</strong><br>
    The server accepted the message for delivery via <strong><?= e($method) ?></strong>.<br>
    Check <strong>imjac123@gmail.com</strong> (including spam/junk folder) within a few minutes.
  </div>
  <?php elseif ($result === 'fail'): ?>
  <div class="err">
    <strong>mail() returned false.</strong><br>
    The server rejected the message. Check that PHP mail is enabled in cPanel, or configure an SMTP relay (e.g. cPanel's "Email Routing" or use PHPMailer).
  </div>
  <?php endif ?>

  <table>
    <tr><td>From</td><td>admin@gemb.co.za</td></tr>
    <tr><td>To</td><td>imjac123@gmail.com</td></tr>
    <tr><td>Method</td><td>PHP mail() with -f envelope sender</td></tr>
    <tr><td>PHP version</td><td><?= phpversion() ?></td></tr>
    <tr><td>Server</td><td><?= e($_SERVER['SERVER_NAME'] ?? 'unknown') ?></td></tr>
  </table>

  <form method="POST">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button class="btn" type="submit">Send Test Email Now</button>
  </form>

  <div class="note">
    <strong>If the email arrives:</strong> delivery is working. You can delete this file.<br>
    <strong>If not:</strong> log in to cPanel &rarr; <em>Email</em> &rarr; <em>Track Delivery</em> to see the bounce reason, then check <em>MX Entry / Email Routing</em> settings.
  </div>

  <a class="back" href="admin_dashboard.php">&larr; Back to Admin Dashboard</a>
</div>
</body>
</html>
