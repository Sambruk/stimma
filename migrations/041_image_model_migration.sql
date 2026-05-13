-- Migration 041: Migrera bildmodell från dall-e-2/dall-e-3 till gpt-image-1-mini
-- Skapad: 2026-05-12
--
-- OpenAI har annonserat att dall-e-2 och dall-e-3 deprekeras 2026-05-12.
-- Rekommendationen från OpenAI är att migrera till gpt-image-2 eller
-- gpt-image-1-mini. Vi väljer gpt-image-1-mini som default eftersom det är
-- den billigaste varianten (~2 cent/bild jämfört med gpt-image-2 ~8 cent/bild)
-- och passar bra för Stimmas lektions-/kursbilder som genereras i volym.
--
-- Superadmin kan när som helst byta till gpt-image-2 eller gpt-image-1 via
-- admin/ai_quotas.php (modellval per funktion).

-- 1. Lägg till nya modeller i pricing
INSERT INTO ai_pricing (model, image_per_call_cents, notes) VALUES
    ('gpt-image-1-mini', 2, 'OpenAI billig bildmodell — default sedan 2026-05-12'),
    ('gpt-image-2',      8, 'OpenAI flaggskepp för bildgenerering')
ON DUPLICATE KEY UPDATE
    notes = VALUES(notes);

-- 2. Markera dall-e som deprekerad (behåll raderna så historisk usage_log
--    fortfarande kan slå upp pris)
UPDATE ai_pricing
   SET notes = CONCAT('[DEPREKERAD 2026-05-12 av OpenAI] ', COALESCE(notes, ''))
 WHERE model IN ('dall-e-2', 'dall-e-3')
   AND notes NOT LIKE '[DEPREKERAD%';

-- 3. Migrera feature->model-mappningen om någon fortfarande pekar på dall-e
UPDATE ai_feature_models
   SET model = 'gpt-image-1-mini',
       notes = CONCAT('Auto-migrerad från dall-e 2026-05-12. ', COALESCE(notes, ''))
 WHERE feature = 'image'
   AND model IN ('dall-e-2', 'dall-e-3');

-- 4. Säkerställ att image-feature har en rad även om den saknades
INSERT INTO ai_feature_models (feature, model, notes)
VALUES ('image', 'gpt-image-1-mini', 'Default sedan 2026-05-12 (dall-e deprekerad)')
ON DUPLICATE KEY UPDATE feature = feature;
