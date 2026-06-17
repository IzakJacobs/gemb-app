<?php
require_once __DIR__ . '/db.php';
sessionStart();

if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    header('Location: admin_dashboard.php'); exit;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $lockId   = 'admin_' . $email;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } elseif (isLocked($lockId)) {
        $err = 'Too many failed attempts. Try again in ' . lockoutSeconds($lockId) . ' seconds.';
    } else {
        $stmt = db()->prepare('SELECT id, password FROM admins WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if (!$admin || !password_verify($password, $admin['password'])) {
            $count = recordFail($lockId);
            $err = isLocked($lockId)
                ? 'Too many failed attempts. Account locked for 5 minutes.'
                : 'Invalid email or password. ' . (3 - $count) . ' attempt(s) remaining.';
        } else {
            clearLock($lockId);
            session_regenerate_id(true);
            $_SESSION['user_type'] = 'admin';
            $_SESSION['admin_id']  = $admin['id'];
            $_SESSION['admin_email'] = $email;
            header('Location: admin_dashboard.php'); exit;
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — GEMB</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:linear-gradient(160deg,#001a3a 0%,#002855 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border-radius:20px;padding:36px 28px;width:100%;max-width:380px;box-shadow:0 16px 48px rgba(0,0,0,.3)}
    .logo{display:block;width:110px;margin:0 auto 16px}
    h2{text-align:center;color:#002855;font-size:20px;margin-bottom:20px}
    .field{margin-bottom:14px}
    .field label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
    .field input{width:100%;padding:12px;font-size:15px;border:1px solid #ccc;border-radius:8px;outline:none}
    .field input:focus{border-color:#002855}
    .btn{width:100%;padding:14px;background:#495057;color:#fff;font-size:16px;font-weight:700;border:none;border-radius:10px;cursor:pointer;margin-top:4px}
    .btn:hover{background:#343a40}
    .err{background:#fff0f0;color:#c0392b;border:1px solid #f5c6c6;border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:14px}
    .back{display:block;text-align:center;margin-top:16px;color:#495057;text-decoration:none;font-size:13px}
  </style>
</head>
<body>
<div class="card">
  <img class="logo" src="logo.png" alt="GEMB Logo">
  <h2>Estate Admin Login</h2>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif ?>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="field"><label>Email Address</label><input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@estate.com" required autocomplete="username"></div>
    <div class="field"><label>Password</label><input type="password" name="password" placeholder="Password" required autocomplete="current-password"></div>
    <button class="btn" type="submit">Login</button>
  </form>
  <a class="back" href="index.php">Back to home</a>
</div>
</body>
</html>
