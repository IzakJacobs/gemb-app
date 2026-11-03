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

// Configure the voting session before layout.php starts it.
ini_set('session.gc_maxlifetime', '10800');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 10800,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// layout.php loads config.php and starts the session.
require_once __DIR__ . '/layout.php';

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
    if (empty($_COOKIE['gemb_vote_device'])) {
        $tok = bin2hex(random_bytes(24));
        setcookie('gemb_vote_device', $tok, time() + (10 * 365 * 24 * 60 * 60), '/', '', true, true);
        $_COOKIE['gemb_vote_device'] = $tok;
    }
    $deviceToken = $_COOKIE['gemb_vote_device'];

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
$pdo = db();
$accessGranted = false;
$meetingStillOpen = false;

try {
    $pdo->beginTransaction();

    // Serialize login claims with meeting-close operations.
    $meetingStmt = $pdo->prepare(
        "SELECT id, status
         FROM meetings
         WHERE id = ?
         FOR UPDATE"
    );
    $meetingStmt->execute([(int)$openMeeting['id']]);
    $currentMeeting = $meetingStmt->fetch();

    if ($currentMeeting && $currentMeeting['status'] === 'open') {
        $meetingStillOpen = true;
        // Prevent a device already registered to another erf
        // from claiming this erf's voting token.
        $deviceStmt = $pdo->prepare(
            "SELECT id
             FROM vote_tokens
             WHERE meeting_id = ?
               AND erf_number <> ?
               AND device_token = ?
             LIMIT 1"
        );

        $deviceStmt->execute([
            (int)$openMeeting['id'],
            $erf,
            $hashedDevice
        ]);

        if ($deviceStmt->fetchColumn() !== false) {
            $error = 'This device has already been used for another erf.';
        } else {
        // Only one device can claim an unassigned token.
        // Recheck the submitted credentials in the UPDATE.
        $claimStmt = $pdo->prepare(
            "UPDATE vote_tokens
             SET device_token = ?, used_at = NOW()
             WHERE id = ?
               AND meeting_id = ?
               AND erf_number = ?
               AND token = ?
               AND device_token IS NULL"
        );

        $claimStmt->execute([
            $hashedDevice,
            (int)$vt['id'],
            (int)$openMeeting['id'],
            $erf,
            $token
        ]);

        if ($claimStmt->rowCount() === 1) {
            $accessGranted = true;
        } else {
            // Re-read the token after the attempted claim.
            // This also supports re-entry from the original device.
            $ownerStmt = $pdo->prepare(
                "SELECT device_token
                 FROM vote_tokens
                 WHERE id = ?
                   AND meeting_id = ?
                   AND erf_number = ?
                   AND token = ?
                 LIMIT 1"
            );

            $ownerStmt->execute([
                (int)$vt['id'],
                (int)$openMeeting['id'],
                $erf,
                $token
            ]);

            $storedDevice = $ownerStmt->fetchColumn();

            if (is_string($storedDevice)
                && hash_equals($storedDevice, $hashedDevice)) {
                $accessGranted = true;
            }
        }
        } // End device-availability check
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $accessGranted = false;
    error_log('Voting device claim failed: ' . $e->getMessage());
    $error = 'Unable to complete voting login. Please try again.';
}

if ($accessGranted) {
    bfClearAttempts('vote', $erf);
    voteGrantAccess((int)$openMeeting['id'], $erf);
    exit;
}

if ($error === '') {
    if (!$meetingStillOpen) {
        $error = 'This meeting is no longer open for voting.';
    } else {
        $error = 'This token has already been used on a different device. '
               . 'If you believe this is an error, please contact the HOA Board.';
    }
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

