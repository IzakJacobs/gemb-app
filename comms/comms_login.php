<?php
// ============================================================
// GEMB Communications Portal — comms_login.php
// Standalone login for the Communications module.
//
// Completely independent visual design — no shared CSS or
// layout with the estate access control system.
//
// Security:
//   - CSRF token on the form
//   - bcrypt password verification
//   - Brute-force lockout (role = 'comms') via bfXxx() helpers
//   - Persistent device-token cookie; unrecognised device on a
//     known account triggers an email OTP challenge
//   - 30-day forced password rotation, tied to the same flow
//
// On success → comms_menu.php
// ============================================================

require_once __DIR__ . '/cfg.php';
require_once __DIR__ . '/comms_otp_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Device token — persistent 10-year cookie ────────────────
if (empty($_COOKIE['gemb_comms_device'])) {
    $tok = bin2hex(random_bytes(24));
    setcookie('gemb_comms_device', $tok, [
        'expires'  => time() + (10 * 365 * 24 * 60 * 60),
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $_COOKIE['gemb_comms_device'] = $tok;
}
$deviceToken = $_COOKIE['gemb_comms_device'];

$action = $_GET['action'] ?? 'login';

// ── LOGOUT ─────────────────────────────────────────────────
if ($action === 'logout') {
    $uid = (int)($_SESSION['comms_user_id'] ?? 0);
    if ($uid) {
        db()->prepare("UPDATE comms_users SET active_session_token=NULL WHERE id=?")
            ->execute([$uid]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Location: comms_login.php'); exit;
}

// ── Already logged in ───────────────────────────────────────
if (!empty($_SESSION['comms_logged_in']) || !empty($_SESSION['admin_id'])) {
    header('Location: comms_menu.php'); exit;
}

$step     = $_SESSION['comms_login_step'] ?? 'credentials';
$error    = '';
$username = '';

if (isset($_GET['cancel'])) {
    session_unset(); session_destroy();
    header('Location: comms_login.php'); exit;
}
if (isset($_GET['err']) && $_GET['err'] === 'otp') {
    $error = 'Incorrect OTP. Session reset. Please try again.';
    $step  = 'credentials';
}
if (isset($_GET['err']) && $_GET['err'] === 'elsewhere') {
    $error = 'You have been signed out because this account was signed in from another device.';
    $step  = 'credentials';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    /* ── Step 1: Username + Password ── */
    if ($step === 'credentials' && isset($_POST['username'], $_POST['password'])) {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']      ?? '';

        $lockCheck = bfIsLocked('comms', $username);
        if ($lockCheck['locked']) {
            $error = bfLockoutMessage($lockCheck);
        } else {
            $stmt = db()->prepare(
                "SELECT id, username, password, full_name, email, active,
                        device_token, password_changed_at
                 FROM comms_users WHERE username = ? LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !$user['active'] || !password_verify($password, $user['password'])) {
                bfRecordFailure('comms', $username);
                $remaining = bfAttemptsRemaining('comms', $username);
                $error = 'Invalid username or password.';
                if ($remaining > 0 && $remaining <= 2) {
                    $error .= ' ' . bfWarningMessage($remaining);
                }
            } else {
                bfClearAttempts('comms', $username);

                if (empty($user['device_token'])) {
                    /* First login ever on this account — register device */
                    db()->prepare(
                        "UPDATE comms_users SET device_token=? WHERE id=?"
                    )->execute([commsHashDeviceToken($deviceToken), $user['id']]);
                    commsGrantAccess($user); exit;
                } elseif (hash_equals($user['device_token'], commsHashDeviceToken($deviceToken))) {
                    commsGrantAccess($user); exit;
                } else {
                    /* New device — email OTP */
                    $userEmail = trim($user['email'] ?? '');
                    if ($userEmail === '') {
                        $error = 'No email address is on file for this account, so a '
                               . 'one-time PIN cannot be sent. Please ask another comms '
                               . 'administrator to add your email address before logging '
                               . 'in from a new device.';
                    } else {
                        $_SESSION['comms_login_step']    = 'otp';
                        $_SESSION['comms_pending_id']     = $user['id'];
                        $_SESSION['comms_pending_email']  = $userEmail;
                        generateCommsEmailOtp($userEmail);
                        header('Location: comms_login.php'); exit;
                    }
                }
            }
        }
    }

    /* ── Step 2: OTP ── */
    elseif ($step === 'otp' && isset($_POST['otp'])) {
        $otp   = preg_replace('/\D/', '', trim($_POST['otp']));
        $email = $_SESSION['comms_pending_email'] ?? '';

        if (strlen($otp) !== 6) {
            $error = 'OTP must be 6 digits.';
        } elseif (!verifyCommsEmailOtp($email, $otp)) {
            session_unset(); session_destroy();
            header('Location: comms_login.php?err=otp'); exit;
        } else {
            $_SESSION['comms_login_step'] = 'reset';
            header('Location: comms_login.php'); exit;
        }
    }

    /* ── Step 3: New password + register device ── */
    elseif ($step === 'reset' && isset($_POST['new_password'], $_POST['confirm_password'])) {
        $newPass = trim($_POST['new_password']);
        $conPass = trim($_POST['confirm_password']);
        $pid     = (int)($_SESSION['comms_pending_id'] ?? 0);

        if (strlen($newPass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPass !== $conPass) {
            $error = 'Passwords do not match.';
        } elseif (!$pid) {
            session_unset(); session_destroy();
            header('Location: comms_login.php'); exit;
        } else {
            $cur = db()->prepare("SELECT password FROM comms_users WHERE id=? LIMIT 1");
            $cur->execute([$pid]);
            $cur = $cur->fetch();
            if ($cur && password_verify($newPass, $cur['password'])) {
                $error = 'New password must be different from your current password.';
            } else {
                db()->prepare(
                    "UPDATE comms_users SET password=?, device_token=?, password_changed_at=NOW() WHERE id=?"
                )->execute([
                    password_hash($newPass, PASSWORD_BCRYPT),
                    commsHashDeviceToken($deviceToken), $pid
                ]);
                unset($_SESSION['comms_force_expired']);
                $user = db()->prepare("SELECT * FROM comms_users WHERE id=? LIMIT 1");
                $user->execute([$pid]);
                commsGrantAccess($user->fetch()); exit;
            }
        }
    }
}

// ── Generate CSRF token for the form ────────────────────────
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sign In — GEMB Communications</title>
  <link rel="manifest" href="manifest-comms.php">
  <link rel="apple-touch-icon" href="apple-touch-icon-comms.png">
  <meta name="theme-color" content="#0D47A1">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --cp:       #0D47A1;
      --cp-dark:  #0A2F6B;
      --cp-hover: #1565C0;
      --bg:       #F0F4FA;
      --white:    #FFFFFF;
      --border:   #C9D3E0;
      --text:     #1A1A2E;
      --muted:    #64748B;
      --danger:   #B91C1C;
      --danger-bg:#FEF2F2;
      --danger-br:#FECACA;
      --success:  #15803D;
      --success-bg:#F0FDF4;
      --success-br:#BBF7D0;
      --info-bg:  #EFF6FF;
      --info-br:  #BFDBFE;
      --info-tx:  #1D4ED8;
      --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    body {
      font-family: var(--font);
      min-height: 100vh;
      display: flex;
      background: var(--white);
    }

    /* ── Left brand panel ── */
    .brand-panel {
      flex: 1;
      background: linear-gradient(160deg, #0D2B5E 0%, #0D47A1 55%, #0277BD 100%);
      color: #fff;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 56px 40px;
      min-height: 100vh;
    }
    .bp-icon  { font-size: 4rem; margin-bottom: 18px; filter: drop-shadow(0 3px 10px rgba(0,0,0,.3)); }
    .bp-title { font-size: 1.8rem; font-weight: 800; margin-bottom: 8px; text-align: center; }
    .bp-sub   { font-size: .9rem; opacity: .75; margin-bottom: 44px; text-align: center; }

    .channel-list {
      list-style: none;
      width: 100%;
      max-width: 270px;
    }
    .channel-list li {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 11px 16px;
      margin-bottom: 8px;
      background: rgba(255,255,255,.1);
      border: 1px solid rgba(255,255,255,.15);
      border-radius: 10px;
      font-size: .88rem;
      font-weight: 500;
    }
    .channel-list li .ci { font-size: 1.15rem; }

    /* ── Right login panel ── */
    .login-panel {
      width: 420px;
      min-width: 340px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 48px 40px;
      background: var(--bg);
    }
    .login-inner { width: 100%; }

    .login-inner h2 {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text);
      margin-bottom: 6px;
    }
    .login-inner .sub {
      font-size: .87rem;
      color: var(--muted);
      margin-bottom: 30px;
      line-height: 1.5;
    }

    /* Boxes */
    .error-box, .info-box, .success-box {
      padding: 11px 14px;
      border-radius: 8px;
      font-size: .88rem;
      margin-bottom: 22px;
      line-height: 1.5;
    }
    .error-box   { background: var(--danger-bg);  border: 1px solid var(--danger-br);  color: var(--danger); }
    .info-box    { background: var(--info-bg);    border: 1px solid var(--info-br);    color: var(--info-tx); }
    .success-box { background: var(--success-bg); border: 1px solid var(--success-br); color: var(--success); }

    /* Form */
    .form-group       { margin-bottom: 20px; }
    .form-group label {
      display: block;
      font-size: .78rem;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .6px;
      margin-bottom: 7px;
    }
    .form-group input {
      width: 100%;
      padding: 12px 14px;
      border: 1.5px solid var(--border);
      border-radius: 8px;
      font-size: .95rem;
      font-family: var(--font);
      background: var(--white);
      color: var(--text);
      transition: border-color .2s, box-shadow .2s;
    }
    .form-group input:focus {
      outline: none;
      border-color: var(--cp);
      box-shadow: 0 0 0 3px rgba(13,71,161,.12);
    }
    .otp-input {
      font-size: 1.6rem !important;
      letter-spacing: .4em !important;
      text-align: center;
    }

    .btn-signin {
      display: block;
      width: 100%;
      padding: 13px;
      margin-top: 4px;
      background: var(--cp);
      color: #fff;
      font-size: 1rem;
      font-weight: 700;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      letter-spacing: .3px;
      transition: background .2s, transform .1s;
    }
    .btn-signin:hover  { background: var(--cp-hover); }
    .btn-signin:active { transform: scale(.98); }

    /* Back link */
    .back-link {
      display: block;
      text-align: center;
      font-size: .82rem;
      color: var(--muted);
      margin-top: 22px;
      text-decoration: none;
      transition: color .15s;
    }
    .back-link:hover { color: var(--cp); }

    /* Footer notice */
    .popia {
      font-size: .72rem;
      color: var(--muted);
      border-top: 1px solid var(--border);
      margin-top: 28px;
      padding-top: 16px;
      line-height: 1.55;
    }

    /* ── Responsive: stack on mobile ── */
    @media (max-width: 720px) {
      body           { flex-direction: column; }
      .brand-panel   { min-height: auto; padding: 40px 24px; flex: none; }
      .bp-icon       { font-size: 2.8rem; margin-bottom: 12px; }
      .bp-title      { font-size: 1.3rem; }
      .channel-list  { display: none; }
      .login-panel   { width: 100%; min-width: unset; padding: 36px 24px; flex: 1; }
    }
  </style>
</head>
<body>

  <!-- Left brand panel -->
  <div class="brand-panel">
    <div class="bp-icon">📣</div>
    <h1 class="bp-title">GEMB Communications</h1>
    <p class="bp-sub">Estate Communications &amp; Engagement Portal</p>

    <ul class="channel-list">
      <li><span class="ci">👥</span> Standalone Contact List</li>
      <li><span class="ci">📤</span> Bulk Messages</li>
      <li><span class="ci">💰</span> Personalised Levy Statements</li>
      <li><span class="ci">📋</span> Surveys &amp; Polls</li>
      <li><span class="ci">🗳️</span> Voting</li>
      <li><span class="ci">📊</span> Reporting</li>
    </ul>
  </div>

  <!-- Right login panel -->
  <div class="login-panel">
    <div class="login-inner">

      <?php if ($step === 'credentials'): ?>

        <h2>Sign In</h2>
        <p class="sub">
          Communications Portal — authorised staff only.<br>
          Your contact list CSV must be imported before sending.
        </p>

        <?php if ($error): ?>
        <div class="error-box"><?= $error /* may contain HTML from bfWarningMessage */ ?></div>
        <?php endif; ?>

        <form method="POST" action="comms_login.php" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

          <div class="form-group">
            <label for="uname">Username</label>
            <input type="text" id="uname" name="username"
                   required autofocus autocomplete="username"
                   value="<?= htmlspecialchars($username) ?>">
          </div>
          <div class="form-group">
            <label for="upass">Password</label>
            <input type="password" id="upass" name="password"
                   required autocomplete="current-password">
          </div>

          <button type="submit" class="btn-signin">Sign In &rarr;</button>
        </form>

        <a href="comms.php" class="back-link">&larr; Back to Welcome Screen</a>

      <?php elseif ($step === 'otp'): ?>

        <h2>Verify This Device</h2>
        <div class="info-box">
          📧 <strong>New device detected.</strong><br><br>
          <?php
          $pendingEmail = $_SESSION['comms_pending_email'] ?? '';
          if ($pendingEmail) {
              $parts  = explode('@', $pendingEmail);
              $masked = substr($parts[0], 0, 2)
                      . str_repeat('*', max(2, strlen($parts[0]) - 2))
                      . '@' . ($parts[1] ?? '');
              echo 'A <strong>6-digit code</strong> has been sent to <strong>'
                   . htmlspecialchars($masked) . '</strong>.';
          } else {
              echo 'No email address is on file for this account. '
                   . 'Please contact another comms administrator.';
          }
          ?><br>
          Enter the code below to verify your identity.
        </div>

        <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="comms_login.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="form-group">
            <label for="otp">6-digit Code</label>
            <input type="text" id="otp" name="otp" class="otp-input"
                   required autofocus maxlength="6" pattern="\d{6}"
                   inputmode="numeric" placeholder="_ _ _ _ _ _">
          </div>
          <button type="submit" class="btn-signin">Verify Code</button>
        </form>

        <a href="comms_login.php?cancel=1" class="back-link">&larr; Cancel and start over</a>

      <?php elseif ($step === 'reset'): ?>

        <h2>Set a New Password</h2>
        <div class="<?= !empty($_SESSION['comms_force_expired']) ? 'info-box' : 'success-box' ?>">
          <?php if (!empty($_SESSION['comms_force_expired'])): ?>
            ⏰ Your password has expired under the 30-day policy.
            Please set a new one to continue.
          <?php else: ?>
            ✅ Identity verified.
            Please set a new password for this device.
          <?php endif; ?>
        </div>

        <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="comms_login.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="form-group">
            <label for="newpw">New Password (min 8 characters)</label>
            <input type="password" id="newpw" name="new_password" required
                   autofocus autocomplete="new-password" minlength="8">
          </div>
          <div class="form-group">
            <label for="conpw">Confirm Password</label>
            <input type="password" id="conpw" name="confirm_password" required
                   autocomplete="new-password" minlength="8">
          </div>
          <button type="submit" class="btn-signin">Save Password &amp; Sign In</button>
        </form>

      <?php endif; ?>

      <div class="popia">
        Access to this portal is restricted to authorised estate management personnel.
        All activity is logged under POPIA Act 4 of 2013. Unauthorised access is
        prohibited and will be reported.
      </div>

    </div>
  </div>

  <script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw-comms.php').catch(function(){});
  }
  </script>

</body>
</html>
<?php
// ── Helper ────────────────────────────────────────────────
function commsGrantAccess(array $user): void {
    $changed = $user['password_changed_at'] ?? null;
    if (empty($changed) || strtotime($changed) < strtotime('-30 days')) {
        $_SESSION['comms_login_step']    = 'reset';
        $_SESSION['comms_pending_id']    = $user['id'];
        $_SESSION['comms_force_expired'] = true;
        header('Location: comms_login.php'); exit;
    }
    session_regenerate_id(true);

    $sessionToken = bin2hex(random_bytes(32));
    db()->prepare("UPDATE comms_users SET active_session_token=? WHERE id=?")
        ->execute([$sessionToken, $user['id']]);

    $_SESSION['comms_logged_in']     = true;
    $_SESSION['comms_user']          = $user['full_name'] ?: $user['username'];
    $_SESSION['comms_user_id']       = (int)$user['id'];
    $_SESSION['comms_session_token'] = $sessionToken;
    $_SESSION['last_activity']       = time();
    unset($_SESSION['comms_login_step'], $_SESSION['comms_pending_id'], $_SESSION['comms_pending_email']);
    header('Location: comms_menu.php'); exit;
}
