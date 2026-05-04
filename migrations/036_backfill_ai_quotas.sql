-- Migration 036: Backfill ai_quotas för befintliga orgs och domäner
-- Skapad: 2026-04-30
--
-- Migration 035 skapade ai_quotas men ingen rad fylldes på — nya orgs/domäner
-- har förlitat sig på PHP-defaulten (AI_QUOTA_DEFAULT_TOKENS = 50000) i runtime.
-- För att superadmin ska kunna se OCH justera kvot för ALLA scopes via UI:t
-- behöver vi explicita rader.
--
-- Strategi:
--   1. En rad per organization (organization_id satt, domain NULL).
--   2. En rad per användardomän som INTE redan tillhör en organization
--      (organization_id NULL, domain satt).
--
-- Idempotent: INSERT IGNORE hoppar över rader som krockar med UNIQUE-keys
-- (uk_org på organization_id, uk_dom på domain), så migrationen kan köras
-- om utan dubblett.

-- 1) Backfill för alla organizations
INSERT IGNORE INTO ai_quotas (
    organization_id, domain, monthly_token_quota, alert_threshold_pct,
    behavior, notes, updated_by, updated_at
)
SELECT id, NULL, 50000, 80, 'block',
       'Backfill 2026-04-30 — standardvärde, justera vid behov',
       'system', NOW()
  FROM organizations;

-- 2) Backfill för alla "fria" domäner (har användare men ingen org-koppling)
-- COLLATE krävs eftersom users-tabellen och organization_domains kan ha olika
-- default-collation (swedish_ci vs unicode_ci) — utan explicit collation blir
-- IN-jämförelsen "Illegal mix of collations".
INSERT IGNORE INTO ai_quotas (
    organization_id, domain, monthly_token_quota, alert_threshold_pct,
    behavior, notes, updated_by, updated_at
)
SELECT NULL,
       LOWER(SUBSTRING_INDEX(u.email, '@', -1)) COLLATE utf8mb4_unicode_ci AS d,
       50000, 80, 'block',
       'Backfill 2026-04-30 — standardvärde, justera vid behov',
       'system', NOW()
  FROM users u
 WHERE u.email LIKE '%@%'
   AND LOWER(SUBSTRING_INDEX(u.email, '@', -1)) COLLATE utf8mb4_unicode_ci NOT IN (
       SELECT LOWER(domain) COLLATE utf8mb4_unicode_ci FROM organization_domains
   )
 GROUP BY d;
