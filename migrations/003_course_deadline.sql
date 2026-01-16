-- Migration: Lägg till deadline för kurser
-- Skapad: 2026-01-16

USE stimma;

-- Lägg till deadline-kolumn i courses-tabellen
ALTER TABLE `courses`
ADD COLUMN `deadline` DATE DEFAULT NULL COMMENT 'Slutdatum för när kursen ska vara genomförd' AFTER `status`;

-- Lägg till index för att snabbt hitta kurser med deadline
CREATE INDEX `idx_deadline` ON `courses` (`deadline`);
