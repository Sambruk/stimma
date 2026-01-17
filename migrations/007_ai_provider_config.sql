-- Migration: AI Provider Configuration
-- Lägger till inställningar för AI-leverantör, API-nycklar och modellval

SET NAMES utf8mb4;
USE stimma;

-- Lägg till nya AI-inställningar om de inte finns
INSERT IGNORE INTO `ai_settings` (`setting_key`, `setting_value`, `description`, `updated_by`) VALUES
('ai_provider', 'openai', 'AI-leverantör (openai, anthropic, azure, custom)', 'system'),
('ai_api_key', '', 'API-nyckel för AI-leverantören', 'system'),
('ai_server_url', 'https://api.openai.com/v1/chat/completions', 'API-serveradress', 'system'),
('ai_model', 'gpt-4', 'Vald AI-modell', 'system'),
('ai_max_tokens', '4096', 'Max tokens för svar', 'system'),
('ai_temperature', '0.7', 'Temperatur (kreativitet)', 'system');
