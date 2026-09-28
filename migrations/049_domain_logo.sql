-- Migration 049: Logga per domän
-- Skapad: 2026-09-28
--
-- Bakgrund: loggan satt bara på organisationen (organizations.icon_url). Alla
-- domäner i en organisation fick därför samma logga — Vimmerby, Boxholm,
-- Ödeshög, Ydre och Åtvidaberg visade alla ITSAM:s. Nu kan superadmin sätta
-- en egen logga på en domän. Den går före organisationens logga.
--
-- Uppslagsordning (resolveDomainLogo() i include/functions.php):
--   1. domänens egen logga
--   2. närmaste överordnade domäns logga (utb.vimmerby.se ärver vimmerby.se)
--   3. organisationens logga
--
-- Filen sparas i upload/org_icons/ som organisationsloggorna, så att alla
-- vyer som redan visar organisationsloggan fungerar utan ändring.
--
-- logo_alt är loggans namn för skärmläsare (alt-text), t.ex. "Vimmerby kommun".
-- Utan den skulle Vimmerbys logga läsas upp som "Kommunalförbundet ITSAM".

ALTER TABLE domain_settings
    ADD COLUMN IF NOT EXISTS logo_url VARCHAR(255) NULL DEFAULT NULL AFTER header_text,
    ADD COLUMN IF NOT EXISTS logo_alt VARCHAR(255) NULL DEFAULT NULL AFTER logo_url;
