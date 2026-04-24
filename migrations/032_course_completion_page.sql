-- Migration 032: Kursavslutssida
--
-- Lägger till completion_content på courses. När en användare slutför sista
-- lektionen i en kurs skickas hen till en avslutssida som visar detta innehåll
-- samt länk till diplomet. Om fältet är NULL används en standardtext.

ALTER TABLE courses
    ADD COLUMN completion_content LONGTEXT DEFAULT NULL
    COMMENT 'HTML-innehåll som visas på kursens avslutssida. NULL = använd standardtext.';
