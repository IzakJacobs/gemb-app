<?php
// ============================================================
// GEMB Communications Portal — config.php
// /home/gembcoza/public_html/comms/config.php
//
// !! GITIGNORED — never commit this file !!
//
// This file is the ONLY place credentials are stored.
// All other comms files reach the database and SMTP
// exclusively through the constants and db() function
// defined here.
// ============================================================

// ── Database ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'gembcoza_comms');
define('DB_USER', 'gembcoza_commsuser');
define('DB_PASS', "2EG(olD[Gr$7UFT#");

// ── Site URL (no trailing slash) ─────────────────────────
define('SITE_URL', 'https://gemb.co.za/comms');

// ── SMTP mailer ──────────────────────────────────────────
define('SMTP_HOST', 'mail.gemb.co.za');
define('SMTP_PORT', 587);
define('SMTP_USER', 'comms@gemb.co.za');
define('SMTP_PASS', '!jH=x]=!M[HpB{DM');
define('SMTP_FROM', 'comms@gemb.co.za');
define('SMTP_NAME', 'GEMB Communications');

// ── File uploads ─────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/documents/');

// ── Session timeout ───────────────────────────────────────
define('SESSION_TIMEOUT', 14400);

// ── Timezone ─────────────────────────────────────────────
date_default_timezone_set('Africa/Johannesburg');

// ── PDO database connection ───────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('GEMB Comms DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            die('Service temporarily unavailable. Please try again later.');
        }
    }
    return $pdo;
}

// ── CSRF helpers ─────────────────────────────────────────
function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(generateCsrfToken()) . '">';
}

function verifyCsrfToken(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $submitted = $_POST['csrf_token'] ?? '';
    $stored    = $_SESSION['csrf_token'] ?? '';
    if (!$stored || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        die('Invalid or expired request token. Please go back and try again.');
    }
}

// ── Flash messages ───────────────────────────────────────
function setFlash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['flash'])) return '';

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $type = $flash['type'];
    $msg  = htmlspecialchars($flash['message']);

    $cssMap = [
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        'warning' => 'alert-warning',
        'info'    => 'alert-info',
    ];
    $css = $cssMap[$type] ?? 'alert-info';

    return '<div class="alert ' . $css . '">' . $msg . '</div>';
}

// ── Device token hashing ─────────────────────────────────
define('COMMS_HMAC_SECRET', 'aacbc658765ea9c4478fee5159372fd7c8f8fb4a9b5ddfbb2ceb9278f484e90f');

function hashDeviceToken(string $rawToken): string {
    return hash_hmac('sha256', $rawToken, COMMS_HMAC_SECRET);
}

// ── Vote session guard ───────────────────────────────────
function requireVoteSession(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['vote_meeting_id']) || empty($_SESSION['vote_erf'])) {
        header('Location: vote_login.php?action=login');
        exit;
    }
    if (!empty($_SESSION['vote_last_activity'])
        && (time() - $_SESSION['vote_last_activity']) > 7200) {
        session_unset();
        session_destroy();
        header('Location: vote_login.php?action=login');
        exit;
    }
    $_SESSION['vote_last_activity'] = time();
}

// ── Brute-force protection ───────────────────────────────
require_once __DIR__ . '/brute_force_helper.php';