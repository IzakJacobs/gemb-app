-- ============================================================
-- Migration: permit_print_log
-- Purpose:   Audit log for every permit printed, with PDF stored
--            on disk and referenced here.
-- Retention: Purged by cron when SP end_date + 30 days has passed.
-- Run once against gembcoza_gemb
-- ============================================================

CREATE TABLE IF NOT EXISTS permit_print_log (
    id               INT(11)      NOT NULL AUTO_INCREMENT,
    sp_id            INT(11)      NOT NULL,                    -- FK → service_providers.id
    unique_code      VARCHAR(10)  NOT NULL,                    -- denormalised for fast lookup / purge
    permit_type      ENUM('card','slip') NOT NULL,
    printed_by_id    INT(11)      NOT NULL,                    -- security_users.id
    printed_by_name  VARCHAR(100) NOT NULL,
    printed_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pdf_path         VARCHAR(500) NOT NULL,                    -- relative path: /uploads/permits/YYYY-MM/filename.pdf
    purge_after      DATE         NOT NULL,                    -- sp.end_date + 30 days, set at insert time
    PRIMARY KEY (id),
    INDEX idx_sp_id       (sp_id),
    INDEX idx_unique_code (unique_code),
    INDEX idx_purge_after (purge_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
