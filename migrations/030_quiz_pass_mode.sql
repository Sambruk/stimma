-- Migration 030: quiz_pass_mode per lektion
--
-- Styr hur en deltagare går vidare efter quiz:
--   require_all_correct : måste svara rätt på ALLA frågor innan lektionen
--                          markeras klar (nuvarande standard).
--   any_result          : svaren registreras men deltagaren fortsätter oavsett
--                          resultat. Bra för enkäter / "tankeväckare" som inte
--                          ska vara progressionsblockerande.

ALTER TABLE lessons
  ADD COLUMN quiz_pass_mode ENUM('require_all_correct','any_result') NOT NULL DEFAULT 'require_all_correct' AFTER quiz_data;
