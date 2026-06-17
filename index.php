<?php require_once __DIR__ . '/db.php'; sessionStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?></title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:linear-gradient(160deg,#001a3a 0%,#0047ab 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border-radius:20px;padding:36px 28px;width:100%;max-width:400px;text-align:center;box-shadow:0 16px 48px rgba(0,0,0,.3)}
    .logo{width:140px;margin-bottom:16px}
    h1{color:#002855;font-size:20px;margin-bottom:4px}
    .sub{color:#777;font-size:13px;margin-bottom:28px}
    .role-btn{display:block;width:100%;padding:17px;margin:9px 0;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;color:#fff;text-decoration:none;transition:opacity .15s,transform .1s}
    .role-btn:hover{opacity:.9;transform:translateY(-1px)}
    .r{background:#007bff}.g{background:#218838}.a{background:#495057}
  </style>
</head>
<body>
  <div class="card">
    <img class="logo" src="logo.png" alt="GEMB Logo">
    <h1><?= e(APP_NAME) ?></h1>
    <p class="sub">Select your role to continue</p>
    <a class="role-btn r" href="login.php">Resident Portal</a>
    <a class="role-btn g" href="guard_login.php">Security Guard</a>
    <a class="role-btn a" href="admin_login.php">Estate Admin</a>
  </div>
</body>
</html>
