-- Migration 056: tecknade PUB-avtal sparas som dokument
-- Skapad: 2026-11-09
--
-- Bakgrund: det stämplade avtalet (mallen + signeringsintyg) mejlades vid
-- signeringen men sparades aldrig. Adminvyns nedladdning återskapade det ur
-- avtalsraden, vilket ger samma innehåll men inte samma fil byte för byte, så
-- det fanns ingen hash att visa för det tecknade dokumentet (Thomas 2026-11-09).
--
-- Nu sparas den exakta fil som mejlades, utanför webbroten (pub_docs/signerade/),
-- med SHA-256 av filen. Avtal tecknade före denna migrering återskapas en gång
-- och sparas med signed_pdf_source = 'aterskapad', så att det syns att filen
-- inte är den som mejlades.
--
-- Additiv och omkörbar.

ALTER TABLE pub_agreement_artifacts
    ADD COLUMN IF NOT EXISTS signed_pdf_file VARCHAR(255) NULL
        COMMENT 'Filnamn i pub_docs/signerade/',
    ADD COLUMN IF NOT EXISTS signed_pdf_hash CHAR(64) NULL
        COMMENT 'SHA-256 av det tecknade (stämplade) avtalet',
    ADD COLUMN IF NOT EXISTS signed_pdf_source ENUM('signering','aterskapad') NULL
        COMMENT 'signering = filen som mejlades; aterskapad = återskapad i efterhand',
    ADD COLUMN IF NOT EXISTS signed_pdf_saved_at DATETIME NULL;
