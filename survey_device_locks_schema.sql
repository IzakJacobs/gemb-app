-- ============================================================
-- survey_device_locks — one row per device per survey
-- Run this in cPanel → phpMyAdmin on your database.
--
-- device_id : random 64-char hex generated on first visit,
--             stored in the gemb_did cookie (5-year expiry).
-- survey_id : the survey that was responded to.
-- response_id: FK to survey_responses for reference.
-- ============================================================

CREATE TABLE IF NOT EXISTS `survey_device_locks` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `survey_id`   INT UNSIGNED    NOT NULL,
    `device_id`   VARCHAR(64)     NOT NULL,
    `response_id` INT UNSIGNED    NOT NULL,
    `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_survey` (`survey_id`, `device_id`),
    KEY `idx_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
