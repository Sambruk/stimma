-- Migration 033: Versionshantering för AI-promptar
--
-- Varje gång en admin sparar en ny version av course_generation_prompt (eller
-- framtida andra prompt-nycklar) skapas en ny rad här. Den senaste versionen
-- sparas samtidigt i ai_settings.setting_value som idag, så runtime-konsumenter
-- inte behöver ändras.

CREATE TABLE ai_prompt_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    version INT NOT NULL,
    content LONGTEXT NOT NULL,
    created_by VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_key_version (setting_key, version),
    INDEX idx_setting_key (setting_key)
);

-- Backfill: om det finns ett sparat course_generation_prompt-värde idag,
-- skapa version 1 av det så att historiken börjar någonstans.
INSERT INTO ai_prompt_versions (setting_key, version, content, created_by, created_at)
SELECT setting_key, 1, setting_value, COALESCE(updated_by, 'system'), COALESCE(updated_at, NOW())
FROM ai_settings
WHERE setting_key = 'course_generation_prompt'
  AND setting_value IS NOT NULL
  AND setting_value != '';
