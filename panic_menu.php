<?php
// ============================================================
// GEMB — panic/panic_menu.php (single session)
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['panic_guard_name'])) {
    header('Location: panic_login.php'); exit;
}

require_once __DIR__ . '/../config.php';

$guardName = $_SESSION['panic_guard_name'];
$gate      = $_SESSION['panic_gate'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PANIC — GEMB</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { background:#8b0000; min-height:100vh; display:flex; align-items:center;
  justify-content:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; padding:20px; }
.card { background:#fff; border-radius:14px; padding:32px 24px;
  width:100%; max-width:420px; text-align:center; }
.logo { font-size:4rem; margin-bottom:10px; animation:pulse 1s infinite; }
h2 { color:#8b0000; margin-bottom:16px; font-size:1.3rem; letter-spacing:1px; }
.who { background:#fff3cd; border-radius:8px; padding:12px 16px;
  margin-bottom:18px; font-size:.9rem; color:#856404; text-align:left; line-height:1.6; }
.who strong { display:block; font-size:1rem; color:#333; }
select, textarea { width:100%; padding:10px 12px; border:1px solid #ccc;
  border-radius:8px; margin-bottom:12px; font-family:inherit; font-size:.9rem; }
.btn-send { display:block; width:100%; padding:18px; background:#8b0000; color:#fff;
  border:none; border-radius:10px; font-size:1.15rem; font-weight:700;
  cursor:pointer; margin-bottom:12px; letter-spacing:1px; }
.btn-send:active { background:#6d0000; transform:scale(.98); }
.btn-cancel { display:block; width:100%; padding:12px; background:#6c757d;
  color:#fff; border:none; border-radius:8px; font-size:.9rem; cursor:pointer;
  text-decoration:none; }
@keyframes pulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.08);} }
</style>
</head>
<body>
<div class="card">
  <div class="logo">🚨</div>
  <h2>SEND PANIC ALERT</h2>
  <div class="who">
    <strong><?= htmlspecialchars($guardName) ?></strong>
    Location: <?= htmlspecialchars($gate) ?>
  </div>
  <form method="POST" action="panic_send.php">
    <select name="alert_type">
      <option>Emergency — assistance required immediately</option>
      <option>Armed threat at gate</option>
      <option>Medical emergency</option>
      <option>Suspicious activity</option>
      <option>Fire</option>
      <option>Intruder on estate</option>
      <option>Other</option>
    </select>
    <textarea name="message" rows="3" placeholder="Additional details (optional)…"></textarea>
    <button type="submit" class="btn-send">🚨 SEND ALERT NOW</button>
  </form>
  <a href="../guard.php?action=verify" class="btn-cancel">Cancel — Return to Gate</a>
</div>
</body>
</html>
