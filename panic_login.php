<?php
// ============================================================
// MBGE — panic/panic_login.php (simplified — single session)
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';

// If guard already logged in via main session — go straight to menu
if (!empty($_SESSION['guard_id'])) {
    $_SESSION['panic_guard_name'] = $_SESSION['guard_name'] ?? 'Guard';
    $_SESSION['panic_gate']       = $_SESSION['guard_gate'] ?? 'Unknown';
    header('Location: panic_menu.php'); exit;
}

// If resident already logged in
if (!empty($_SESSION['resident_id'])) {
    $_SESSION['panic_guard_name'] = ($_SESSION['resident_name'] ?? 'Resident')
        . ' — Erf ' . ($_SESSION['resident_erf'] ?? '');
    $_SESSION['panic_gate'] = 'Residential';
    header('Location: panic_menu.php'); exit;
}

// Not logged in — show login form
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    // Try guard
    $stmt = db()->prepare("SELECT * FROM guards WHERE username = ?");
    $stmt->execute([$user]);
    $guard = $stmt->fetch();
    if ($guard && password_verify($pass, $guard['pin'])) {
        $_SESSION['panic_guard_name'] = $guard['name'];
        $_SESSION['panic_gate']       = $guard['gate'] ?? 'Unknown';
        header('Location: panic_menu.php'); exit;
    }

    // Try resident by erf or phone
    $stmt = db()->prepare("SELECT * FROM residents WHERE (resident_erfno = ? OR phone = ?) AND status = 'active'");
    $stmt->execute([$user, $user]);
    $res = $stmt->fetch();
    if ($res && password_verify($pass, $res['pin_hash'])) {
        $_SESSION['panic_guard_name'] = $res['resident_name'] . ' — Erf ' . $res['resident_erfno'];
        $_SESSION['panic_gate']       = 'Residential';
        header('Location: panic_menu.php'); exit;
    }

    $error = 'Invalid credentials. Use your guard username or erf/phone number and PIN.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PANIC ALERT — MBGE</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { background:#8b0000; min-height:100vh; display:flex; align-items:center;
  justify-content:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; padding:16px; }
.card { background:#fff; border-radius:14px; padding:32px 24px;
  width:100%; max-width:380px; box-shadow:0 8px 32px rgba(0,0,0,.4); }
.logo { text-align:center; font-size:3rem; margin-bottom:10px; }
h2 { color:#8b0000; text-align:center; margin-bottom:4px; }
.sub { text-align:center; color:#666; font-size:.85rem; margin-bottom:22px; }
label { display:block; font-size:.85rem; font-weight:600; color:#444; margin-bottom:5px; }
input { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px;
  font-size:.95rem; margin-bottom:14px; }
button { width:100%; padding:14px; background:#8b0000; color:#fff; border:none;
  border-radius:8px; font-size:1rem; font-weight:700; cursor:pointer; letter-spacing:1px; }
.error { background:#f8d7da; color:#721c24; padding:10px 14px;
  border-radius:6px; margin-bottom:16px; font-size:.88rem; line-height:1.5; }
.note { font-size:.73rem; color:#999; text-align:center; margin-top:14px; line-height:1.6; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">🚨</div>
  <h2>PANIC ALERT</h2>
  <div class="sub">Verify identity to send alert</div>
  <?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <label>Username / Erf No / Phone</label>
    <input type="text" name="username" required autocomplete="username" autofocus>
    <label>Password / PIN</label>
    <input type="password" name="password" required>
    <button type="submit">🚨 VERIFY &amp; CONTINUE</button>
  </form>
  <div class="note">
    Guards and residents already logged in are taken directly to the alert screen.<br>
    Enter your guard username or resident erf/phone number and PIN.
  </div>
</div>
</body>
</html>
