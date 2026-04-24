-- Migration 034: Informationssidor i lektionsflödet
--
-- Introducerar en ny sidotyp: "Informationssida" — en sida som fungerar
-- som en lektion (samma redigerare, samma renderare) men som inte räknas
-- som en lektion i stegvisa kurser. Infosidor kräver ingen quiz; användaren
-- klickar "Fortsätt" för att markera som visad.
--
-- Infosidor kan kopplas till en specifik lektion via belongs_to_lesson_id.
-- Då låses infosidan upp samtidigt som den lektionen i stegvis flöde.
-- En fristående infosida (NULL) är alltid tillgänglig så snart kursen
-- är tillgänglig — detta används bl.a. för kursens startsida/välkomstsida
-- (ingen särskild "startside"-typ; det är bara en fristående infosida med
-- lågt sort_order).
--
-- Befintliga rader får default 'lesson' / NULL — ingen beteendeändring.

ALTER TABLE lessons
  ADD COLUMN lesson_type ENUM('lesson','info_page') NOT NULL DEFAULT 'lesson' AFTER course_id,
  ADD COLUMN belongs_to_lesson_id INT NULL AFTER lesson_type,
  ADD CONSTRAINT fk_lessons_belongs_to
      FOREIGN KEY (belongs_to_lesson_id) REFERENCES lessons(id) ON DELETE SET NULL,
  ADD INDEX idx_lessons_course_type (course_id, lesson_type, sort_order);
