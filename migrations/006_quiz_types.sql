-- Migration: Quiz Types
-- Lägger till stöd för olika frågetyper: enkelval, flerval, lucktext, dra-och-släpp, bildbaserade

SET NAMES utf8mb4;
USE stimma;

-- Lägg till quiz_type kolumn för att ange frågetyp
-- Standardvärde 'single_choice' för bakåtkompatibilitet
ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_type` ENUM(
    'single_choice',      -- Enkelval (nuvarande standard)
    'multiple_choice',    -- Flerval (flera rätta svar)
    'fill_blank',         -- Lucktext (fyll i tomma fält)
    'drag_drop',          -- Dra-och-släpp (matcha/ordna)
    'image_choice'        -- Bildbaserade frågor
) DEFAULT 'single_choice' AFTER `quiz_correct_answer`;

-- Lägg till quiz_data för flexibel datalagring (JSON)
-- Används för komplexa frågetyper som dra-och-släpp och lucktext
ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_data` JSON DEFAULT NULL COMMENT 'JSON-data för komplexa frågetyper' AFTER `quiz_type`;

-- Lägg till quiz_answer4 och quiz_answer5 för fler svarsalternativ
ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_answer4` TEXT DEFAULT NULL AFTER `quiz_answer3`;

ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_answer5` TEXT DEFAULT NULL AFTER `quiz_answer4`;

-- Lägg till quiz_correct_answers för flerval (kommaseparerade nummer, t.ex. "1,3,4")
ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_correct_answers` VARCHAR(50) DEFAULT NULL COMMENT 'Kommaseparerade korrekta svar för flervalsfrågor' AFTER `quiz_correct_answer`;

-- Lägg till bild-URL:er för bildbaserade frågor
ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_image1` VARCHAR(255) DEFAULT NULL AFTER `quiz_answer5`;

ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_image2` VARCHAR(255) DEFAULT NULL AFTER `quiz_image1`;

ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_image3` VARCHAR(255) DEFAULT NULL AFTER `quiz_image2`;

ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_image4` VARCHAR(255) DEFAULT NULL AFTER `quiz_image3`;

ALTER TABLE `lessons`
ADD COLUMN IF NOT EXISTS `quiz_image5` VARCHAR(255) DEFAULT NULL AFTER `quiz_image4`;

-- Uppdatera befintliga lektioner till single_choice om de har quiz
UPDATE `lessons`
SET `quiz_type` = 'single_choice'
WHERE `quiz_question` IS NOT NULL
  AND `quiz_question` != ''
  AND `quiz_type` IS NULL;
