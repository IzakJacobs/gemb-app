<?php
// ============================================================
// GEMB Communications — comms_login.php
// PHP 8.3 — password + fresh OTP for every administrator login
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/comms_otp_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

const COMMS_LOGIN_OTP_ATTEMPTS = 5;
const COMMS_LOGIN_OTP_SECONDS = 600;


function commsLoginLog(
    string $event,
    ?int $userId = null,
    ?string $email = null,
    ?string $detail = null
): void {
    try {
        db()->prepare(
            "INSERT INTO comms_login_log
                (user_id, email, event, ip_address, user_agent, detail)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $userId,
            $email,
            $event,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $detail
        ]);
    } catch (\Throwable $e) {
        error_log(
            'commsLoginLog failed: ' . $e->getMessage()
        );

        // OTP rate limiting depends on successful logging.
        // Never silently continue if otp_sent cannot be recorded.
        if ($event === 'otp_sent') {
            throw new \RuntimeException(
                'Unable to record OTP submission.',
                0,
                $e
            );
        }
    }
}


function commsClearPending(): void
{
    unset(
        $_SESSION['comms_login_step'],
        $_SESSION['comms_pending_id'],
        $_SESSION['comms_pending_email'],
        $_SESSION['comms_otp_started'],
        $_SESSION['comms_otp_attempts'],
        $_SESSION['comms_otp_verified'],
        $_SESSION['comms_force_expired']
    );
}

