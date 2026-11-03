-- Migration 054: Användningspartens uppgifter i PUB-avtalets partstabell
-- Skapad: 2026-11-03
--
-- Bakgrund: partstabellen i avtalet (avsnitt 1, sidan 3 i mallen) har en kolumn
-- för Användningsparten som aldrig fylldes i. Organisationens uppgifter hamnade
-- bara i signeringsintyget sist i dokumentet, och adress, postadress, Peppol-ID,
-- fakturareferens och kontaktperson frågades det aldrig efter.
--
-- De nya kolumnerna fylls vid signering. Avtal tecknade före denna migrering har
-- NULL och återskapas oförändrade (utan ifylld partstabell), som de mejlades ut.
-- De ska tecknas om (Thomas 2026-11-03).
--
-- Additiv och omkörbar.

ALTER TABLE pub_agreement_artifacts
    ADD COLUMN IF NOT EXISTS org_address VARCHAR(255) NULL COMMENT 'Användningspartens adress' AFTER org_number,
    ADD COLUMN IF NOT EXISTS org_postal_address VARCHAR(255) NULL COMMENT 'Postnummer och ort' AFTER org_address,
    ADD COLUMN IF NOT EXISTS peppol_id VARCHAR(100) NULL COMMENT 'Peppol-ID/GLN' AFTER org_postal_address,
    ADD COLUMN IF NOT EXISTS invoice_reference VARCHAR(255) NULL COMMENT 'Fakturareferens' AFTER peppol_id,
    ADD COLUMN IF NOT EXISTS contact_name VARCHAR(255) NULL COMMENT 'Kontaktperson' AFTER invoice_reference,
    ADD COLUMN IF NOT EXISTS contact_email VARCHAR(255) NULL AFTER contact_name,
    ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(50) NULL AFTER contact_email;
