-- Migration 037: Informationsmeddelanden från superadmin till admin/redaktör
-- Skapad: 2026-05-04
--
-- Superadmin kan publicera ett meddelande som visas som modal-popup för
-- alla admin/redaktörer varje gång de öppnar admin- eller användarvyn,
-- tills de aktivt klickar "visa inte igen". När superadmin publicerar ett
-- NYTT meddelande nollställs dismiss-statusen automatiskt eftersom
-- dismissals är knutna till announcement_id (nytt id = ingen dismissal).
--
-- En aktiv tillfällig regel: bara ETT meddelande är aktivt åt gången.
-- När ett nytt skapas/aktiveras sätts active=0 på alla andra.

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL COMMENT 'HTML tillåtet — sanering sker vid render',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_dismissals (
    user_id INT NOT NULL,
    announcement_id INT NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, announcement_id),
    INDEX idx_announcement (announcement_id),
    CONSTRAINT fk_ad_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_announcement FOREIGN KEY (announcement_id)
        REFERENCES announcements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
