-- Migration 011: Sambruks kontrasignering av PUB-avtalsmall
-- Lägger till kolumner i pub_agreement_documents för att spara Sambruks signatur per mallversion.
-- När en ny mall laddas upp har alla sambruk_*-kolumner NULL → organisationer blockeras tills kontrasignering skett.

ALTER TABLE pub_agreement_documents
  ADD COLUMN sambruk_signed_at DATETIME NULL,
  ADD COLUMN sambruk_signer_name VARCHAR(255) NULL,
  ADD COLUMN sambruk_signer_email VARCHAR(255) NULL,
  ADD COLUMN sambruk_signer_title VARCHAR(255) NULL,
  ADD COLUMN sambruk_signer_phone VARCHAR(50) NULL,
  ADD COLUMN sambruk_signer_user_id INT NULL,
  ADD COLUMN sambruk_ip_address VARCHAR(45) NULL,
  ADD COLUMN sambruk_signature_hash VARCHAR(128) NULL,
  ADD COLUMN sambruk_certification_text TEXT NULL;
