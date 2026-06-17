-- ============================================================
-- GEMB Access Control — auth upgrade schema changes
-- Database: gembbfev_access.  Run ONCE in phpMyAdmin (SQL tab).
--
-- ADDS
--   1. security_users.email             (self-service reset + future use)
--   2. admins.password_changed_at       (30-day rotation tracking)
--   3. security_users.password_changed_at
--
-- ROLLOUT BEHAVIOUR (as agreed)
--   Existing admin and security rows keep password_changed_at = NULL.
--   The login treats NULL as "expired", so every current admin and
--   officer is forced to change their password ONCE on next login.
--   That change stamps the date and starts their fresh 30-day clock.
--   New accounts created afterwards are stamped NOW() at creation, so
--   they are not force-expired the moment they are made.
--
--   (If you would rather NOT force a day-one reset, run the optional
--    backfill at the very bottom to start the clock at "now" instead.)
--
-- NOTE: ADD COLUMN is not re-runnable — if a column already exists the
--       statement errors harmlessly. Run this script only once.
-- ============================================================


-- ── 1. Security officers need an email (for the OTP + other uses) ──
ALTER TABLE security_users
  ADD COLUMN email VARCHAR(100) NULL AFTER phone;


-- ── 2. Password-age tracking for the 30-day rotation ──
ALTER TABLE admins
  ADD COLUMN password_changed_at DATETIME NULL;

ALTER TABLE security_users
  ADD COLUMN password_changed_at DATETIME NULL;


-- ── Verify the new columns ──
SHOW COLUMNS FROM security_users;
SHOW COLUMNS FROM admins;


-- ============================================================
-- BACKFILL EXISTING OFFICERS' EMAILS (do this so their reset works)
-- Replace the addresses with the real ones, then run each line.
-- ============================================================
-- UPDATE security_users SET email = 'officer1@example.com' WHERE username = 'officer1';
-- UPDATE security_users SET email = 'officer2@example.com' WHERE username = 'officer2';


-- ============================================================
-- OPTIONAL — skip the day-one forced reset (start clock at "now")
-- Only run these two lines if you do NOT want everyone forced to
-- change their password on first login after deploy.
-- ============================================================
-- UPDATE admins         SET password_changed_at = NOW() WHERE password_changed_at IS NULL;
-- UPDATE security_users SET password_changed_at = NOW() WHERE password_changed_at IS NULL;
