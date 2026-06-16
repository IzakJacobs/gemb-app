-- ============================================================
-- gemB / MBGE — comms_contacts table
-- ============================================================
-- Run once. Creates the standalone contacts table used by the
-- Communications module for bulk/levy/survey sends.
--
-- This table is completely independent of residents or any other
-- application table. Populate it via comms_contacts.php CSV import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `comms_contacts` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(255)  NOT NULL,
  `name`        VARCHAR(255)  DEFAULT NULL,
  `erf`         VARCHAR(50)   DEFAULT NULL,
  `phone`       VARCHAR(50)   DEFAULT NULL,
  `group_tag`   VARCHAR(100)  DEFAULT NULL,   -- optional list/group label
  `active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_active`    (`active`),
  KEY `idx_group_tag` (`group_tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
