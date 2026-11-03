-- Migration 055: Priser för Berget AI:s modeller (AI-tutorn, 2026-11-03)
--
-- AI-tutorn kan gå via Berget AI (inferens i Sverige, AI_TUTOR_* i .env). Utan
-- rader här blir kostnaden 0 i ai_usage_log och kvoterna räknar fel.
--
-- Berget prissätter i EUR per 1M tokens (GET /v1/models 2026-11-03):
--   google/gemma-4-31B-it                          in 0,25  ut 0,50
--   mistralai/Mistral-Small-3.2-24B-Instruct-2506  in 0,30  ut 0,30
-- Tabellen räknar i USD-cent per 1M; omräknat med 1,15 USD/EUR och avrundat.
-- Justera vid behov – det är en uppskattning, inte fakturabeloppet.
--
-- Omkörbar.

INSERT INTO ai_pricing (model, input_per_1m_cents, output_per_1m_cents, image_per_call_cents, image_token_per_1m_cents, notes) VALUES
 ('google/gemma-4-31B-it', 29, 58, 0, 0, 'Berget AI (Sverige), EUR 0,25/0,50 per 1M omräknat 1,15 USD/EUR – alternativ för AI-tutorn (bättre svar, 5–6 ggr långsammare)'),
 ('mistralai/Mistral-Small-3.2-24B-Instruct-2506', 35, 35, 0, 0, 'Berget AI (Sverige), EUR 0,30/0,30 per 1M omräknat 1,15 USD/EUR – AI-tutorn sedan 2026-11-03')
ON DUPLICATE KEY UPDATE input_per_1m_cents = VALUES(input_per_1m_cents), output_per_1m_cents = VALUES(output_per_1m_cents), notes = VALUES(notes);
