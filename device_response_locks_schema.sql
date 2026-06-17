-- ============================================================
-- device_response_locks — unified one-submission-per-device
-- lock for both surveys and votes.
--
-- Run this in cPanel → phpMyAdmin on your database.
--
-- type      : 'survey' or 'vote'
-- target_id : survey_id  (for surveys)
--             motion_id  (for votes)
-- device_id : 64-char random hex from the gemb_did cookie
-- response_id: survey_responses.id  OR  vote_cast.id
--
-- The UNIQUE KEY on (type, target_id, device_id) means:
--   - Same device, different survey       → allowed (different target_id)
--   - Same device, same survey            → blocked
--   - Same device, different motion       → allowed (different target_id)
--   - Same device, same motion            → blocked
--   - Same device, survey + vote          → allowed (different type)
-- ============================================================

CREATE TABLE IF NOT EXISTS `device_response_locks` (
    `id`          INT UNSIGNED            NOT NULL AUTO_INCREMENT,
    `type`        ENUM('survey','vote')   NOT NULL,
    `target_id`   INT UNSIGNED            NOT NULL,
    `device_id`   VARCHAR(64)             NOT NULL,
    `response_id` INT UNSIGNED            NOT NULL,
    `created_at`  TIMESTAMP               NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_type_target` (`type`, `target_id`, `device_id`),
    KEY `idx_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate any rows from the old survey_device_locks table (safe to run
-- even if that table no longer exists — just skip the INSERT in that case).
INSERT IGNORE INTO `device_response_locks`
    (`type`, `target_id`, `device_id`, `response_id`, `created_at`)
SELECT 'survey', `survey_id`, `device_id`, `response_id`, `created_at`
FROM   `survey_device_locks`
WHERE  EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE()
                 AND table_name = 'survey_device_locks');
