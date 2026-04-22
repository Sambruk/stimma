-- Migration 026: Ljuduppladdning på lektioner
--
-- Låter redaktörer ladda upp ett ljudklipp per lektion som spelas upp inline.
-- Primärt användningsområde är tillgänglighet (inläst innehåll som alternativ
-- till text) men kan också användas för pedagogiskt ljudmaterial.
--
-- Formatet håller vi enkelt: en filreferens. Ingen extern streaming (YouTube-
-- motsvarighet) eftersom det inte finns någon bred standard för ljudlänkar
-- som kan bäddas in pålitligt.

ALTER TABLE lessons
  ADD COLUMN audio_url VARCHAR(255) NULL AFTER video_type;
