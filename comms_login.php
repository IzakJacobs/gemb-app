<?php
// ============================================================
// gemB / MBGE — comms_login.php
// Standalone login for the Communications module.
//
// Used only when Communications is deployed as a stand-alone
// product (not embedded in the MBGE admin suite). If the person
// arrives here while already holding an admin session
// ($_SESSION['admin_logged_in']), they're sent straight through —
// embedded deployments never need this page.
//
// comms_users table: id, username, password (bcrypt), full_name,
// email, active, created_at  — see comms_schema.sql
//
// Security:
//   - Brute-force protection via existing bfXxx() helpers,
//     keyed under role='comms'
//   - CSRF token on the form
//   - password_verify() / bcrypt
// ============================================================

require_once __DIR__ . '/comms_core.php';

$action = $_GET['action'] ?? 'login';

// ── LOGOUT ─────────────────────────────────────────────────
// Must run BEFORE the "already authenticated" check below —
// otherwise a logged-in user hitting ?action=logout would be
// redirected straight back to comms.php and logout would never
// execute.
if ($action === 'logout') {
    // Full session destruction (same approach as logout.php) —
    // a "Logout" click from comms.php should end the session
    // completely, regardless of whether it was an embedded admin
    // session or a standalone comms_users session.
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: comms_login.php?action=login'); exit;
}

// ── Already authenticated (embedded admin OR standalone comms) ──
if (!empty($_SESSION['admin_id']) || !empty($_SESSION['comms_logged_in'])) {
    header('Location: comms.php'); exit;
}

// ── LOGIN ──────────────────────────────────────────────────
if ($action === 'login') {

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password']      ?? '';

        $lockCheck = bfIsLocked('comms', $user);
        if ($lockCheck['locked']) {
            $error = bfLockoutMessage($lockCheck);
        } else {
            $stmt = db()->prepare("SELECT * FROM comms_users WHERE username = ? AND active = 1 LIMIT 1");
            $stmt->execute([$user]);
            $cu = $stmt->fetch();

            if (!$cu || !password_verify($pass, $cu['password'])) {
                bfRecordFailure('comms', $user);
                $remaining = bfAttemptsRemaining('comms', $user);
                $error = 'Invalid credentials.'
                       . ($remaining <= 2 ? ' ' . bfWarningMessage($remaining) : '');
            } else {
                bfClearAttempts('comms', $user);
                session_regenerate_id(true);
                $_SESSION['comms_logged_in'] = true;
                $_SESSION['comms_user']      = $cu['full_name'] ?: $cu['username'];
                $_SESSION['comms_user_id']   = (int)$cu['id'];
                header('Location: comms.php'); exit;
            }
        }
    }

    pageHeader('Communications Login', '');
    ?>
    <div class="login-wrap">
      <div class="login-card">
        <div class="login-logo">📣</div>
        <h2>Communications</h2>
        <div class="subtitle">Sign in to send and manage estate communications</div>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="comms_login.php?action=login">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required autofocus autocomplete="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
            Sign In
          </button>
        </form>

        <div class="popia-notice">
          This is a standalone Communications portal. If your organisation runs
          Communications inside the MBGE admin suite, sign in there instead.
        </div>
      </div>
    </div>
    <?php
    pageFooter(); exit;
}

// Fallback
header('Location: comms_login.php?action=login'); exit;
