-- Migration 039: Explicit på/av-flagga för AI-chat per lektion
-- Skapad: 2026-05-06
--
-- Tidigare visades AI-chatten på en lektion om antingen ai_instruction eller
-- ai_prompt hade innehåll. Det gjorde det otydligt hur man stänger av chatten
-- och innebar att redaktörer inte kunde "förbereda" instruktioner utan att
-- de syntes för elever.
--
-- Nu blir AI-chatten en explicit opt-in via lessons.ai_chat_enabled. Default
-- för nya lektioner är 0 (av) eftersom värdet av chatten inte är uppenbart
-- för alla författare.
--
-- För BEFINTLIGA lektioner som redan har AI-innehåll sätter vi flaggan till
-- 1 så ingen funktionalitet plötsligt försvinner. Detta backfill:as i ett
-- separat UPDATE direkt efter ALTER TABLE.

ALTER TABLE lessons
    ADD COLUMN ai_chat_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_prompt;

-- Backfill: aktivera chatten för befintliga lektioner som har innehåll i
-- ai_instruction eller ai_prompt (efter strip av <p>&nbsp;</p>-tomheter).
UPDATE lessons
   SET ai_chat_enabled = 1
 WHERE (TRIM(REPLACE(REPLACE(REPLACE(COALESCE(ai_instruction,''), '<p>&nbsp;</p>',''), '<p></p>',''), '&nbsp;','')) <> ''
     OR TRIM(REPLACE(REPLACE(REPLACE(COALESCE(ai_prompt,''),      '<p>&nbsp;</p>',''), '<p></p>',''), '&nbsp;','')) <> '');
