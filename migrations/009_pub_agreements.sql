-- Migration: 009_pub_agreements.sql
-- Skapad: 2026-01-20
-- Beskrivning: Lägger till tabell för PUB-avtal per domän

-- Tabell för domäninställningar inklusive PUB-avtal
CREATE TABLE IF NOT EXISTS domain_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    has_pub_agreement TINYINT(1) NOT NULL DEFAULT 0,
    pub_agreement_date DATE NULL,
    pub_agreement_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_pub_agreement (has_pub_agreement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrera befintliga domäner från allowed_domains.txt
-- Detta görs via PHP efter migrationen körs
