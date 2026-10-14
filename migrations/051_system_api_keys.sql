-- Migration 051: Systemnycklar med läsbehörighet (insyns-API:t)
-- Skapad: 2026-10-14
--
-- Bakgrund: Sambruks virtuella projektledare (Magnus, AIPL) ska kunna läsa
-- uppgifter ur Stimma för förvaltningsråd, utskick och uppföljning, utan att
-- kunna ändra något. Se docs/API-INSYN.md.
--
-- En systemnyckel är en egen nyckeltyp, skild från organisationernas
-- synknycklar i api_keys:
--   - Prefix sts_ (synknycklarna har stm_). authenticateApiKey() godtar bara stm_
--     och läser bara api_keys, insynsAuthenticate() godtar bara sts_ och läser
--     bara system_api_keys. En nyckel kan därför aldrig gälla för fel API.
--   - Gäller alla organisationer, men bara GET under /api/v1/insyn/.
--   - Skapas och återkallas av superadmin (admin/system_api_keys.php).
--   - Bara SHA-256-hashen lagras. Nyckeln visas en gång när den skapas.
--   - Kan begränsas till vissa IP-adresser (allowed_ips, kommaseparerat, CIDR går).
--
-- system_api_log: ett rad per anrop (tid, endpoint, nyckel-id, status, IP).
-- Används för rate limiting och för att se vad nyckeln har använts till.
-- Misslyckade autentiseringar loggas med key_id NULL.

CREATE TABLE IF NOT EXISTS system_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Vem/vad nyckeln är till, t.ex. Magnus (AIPL)',
    scope VARCHAR(32) NOT NULL DEFAULT 'insyn' COMMENT 'Bara insyn finns',
    api_key_hash CHAR(64) NOT NULL COMMENT 'SHA-256 av nyckeln',
    api_key_prefix VARCHAR(16) NOT NULL COMMENT 'sts_ + 8 hex, för identifiering',
    allowed_ips VARCHAR(500) DEFAULT NULL COMMENT 'Kommaseparerade IP/CIDR. NULL = alla',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    last_used_ip VARCHAR(45) DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoked_by INT DEFAULT NULL,
    UNIQUE KEY uk_system_api_key_hash (api_key_hash),
    KEY idx_system_api_keys_active (is_active),
    CONSTRAINT fk_system_api_keys_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_system_api_keys_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_api_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    key_id INT DEFAULT NULL COMMENT 'NULL = misslyckad autentisering',
    method VARCHAR(10) NOT NULL,
    endpoint VARCHAR(255) NOT NULL COMMENT 'Sökväg + frågesträng, kapad',
    status SMALLINT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_system_api_log_key_time (key_id, created_at),
    KEY idx_system_api_log_ip_time (ip_address, created_at),
    CONSTRAINT fk_system_api_log_key FOREIGN KEY (key_id) REFERENCES system_api_keys (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
