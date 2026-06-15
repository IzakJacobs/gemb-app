<?php
// ============================================================
// logout_vote.php — MBGE Access Control
// Clears the token-based voting session (vote_login.php /
// vote_cast.php) and returns to the voting login screen.
//
// This is separate from logout.php (which handles the four
// portal roles: admin, resident, guard, security). The voting
// session uses its own session keys (vote_meeting_id, vote_erf,
// vote_last_activity) and its own device cookie
// (mbge_vote_device), which is intentionally preserved — it is
// the legitimate one-token-per-device binding and must persist
// so a returning voter can be recognised on the same device.
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Step 1: Wipe all session variables ────────────────────
$_SESSION = [];

// ── Step 2: Delete the session cookie from the browser ────
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

// ── Step 3: Destroy the server-side session data ──────────
session_destroy();

// ── Step 4: Prevent the browser caching this page ─────────
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ── Step 5: Redirect back to voting token entry ───────────
header('Location: vote_login.php?action=login');
exit;
