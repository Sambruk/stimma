-- Migration 013: Dynamic max lesson count setting
-- Adds max_lesson_count to ai_settings so superadmin can configure the limit.

INSERT INTO ai_settings (setting_key, setting_value, description)
VALUES ('max_lesson_count', '50', 'Maximalt antal lektioner vid AI-kursgenerering')
ON DUPLICATE KEY UPDATE description = VALUES(description);
