<?php
require_once __DIR__ . '/config.php';

// db() and $conn are provided by config.php — do not redeclare here.

function sessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_active'] = time();
}

// CSRF
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $submitted = $_POST['csrf'] ?? '';
    if (!hash_equals(($_SESSION['csrf'] ?? ''), $submitted)) {
        http_response_code(403);
        die('Invalid request token. Please go back and try again.');
    }
}

// Lockout (stored in login_attempts table) — uses global $conn (mysqli)
function isLocked(string $id): bool {
    global $conn;
    $stmt = $conn->prepare('SELECT 1 FROM login_attempts WHERE identifier=? AND locked_until > NOW()');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function lockoutSeconds(string $id): int {
    global $conn;
    $stmt = $conn->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), locked_until)) s FROM login_attempts WHERE identifier=?');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r ? (int)$r['s'] : 0;
}

function recordFail(string $id, int $max = 3, int $lockMins = 5): int {
    global $conn;
    $stmt = $conn->prepare('
        INSERT INTO login_attempts (identifier, attempts, locked_until)
        VALUES (?, 1, NULL)
        ON DUPLICATE KEY UPDATE
            attempts    = IF(locked_until IS NOT NULL AND locked_until <= NOW(), 1, attempts + 1),
            locked_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL)
    ');
    $stmt->bind_param('sii', $id, $max, $lockMins);
    $stmt->execute();

    $stmt2 = $conn->prepare('SELECT attempts FROM login_attempts WHERE identifier=?');
    $stmt2->bind_param('s', $id);
    $stmt2->execute();
    $r = $stmt2->get_result()->fetch_assoc();
    return $r ? (int)$r['attempts'] : 1;
}

function clearLock(string $id): void {
    global $conn;
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE identifier=?');
    $stmt->bind_param('s', $id);
    $stmt->execute();
}

// Settings table
function getSetting(string $key, string $default = ''): string {
    global $conn;
    $stmt = $conn->prepare('SELECT value FROM settings WHERE `key`=?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r ? $r['value'] : $default;
}

function setSetting(string $key, string $value): void {
    global $conn;
    $stmt = $conn->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=?');
    $stmt->bind_param('sss', $key, $value, $value);
    $stmt->execute();
}

// Output escaping
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// JSON response helper (for api.php)
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Auth guards
function requireResident(): array {
    sessionStart();
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
        header('Location: login.php');
        exit;
    }
    return $_SESSION['resident'];
}

function requireGuard(): void {
    sessionStart();
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'guard') {
        header('Location: guard_login.php');
        exit;
    }
}

function requireAdmin(): void {
    sessionStart();
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: admin_login.php');
        exit;
    }
}
