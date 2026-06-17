<?php
// ============================================================
// resident.php — LOGIN ACTION REPLACEMENT BLOCK ONLY
// Replace the entire $action === 'login' block in resident.php
// with this updated version that accepts E15227A format
// ============================================================

// ── LOGIN ─────────────────────────────────────────────────
if ($action === 'login') {

    /* Device token — persistent 10-year cookie */
    if (empty($_COOKIE['gemb_device'])) {
        $tok = bin2hex(random_bytes(24));
        setcookie('gemb_device', $tok,
                  time() + (10 * 365 * 24 * 60 * 60),
                  '/', '', true, true);
        $_COOKIE['gemb_device'] = $tok;
    }
    $deviceToken = $_COOKIE['gemb_device'];

    $step  = $_SESSION['login_step'] ?? 'credentials';
    $error = '';

    /* Cancel OTP */
    if (isset($_GET['cancel'])) {
        session_unset(); session_destroy();
        header('Location: resident.php?action=login'); exit;
    }
    /* OTP failure */
    if (isset($_GET['err']) && $_GET['err'] === 'otp') {
        $error = 'Incorrect OTP. Session reset. Please try again.';
        $step  = 'credentials';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        /* ── Step 1: Erf+Code + PIN ── */
        if ($step === 'credentials'
            && isset($_POST['erf_code'], $_POST['pin'])) {

            // Accept: E15227A or E15227 (defaults to A)
            $raw  = strtoupper(trim($_POST['erf_code']));
            $pin  = trim($_POST['pin']);

            // Split erf number from occupant code
            // Pattern: letters/numbers then optional single letter A-Z at end
            if (preg_match('/^([A-Z0-9]+?)([A-Z])$/', $raw, $m)) {
                $erfno = $m[1];           // E15227
                $code  = $m[2];           // A
            } else {
                $erfno = $raw;            // E15227 — default to A
                $code  = 'A';
            }

            if (!preg_match('/^\d{4}$/', $pin)) {
                $error = 'PIN must be exactly 4 digits.';
            } else {
                $res = db()->prepare("
                    SELECT id, resident_name, address,
                           resident_erfno, occupant_code,
                           occupant_type, is_primary,
                           phone, pin_hash, device_token, status
                    FROM residents
                    WHERE UPPER(resident_erfno) = ?
                      AND UPPER(occupant_code)  = ?
                      AND status = 'active'
                    LIMIT 1
                ");
                $res->execute([$erfno, $code]);
                $res = $res->fetch();

                if (!$res) {
                    $error = 'Erf/occupant code not found or inactive.';
                } elseif (!password_verify($pin, $res['pin_hash'])) {
                    $error = 'Incorrect PIN.';
                } elseif (empty($res['device_token'])) {
                    /* First login — register device */
                    db()->prepare(
                        "UPDATE residents
                         SET device_token=? WHERE id=?"
                    )->execute([$deviceToken, $res['id']]);
                    residentGrantAccess($res); exit;
                } elseif ($res['device_token'] === $deviceToken) {
                    /* Known device */
                    residentGrantAccess($res); exit;
                } else {
                    /* New device — OTP */
                    $_SESSION['login_step']    = 'otp';
                    $_SESSION['pending_id']    = $res['id'];
                    $_SESSION['pending_phone'] = $res['phone'];
                    header('Location: resident.php?action=login'); exit;
                }
            }
        }

        /* ── Step 2: OTP ── */
        elseif ($step === 'otp' && isset($_POST['otp'])) {
            $otp      = preg_replace('/\D/', '', trim($_POST['otp']));
            $phone    = $_SESSION['pending_phone'] ?? '';
            $expected = substr(preg_replace('/\D/', '', $phone), -6);

            if (strlen($otp) !== 6) {
                $error = 'OTP must be 6 digits.';
            } elseif ($otp !== $expected) {
                session_unset(); session_destroy();
                header('Location: resident.php?action=login&err=otp'); exit;
            } else {
                $_SESSION['login_step'] = 'reset';
                header('Location: resident.php?action=login'); exit;
            }
        }

        /* ── Step 3: New PIN ── */
        elseif ($step === 'reset'
                && isset($_POST['new_pin'], $_POST['confirm_pin'])) {
            $newPin = trim($_POST['new_pin']);
            $conPin = trim($_POST['confirm_pin']);
            $pid    = (int)($_SESSION['pending_id'] ?? 0);

            if (!preg_match('/^\d{4}$/', $newPin)) {
                $error = 'PIN must be exactly 4 digits.';
            } elseif ($newPin !== $conPin) {
                $error = 'PINs do not match.';
            } elseif (!$pid) {
                session_unset(); session_destroy();
                header('Location: resident.php?action=login'); exit;
            } else {
                db()->prepare(
                    "UPDATE residents
                     SET pin_hash=?, device_token=? WHERE id=?"
                )->execute([
                    password_hash($newPin, PASSWORD_BCRYPT),
                    $deviceToken, $pid
                ]);
                $res = db()->prepare(
                    "SELECT id, resident_name, address,
                            resident_erfno, occupant_code,
                            occupant_type, is_primary,
                            phone, pin_hash, device_token, status
                     FROM residents WHERE id=? LIMIT 1"
                );
                $res->execute([$pid]);
                residentGrantAccess($res->fetch()); exit;
            }
        }
    }

    /* ── Masked phone for OTP screen ── */
    $maskedPhone = '';
    if ($step === 'otp' && !empty($_SESSION['pending_phone'])) {
        $p = preg_replace('/\D/', '', $_SESSION['pending_phone']);
        $maskedPhone = substr($p, 0, 4) . '***' . substr($p, -3);
    }

    /* ── Render ── */
    pageHeader('Resident Login', 'resident');
    ?>
    <div class="login-wrap">
      <div class="login-card">
        <div class="login-logo">🏠</div>
        <h2>Resident Portal</h2>
        <div class="subtitle">Mossel Bay Golf Estate</div>

        <?php if ($error): ?>
          <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <?php if ($step === 'credentials'): ?>
        <!-- Step 1: Erf+Code + PIN -->
        <form method="POST" action="resident.php?action=login">
          <div class="form-group">
            <label>Erf Number + Code</label>
            <input type="text" name="erf_code" required
                   autocomplete="username"
                   style="text-transform:uppercase;font-size:1.1rem;
                          letter-spacing:0.1em;text-align:center;"
                   oninput="this.value=this.value.toUpperCase()"
                   placeholder="e.g. E15227A">
            <small style="color:#888;">
              Primary resident: E15227A &nbsp;|&nbsp;
              Second occupant: E15227B
            </small>
          </div>
          <div class="form-group">
            <label>4-digit PIN</label>
            <input type="password" name="pin" required
                   style="font-size:1.6rem;letter-spacing:0.4em;
                          text-align:center;"
                   maxlength="4" pattern="\d{4}"
                   inputmode="numeric"
                   autocomplete="current-password"
                   placeholder="••••">
          </div>
          <button type="submit"
                  class="btn btn-primary btn-block"
                  style="margin-top:8px;">
            Login
          </button>
        </form>

        <?php elseif ($step === 'otp'): ?>
        <!-- Step 2: OTP -->
        <div class="alert alert-info" style="font-size:.88rem;">
          🔐 <strong>New device detected.</strong><br><br>
          Enter the <strong>last 6 digits</strong> of your
          registered phone number.<br>
          Number on file:
          <strong><?= htmlspecialchars($maskedPhone) ?></strong>
        </div>
        <form method="POST" action="resident.php?action=login">
          <div class="form-group">
            <label>6-digit OTP</label>
            <input type="text" name="otp" required autofocus
                   style="font-size:1.6rem;letter-spacing:0.4em;
                          text-align:center;"
                   maxlength="6" pattern="\d{6}"
                   inputmode="numeric"
                   placeholder="_ _ _ _ _ _">
          </div>
          <button type="submit" class="btn btn-primary btn-block">
            Verify OTP
          </button>
        </form>
        <a href="resident.php?action=login&cancel=1"
           style="display:block;text-align:center;margin-top:14px;
                  font-size:.85rem;color:var(--muted);">
          ← Cancel and start over
        </a>

        <?php elseif ($step === 'reset'): ?>
        <!-- Step 3: New PIN -->
        <div class="alert alert-success" style="font-size:.88rem;">
          ✅ Identity verified.
          Please set a new 4-digit PIN for this device.
        </div>
        <form method="POST" action="resident.php?action=login">
          <div class="form-group">
            <label>New 4-digit PIN</label>
            <input type="password" name="new_pin" required autofocus
                   style="font-size:1.6rem;letter-spacing:0.4em;
                          text-align:center;"
                   maxlength="4" pattern="\d{4}"
                   inputmode="numeric" placeholder="••••">
          </div>
          <div class="form-group">
            <label>Confirm PIN</label>
            <input type="password" name="confirm_pin" required
                   style="font-size:1.6rem;letter-spacing:0.4em;
                          text-align:center;"
                   maxlength="4" pattern="\d{4}"
                   inputmode="numeric" placeholder="••••">
          </div>
          <button type="submit" class="btn btn-primary btn-block">
            Save PIN &amp; Login
          </button>
        </form>
        <?php endif; ?>

        <div class="popia-notice">
          Your personal information is processed under POPIA §11
          for estate security and management purposes.
        </div>
      </div>
    </div>
    <?php
    pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// Updated residentGrantAccess — includes occupant info
// ════════════════════════════════════════════════════════
function residentGrantAccess(array $res): void {
    session_regenerate_id(true);
    $_SESSION['resident_logged_in'] = true;
    $_SESSION['resident_id']        = $res['id'];
    $_SESSION['resident_name']      = $res['resident_name'];
    $_SESSION['resident_erf']       = $res['resident_erfno'];
    $_SESSION['resident_code']      = $res['occupant_code'];
    $_SESSION['resident_login']     = $res['resident_erfno']
                                    . $res['occupant_code'];
    // e.g. "E15227A"
    $_SESSION['resident_type']      = $res['occupant_type'];
    $_SESSION['is_primary']         = $res['is_primary'];
    $_SESSION['user_address']       = $res['address'];
    $_SESSION['last_activity']      = time();
    unset($_SESSION['login_step'],  $_SESSION['pending_id'],
          $_SESSION['pending_phone']);
    header('Location: resident.php?action=menu');
}
