-- ============================================================
-- GEMB Access Control — Entry/Exit Migration
-- Run once against gembbfev_access
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE)
-- ============================================================

-- ── 1. Extend access_log ──────────────────────────────────
ALTER TABLE access_log
    MODIFY COLUMN direction VARCHAR(10) NOT NULL DEFAULT 'ENTRY',

    -- Specific gate point within the estate
    ADD COLUMN IF NOT EXISTS gate_point VARCHAR(20) DEFAULT NULL
        COMMENT 'SS_CAR_IN1 | SS_CAR_IN2 | SS_CAR_OUT | SS_TURN | CS_CAR_IN | CS_CAR_OUT | CS_CONT | CS_TURN',

    -- For EXIT records: event_id of the matching ENTRY record
    ADD COLUMN IF NOT EXISTS entry_ref VARCHAR(30) DEFAULT NULL
        COMMENT 'event_id of paired ENTRY — NULL for entry records',

    -- Explicit denied flag (replaces relying on deny_reason being empty)
    ADD COLUMN IF NOT EXISTS granted TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = access granted, 0 = access denied',

    -- Ensure person_name column exists (some installs use visitor_name)
    ADD COLUMN IF NOT EXISTS person_name VARCHAR(150) DEFAULT NULL;

-- ── 2. Add indexes for log queries ───────────────────────
ALTER TABLE access_log
    ADD INDEX IF NOT EXISTS idx_gate_point  (gate_point),
    ADD INDEX IF NOT EXISTS idx_direction   (direction),
    ADD INDEX IF NOT EXISTS idx_entry_ref   (entry_ref),
    ADD INDEX IF NOT EXISTS idx_granted     (granted);

-- ── 3. Extend guards table — gate_point on login ─────────
ALTER TABLE guards
    ADD COLUMN IF NOT EXISTS gate_point VARCHAR(20) DEFAULT NULL
        COMMENT 'Last selected gate point — persisted across shifts';

-- ── 4. Back-fill existing records ────────────────────────
UPDATE access_log SET direction = 'ENTRY' WHERE direction IN ('IN', '');
UPDATE access_log SET granted   = 1       WHERE deny_reason IS NULL OR deny_reason = '';
UPDATE access_log SET granted   = 0       WHERE deny_reason IS NOT NULL AND deny_reason != '';

-- ── 5. Gate point reference (comment only — no table needed)
-- SS_CAR_IN1  Schoeman Street  Car entry gate 1
-- SS_CAR_IN2  Schoeman Street  Car entry gate 2
-- SS_CAR_OUT  Schoeman Street  Car exit gate
-- SS_TURN     Schoeman Street  Pedestrian turnstile (entry + exit)
-- CS_CAR_IN   Church Street    Car entry gate
-- CS_CAR_OUT  Church Street    Car exit gate
-- CS_CONT     Church Street    Contractor gate (entry + exit)
-- CS_TURN     Church Street    Pedestrian turnstile (entry + exit)

SELECT 'Migration complete' AS status;
