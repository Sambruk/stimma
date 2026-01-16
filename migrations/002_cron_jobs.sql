-- Migration: Cron-jobbhantering för Stimma
-- Skapad: 2026-01-15

USE stimma;

-- Tabell för cron-jobbinställningar
CREATE TABLE IF NOT EXISTS `cron_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Unikt namn för jobbet',
  `display_name` varchar(255) NOT NULL COMMENT 'Visningsnamn',
  `description` text DEFAULT NULL COMMENT 'Beskrivning av vad jobbet gör',
  `script_path` varchar(255) NOT NULL COMMENT 'Sökväg till PHP-skriptet',
  `enabled` tinyint(1) DEFAULT 0 COMMENT 'Om jobbet är aktiverat',
  `schedule_type` enum('interval','daily','weekly','monthly','custom') DEFAULT 'daily' COMMENT 'Typ av schema',
  `interval_minutes` int(11) DEFAULT 60 COMMENT 'Intervall i minuter (för interval-typ)',
  `run_at_hour` int(11) DEFAULT 9 COMMENT 'Timme att köra (0-23)',
  `run_at_minute` int(11) DEFAULT 0 COMMENT 'Minut att köra (0-59)',
  `run_on_days` varchar(50) DEFAULT '1,2,3,4,5,6,7' COMMENT 'Vilka dagar att köra (1=måndag, 7=söndag)',
  `run_on_day_of_month` int(11) DEFAULT 1 COMMENT 'Dag i månaden att köra (för monthly)',
  `last_run_at` timestamp NULL DEFAULT NULL COMMENT 'Senaste körning',
  `last_run_status` enum('success','failed','running') DEFAULT NULL,
  `last_run_message` text DEFAULT NULL,
  `last_run_duration` int(11) DEFAULT NULL COMMENT 'Körtid i sekunder',
  `next_run_at` timestamp NULL DEFAULT NULL COMMENT 'Nästa planerade körning',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_next_run` (`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Tabell för cron-jobblogg
CREATE TABLE IF NOT EXISTS `cron_job_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` enum('success','failed','running') DEFAULT 'running',
  `output` text DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_started_at` (`started_at`),
  CONSTRAINT `fk_cron_job_logs_job` FOREIGN KEY (`job_id`) REFERENCES `cron_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Lägg till standardjobb för påminnelser
INSERT INTO `cron_jobs` (`name`, `display_name`, `description`, `script_path`, `enabled`, `schedule_type`, `run_at_hour`, `run_at_minute`)
VALUES ('send_reminders', 'Skicka kurspåminnelser', 'Skickar påminnelser till användare som påbörjat men inte slutfört kurser.', 'cron/send_reminders.php', 0, 'daily', 9, 0)
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);
