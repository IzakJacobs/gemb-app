-- ============================================================
-- MBGE Access Control System — Database Installation
-- Run this once on a fresh MySQL database
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Admins
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(60) NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Security officers
CREATE TABLE IF NOT EXISTS `security_users` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(60) NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Guards
CREATE TABLE IF NOT EXISTS `guards` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(60) NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `assigned_gate` ENUM('SSgate','CSgate','Any') DEFAULT 'Any',
  `device_token`  VARCHAR(255) NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. Residents
CREATE TABLE IF NOT EXISTS `residents` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(60) NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `erf_no`        VARCHAR(20) NOT NULL,
  `address`       VARCHAR(200) NOT NULL,
  `phone`         VARCHAR(30) NOT NULL,
  `email`         VARCHAR(150) NULL,
  `status`        ENUM('active','inactive') DEFAULT 'active',
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 5. Resident vehicles (LPR)
CREATE TABLE IF NOT EXISTS `resident_vehicles` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `resident_id`   INT NOT NULL,
  `plate`         VARCHAR(20) NOT NULL,
  `description`   VARCHAR(100) NULL,
  `active`        TINYINT(1) DEFAULT 1,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(`plate`),
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Visitors (pre-registered)
CREATE TABLE IF NOT EXISTS `visitors` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `resident_id`   INT NOT NULL,
  `visitor_name`  VARCHAR(100) NOT NULL,
  `id_number`     VARCHAR(30) NULL,
  `phone`         VARCHAR(30) NULL,
  `vehicle_plate` VARCHAR(20) NULL,
  `visit_date`    DATE NOT NULL,
  `valid_from`    DATETIME NOT NULL,
  `valid_until`   DATETIME NOT NULL,
  `qr_code`       VARCHAR(80) NOT NULL UNIQUE,
  `used`          TINYINT(1) DEFAULT 0,
  `used_at`       DATETIME NULL,
  `status`        ENUM('active','expired','cancelled') DEFAULT 'active',
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(`qr_code`),
  INDEX(`visit_date`),
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. Service providers / contractors
CREATE TABLE IF NOT EXISTS `service_providers` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `full_name`     VARCHAR(100) NOT NULL,
  `company`       VARCHAR(100) NULL,
  `id_number`     VARCHAR(30) NULL,
  `phone`         VARCHAR(30) NULL,
  `resident_id`   INT NULL,
  `work_description` VARCHAR(200) NULL,
  `qr_code`       VARCHAR(80) NOT NULL UNIQUE,
  `valid_from`    DATE NOT NULL,
  `valid_until`   DATE NOT NULL,
  `status`        ENUM('pending','approved','active','expired','revoked') DEFAULT 'pending',
  `approved_by`   INT NULL,
  `approved_at`   DATETIME NULL,
  `photo_path`    VARCHAR(255) NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(`qr_code`),
  INDEX(`status`)
) ENGINE=InnoDB;

-- 8. Access log
CREATE TABLE IF NOT EXISTS `access_log` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `event_id`      VARCHAR(20) NOT NULL UNIQUE,
  `gate`          ENUM('SSgate','CSgate','Unknown') NOT NULL,
  `direction`     ENUM('IN','OUT') NOT NULL,
  `entry_type`    ENUM('resident','visitor','service_provider','unknown') NOT NULL,
  `person_name`   VARCHAR(100) NULL,
  `plate`         VARCHAR(20) NULL,
  `qr_code`       VARCHAR(80) NULL,
  `resident_id`   INT NULL,
  `visitor_id`    INT NULL,
  `sp_id`         INT NULL,
  `guard_id`      INT NULL,
  `status`        ENUM('granted','denied') NOT NULL,
  `deny_reason`   VARCHAR(150) NULL,
  `notes`         TEXT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(`gate`),
  INDEX(`created_at`),
  INDEX(`plate`),
  INDEX(`qr_code`)
) ENGINE=InnoDB;

-- 9. Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `resident_id`   INT NOT NULL,
  `message`       VARCHAR(255) NOT NULL,
  `read_flag`     TINYINT(1) DEFAULT 0,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. Newsletters / communications
CREATE TABLE IF NOT EXISTS `newsletters` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `title`         VARCHAR(200) NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `uploaded_by`   INT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 11. Helpdesk / fault reports
CREATE TABLE IF NOT EXISTS `helpdesk` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `resident_id`   INT NOT NULL,
  `category`      VARCHAR(60) NOT NULL,
  `description`   TEXT NOT NULL,
  `status`        ENUM('open','in_progress','resolved') DEFAULT 'open',
  `response`      TEXT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 12. Panic log
CREATE TABLE IF NOT EXISTS `panic_log` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `guard_id`      INT NULL,
  `guard_name`    VARCHAR(100) NULL,
  `gate`          VARCHAR(30) NULL,
  `message`       VARCHAR(255) NULL,
  `sent_to`       VARCHAR(255) NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 13. Active sessions (on-estate tracking)
CREATE TABLE IF NOT EXISTS `active_sessions` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `entry_type`    ENUM('resident','visitor','service_provider') NOT NULL,
  `person_name`   VARCHAR(100) NOT NULL,
  `plate`         VARCHAR(20) NULL,
  `qr_code`       VARCHAR(80) NULL,
  `gate`          VARCHAR(20) NOT NULL,
  `entered_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 14. System settings
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(60) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Default data ─────────────────────────────────────────
-- Default admin (password: Admin@MBGE2026 — CHANGE IMMEDIATELY)
INSERT IGNORE INTO `admins` (`username`,`password`,`full_name`,`email`)
VALUES ('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uHpwxpnWO', 'System Administrator', 'admin@mbge.co.za');

-- Default settings
INSERT IGNORE INTO `settings` VALUES
  ('site_name',   'MBGE Access Control',    NOW()),
  ('site_url',    'https://mbge.ink',       NOW()),
  ('cron_key',    'change-me-random-key',   NOW()),
  ('whatsapp_enabled', '0',                NOW()),
  ('log_retention_days', '90',             NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- ── Done ─────────────────────────────────────────────────
SELECT 'MBGE database installed successfully.' AS result;
