-- Migration 048: Bildstil per kurs och pris per bildtoken
-- Skapad: 2026-09-17
--
-- Bakgrund: de AI-genererade kursbilderna blev intetsägande. Två av orsakerna
-- krävde databasändringar.
--
-- 1. BILDSTIL PER KURS (courses.image_style)
--    Alla bilder tvingades in i en enda global stil. En kurs om sockerkaka och
--    en om sekretess vill inte ha samma bildspråk. Stilen sparas på kursen och
--    ärvs av kursens lektionsbilder, så att en kurs håller ihop visuellt.
--    NULL betyder "varm illustration", som är standard. Fältet är avsiktligt
--    fritext och inte ett enum: stilarna definieras i aiImageStyles() i
--    include/ai_image_helper.php, och ett enum hade krävt en migration varje
--    gång listan ändras.
--
-- 2. PRIS PER BILDTOKEN (ai_pricing.image_token_per_1m_cents)
--    Kostnaden loggades som ett fast belopp per bild (8 cent för gpt-image-2)
--    oavsett storlek och kvalitet. OpenAI tar betalt per bildtoken, och en bild
--    i 1536x1024 med hög kvalitet förbrukar 5 488 tokens — alltså ungefär
--    16 cent, dubbelt mot det loggade. Med brett format och hög kvalitet som
--    nytt standardval hade kvoter och kostnadsuppföljning blivit missvisande.
--    Priserna nedan är hämtade från OpenAI:s prissida 2026-09-17:
--      gpt-image-2      $5 / 1M input-texttokens, $30 / 1M output-bildtokens
--      gpt-image-1      $5 / 1M input-texttokens, $40 / 1M output-bildtokens
--      gpt-image-1-mini $2 / 1M input-texttokens, $8  / 1M output-bildtokens
--    image_per_call_cents behålls som fallback för modeller utan tokenpris
--    (dall-e-*, som fakturerar per bild).
--
-- Rollback:
--   ALTER TABLE courses DROP COLUMN image_style;
--   ALTER TABLE ai_pricing DROP COLUMN image_token_per_1m_cents;

ALTER TABLE courses
    ADD COLUMN image_style VARCHAR(20) NULL DEFAULT NULL AFTER image_url;

ALTER TABLE ai_pricing
    ADD COLUMN image_token_per_1m_cents INT(11) NOT NULL DEFAULT 0 AFTER image_per_call_cents;

UPDATE ai_pricing SET image_token_per_1m_cents = 3000, input_per_1m_cents = 500 WHERE LOWER(model) = 'gpt-image-2';
UPDATE ai_pricing SET image_token_per_1m_cents = 4000, input_per_1m_cents = 500 WHERE LOWER(model) = 'gpt-image-1';
UPDATE ai_pricing SET image_token_per_1m_cents = 800,  input_per_1m_cents = 200 WHERE LOWER(model) = 'gpt-image-1-mini';
