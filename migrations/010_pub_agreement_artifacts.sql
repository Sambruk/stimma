-- Migration: 010_pub_agreement_artifacts.sql
-- Skapad: 2026-02-12
-- Beskrivning: Tabeller för PUB-avtalssignering och dokumenthantering

-- Tabell för juridiskt spårbara signeringsartefakter
CREATE TABLE IF NOT EXISTS pub_agreement_artifacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agreement_id VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID v4 för avtalet',
    version VARCHAR(50) NOT NULL DEFAULT '1.0' COMMENT 'Version av PUB-avtalet som tecknades',
    pdf_filename VARCHAR(255) NULL COMMENT 'Filnamn på PUB-PDF vid tecknande',
    pdf_hash VARCHAR(64) NULL COMMENT 'SHA-256 hash av PUB-PDF vid tecknande',
    signed_at DATETIME NOT NULL COMMENT 'Tidpunkt för signering',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP-adress vid signering',
    user_id INT NOT NULL COMMENT 'Användar-ID för undertecknaren',
    user_email VARCHAR(255) NOT NULL COMMENT 'E-post för undertecknaren',
    user_name VARCHAR(255) NOT NULL COMMENT 'Namn på undertecknaren',
    user_title VARCHAR(255) NULL COMMENT 'Titel för undertecknaren',
    user_phone VARCHAR(50) NULL COMMENT 'Telefon för undertecknaren',
    domain VARCHAR(255) NOT NULL COMMENT 'Domän som avtalet gäller',
    org_name VARCHAR(255) NOT NULL COMMENT 'Organisationens namn',
    org_number VARCHAR(20) NOT NULL COMMENT 'Organisationsnummer (NNNNNN-NNNN)',
    agreement_email VARCHAR(255) NOT NULL COMMENT 'E-post för avtalskopia',
    certification_text TEXT NOT NULL COMMENT 'Exakt intygandetext som accepterades',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_user_id (user_id),
    INDEX idx_signed_at (signed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabell för superadmin-uppladdade PUB-avtal PDF:er
CREATE TABLE IF NOT EXISTS pub_agreement_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL COMMENT 'Versionsnamn för dokumentet',
    filename VARCHAR(255) NOT NULL COMMENT 'Lagrat filnamn på servern',
    original_filename VARCHAR(255) NOT NULL COMMENT 'Ursprungligt filnamn vid uppladdning',
    file_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash av filen',
    uploaded_by INT NOT NULL COMMENT 'Användar-ID för den som laddade upp',
    is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Endast ett dokument kan vara aktivt',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
