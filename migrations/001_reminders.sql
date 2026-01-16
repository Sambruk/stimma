-- Migration: Påminnelsesystem för Stimma
-- Skapad: 2026-01-15

USE stimma;

-- Tabell för påminnelseinställningar per organisation (domän)
CREATE TABLE IF NOT EXISTS `reminder_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(150) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `days_after_start` int(11) DEFAULT 7 COMMENT 'Antal dagar efter påbörjad kurs innan första påminnelsen',
  `max_reminders` int(11) DEFAULT 3 COMMENT 'Max antal påminnelser att skicka',
  `days_between_reminders` int(11) DEFAULT 7 COMMENT 'Antal dagar mellan påminnelser',
  `email_subject` varchar(255) DEFAULT 'Påminnelse: Du har en påbörjad kurs i Stimma',
  `email_body` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Tabell för loggning av skickade påminnelser
CREATE TABLE IF NOT EXISTS `reminder_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `reminder_number` int(11) DEFAULT 1 COMMENT 'Vilken påminnelse i ordningen (1, 2, 3...)',
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  `email_status` enum('sent','failed','bounced') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_course` (`user_id`, `course_id`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Tabell för användarens kursregistreringar (inkl. avslutade/abandonerade kurser)
CREATE TABLE IF NOT EXISTS `course_enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `status` enum('active','completed','abandoned') DEFAULT 'active',
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `abandoned_at` timestamp NULL DEFAULT NULL,
  `abandon_reason` text DEFAULT NULL,
  `reminder_count` int(11) DEFAULT 0 COMMENT 'Antal skickade påminnelser',
  `last_reminder_at` timestamp NULL DEFAULT NULL,
  `opt_out_reminders` tinyint(1) DEFAULT 0 COMMENT 'Användaren vill inte ha påminnelser för denna kurs',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_course` (`user_id`, `course_id`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Lägg till default-värde för email_body
UPDATE `reminder_settings` SET `email_body` = 'Hej!

Du har påbörjat kursen "{{course_title}}" i Stimma men har ännu inte slutfört den.

Du har slutfört {{completed_lessons}} av {{total_lessons}} lektioner.

Klicka här för att fortsätta: {{course_url}}

Om du inte längre vill gå kursen kan du avsluta den genom att klicka här: {{abandon_url}}

Med vänliga hälsningar,
Stimma' WHERE `email_body` IS NULL;

-- Sätt default email_body för nya poster
ALTER TABLE `reminder_settings`
MODIFY COLUMN `email_body` text DEFAULT 'Hej!\n\nDu har påbörjat kursen \"{{course_title}}\" i Stimma men har ännu inte slutfört den.\n\nDu har slutfört {{completed_lessons}} av {{total_lessons}} lektioner.\n\nKlicka här för att fortsätta: {{course_url}}\n\nOm du inte längre vill gå kursen kan du avsluta den genom att klicka här: {{abandon_url}}\n\nMed vänliga hälsningar,\nStimma';
