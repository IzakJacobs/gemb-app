<?php
// ============================================================
// vote_login.php — Token-based Voting Login (standalone)
// genB Access Control System
// Version 1.0  |  2026-06-14
//
// Standalone entry point for AGM/SGM electronic voting.
// No resident portal login required — access is via the
// 6-digit token emailed to the registered owner (per the
// levy roll uploaded for the currently open meeting).
//
// Flow:
//   1. Resident enters Erf Number + 6-digit Token
//   2. Token looked up in vote_tokens for the single open meeting
//   3. If device_token is NULL — first use: bind to this device,
//      create vote session, proceed to vote_cast.php
//   4. If device_token is set and matches this device — proceed
//   5. If device_token is set and does NOT match — reject
//      ("token already used on another device")
//
// Security:
//   - Device token is a persistent cookie, hashed via
//     hashDeviceToken() before storage/comparison (same
//     mechanism as resident.php)
//   - CSRF token on the form
//   - Brute-force protection via existing bfXxx() helpers,
//     keyed on erf_number for the 'vote' realm
// ============================================================

require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$action = $_GET['action'] ?? 'login';

// ── Already in an active vote session for the open meeting ──
if ($action === 'login' && !empty($_SESSION['vote_meeting_id']) && !empty($_SESSION['vote_erf'])) {
    header('Location: vote_cast.php?action=list'); exit;
}

// ── Find the single currently-open meeting ───────────────
$openMeeting = db()->query(
    "SELECT * FROM meetings WHERE status = 'open' ORDER BY id DESC LIMIT 1"
)->fetch();

if ($action === 'login') {

    /* Device token — persistent 10-year cookie, separate from
       the resident portal's device cookie */
    if (empty($_COOKIE['mbge_vote_device'])) {
        $tok = bin2hex(random_bytes(24));
        setcookie('mbge_vote_device', $tok, time() + (10 * 365 * 24 * 60 * 60), '/', '', true, true);
        $_COOKIE['mbge_vote_device'] = $tok;
    }
    $deviceToken = $_COOKIE['mbge_vote_device'];

    $error = '';

    if (!$openMeeting) {
        pageHeader('Voting', 'resident');
        ?>
        <div class="login-wrap">
          <div class="login-card">
            <div class="login-logo">🗳️</div>
            <h2>Voting</h2>
            <div class="subtitle">Mossel Bay Golf Estate</div>
            <div class="alert alert-info" style="font-size:.88rem;">
              There is no meeting currently open for voting.
              Please check back when an AGM/SGM voting window is active,
              or refer to the email you were sent for details.
            </div>
          </div>
        </div>
        <?php
        pageFooter(); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $erf   = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($_POST['erf_number'] ?? '')));
        $token = preg_replace('/\D/', '', trim($_POST['token'] ?? ''));

        $lockCheck = bfIsLocked('vote', $erf);
        if ($lockCheck['locked']) {
            $error = bfLockoutMessage($lockCheck);
        } elseif ($erf === '' || strlen($token) !== 6) {
            $error = 'Please enter your erf number and the 6-digit token from your email.';
        } else {
            $stmt = db()->prepare(
                "SELECT * FROM vote_tokens
                 WHERE meeting_id = ? AND erf_number = ? AND token = ?
                 LIMIT 1"
            );
            $stmt->execute([$openMeeting['id'], $erf, $token]);
            $vt = $stmt->fetch();

            if (!$vt) {
                bfRecordFailure('vote', $erf);
                $remaining = bfAttemptsRemaining('vote', $erf);
                $error = 'Erf number or token is incorrect.'
                       . ($remaining <= 2 ? ' ' . bfWarningMessage($remaining) : '');
            } else {
                $hashedDevice = hashDeviceToken($deviceToken);

                if (empty($vt['device_token'])) {
                    // First use — bind to this device
                    db()->prepare(
                        "UPDATE vote_tokens SET device_token=?, used_at=NOW() WHERE id=?"
                    )->execute([$hashedDevice, $vt['id']]);
                    bfClearAttempts('vote', $erf);
                    voteGrantAccess($openMeeting['id'], $erf);
                    exit;
                } elseif (hash_equals($vt['device_token'], $hashedDevice)) {
                    // Same device — allow re-entry
                    bfClearAttempts('vote', $erf);
                    voteGrantAccess($openMeeting['id'], $erf);
                    exit;
                } else {
                    // Token already used on a different device
                    $error = 'This token has already been used to vote from a different device. '
                           . 'If you believe this is an error, please contact the HOA Board.';
                }
            }
        }
    }

    pageHeader('Voting Login', 'resident');
    ?>
    <div class="login-wrap">
      <div class="login-card">
        <div class="login-logo">🗳️</div>
        <h2>Cast Your Vote</h2>
        <div class="subtitle"><?= htmlspecialchars($openMeeting['title']) ?></div>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="vote_login.php?action=login">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Erf Number</label>
            <input type="text" name="erf_number" required
                   autocomplete="off"
                   style="text-transform:uppercase;font-size:1.1rem;
                          letter-spacing:0.1em;text-align:center;"
                   oninput="this.value=this.value.toUpperCase()"
                   placeholder="e.g. E15227"
                   maxlength="10">
          </div>
          <div class="form-group">
            <label>6-digit Token <span class="muted-note">(from your email)</span></label>
            <input type="text" name="token" required autofocus
                   style="font-size:1.6rem;letter-spacing:0.4em;text-align:center;"
                   maxlength="6" pattern="\d{6}" inputmode="numeric"
                   placeholder="_ _ _ _ _ _">
          </div>
          <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
            Continue to Vote
          </button>
        </form>

        <div class="popia-notice">
          Your personal information is processed under POPIA §11 for estate
          governance purposes. This token may only be used once, from one device.
        </div>
      </div>
    </div>
    <?php
    pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// HELPER
// ════════════════════════════════════════════════════════
function voteGrantAccess(int $meetingId, string $erf): void {
    session_regenerate_id(true);
    $_SESSION['vote_meeting_id'] = $meetingId;
    $_SESSION['vote_erf']        = $erf;
    $_SESSION['vote_last_activity'] = time();
    header('Location: vote_cast.php?action=list'); exit;
}

// Fallback
header('Location: vote_login.php?action=login'); exit;
