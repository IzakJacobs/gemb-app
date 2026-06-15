<?php
/**
 * Brute force protection helpers
 * ─────────────────────────────────────────────────────────
 * Loaded by config.php — available to all login files.
 *
 * Rules:
 *   - 5 failed attempts within 15 minutes = lockout
 *   - Lockout duration: 15 minutes
 *   - Counter resets on successful login
 *   - Tracks by role + identifier + IP
 */

define('BF_MAX_ATTEMPTS',  5);
define('BF_WINDOW_MINS',   15);
define('BF_LOCKOUT_MINS',  15);

function bfIsLocked(string $role, string $identifier): array
{
    db()->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        role         VARCHAR(20)  NOT NULL,
        identifier   VARCHAR(100) NOT NULL,
        ip_address   VARCHAR(45)  DEFAULT NULL,
        attempted_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role_id   (role, identifier),
        INDEX idx_attempted (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = db()->prepare("
        SELECT COUNT(*) as cnt,
               MAX(attempted_at) as last_attempt
        FROM login_attempts
        WHERE role       = ?
          AND identifier = ?
          AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$role, $identifier, BF_WINDOW_MINS]);
    $row = $stmt->fetch();

    if ((int)$row['cnt'] >= BF_MAX_ATTEMPTS && $row['last_attempt']) {
        $unlockAt  = strtotime($row['last_attempt']) + (BF_LOCKOUT_MINS * 60);
        $remaining = $unlockAt - time();
        if ($remaining > 0) {
            return [
                'locked'  => true,
                'minutes' => ceil($remaining / 60),
                'until'   => date('H:i', $unlockAt),
                'count'   => (int)$row['cnt'],
            ];
        }
    }

    return ['locked' => false, 'count' => (int)$row['cnt']];
}

function bfRecordFailure(string $role, string $identifier): void
{
    $ip = trim(explode(',',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    )[0]);
    db()->prepare("
        INSERT INTO login_attempts (role, identifier, ip_address)
        VALUES (?, ?, ?)
    ")->execute([$role, $identifier, $ip]);
}

function bfClearAttempts(string $role, string $identifier): void
{
    db()->prepare("
        DELETE FROM login_attempts WHERE role = ? AND identifier = ?
    ")->execute([$role, $identifier]);
}

function bfAttemptsRemaining(string $role, string $identifier): int
{
    $check = bfIsLocked($role, $identifier);
    return max(0, BF_MAX_ATTEMPTS - (int)($check['count'] ?? 0));
}

function bfLockoutMessage(array $lockInfo): string
{
    return "Account locked after " . BF_MAX_ATTEMPTS . " failed attempts. "
         . "Try again at <strong>{$lockInfo['until']}</strong> "
         . "({$lockInfo['minutes']} minute"
         . ($lockInfo['minutes'] > 1 ? 's' : '') . " remaining).";
}

function bfWarningMessage(int $remaining): string
{
    if ($remaining <= 0) return '';
    if ($remaining === 1)
        return "⚠️ <strong>1 attempt remaining</strong> before account is locked.";
    if ($remaining <= 2)
        return "⚠️ {$remaining} attempts remaining before account is locked.";
    return '';
}