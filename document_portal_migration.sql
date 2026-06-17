-- ============================================================
-- MBGE Document Portal — Migration
-- Run once in phpMyAdmin SQL tab
-- ============================================================

-- 1. Broadcast log (one row per PDF upload or levy import)
CREATE TABLE IF NOT EXISTS `document_broadcasts` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `type`        ENUM('circular','levy') NOT NULL,
    `title`       VARCHAR(200) NOT NULL,
    `filename`    VARCHAR(255) NULL             COMMENT 'Stored filename (PDFs)',
    `original_name` VARCHAR(255) NULL           COMMENT 'Original uploaded filename',
    `sent_to`     INT NOT NULL DEFAULT 0        COMMENT 'Number of emails sent',
    `failed`      INT NOT NULL DEFAULT 0        COMMENT 'Number of failed sends',
    `sent_by`     INT NULL                      COMMENT 'Admin user ID',
    `notes`       TEXT NULL,
    `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(`type`),
    INDEX(`created_at`)
) ENGINE=InnoDB;

-- 2. Individual send log (one row per email sent)
CREATE TABLE IF NOT EXISTS `document_send_log` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `broadcast_id`   INT NOT NULL,
    `recipient_email` VARCHAR(150) NOT NULL,
    `recipient_name`  VARCHAR(100) NULL,
    `amount`          DECIMAL(10,2) NULL        COMMENT 'Levy amount (levy type only)',
    `message`         TEXT NULL                 COMMENT 'Personal message (levy type only)',
    `status`          ENUM('sent','failed') DEFAULT 'sent',
    `error_msg`       VARCHAR(255) NULL,
    `sent_at`         DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(`broadcast_id`),
    INDEX(`status`),
    FOREIGN KEY (`broadcast_id`) REFERENCES `document_broadcasts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

SELECT 'Document portal tables created.' AS result;
