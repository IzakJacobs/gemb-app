<?php
// ============================================================
// MBGE Access Control — panic/panic_menu.php
// ============================================================
session_name('mbge_panic');
session_start();

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['panic_guard_id'])) {
    header('Location: panic_login.php'); exit;
}

$guardName = $_SESSION['panic_guard_name'] ?? 'Guard';
$gate      = $_SESSION['panic_gate'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PANIC — MBGE</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body {
  background:#8b0000;
  min-height:100vh; display:flex; align-items:center; justify-content:center;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  padding:20px;
}
.card { background:#fff; border-radius:14px; padding:32px 24px; width:100%; max-width:400px; text-align:center; }
.logo { font-size:4rem; margin-bottom:10px; animation: pulse 1s infinite; }
h2 { color:#8b0000; margin-bottom:8px; }
p { color:#444; font-size:.9rem; margin-bottom:20px; line-height:1.5; }
.guard-info { background:#fff3cd; border-radius:8px; padding:12px; margin-bottom:20px; font-size:.88rem; color:#856404; }
.btn-panic {
  display:block; width:100%; padding:16px; background:#8b0000; color:#fff;
  border:none; border-radius:10px; font-size:1.1rem; font-weight:700;
  cursor:pointer; margin-bottom:12px; text-decoration:none;
}
.btn-cancel {
  display:block; width:100%; padding:12px; background:#6c757d; color:#fff;
  border:none; border-radius:8px; font-size:.9rem; cursor:pointer; text-decoration:none;
}
select, textarea { width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; margin-bottom:12px; font-family:inherit; }
@keyframes pulse { 0%,100% { transform:scale(1); } 50% { transform:scale(1.1); } }
</style>
</head>
<body>
<div class="card">
  <div class="logo">🚨</div>
  <h2>PANIC ALERT</h2>
  <div class="guard-info">Guard: <strong><?= htmlspecialchars($guardName) ?></strong> | Gate: <strong><?= htmlspecialchars($gate) ?></strong></div>
  <form method="POST" action="panic_send.php">
    <select name="alert_type">
      <option value="Emergency — assistance required immediately">Emergency — assistance required immediately</option>
      <option value="Armed threat at gate">Armed threat at gate</option>
      <option value="Medical emergency">Medical emergency</option>
      <option value="Suspicious activity">Suspicious activity</option>
      <option value="Fire">Fire</option>
      <option value="Intruder">Intruder</option>
      <option value="Other">Other</option>
    </select>
    <textarea name="message" rows="3" placeholder="Additional details (optional)…"></textarea>
    <button type="submit" class="btn-panic">🚨 SEND PANIC ALERT NOW</button>
  </form>
  <a href="../logout.php" class="btn-cancel">Cancel / Logout</a>
</div>
</body>
</html>
