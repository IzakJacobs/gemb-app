-- ============================================================
-- MBGE Gate Override — Migration
-- Run once in phpMyAdmin SQL tab
-- ============================================================

CREATE TABLE IF NOT EXISTS `pending_gate_overrides` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `gate`           VARCHAR(20) NOT NULL,
    `reason`         TEXT NOT NULL,
    `officer_name`   VARCHAR(100) NOT NULL,
    `officer_id`     INT NOT NULL,
    `resident_erfno` VARCHAR(20) NULL,
    `status`         ENUM('pending','executed','failed','expired') DEFAULT 'pending',
    `result_msg`     VARCHAR(255) NULL,
    `executed_at`    DATETIME NULL,
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at`     DATETIME NOT NULL   COMMENT 'Auto-expire if Pi does not respond within 5 min',
    INDEX(`status`),
    INDEX(`created_at`),
    INDEX(`expires_at`)
) ENGINE=InnoDB;

SELECT 'Gate override table created.' AS result;
