<?php
require_once __DIR__ . '/db.php';
sessionStart();

// Already logged in
if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') {
    header('Location: visitor.php'); exit;
}

$tab = 'login';
$loginEmail = $regName = $regUnit = $regEmail = '';
$loginErr = $regErr = $regOk = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $tab = $_POST['tab'] ?? 'login';

    // ---- LOGIN ----
    if ($tab === 'login') {
        $loginEmail = trim($_POST['email'] ?? '');
        $pin        = implode('', array_slice($_POST['pin'] ?? [], 0, 6));

        if (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
            $loginErr = 'Please enter a valid email address.';
        } elseif (!preg_match('/^\d{6}$/', $pin)) {
            $loginErr = 'Please enter your 6-digit PIN.';
        } elseif (isLocked($loginEmail)) {
            $loginErr = 'Too many failed attempts. Try again in ' . lockoutSeconds($loginEmail) . ' seconds.';
        } else {
            $stmt = db()->prepare('SELECT id,name,unit,pin_hash,status FROM users WHERE email=?');
            $stmt->bind_param('s', $loginEmail);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user || !password_verify($pin, $user['pin_hash'])) {
                $count = recordFail($loginEmail);
                if (isLocked($loginEmail)) {
                    $loginErr = 'Too many failed attempts. Account locked for 5 minutes.';
                } else {
                    $loginErr = 'Incorrect email or PIN. ' . (3 - $count) . ' attempt(s) remaining.';
                }
            } elseif ($user['status'] === 'pending') {
                $loginErr = 'Your account is pending admin approval. Please contact estate management.';
            } else {
                clearLock($loginEmail);
                session_regenerate_id(true);
                $_SESSION['user_type'] = 'resident';
                $_SESSION['resident']  = ['id' => $user['id'], 'name' => $user['name'], 'unit' => $user['unit'], 'email' => $loginEmail];
                header('Location: visitor.php'); exit;
            }
        }

    // ---- REGISTER ----
    } else {
        $regName  = trim($_POST['reg_name']  ?? '');
        $regUnit  = strtoupper(trim($_POST['reg_unit']  ?? ''));
        $regEmail = trim($_POST['reg_email'] ?? '');
        $pin      = implode('', array_slice($_POST['reg_pin'] ?? [], 0, 6));

        if (!$regName)                                    $regErr = 'Please enter your full name.';
        elseif (!$regUnit)                                $regErr = 'Please enter your unit number.';
        elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) $regErr = 'Please enter a valid email address.';
        elseif (!preg_match('/^\d{6}$/', $pin))           $regErr = 'Please set a 6-digit numeric PIN.';
        else {
            $chk = db()->prepare('SELECT id FROM users WHERE email=?');
            $chk->bind_param('s', $regEmail);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $regErr = 'An account with this email already exists. Please log in.';
            } else {
                $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
                $ins  = db()->prepare('INSERT INTO users (name,unit,email,pin_hash,status) VALUES (?,?,?,?,\'pending\')');
                $ins->bind_param('ssss', $regName, $regUnit, $regEmail, $hash);
                if ($ins->execute()) {
                    $regOk = 'Account created! Please wait for admin approval before logging in.';
                    $regName = $regUnit = $regEmail = '';
                } else {
                    $regErr = 'Registration failed. Please try again.';
                }
            }
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
  <title>Resident Login — MBGE</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:linear-gradient(160deg,#001a3a 0%,#0047ab 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border-radius:20px;padding:32px 24px;width:100%;max-width:400px;box-shadow:0 16px 48px rgba(0,0,0,.3)}
    .logo{display:block;width:120px;margin:0 auto 16px}
    h2{text-align:center;color:#002855;font-size:20px;margin-bottom:20px}
    .tabs{display:flex;border-radius:10px;overflow:hidden;border:2px solid #007bff;margin-bottom:22px}
    .tab-btn{flex:1;padding:10px;border:none;background:#fff;color:#007bff;font-size:15px;font-weight:600;cursor:pointer}
    .tab-btn.active{background:#007bff;color:#fff}
    .section{display:none}.section.active{display:block}
    .field{margin-bottom:13px}
    .field label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
    .field input[type=email],.field input[type=text]{width:100%;padding:12px;font-size:15px;border:1px solid #ccc;border-radius:8px;outline:none}
    .field input:focus{border-color:#007bff}
    .pin-row{display:flex;gap:7px}
    .pin-row input{flex:1;height:50px;font-size:22px;text-align:center;border:1px solid #ccc;border-radius:8px;outline:none;padding:0}
    .pin-row input:focus{border-color:#007bff}
    .btn{width:100%;padding:14px;background:#007bff;color:#fff;font-size:16px;font-weight:700;border:none;border-radius:10px;cursor:pointer;margin-top:6px}
    .btn:hover{background:#0062cc}
    .back{display:block;text-align:center;margin-top:18px;color:#007bff;text-decoration:none;font-size:13px}
    .msg{font-size:13px;margin-top:8px;padding:9px 11px;border-radius:6px}
    .err{background:#fff0f0;color:#c0392b;border:1px solid #f5c6c6}
    .ok {background:#f0fff4;color:#1e7e34;border:1px solid #b7dfc0}
  </style>
</head>
<body>
<div class="card">
  <img class="logo" src="logo.png" alt="MBGE Logo">
  <h2>Resident Portal</h2>

  <div class="tabs">
    <button class="tab-btn <?= $tab==='login'?'active':'' ?>" onclick="sw('login')">Login</button>
    <button class="tab-btn <?= $tab==='register'?'active':'' ?>" onclick="sw('register')">Register</button>
  </div>

  <!-- Login -->
  <div id="loginSection" class="section <?= $tab==='login'?'active':'' ?>">
    <?php if ($loginErr): ?><div class="msg err"><?= e($loginErr) ?></div><?php endif ?>
    <form method="POST">
      <input type="hidden" name="tab" value="login">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="field"><label>Email Address</label>
        <input type="email" name="email" value="<?= e($loginEmail) ?>" placeholder="your@email.com" required autocomplete="email">
      </div>
      <div class="field"><label>6-Digit PIN</label>
        <div class="pin-row" id="lp">
          <?php for ($i=0;$i<6;$i++): ?>
          <input type="password" name="pin[]" maxlength="1" inputmode="numeric" autocomplete="off" required>
          <?php endfor ?>
        </div>
      </div>
      <button class="btn" type="submit">Login</button>
    </form>
  </div>

  <!-- Register -->
  <div id="registerSection" class="section <?= $tab==='register'?'active':'' ?>">
    <?php if ($regErr): ?><div class="msg err"><?= e($regErr) ?></div><?php endif ?>
    <?php if ($regOk):  ?><div class="msg ok"><?= e($regOk) ?></div><?php endif ?>
    <form method="POST">
      <input type="hidden" name="tab" value="register">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="field"><label>Full Name</label><input type="text" name="reg_name" value="<?= e($regName) ?>" placeholder="e.g. John Smith" required></div>
      <div class="field"><label>Unit Number</label><input type="text" name="reg_unit" value="<?= e($regUnit) ?>" placeholder="e.g. A12" required></div>
      <div class="field"><label>Email Address</label><input type="email" name="reg_email" value="<?= e($regEmail) ?>" placeholder="your@email.com" required></div>
      <div class="field"><label>Set 6-Digit PIN</label>
        <div class="pin-row" id="rp">
          <?php for ($i=0;$i<6;$i++): ?>
          <input type="password" name="reg_pin[]" maxlength="1" inputmode="numeric" autocomplete="new-password" required>
          <?php endfor ?>
        </div>
      </div>
      <button class="btn" type="submit">Create Account</button>
    </form>
  </div>

  <a class="back" href="index.php">Back to home</a>
</div>
<script>
  function sw(t){
    document.getElementById('loginSection').classList.toggle('active',t==='login');
    document.getElementById('registerSection').classList.toggle('active',t==='register');
    document.querySelectorAll('.tab-btn').forEach((b,i)=>b.classList.toggle('active',(i===0)===(t==='login')));
  }
  function pinRow(id){
    const inputs=document.querySelectorAll('#'+id+' input');
    inputs.forEach((inp,i)=>{
      inp.addEventListener('input',()=>{if(!/^\d$/.test(inp.value)){inp.value='';return;}if(i<inputs.length-1)inputs[i+1].focus();});
      inp.addEventListener('keydown',e=>{if(e.key==='Backspace'&&!inp.value&&i>0)inputs[i-1].focus();});
    });
  }
  pinRow('lp'); pinRow('rp');
</script>
</body>
</html>
