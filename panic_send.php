<?php
// ============================================================
// MBGE — panic/panic_send.php (single session)
// panic_log columns: resident_name, emergency_type, address, alert_time
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['panic_guard_name'])) {
    header('Location: panic_login.php'); exit;
}

require_once __DIR__ . '/../config.php';

$guardName = $_SESSION['panic_guard_name'];
$gate      = $_SESSION['panic_gate'] ?? 'Unknown';
$alertType = trim($_POST['alert_type'] ?? 'Emergency');
$message   = trim($_POST['message'] ?? '');
$sent      = false;

$fullMessage = "🚨 MBGE PANIC ALERT\n"
    . "From: $guardName\n"
    . "Location: $gate\n"
    . "Type: $alertType\n"
    . ($message ? "Details: $message\n" : '')
    . "Time: " . date('d M Y H:i:s');

try {
    db()->prepare("INSERT INTO panic_log (resident_name, emergency_type, address, alert_time)
        VALUES (?, ?, ?, NOW())")
    ->execute([
        $guardName,
        $alertType . ($message ? ' — ' . $message : ''),
        $gate,
    ]);
    $sent = true;
} catch (Exception $e) {
    $sent = false;
}

// Clear panic session variables after sending
unset($_SESSION['panic_guard_name'], $_SESSION['panic_gate']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alert <?= $sent?'Sent':'Failed' ?> — MBGE</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { background:<?= $sent?'#155724':'#8b0000' ?>; min-height:100vh;
  display:flex; align-items:center; justify-content:center;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; padding:20px; }
.card { background:#fff; border-radius:14px; padding:32px 24px;
  width:100%; max-width:420px; text-align:center; }
.icon { font-size:4rem; margin-bottom:14px; }
h2 { color:<?= $sent?'#155724':'#8b0000' ?>; margin-bottom:12px; }
p { color:#444; font-size:.9rem; margin-bottom:16px; line-height:1.5; }
.msg-box { background:#f8f9fa; border-radius:8px; padding:14px; font-size:.82rem;
  color:#333; text-align:left; margin-bottom:20px; white-space:pre-line; line-height:1.6; }
.btn { display:block; padding:13px; border-radius:8px; text-decoration:none;
  font-weight:600; font-size:.95rem; margin-bottom:10px; text-align:center; color:#fff; }
.btn-gate { background:#1a3c5e; }
.btn-again { background:#8b0000; }
</style>
</head>
<body>
<div class="card">
  <div class="icon"><?= $sent?'✅':'❌' ?></div>
  <h2><?= $sent?'ALERT LOGGED':'ALERT FAILED' ?></h2>
  <p><?= $sent
    ? 'Your panic alert has been recorded. Control room has been notified.'
    : 'Could not save the alert. Contact control room directly by radio or phone.' ?>
  </p>
  <div class="msg-box"><?= htmlspecialchars($fullMessage) ?></div>
  <a href="../guard.php?action=verify" class="btn btn-gate">Return to Gate Screen</a>
  <a href="panic_login.php" class="btn btn-again">Send Another Alert</a>
</div>
</body>
</html>
