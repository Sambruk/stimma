-- Migration 021: Bakgrundsfärg per lektion
ALTER TABLE lessons ADD COLUMN background_color VARCHAR(30) DEFAULT NULL AFTER content;
