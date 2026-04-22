-- Migration 023: Organisationsgruppering — flera domäner kan tillhöra samma organisation
-- Skapad: 2026-04-10
--
-- Bakgrund: tidigare modell behandlade varje e-postdomän som en egen organisation
-- (courses.organization_domain, tags.organization_domain, domain_settings, pub_agreement_artifacts).
-- Denna migration inför ett organisationskoncept som en domän kan kopplas till.
-- Domäner som inte tilldelas en organisation behåller dagens beteende (implicit single-domain org).
--
-- Refaktorerade queries i admin-/index-filer expanderar filterklausuler från
-- "WHERE organization_domain = ?" till "WHERE organization_domain IN (...)" via
-- helpern getOrgScopeDomains() i include/functions.php.

CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    org_number VARCHAR(20) NULL COMMENT 'NNNNNN-NNNN',
    contact_email VARCHAR(255) NULL COMMENT 'Standard agreement_email vid PUB-signering',
    has_pub_agreement TINYINT(1) NOT NULL DEFAULT 0,
    pub_agreement_date DATE NULL,
    pub_agreement_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_org_number (org_number),
    INDEX idx_has_pub (has_pub_agreement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Markerar orgens huvuddomän (visningssyfte)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain),
    INDEX idx_org (organization_id),
    CONSTRAINT fk_orgdom_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lägg till organization_id på pub_agreement_artifacts så signerade avtal kan kopplas
-- till orgen. ON DELETE SET NULL bevarar artefakten även om orgen senare raderas.
ALTER TABLE pub_agreement_artifacts
    ADD COLUMN organization_id INT NULL AFTER domain,
    ADD INDEX idx_org_id (organization_id),
    ADD CONSTRAINT fk_pub_artifact_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE SET NULL;
