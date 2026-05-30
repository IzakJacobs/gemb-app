<?php
require_once __DIR__ . '/db.php';
sessionStart();

if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'guard') {
    header('Location: guard.php'); exit;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $code = trim($_POST['code'] ?? '');
    $lockId = 'guard_access';

    if (isLocked($lockId)) {
        $err = 'Too many failed attempts. Try again in ' . lockoutSeconds($lockId) . ' seconds.';
    } else {
        $stored = getSetting('guard_code_hash');
        $valid  = $stored && password_verify($code, $stored);

        if (!$valid) {
            $count = recordFail($lockId);
            $err   = isLocked($lockId)
                ? 'Too many failed attempts. Locked for 5 minutes.'
                : 'Incorrect guard code. ' . (3 - $count) . ' attempt(s) remaining.';
        } else {
            clearLock($lockId);
            session_regenerate_id(true);
            $_SESSION['user_type'] = 'guard';
            header('Location: guard.php'); exit;
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
  <title>Guard Access — MBGE</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:linear-gradient(160deg,#0d3d1a 0%,#1a5c2a 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border-radius:20px;padding:36px 28px;width:100%;max-width:360px;text-align:center;box-shadow:0 16px 48px rgba(0,0,0,.3)}
    .logo{width:110px;margin-bottom:16px}
    h2{color:#1a5c2a;font-size:20px;margin-bottom:6px}
    p{color:#666;font-size:13px;margin-bottom:20px}
    input[type=password]{width:100%;padding:14px;font-size:18px;text-align:center;border:1px solid #ccc;border-radius:8px;outline:none;letter-spacing:4px;margin-bottom:6px}
    input:focus{border-color:#1a5c2a}
    .btn{width:100%;padding:14px;background:#1a5c2a;color:#fff;font-size:16px;font-weight:700;border:none;border-radius:10px;cursor:pointer;margin-top:6px}
    .btn:hover{background:#145221}
    .err{background:#fff0f0;color:#c0392b;border:1px solid #f5c6c6;border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px}
    .back{display:block;margin-top:16px;color:#1a5c2a;text-decoration:none;font-size:13px}
  </style>
</head>
<body>
<div class="card">
  <img class="logo" src="logo.png" alt="MBGE Logo">
  <h2>Security Gate Access</h2>
  <p>Enter the guard access code to continue.<br>Set by the estate admin.</p>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif ?>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="password" name="code" placeholder="Enter access code" autocomplete="current-password" required>
    <button class="btn" type="submit">Enter Gate</button>
  </form>
  <a class="back" href="index.php">Back to home</a>
</div>
</body>
</html>
