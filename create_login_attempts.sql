-- ============================================================
-- GEMB Access System — Brute Force Protection
-- Run ONCE in phpMyAdmin SQL tab
-- ============================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    role         ENUM('admin','resident','guard','security')
                 NOT NULL,
    identifier   VARCHAR(100) NOT NULL,
    -- admin: username
    -- resident: E15227A
    -- guard: username
    -- security: username
    ip_address   VARCHAR(45)  DEFAULT NULL,
    attempted_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role_id  (role, identifier),
    INDEX idx_attempted (attempted_at)
);

-- Auto-cleanup: remove attempts older than 24 hours
-- (run as a scheduled task or on each login check)
-- DELETE FROM login_attempts
-- WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
