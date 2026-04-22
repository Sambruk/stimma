-- Migration 028: Anpassningsbar headertext
--
-- Admin kan ange en egen text som visas i top-nav istället för default-texten
-- "Stimma - en utbildningsplattform från Sambruk". Texten kan sättas på
-- organisationsnivå (gäller alla domäner i organisationen) eller på domännivå
-- (för icke-grupperade domäner). Resolution: org → domain → default.
--
-- Texten får innehålla platshållare som substitueras vid rendering:
--   {{domain}}       — användarens e-postdomän
--   {{organization}} — organisationens namn
--   {{date}}         — dagens datum (svensk format, t.ex. "22 april 2026")

ALTER TABLE organizations
  ADD COLUMN header_text VARCHAR(500) NULL AFTER icon_url;

ALTER TABLE domain_settings
  ADD COLUMN header_text VARCHAR(500) NULL AFTER sync_enabled;
