-- Migration: 016_user_sync.sql
-- Skapad: 2026-03-02
-- Beskrivning: Lägger till synkfält på users, skapar user_org_tags och sync_log

-- Nya kolumner på users-tabellen för synkstatus
ALTER TABLE users
    ADD COLUMN is_synced TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Markerar API-synkade användare' AFTER preferences,
    ADD COLUMN sync_status ENUM('active','inactive') DEFAULT 'active' COMMENT 'Synkstatus (påverkar INTE inloggning)' AFTER is_synced,
    ADD COLUMN synced_at DATETIME DEFAULT NULL COMMENT 'Senaste synktidpunkt' AFTER sync_status;

ALTER TABLE users ADD INDEX idx_is_synced (is_synced);
ALTER TABLE users ADD INDEX idx_sync_status (sync_status);

-- Organisationstaggar per användare
CREATE TABLE IF NOT EXISTS user_org_tags (
    user_id INT NOT NULL,
    tag VARCHAR(255) NOT NULL,
    PRIMARY KEY (user_id, tag),
    INDEX idx_tag (tag),
    CONSTRAINT fk_user_org_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Synklogg
CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    api_key_id INT DEFAULT NULL,
    users_in_payload INT NOT NULL DEFAULT 0,
    users_created INT NOT NULL DEFAULT 0,
    users_updated INT NOT NULL DEFAULT 0,
    users_deactivated INT NOT NULL DEFAULT 0,
    users_reactivated INT NOT NULL DEFAULT 0,
    status ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_created_at (created_at),
    INDEX idx_api_key_id (api_key_id),
    CONSTRAINT fk_sync_log_api_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
