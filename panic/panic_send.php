<?php
// ============================================================
// GEMB Access Control — panic/panic_send.php
// ============================================================
session_name('gemb_panic');
session_start();

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['panic_guard_id'])) {
    header('Location: panic_login.php'); exit;
}

$guardId   = $_SESSION['panic_guard_id'];
$guardName = $_SESSION['panic_guard_name'] ?? 'Unknown Guard';
$gate      = $_SESSION['panic_gate'] ?? 'Unknown';
$alertType = trim($_POST['alert_type'] ?? 'Emergency');
$message   = trim($_POST['message'] ?? '');
$sent      = false;
$sentTo    = [];

// Build full alert message
$fullMessage = "🚨 GEMB PANIC ALERT\n"
    . "Guard: $guardName\n"
    . "Gate: $gate\n"
    . "Type: $alertType\n"
    . ($message ? "Details: $message\n" : '')
    . "Time: " . date('d M Y H:i:s');

// Log to database
try {
    db()->prepare("INSERT INTO panic_log (guard_id,guard_name,gate,message,sent_to,created_at) VALUES (?,?,?,?,?,NOW())")
      ->execute([$guardId, $guardName, $gate, $fullMessage, PANIC_RECIPIENTS]);
    $sent = true;
    $sentTo = explode(',', PANIC_RECIPIENTS);
} catch (Exception $e) {
    $sent = false;
}

// ── Optional: WhatsApp / SMS integration ─────────────────
// Uncomment and configure your preferred notification service:
//
// foreach ($sentTo as $number) {
//     $number = trim($number);
//     // Example using CallMeBot WhatsApp API:
//     $url = "https://api.callmebot.com/whatsapp.php?phone={$number}&text=" . urlencode($fullMessage) . "&apikey=YOUR_API_KEY";
//     @file_get_contents($url);
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alert Sent — GEMB</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body {
  background:<?= $sent ? '#155724' : '#8b0000' ?>;
  min-height:100vh; display:flex; align-items:center; justify-content:center;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; padding:20px;
}
.card { background:#fff; border-radius:14px; padding:32px 24px; width:100%; max-width:400px; text-align:center; }
.icon { font-size:4rem; margin-bottom:14px; }
h2 { color:<?= $sent ? '#155724' : '#8b0000' ?>; margin-bottom:12px; }
p { color:#444; font-size:.9rem; margin-bottom:16px; line-height:1.5; }
.msg-box { background:#f8f9fa; border-radius:8px; padding:12px; font-size:.82rem; color:#333; text-align:left; margin-bottom:20px; white-space:pre-line; }
a { display:block; padding:12px; background:#1a3c5e; color:#fff; border-radius:8px; text-decoration:none; font-weight:600; }
</style>
</head>
<body>
<div class="card">
  <div class="icon"><?= $sent ? '✅' : '❌' ?></div>
  <h2><?= $sent ? 'ALERT SENT' : 'ALERT FAILED' ?></h2>
  <p><?= $sent ? 'Your panic alert has been logged and dispatched.' : 'The alert could not be sent. Contact control room directly.' ?></p>
  <div class="msg-box"><?= htmlspecialchars($fullMessage) ?></div>
  <a href="panic_menu.php">Send Another Alert</a>
</div>
</body>
</html>
