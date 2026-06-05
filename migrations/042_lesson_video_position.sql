-- Migration 042: Välj om lektionens video visas först eller sist
-- Skapad: 2026-05-29
--
-- Tidigare visades videon alltid efter lektionens innehåll (sist, före quiz).
-- Denna kolumn låter redaktören välja placering:
--   'bottom' (default) = efter innehållet, före quiz (oförändrat beteende)
--   'top'              = före innehållet (men alltid före ev. quiz)
--
-- Default 'bottom' bevarar nuvarande beteende för alla befintliga lektioner.

ALTER TABLE lessons
    ADD COLUMN video_position ENUM('top','bottom') NOT NULL DEFAULT 'bottom' AFTER video_type;
