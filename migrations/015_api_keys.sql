-- Migration: 015_api_keys.sql
-- Skapad: 2026-03-02
-- Beskrivning: Skapar api_keys-tabell och lägger till sync_enabled i domain_settings

-- API-nycklar för extern användarsynkronisering
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    api_key_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash av API-nyckeln',
    api_key_prefix VARCHAR(12) NOT NULL COMMENT 'Prefix för identifiering (stm_XXXXXXXX)',
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    INDEX idx_api_key_hash (api_key_hash),
    INDEX idx_is_active (is_active),
    CONSTRAINT fk_api_keys_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lägg till sync_enabled per domän
ALTER TABLE domain_settings
    ADD COLUMN sync_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pub_agreement_notes;
