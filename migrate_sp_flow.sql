-- ============================================================
-- GEMB — Service Provider Flow Migration (Final)
-- Run ONCE in phpMyAdmin SQL tab
-- ============================================================

-- Step 1: Add status column
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS status ENUM(
        'invited',    -- resident sent invite, SP not yet at security office
        'pending',    -- SP registered at security office, awaiting approval
        'approved',   -- approved, permit issued
        'revoked',    -- revoked by site manager
        'expired'     -- past end date
    ) NOT NULL DEFAULT 'pending';

-- Step 2: Add ID verification flag
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS id_verified TINYINT(1) DEFAULT 0;

-- Step 3: Add permit type
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS permit_type ENUM(
        'card',    -- credit card — domestic/garden/contractor_lead
        'slip'     -- paper slip — contractor_worker, delivery
    ) NOT NULL DEFAULT 'slip';

-- Step 4: Add SP phone
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS sp_phone VARCHAR(20) DEFAULT NULL;

-- Step 5: Add invited_by (NULL = site manager registered directly)
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS invited_by_resident_id INT DEFAULT NULL
    COMMENT 'NULL = registered by site manager directly';

-- Step 6: Add once_off flag (delivery = single use after approval)
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS once_off TINYINT(1) DEFAULT 0
    COMMENT '1 = expires after first gate scan';

-- Step 7: Add access hours
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS access_days  VARCHAR(30) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat',
    ADD COLUMN IF NOT EXISTS access_start TIME        DEFAULT '07:00:00',
    ADD COLUMN IF NOT EXISTS access_end   TIME        DEFAULT '17:00:00';

-- Step 8: Add lead_id for contractor workers
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS lead_id INT DEFAULT NULL;

-- Step 9: Add category
ALTER TABLE service_providers
    ADD COLUMN IF NOT EXISTS category ENUM(
        'domestic',
        'delivery',
        'resident_worker',
        'contractor_lead',
        'contractor_worker'
    ) NOT NULL DEFAULT 'resident_worker';

-- Step 10: Set permit_type defaults from category
UPDATE service_providers
SET permit_type = 'card'
WHERE category IN ('domestic','resident_worker','contractor_lead');

UPDATE service_providers
SET permit_type = 'slip',
    once_off    = 1
WHERE category = 'delivery';

UPDATE service_providers
SET permit_type = 'slip'
WHERE category = 'contractor_worker';

-- Step 11: Set once_off for delivery
UPDATE service_providers
SET once_off = 1
WHERE category = 'delivery';

-- Step 12: Set status from approved column
UPDATE service_providers SET status = 'approved'
WHERE approved = 'true' OR approved = 1;

UPDATE service_providers SET status = 'pending'
WHERE (approved = 'false' OR approved IS NULL)
  AND expired = 0
  AND status != 'invited';

-- Step 13: Verify
SELECT id, service_name, category, status, permit_type,
       once_off, sp_phone, invited_by_resident_id, lead_id
FROM service_providers ORDER BY id;
