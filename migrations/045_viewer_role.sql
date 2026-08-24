-- Migration 045: Rollen "Läsbehörig" (is_viewer)
-- Skapad: 2026-08-24
--
-- Bakgrund: mellan Redaktör och Administratör fanns inget mellanläge. En chef
-- eller HR-funktion som ska följa upp medarbetares utbildning behövde tidigare
-- få full administratörsbehörighet — vilket också ger rätt att skapa och radera
-- användare, ändra andras roller, hantera API-nycklar och styra synken. Det är
-- långt mer än uppföljning kräver.
--
-- Designbeslut:
--
-- 1. FLAGGA, INTE NYTT ENUM-VÄRDE.
--    Behörighet i Stimma bärs av is_admin/is_editor, inte av users.role.
--    Enum-värdet är i praktiken dekorativt — det finns användare med
--    role='student' och is_admin=1. En ny flagga följer den etablerade
--    modellen; ett nytt enum-värde hade sett ut att styra något det inte styr.
--
-- 2. LÄSANDE, MEN MED EXPORT.
--    Rollen får se kursstatistik, diplom och användarinformation inom sitt
--    domänscope, och får exportera samma underlag som en administratör. Den
--    kan inte ändra något: inga användare skapas, raderas eller får ändrad
--    roll, inga diplominställningar sparas.
--
-- 3. SÄTTS BARA MANUELLT.
--    API:ets tillåtna roller förblir användare/redaktör/admin. Kunder behöver
--    alltså inte ändra sin AD-synk, och en synk kan inte oavsiktligt dela ut
--    läsbehörighet till personuppgifter.
--
-- 4. SAMMA DOMÄNSCOPE SOM ADMIN.
--    Rollen använder getEffectiveOrgScopeDomains(): på organisationens
--    primärdomän ser man hela organisationen inklusive underdomäner, på en
--    underdomän bara den egna. Ingen egen scope-logik som kan glida isär
--    från administratörens.
--
-- Rollback:
--   ALTER TABLE users DROP COLUMN is_viewer;

ALTER TABLE users
    ADD COLUMN is_viewer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_editor;

CREATE INDEX idx_users_is_viewer ON users (is_viewer);
