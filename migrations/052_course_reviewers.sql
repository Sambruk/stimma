-- Migration 052: Granskare per kurs (läsbehörighet till en enskild kurs)
-- Skapad: 2026-10-14
--
-- Bakgrund: Sambruk vill få synpunkter från externa på baskurserna innan de
-- publiceras. Kursredaktörer (course_editors) får ändra kursen; en granskare får
-- bara läsa den.
--
-- En granskare:
--   - går igenom kursen i förhandsgranskningsläge (lesson.php?preview=1): inga
--     framsteg, inskrivningar eller quizsvar sparas, och kursen räknas inte i
--     statistiken
--   - når kursen även när den är inaktiv och tillhör en annan organisation
--   - får ingen åtkomst till adminytan och ingen roll på sitt konto
--
-- Läggs till och tas bort av den som får hantera kursens redaktörer
-- (admin/edit_course.php → Granskare).

CREATE TABLE IF NOT EXISTS `course_reviewers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_course_reviewer` (`course_id`,`email`),
  KEY `idx_email` (`email`),
  CONSTRAINT `course_reviewers_course_fk` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