function commsGrantAccess(array $user): void
{
    if (
        empty($user['id']) ||
        empty($user['active']) ||
        empty($_SESSION['comms_otp_verified']) ||
        empty($_SESSION['comms_pending_id']) ||
        (int)$_SESSION['comms_pending_id'] !== (int)$user['id']
    ) {
        throw new \RuntimeException(
            'Administrator authentication is incomplete.'
        );
    }

    // Forced reset is permitted only after OTP verification.
    if (!empty($user['force_password_reset'])) {
        $_SESSION['comms_login_step'] = 'reset';
        $_SESSION['comms_force_expired'] = true;
        header('Location: comms_login.php');
        exit;
    }

    session_regenerate_id(true);

    $sessionToken = bin2hex(random_bytes(32));

    $stmt = db()->prepare(
        "UPDATE comms_users
         SET active_session_token = ?
         WHERE id = ? AND active = 1"
    );
    $stmt->execute([
        $sessionToken,
        (int)$user['id']
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new \RuntimeException(
            'Administrator account is not active.'
        );
    }

    commsClearPending();

    $_SESSION['comms_logged_in'] = true;
    $_SESSION['comms_user'] =
        $user['full_name'] ?: ($user['username'] ?? '');
    $_SESSION['comms_user_id'] = (int)$user['id'];
    $_SESSION['comms_session_token'] = $sessionToken;
    $_SESSION['last_activity'] = time();

    header('Location: comms_menu.php');
    exit;
}

function commsLoadUser(int $id): ?array
{
    $stmt = db()->prepare(
        "SELECT *
         FROM comms_users
         WHERE id = ? AND active = 1
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function commsResetLogin(): void
{
    commsClearPending();
    session_regenerate_id(true);
}

// ── Logout ─────────────────────────────────────────────────

$action = $_GET['action'] ?? 'login';

if ($action === 'logout') {
    $uid = (int)($_SESSION['comms_user_id'] ?? 0);
    $token = $_SESSION['comms_session_token'] ?? '';

    // Clear only this session's token, not another
    // administrator session that may have replaced it.
    if ($uid > 0 && $token !== '') {
        try {
            db()->prepare(
                "UPDATE comms_users
                 SET active_session_token = NULL
                 WHERE id = ?
                   AND active_session_token = ?"
            )->execute([$uid, $token]);
        } catch (\Throwable $e) {
            error_log('Comms logout failed: ' . $e->getMessage());
        }
    }

    commsLoginLog(
        'logout',
        $uid ?: null,
        null,
        'Administrator logged out'
    );

    // Remove only Communications authentication keys.
    // Do not destroy unrelated owner voting sessions.
    unset(
        $_SESSION['comms_logged_in'],
        $_SESSION['comms_user'],
        $_SESSION['comms_user_id'],
        $_SESSION['comms_session_token'],
        $_SESSION['last_activity']
    );
    commsClearPending();

    header('Location: comms_login.php');
    exit;
}

// ── Existing administrator session ─────────────────────────

if (!empty($_SESSION['comms_logged_in'])) {
    // Protected pages must still validate the session
    // token and inactivity period in commsRequireAuth().
    header('Location: comms_menu.php');
    exit;
}

// ── Cancel current challenge ───────────────────────────────

if (isset($_GET['cancel'])) {
    commsResetLogin();
    header('Location: comms_login.php');
    exit;
}

$step = $_SESSION['comms_login_step'] ?? 'credentials';
$error = '';
$username = '';

// Expire pending challenge after 10 minutes.
if (
    $step === 'otp' &&
    (
        empty($_SESSION['comms_otp_started']) ||
        time() - (int)$_SESSION['comms_otp_started']
            > COMMS_LOGIN_OTP_SECONDS
    )
) {
    commsResetLogin();
    $step = 'credentials';
    $error = 'Verification expired. Please sign in again.';
}

// Reset stage must be tied to successful OTP.
if (
    $step === 'reset' &&
    empty($_SESSION['comms_otp_verified'])
) {
    commsResetLogin();
    $step = 'credentials';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    // ── Credentials ────────────────────────────────────────
    if (
        $step === 'credentials' &&
        isset($_POST['username'], $_POST['password'])
    ) {
        $username = trim((string)$_POST['username']);
        $password = (string)$_POST['password'];

        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $lock = bfIsLocked('comms', $username);

                if ($lock && !empty($lock['locked'])) {
                    $error = 'Too many attempts. Please try again later.';
                } else {
                    $stmt = db()->prepare(
                        "SELECT *
                         FROM comms_users
                         WHERE email = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    if (
                        !$user ||
                        empty($user['active']) ||
                        !password_verify(
                            $password,
                            $user['password']
                        )
                    ) {
                        bfRecordFailure('comms', $username);

                        commsLoginLog(
                            'login_fail',
                            $user ? (int)$user['id'] : null,
                            $username,
                            'Invalid credentials'
                        );

                        $error = 'Invalid email or password.';
                    } else {
                        // Never grant access at this point.
                        // Every login requires a new OTP.
                        commsClearPending();

                        $email = trim((string)$user['email']);
                        $sent = generateCommsEmailOtp($email);

                        if (!$sent) {
                            $error =
                                'A verification code could not be ' .
                                'sent. Please try again later.';

                            commsLoginLog(
                                'otp_fail',
                                (int)$user['id'],
                                $email,
                                'OTP generation or submission failed'
                            );
                        } else {
                            $_SESSION['comms_login_step'] = 'otp';
                            $_SESSION['comms_pending_id'] =
                                (int)$user['id'];
                            $_SESSION['comms_pending_email'] = $email;
                            $_SESSION['comms_otp_started'] = time();
                            $_SESSION['comms_otp_attempts'] = 0;
                            $_SESSION['comms_otp_verified'] = false;

                            header('Location: comms_login.php');
                            exit;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log(
                    'Comms credentials error: ' . $e->getMessage()
                );
                $error =
                    'Sign-in is temporarily unavailable. ' .
                    'Please try again later.';
            }
        }
    }

    // ── OTP verification ───────────────────────────────────
    elseif (
        $step === 'otp' &&
        isset($_POST['otp'])
    ) {
        $email = (string)(
            $_SESSION['comms_pending_email'] ?? ''
        );
        $pid = (int)(
            $_SESSION['comms_pending_id'] ?? 0
        );
        $otp = trim((string)$_POST['otp']);

        $attempts =
            (int)($_SESSION['comms_otp_attempts'] ?? 0);

        if (
            $pid <= 0 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            empty($_SESSION['comms_otp_started']) ||
            time() - (int)$_SESSION['comms_otp_started']
                > COMMS_LOGIN_OTP_SECONDS ||
            $attempts >= COMMS_LOGIN_OTP_ATTEMPTS
        ) {
            commsResetLogin();
            $step = 'credentials';
            $error =
                'Verification expired or was locked. ' .
                'Please sign in again.';
        } else {
            // Count every submission, including malformed codes.
            $_SESSION['comms_otp_attempts'] = $attempts + 1;

            $valid = preg_match('/^\d{6}$/D', $otp)
                && verifyCommsEmailOtp($email, $otp);

            if (!$valid) {
                commsLoginLog(
                    'otp_fail',
                    $pid,
                    $email,
                    'Incorrect administrator OTP'
                );

                if (
                    $_SESSION['comms_otp_attempts']
                    >= COMMS_LOGIN_OTP_ATTEMPTS
                ) {
                    commsResetLogin();
                    $step = 'credentials';
                    $error =
                        'Too many incorrect codes. ' .
                        'Please sign in again.';
                } else {
                    $error = 'Incorrect verification code.';
                }
            } else {
                try {
                    $user = commsLoadUser($pid);

                    if (
                        !$user ||
                        strcasecmp(
                            (string)$user['email'],
                            $email
                        ) !== 0
                    ) {
                        throw new \RuntimeException(
                            'Pending account is unavailable.'
                        );
                    }

                    $_SESSION['comms_otp_verified'] = true;

                    // Clear password failures only after
                    // completing both authentication factors.
                    bfClearAttempts('comms', $email);

                    commsLoginLog(
                        'login_ok',
                        $pid,
                        $email,
                        'Password and OTP verified'
                    );

                    commsGrantAccess($user);
                } catch (\Throwable $e) {
                    error_log(
                        'Comms OTP completion error: ' .
                        $e->getMessage()
                    );
                    commsResetLogin();
                    $step = 'credentials';
                    $error =
                        'Sign-in could not be completed. ' .
                        'Please try again.';
                }
            }
        }
    }

    // ── Forced password reset ──────────────────────────────
    elseif (
        $step === 'reset' &&
        isset(
            $_POST['new_password'],
            $_POST['confirm_password']
        )
    ) {
        $pid = (int)(
            $_SESSION['comms_pending_id'] ?? 0
        );
        $newPass = (string)$_POST['new_password'];
        $confirm = (string)$_POST['confirm_password'];

        if (
            !$pid ||
            empty($_SESSION['comms_otp_verified'])
        ) {
            commsResetLogin();
            $step = 'credentials';
            $error = 'Please sign in again.';
        } elseif (strlen($newPass) < 8) {
            $error = 'Password must have at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $newPass)) {
            $error = 'Include at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $newPass)) {
            $error = 'Include at least one lowercase letter.';
        } elseif (
            !preg_match(
                '/[!@#$%^&*()\-_=+\[\]{}|;:,.<>?]/',
                $newPass
            )
        ) {
            $error = 'Include at least one special character.';
        } elseif ($newPass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $user = commsLoadUser($pid);

                if (!$user) {
                    throw new \RuntimeException(
                        'Account not found.'
                    );
                }

                if (
                    password_verify(
                        $newPass,
                        $user['password']
                    )
                ) {
                    $error =
                        'Choose a password different ' .
                        'from your current password.';
                } else {
                    $stmt = db()->prepare(
                        "UPDATE comms_users
                         SET password = ?,
                             password_changed_at = NOW(),
                             force_password_reset = 0
                         WHERE id = ? AND active = 1"
                    );
                    $stmt->execute([
                        password_hash(
                            $newPass,
                            PASSWORD_BCRYPT
                        ),
                        $pid
                    ]);

                    $user = commsLoadUser($pid);

                    if (!$user) {
                        throw new \RuntimeException(
                            'Account unavailable after reset.'
                        );
                    }

                    commsLoginLog(
                        'login_ok',
                        $pid,
                        (string)$user['email'],
                        'Forced password reset completed'
                    );

                    commsGrantAccess($user);
                }
            } catch (\Throwable $e) {
                error_log(
                    'Comms password reset error: ' .
                    $e->getMessage()
                );
                $error =
                    'Password could not be updated. ' .
                    'Please try again.';
            }
        }
    }
}

$csrfToken = generateCsrfToken();

function commsEscape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$pendingEmail =
    (string)($_SESSION['comms_pending_email'] ?? '');

$maskedEmail = '';

if (filter_var($pendingEmail, FILTER_VALIDATE_EMAIL)) {
    [$local, $domain] = explode('@', $pendingEmail, 2);
    $maskedEmail =
        substr($local, 0, 2) .
        str_repeat('*', max(2, strlen($local) - 2)) .
        '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — GEMB Communications</title>
<link rel="manifest" href="manifest-comms.php">
<link rel="apple-touch-icon" href="apple-touch-icon-comms.png">
<meta name="theme-color" content="#0D47A1">
<style>
*,*::before,*::after{
    box-sizing:border-box;
}
body{
    margin:0;
    min-height:100vh;
    display:flex;
    font-family:-apple-system,BlinkMacSystemFont,
        "Segoe UI",Roboto,Arial,sans-serif;
    color:#1A1A2E;
    background:#F0F4FA;
}
.brand{
    flex:1;
    background:linear-gradient(
        160deg,#0D2B5E,#0D47A1,#0277BD
    );
    color:white;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:45px;
    text-align:center;
}
.brand .symbol{
    font-size:4rem;
}
.brand h1{
    margin:15px 0 5px;
    font-size:1.9rem;
}
.brand p{
    opacity:.8;
}
.channels{
    margin-top:30px;
    text-align:left;
}
.channels div{
    padding:12px 20px;
    margin:9px;
    border-radius:9px;
    background:rgba(255,255,255,.12);
}
.panel{
    width:420px;
    min-width:340px;
    padding:45px 40px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.inner{
    width:100%;
}
h2{
    font-size:1.55rem;
    margin:0 0 8px;
}
.sub{
    color:#64748B;
    font-size:.9rem;
    line-height:1.5;
    margin-bottom:25px;
}
label{
    display:block;
    font-size:.78rem;
    font-weight:700;
    text-transform:uppercase;
    color:#64748B;
    margin:18px 0 7px;
}
input{
    width:100%;
    border:1.5px solid #C9D3E0;
    border-radius:8px;
    padding:13px;
    font-size:1rem;
    background:white;
}
input:focus{
    outline:none;
    border-color:#0D47A1;
    box-shadow:0 0 0 3px rgba(13,71,161,.12);
}
button{
    width:100%;
    margin-top:24px;
    padding:14px;
    border:0;
    border-radius:8px;
    background:#0D47A1;
    color:white;
    font-size:1rem;
    font-weight:700;
    cursor:pointer;
}
button:hover{
    background:#1565C0;
}
.notice,.error{
    border-radius:8px;
    padding:13px;
    margin:18px 0;
    font-size:.9rem;
    line-height:1.5;
}
.notice{
    background:#EFF6FF;
    border:1px solid #BFDBFE;
    color:#1D4ED8;
}
.error{
    background:#FEF2F2;
    border:1px solid #FECACA;
    color:#B91C1C;
}
.back{
    display:block;
    text-align:center;
    margin-top:24px;
    font-size:.85rem;
    color:#64748B;
    text-decoration:none;
}
.footer{
    margin-top:30px;
    border-top:1px solid #C9D3E0;
    padding-top:16px;
    font-size:.74rem;
    color:#64748B;
    line-height:1.6;
}
.otp{
    font-size:1.6rem;
    letter-spacing:.3em;
    text-align:center;
}
@media(max-width:720px){
    body{
        flex-direction:column;
    }
    .brand{
        padding:30px 20px;
    }
    .brand .symbol{
        font-size:2.5rem;
    }
    .brand h1{
        font-size:1.4rem;
    }
    .channels{
        display:none;
    }
    .panel{
        width:100%;
        min-width:0;
        padding:35px 25px;
    }
}
</style>
</head>
<body>

<section class="brand">
    <div class="symbol">📣</div>
    <h1>GEMB Communications</h1>
    <p>Estate Communications &amp; Engagement Portal</p>
    <div class="channels">
        <div>📋 Surveys &amp; Polls</div>
        <div>🗳️ Voting</div>
    </div>
</section>

<main class="panel">
<div class="inner">

<?php if ($step === 'credentials'): ?>

    <h2>Sign In</h2>
    <p class="sub">
        Communications Portal — authorised staff only.
        A verification code is required for every sign-in.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= commsEscape($error) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="comms_login.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= commsEscape($csrfToken) ?>"
        >

        <label for="username">Email Address</label>
        <input
            id="username"
            name="username"
            type="email"
            autocomplete="email"
            required
            autofocus
            value="<?= commsEscape($username) ?>"
        >

        <label for="password">Password</label>
        <input
            id="password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            Sign In
        </button>
    </form>

    <a class="back" href="comms.php">
        &larr; Back to Welcome Screen
    </a>

<?php elseif ($step === 'otp'): ?>

    <h2>Verify Your Sign-In</h2>

    <div class="notice">
        A fresh six-digit verification code has been
        submitted for delivery to
        <strong><?= commsEscape($maskedEmail) ?></strong>.
        <p>
            Enter the code within 10 minutes.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= commsEscape($error) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="comms_login.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= commsEscape($csrfToken) ?>"
        >

        <label for="otp">Verification Code</label>
        <input
            id="otp"
            name="otp"
            class="otp"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            pattern="[0-9]{6}"
            required
            autofocus
        >

        <button type="submit">
            Verify Code
        </button>
    </form>

    <a class="back" href="comms_login.php?cancel=1">
        &larr; Cancel and start over
    </a>

<?php elseif ($step === 'reset'): ?>

    <h2>Set a New Password</h2>

    <div class="notice">
        Your identity has been verified.
        An administrator has required a password reset
        before you can continue.
    </div>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= commsEscape($error) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="comms_login.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= commsEscape($csrfToken) ?>"
        >

        <label for="new_password">
            New Password
        </label>
        <input
            id="new_password"
            name="new_password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            autofocus
        >

        <label for="confirm_password">
            Confirm New Password
        </label>
        <input
            id="confirm_password"
            name="confirm_password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
        >

        <button type="submit">
            Save Password &amp; Sign In
        </button>
    </form>

    <a class="back" href="comms_login.php?cancel=1">
        &larr; Cancel
    </a>

<?php endif; ?>

<div class="footer">
    GEMB Communications Portal.
    Administrator access is protected by password
    and email verification.
    Personal information is handled in accordance
    with applicable privacy requirements.
</div>

</div>
</main>
</body>
</html>
