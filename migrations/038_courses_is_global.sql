-- Migration 038: Globala kurser (synliga över alla organisationer)
-- Skapad: 2026-05-05
--
-- Bakgrund: Sambruk vill kunna publicera centrala kurser (t.ex. introduktion
-- till Stimma, GDPR-grundkurs) som är synliga för alla användare oavsett
-- organisation. Idag kan en kurs scopas till sin egen organization_domain,
-- delas till specifika domäner via course_shared_domains, eller sättas som
-- public (vilket också aktiverar publik registrering — fel semantik här).
--
-- Lösning: ny boolean is_global. När den är satt skippas domain-matchningen
-- helt och kursen syns för alla inloggade. Endast superadmin kan toggla
-- flaggan via UI:t.

ALTER TABLE courses
    ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public,
    ADD INDEX idx_is_global (is_global);
