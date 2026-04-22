-- Migration 027: Organisationsikon
--
-- En organisationsikon visas i top-nav:en bredvid Stimma-loggan för alla
-- användare vars domän tillhör organisationen. Publika kursdeltagare ser
-- ikonen för den organisation som publicerat den kurs de är registrerade för.
-- Samma ikon gäller för alla domäner i samma organisation (en ikon per
-- organizations-rad).

ALTER TABLE organizations
  ADD COLUMN icon_url VARCHAR(255) NULL AFTER contact_email;
