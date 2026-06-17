<?php
// ============================================================
// GEMB Access Control — panic/panic_login.php
// Isolated session — separate from main app sessions
// ============================================================
session_name('gemb_panic');
session_start();

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $stmt = db()->prepare("SELECT * FROM guards WHERE username = ?");
    $stmt->execute([$user]);
    $guard = $stmt->fetch();
    if ($guard && password_verify($pass, $guard['password'])) {
        $_SESSION['panic_guard_id']   = $guard['id'];
        $_SESSION['panic_guard_name'] = $guard['full_name'];
        $_SESSION['panic_gate']       = $_SESSION['panic_gate'] ?? 'Unknown';
        header('Location: panic_menu.php'); exit;
    }
    $error = 'Invalid credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PANIC ALERT — GEMB</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { background:#8b0000; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
.card { background:#fff; border-radius:14px; padding:32px 24px; width:100%; max-width:360px; box-shadow:0 8px 32px rgba(0,0,0,.4); }
h2 { color:#8b0000; text-align:center; margin-bottom:6px; }
.sub { text-align:center; color:#666; font-size:.85rem; margin-bottom:24px; }
.logo { text-align:center; font-size:3rem; margin-bottom:10px; }
label { display:block; font-size:.85rem; font-weight:600; color:#444; margin-bottom:5px; }
input { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:.95rem; margin-bottom:14px; }
button { width:100%; padding:12px; background:#8b0000; color:#fff; border:none; border-radius:6px; font-size:1rem; font-weight:700; cursor:pointer; }
.error { background:#f8d7da; color:#721c24; padding:10px; border-radius:6px; margin-bottom:14px; font-size:.88rem; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">🚨</div>
  <h2>PANIC ALERT</h2>
  <div class="sub">Guard verification required</div>
  <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="POST">
    <label>Username</label>
    <input type="text" name="username" required autocomplete="username">
    <label>Password</label>
    <input type="password" name="password" required autocomplete="current-password">
    <button type="submit">SEND PANIC ALERT</button>
  </form>
</div>
</body>
</html>
