-- Migration 047: Ogiltigförklaring av tecknade PUB-avtal
-- Skapad: 2026-09-16
--
-- Bakgrund: ett digitalt tecknat PUB-avtal (pub_agreement_artifacts) kunde bara
-- finnas eller inte finnas. Saknades förutsättningarna — undertecknaren var inte
-- behörig, organisationen bytte namn eller organisationsnummer, mallen ersattes
-- av en ny version, eller avtalet sades upp — fanns ingen väg tillbaka annat än
-- att peta i databasen. Nu kan superadmin markera ett avtal som ogiltigt, med
-- tidsstämpel, vem som gjorde det och en obligatorisk kommentar som säger varför.
--
-- Designbeslut:
--
-- 1. AVTALET RADERAS INTE.
--    Artefakten är organisationens handling med undertecknarens namn, e-post, IP
--    och PDF-hash. Den bevaras som förut (jämför migration 046 punkt 3) och
--    förses i stället med en ogiltigmarkering. Historiken ska gå att läsa i
--    efterhand: vad som tecknades, när, av vem — och när det slutade gälla.
--
-- 2. OGILTIGFÖRKLARING MÅSTE FÅ VERKAN, INTE BARA VARA EN ETIKETT.
--    Grinden i produkten läser domain_settings.has_pub_agreement respektive
--    organisationens flagga, inte artefakten. Därför släcker koden även den
--    flaggan när avtalet ogiltigförklaras. Följden är avsiktlig: varnings-
--    bannern om saknat PUB-avtal kommer tillbaka, och organisationens
--    administratör kan teckna ett nytt avtal (pub_agreement.php släpper bara
--    igenom den som saknar giltigt avtal).
--
-- 3. KOMMENTAREN ÄR OBLIGATORISK.
--    Ett avtal som slutar gälla utan angiven anledning är svårt att försvara i
--    efterhand. Fältet är fritext och skrivs av superadmin.
--
-- 4. GÅR ATT ÅNGRA.
--    Ogiltigförklaring är ett handgrepp, och handgrepp blir fel ibland. Ångra
--    nollställer kolumnerna och tänder domänens flagga igen med avtalets
--    ursprungliga signeringsdatum. Båda händelserna loggas i activity-loggen.
--
-- Rollback:
--   ALTER TABLE pub_agreement_artifacts
--     DROP COLUMN invalidated_at, DROP COLUMN invalidated_by, 
--     DROP COLUMN invalidated_by_email, DROP COLUMN invalidation_comment;

ALTER TABLE pub_agreement_artifacts
    ADD COLUMN invalidated_at DATETIME NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN invalidated_by INT(11) NULL DEFAULT NULL AFTER invalidated_at,
    ADD COLUMN invalidated_by_email VARCHAR(255) NULL DEFAULT NULL AFTER invalidated_by,
    ADD COLUMN invalidation_comment TEXT NULL DEFAULT NULL AFTER invalidated_by_email;

CREATE INDEX idx_pub_artifacts_invalidated ON pub_agreement_artifacts (invalidated_at);
