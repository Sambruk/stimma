-- Migration 053: Inloggning med engångskod i stället för länk
-- Skapad: 2026-10-16
--
-- Bakgrund: inloggningslänken (users.verification_token) gick att använda hur
-- många gånger som helst under sin giltighetstid. Mottagarnas säkerhetsfilter
-- (t.ex. Microsoft Defender) öppnar länkar i mejl och blev därmed inloggade, och
-- ett vidarebefordrat mejl gav inloggning. Länken kunde inte göras engångs utan att
-- filtren förbrukade den före användaren.
--
-- Nu skickas en sexsiffrig kod som skrivs in i samma webbläsare som begärde den:
--   - code_hash: SHA-256 av request_id + kod. Koden sparas aldrig i klartext.
--   - request_id: slumpat id för begäran. Den publika kursregistreringen kopplar
--     sin rad i public_registration_intents till det (kolumnen verification_token).
--   - binding_hash: SHA-256 av ett slumpvärde i den begärande sessionen. Koden
--     gäller bara i den webbläsaren.
--   - attempts: felaktiga försök. Koden spärras efter 5.
--   - Engångs: used_at sätts när koden godtas, och äldre koder för samma adress
--     upphör när en ny skickas.
--
-- Giltighetstiden styrs som förut av AUTH_TOKEN_EXPIRY_MINUTES (standard 15).
-- Sessioner och kom-ihåg-mig är oförändrade.

CREATE TABLE IF NOT EXISTS `login_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `request_id` char(64) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `binding_hash` char(64) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_request_id` (`request_id`),
  KEY `idx_email_created` (`email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Utestående inloggningslänkar slutar gälla. updated_at lämnas orörd.
UPDATE `users` SET `verification_token` = NULL, `updated_at` = `updated_at`
WHERE `verification_token` IS NOT NULL;
