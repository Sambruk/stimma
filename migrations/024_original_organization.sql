-- Migration 024: Ursprunglig organisation på kurser
--
-- Permanent etikett som visar vilken organisations domän som ursprungligen
-- skapade kursen. Sätts vid kopiering/import och får aldrig ändras efteråt
-- (ingen UI för det — kolumnen skrivs bara i copy_course.php och import.php
-- och vid backfill här nedan).
--
-- Bakåtkompatibilitet: befintliga kurser antas ha skapats i sin nuvarande
-- organisation, så `original_organization_domain` backfillas till
-- `organization_domain`.

ALTER TABLE courses
    ADD COLUMN original_organization_domain VARCHAR(150) NULL AFTER organization_domain;

UPDATE courses
SET original_organization_domain = organization_domain
WHERE original_organization_domain IS NULL;

CREATE INDEX idx_courses_original_org ON courses(original_organization_domain);
