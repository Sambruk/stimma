/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(150) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_course_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_course_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `organization_domain` varchar(255) NOT NULL,
  `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
  `course_name` varchar(255) NOT NULL,
  `course_description` text NOT NULL,
  `lesson_count` int(11) NOT NULL DEFAULT 5,
  `include_quiz` tinyint(1) DEFAULT 0,
  `include_video_links` tinyint(1) DEFAULT 0,
  `image_option` enum('none','internet','ai') DEFAULT 'none',
  `difficulty_level` enum('beginner','intermediate','advanced') DEFAULT 'beginner',
  `include_ai_tutor` tinyint(1) DEFAULT 0,
  `generated_json` longtext DEFAULT NULL,
  `result_course_id` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `progress_percent` int(11) DEFAULT 0,
  `progress_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `tone` varchar(50) DEFAULT 'pedagogical',
  `color_theme` varchar(7) DEFAULT '#007bff',
  `target_audience` varchar(255) DEFAULT NULL,
  `language_style` varchar(50) DEFAULT 'formal',
  `generate_images` tinyint(1) NOT NULL DEFAULT 1,
  `ai_questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_questions`)),
  `ai_answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_answers`)),
  `text_length` varchar(20) DEFAULT 'medium',
  PRIMARY KEY (`id`),
  KEY `result_course_id` (`result_course_id`),
  KEY `idx_ai_jobs_user` (`user_id`),
  KEY `idx_ai_jobs_status` (`status`),
  CONSTRAINT `ai_course_jobs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_course_jobs_ibfk_2` FOREIGN KEY (`result_course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_feature_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_feature_models` (
  `feature` varchar(64) NOT NULL COMMENT 'course_gen | lesson_gen | chat | image',
  `model` varchar(64) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_pricing` (
  `model` varchar(64) NOT NULL,
  `input_per_1m_cents` int(11) NOT NULL DEFAULT 0 COMMENT 'USD-cent per 1M input-tokens',
  `output_per_1m_cents` int(11) NOT NULL DEFAULT 0 COMMENT 'USD-cent per 1M output-tokens',
  `image_per_call_cents` int(11) NOT NULL DEFAULT 0 COMMENT 'USD-cent per bildanrop (0 om ej bildmodell)',
  `image_token_per_1m_cents` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_prompt_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_prompt_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `version` int(11) NOT NULL,
  `content` longtext NOT NULL,
  `created_by` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_version` (`setting_key`,`version`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_quotas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_quotas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organization_id` int(11) DEFAULT NULL COMMENT 'Antingen org_id ELLER domain är satt',
  `domain` varchar(255) DEFAULT NULL COMMENT 'Används när domänen inte tillhör någon organisation',
  `monthly_token_quota` bigint(20) NOT NULL DEFAULT 500000 COMMENT 'Tokens per kalendermånad',
  `alert_threshold_pct` tinyint(3) unsigned NOT NULL DEFAULT 80 COMMENT 'Procent som triggar varningsbanner',
  `behavior` enum('block','warn') NOT NULL DEFAULT 'block' COMMENT 'block = stoppa anrop vid 100 %, warn = bara logga',
  `notes` text DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_org` (`organization_id`),
  UNIQUE KEY `uk_dom` (`domain`),
  CONSTRAINT `fk_aiquota_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_usage_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `organization_id` int(11) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL COMMENT 'Sätts när anropet hör till en domän utan org',
  `user_email` varchar(255) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `feature` varchar(64) NOT NULL COMMENT 'course_gen | lesson_gen | chat | image | other',
  `model` varchar(64) NOT NULL,
  `prompt_tokens` int(11) NOT NULL DEFAULT 0,
  `completion_tokens` int(11) NOT NULL DEFAULT 0,
  `total_tokens` int(11) NOT NULL DEFAULT 0,
  `cost_cents` int(11) NOT NULL DEFAULT 0 COMMENT 'Beräknad kostnad i USD-cent',
  `status` enum('ok','blocked','error') NOT NULL DEFAULT 'ok',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_created` (`organization_id`,`created_at`),
  KEY `idx_dom_created` (`domain`,`created_at`),
  KEY `idx_course` (`course_id`),
  KEY `idx_feature_created` (`feature`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcement_dismissals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcement_dismissals` (
  `user_id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `dismissed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`announcement_id`),
  KEY `idx_announcement` (`announcement_id`),
  CONSTRAINT `fk_ad_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL COMMENT 'HTML tillåtet — sanering sker vid render',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`active`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `api_key_hash` varchar(64) NOT NULL COMMENT 'SHA-256 hash av API-nyckeln',
  `api_key_prefix` varchar(12) NOT NULL COMMENT 'Prefix för identifiering (stm_XXXXXXXX)',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`),
  KEY `idx_api_key_hash` (`api_key_hash`),
  KEY `idx_is_active` (`is_active`),
  KEY `fk_api_keys_created_by` (`created_by`),
  CONSTRAINT `fk_api_keys_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `badges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `badges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `icon` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT 'primary',
  `category` enum('streak','completion','milestone','special') DEFAULT 'milestone',
  `requirement_type` varchar(50) NOT NULL,
  `requirement_value` int(11) NOT NULL,
  `xp_reward` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `issued_at` timestamp NULL DEFAULT current_timestamp(),
  `course_title` varchar(255) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `completion_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_course` (`user_id`,`course_id`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_completion_criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_completion_criteria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `criterion_type` enum('min_quiz_percentage') NOT NULL,
  `threshold_value` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_course` (`course_id`),
  CONSTRAINT `fk_criteria_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_editors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_editors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_course_editor` (`course_id`,`email`),
  KEY `idx_email` (`email`),
  CONSTRAINT `course_editors_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_enrollments` (
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
  UNIQUE KEY `unique_user_course` (`user_id`,`course_id`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_org_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_org_tags` (
  `course_id` int(11) NOT NULL,
  `tag` varchar(255) NOT NULL,
  PRIMARY KEY (`course_id`,`tag`),
  KEY `idx_tag` (`tag`),
  CONSTRAINT `fk_course_org_tags_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_shared_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_shared_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `domain` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_course_domain` (`course_id`,`domain`),
  KEY `idx_domain` (`domain`),
  CONSTRAINT `course_shared_domains_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_tags` (
  `course_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`course_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `course_tags_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `difficulty_level` enum('beginner','intermediate','advanced') DEFAULT 'beginner',
  `duration_minutes` int(11) DEFAULT 0,
  `prerequisites` text DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `image_url` varchar(255) DEFAULT NULL,
  `image_style` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `deadline` date DEFAULT NULL COMMENT 'Slutdatum för när kursen ska vara genomförd',
  `start_date` date DEFAULT NULL,
  `seq_new_lesson_subject` varchar(500) DEFAULT NULL,
  `seq_new_lesson_body` text DEFAULT NULL,
  `seq_reminder_subject` varchar(500) DEFAULT NULL,
  `seq_reminder_body` text DEFAULT NULL,
  `sequential_status` enum('pending','sending','active','completed') DEFAULT NULL,
  `enrollment_type` enum('bulk_start','rolling') NOT NULL DEFAULT 'bulk_start',
  `sequential_mode` tinyint(1) NOT NULL DEFAULT 0,
  `sequential_interval_days` int(11) NOT NULL DEFAULT 7,
  `sequential_reminder_delay_days` int(11) NOT NULL DEFAULT 3,
  `sort_order` int(11) DEFAULT 0,
  `featured` tinyint(1) DEFAULT 0,
  `author_id` int(11) DEFAULT NULL,
  `organization_domain` varchar(150) DEFAULT NULL,
  `original_organization_domain` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `certificate_image_url` varchar(255) DEFAULT NULL COMMENT 'URL to custom certificate image/logo for this course',
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `public_registration_token` varchar(64) DEFAULT NULL,
  `completion_content` longtext DEFAULT NULL COMMENT 'HTML-innehåll som visas på kursens avslutssida. NULL = använd standardtext.',
  `allow_quiz_retry` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pub_token` (`public_registration_token`),
  KEY `courses_ibfk_1` (`category_id`),
  KEY `courses_ibfk_2` (`author_id`),
  KEY `idx_organization_domain` (`organization_domain`),
  KEY `idx_deadline` (`deadline`),
  KEY `idx_courses_original_org` (`original_organization_domain`),
  KEY `idx_is_global` (`is_global`),
  CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `courses_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cron_job_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_job_logs` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cron_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_jobs` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `daily_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_date` date NOT NULL,
  `lessons_completed` int(11) DEFAULT 0,
  `xp_earned` int(11) DEFAULT 0,
  `time_spent_minutes` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_date` (`user_id`,`activity_date`),
  KEY `activity_date` (`activity_date`),
  CONSTRAINT `daily_activity_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `has_pub_agreement` tinyint(1) NOT NULL DEFAULT 0,
  `pub_agreement_date` date DEFAULT NULL,
  `pub_agreement_notes` text DEFAULT NULL,
  `sync_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `header_text` varchar(500) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `logo_alt` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `idx_domain` (`domain`),
  KEY `idx_pub_agreement` (`has_pub_agreement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_path_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_path_courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `learning_path_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Rekommenderad ordning, 0-baserad',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lpc_path_course` (`learning_path_id`,`course_id`),
  KEY `idx_lpc_path_order` (`learning_path_id`,`sort_order`),
  KEY `idx_lpc_course` (`course_id`),
  CONSTRAINT `fk_lpc_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lpc_path` FOREIGN KEY (`learning_path_id`) REFERENCES `learning_paths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_path_shared_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_path_shared_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `learning_path_id` int(11) NOT NULL,
  `domain` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lpsd_path_domain` (`learning_path_id`,`domain`),
  KEY `idx_lpsd_domain` (`domain`),
  CONSTRAINT `fk_lpsd_path` FOREIGN KEY (`learning_path_id`) REFERENCES `learning_paths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_paths`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_paths` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL COMMENT 'Ren text - renderas med nl2br(sanitize()), inget HTML',
  `image_url` varchar(255) DEFAULT NULL COMMENT 'Sökväg relativt projektroten, samma som courses.image_url',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `organization_domain` varchar(150) NOT NULL COMMENT 'Ägardomän - sätts från skaparens e-postdomän',
  `is_global` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Endast superadmin: syns för alla organisationer',
  `created_by` int(11) DEFAULT NULL COMMENT 'users.id - NULL om användaren raderats',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lp_org_status` (`organization_domain`,`status`),
  KEY `idx_lp_is_global` (`is_global`),
  KEY `idx_lp_sort` (`sort_order`),
  KEY `fk_lp_created_by` (`created_by`),
  CONSTRAINT `fk_lp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lessons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lessons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `lesson_type` enum('lesson','info_page') NOT NULL DEFAULT 'lesson',
  `belongs_to_lesson_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `estimated_duration` int(11) DEFAULT 5,
  `image_url` varchar(255) DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  `video_type` enum('youtube','local') DEFAULT NULL,
  `video_position` enum('top','bottom') NOT NULL DEFAULT 'bottom',
  `audio_url` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `background_color` varchar(30) DEFAULT NULL,
  `resource_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`resource_links`)),
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `status` enum('active','inactive') DEFAULT 'active',
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ai_instruction` text DEFAULT NULL,
  `ai_prompt` text DEFAULT NULL,
  `ai_chat_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `quiz_question` text DEFAULT NULL,
  `quiz_answer1` text DEFAULT NULL,
  `quiz_answer2` text DEFAULT NULL,
  `quiz_answer3` text DEFAULT NULL,
  `quiz_answer4` text DEFAULT NULL,
  `quiz_answer5` text DEFAULT NULL,
  `quiz_image1` varchar(255) DEFAULT NULL,
  `quiz_image2` varchar(255) DEFAULT NULL,
  `quiz_image3` varchar(255) DEFAULT NULL,
  `quiz_image4` varchar(255) DEFAULT NULL,
  `quiz_image5` varchar(255) DEFAULT NULL,
  `quiz_correct_answer` tinyint(4) DEFAULT NULL,
  `quiz_correct_answers` varchar(50) DEFAULT NULL COMMENT 'Kommaseparerade korrekta svar för flervalsfrågor',
  `quiz_type` enum('single_choice','multiple_choice','fill_blank','drag_drop','image_choice') DEFAULT 'single_choice',
  `quiz_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON-data för komplexa frågetyper' CHECK (json_valid(`quiz_data`)),
  `quiz_pass_mode` enum('require_all_correct','any_result') NOT NULL DEFAULT 'require_all_correct',
  `author_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `course_id` (`course_id`),
  KEY `lessons_ibfk_2` (`author_id`),
  KEY `fk_lessons_belongs_to` (`belongs_to_lesson_id`),
  KEY `idx_lessons_course_type` (`course_id`,`lesson_type`,`sort_order`),
  CONSTRAINT `fk_lessons_belongs_to` FOREIGN KEY (`belongs_to_lesson_id`) REFERENCES `lessons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lessons_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `organization_billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_billing` (
  `organization_id` int(11) NOT NULL,
  `contact_name` varchar(255) NOT NULL,
  `invoice_address` text NOT NULL COMMENT 'Hela fakturaadressen som fritext',
  `gln` varchar(32) DEFAULT NULL COMMENT 'Global Location Number (13 siffror)',
  `peppol` varchar(64) DEFAULT NULL COMMENT 'PEPPOL-ID (t.ex. 0007:5560000000)',
  `contact_email` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`organization_id`),
  CONSTRAINT `fk_orgbilling_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `organization_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organization_id` int(11) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Markerar orgens huvuddomän (visningssyfte)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_org` (`organization_id`),
  CONSTRAINT `fk_orgdom_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `organization_token_balance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_token_balance` (
  `organization_id` int(11) NOT NULL,
  `balance` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Aktuellt saldo (tokens)',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`organization_id`),
  CONSTRAINT `fk_otbalance_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organizations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `org_number` varchar(20) DEFAULT NULL COMMENT 'NNNNNN-NNNN',
  `contact_email` varchar(255) DEFAULT NULL COMMENT 'Standard agreement_email vid PUB-signering',
  `icon_url` varchar(255) DEFAULT NULL,
  `header_text` varchar(500) DEFAULT NULL,
  `has_pub_agreement` tinyint(1) NOT NULL DEFAULT 0,
  `pub_agreement_date` date DEFAULT NULL,
  `pub_agreement_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_org_number` (`org_number`),
  KEY `idx_has_pub` (`has_pub_agreement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `lesson_id` int(11) DEFAULT NULL,
  `status` enum('not_started','completed') DEFAULT 'not_started',
  `started_at` timestamp NULL DEFAULT NULL,
  `completion_time` int(11) DEFAULT NULL,
  `attempts` int(11) DEFAULT 1,
  `score` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_progress_user_lesson` (`user_id`,`lesson_id`),
  KEY `idx_progress_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pub_agreement_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pub_agreement_artifacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agreement_id` varchar(36) NOT NULL COMMENT 'UUID v4 för avtalet',
  `version` varchar(50) NOT NULL DEFAULT '1.0' COMMENT 'Version av PUB-avtalet som tecknades',
  `pdf_filename` varchar(255) DEFAULT NULL COMMENT 'Filnamn på PUB-PDF vid tecknande',
  `pdf_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash av PUB-PDF vid tecknande',
  `signed_at` datetime NOT NULL COMMENT 'Tidpunkt för signering',
  `ip_address` varchar(45) NOT NULL COMMENT 'IP-adress vid signering',
  `user_id` int(11) NOT NULL COMMENT 'Användar-ID för undertecknaren',
  `user_email` varchar(255) NOT NULL COMMENT 'E-post för undertecknaren',
  `user_name` varchar(255) NOT NULL COMMENT 'Namn på undertecknaren',
  `user_title` varchar(255) DEFAULT NULL COMMENT 'Titel för undertecknaren',
  `user_phone` varchar(50) DEFAULT NULL COMMENT 'Telefon för undertecknaren',
  `domain` varchar(255) NOT NULL COMMENT 'Domän som avtalet gäller',
  `organization_id` int(11) DEFAULT NULL,
  `org_name` varchar(255) NOT NULL COMMENT 'Organisationens namn',
  `org_number` varchar(20) NOT NULL COMMENT 'Organisationsnummer (NNNNNN-NNNN)',
  `agreement_email` varchar(255) NOT NULL COMMENT 'E-post för avtalskopia',
  `certification_text` text NOT NULL COMMENT 'Exakt intygandetext som accepterades',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `invalidated_at` datetime DEFAULT NULL,
  `invalidated_by` int(11) DEFAULT NULL,
  `invalidated_by_email` varchar(255) DEFAULT NULL,
  `invalidation_comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agreement_id` (`agreement_id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_signed_at` (`signed_at`),
  KEY `idx_org_id` (`organization_id`),
  KEY `idx_pub_artifacts_invalidated` (`invalidated_at`),
  CONSTRAINT `fk_pub_artifact_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pub_agreement_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pub_agreement_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(50) NOT NULL COMMENT 'Versionsnamn för dokumentet',
  `filename` varchar(255) NOT NULL COMMENT 'Lagrat filnamn på servern',
  `original_filename` varchar(255) NOT NULL COMMENT 'Ursprungligt filnamn vid uppladdning',
  `file_hash` varchar(64) NOT NULL COMMENT 'SHA-256 hash av filen',
  `uploaded_by` int(11) NOT NULL COMMENT 'Användar-ID för den som laddade upp',
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Endast ett dokument kan vara aktivt',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sambruk_signed_at` datetime DEFAULT NULL,
  `sambruk_signer_name` varchar(255) DEFAULT NULL,
  `sambruk_signer_email` varchar(255) DEFAULT NULL,
  `sambruk_signer_title` varchar(255) DEFAULT NULL,
  `sambruk_signer_phone` varchar(50) DEFAULT NULL,
  `sambruk_signer_user_id` int(11) DEFAULT NULL,
  `sambruk_ip_address` varchar(45) DEFAULT NULL,
  `sambruk_signature_hash` varchar(128) DEFAULT NULL,
  `sambruk_certification_text` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_course_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_course_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `registered_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_course` (`user_id`,`course_id`),
  KEY `organization_id` (`organization_id`),
  KEY `idx_course` (`course_id`),
  CONSTRAINT `public_course_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `public_course_access_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `public_course_access_ibfk_3` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_registration_intents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_registration_intents` (
  `verification_token` varchar(64) NOT NULL,
  `email` varchar(150) NOT NULL,
  `course_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`verification_token`),
  KEY `course_id` (`course_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `public_registration_intents_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quiz_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quiz_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer` text DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_lesson_question` (`user_id`,`lesson_id`,`question_id`),
  KEY `idx_user_lesson` (`user_id`,`lesson_id`),
  KEY `idx_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quiz_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `question_type` enum('single_choice','multiple_choice','true_false','fill_blank','image_choice','order','match_pairs','categorize','numeric','hotspot','short_text') NOT NULL DEFAULT 'single_choice',
  `question_text` text DEFAULT NULL,
  `question_image` varchar(255) DEFAULT NULL,
  `quiz_data` longtext DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lesson_sort` (`lesson_id`,`sort_order`),
  CONSTRAINT `quiz_questions_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reminder_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reminder_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `reminder_number` int(11) DEFAULT 1 COMMENT 'Vilken påminnelse i ordningen (1, 2, 3...)',
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  `email_status` enum('sent','failed','bounced') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_course` (`user_id`,`course_id`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reminder_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reminder_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(150) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `days_after_start` int(11) DEFAULT 7 COMMENT 'Antal dagar efter påbörjad kurs innan första påminnelsen',
  `max_reminders` int(11) DEFAULT 3 COMMENT 'Max antal påminnelser att skicka',
  `days_between_reminders` int(11) DEFAULT 7 COMMENT 'Antal dagar mellan påminnelser',
  `email_subject` varchar(255) DEFAULT 'Påminnelse: Du har en påbörjad kurs i Stimma',
  `email_body` text DEFAULT 'Hej!\n\nDu har påbörjat kursen "{{course_title}}" i Stimma men har ännu inte slutfört den.\n\nDu har slutfört {{completed_lessons}} av {{total_lessons}} lektioner.\n\nKlicka här för att fortsätta: {{course_url}}\n\nOm du inte längre vill gå kursen kan du avsluta den genom att klicka här: {{abandon_url}}\n\nMed vänliga hälsningar,\nStimma',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `type` enum('link','file','embed') DEFAULT 'link',
  `lesson_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lesson_id` (`lesson_id`),
  KEY `course_id` (`course_id`),
  KEY `author_id` (`author_id`),
  CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resources_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resources_ibfk_3` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequential_email_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequential_email_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `email_type` enum('new_lesson','reminder') NOT NULL,
  `scheduled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `status` enum('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  `error_message` text DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_scheduled` (`status`,`scheduled_at`),
  KEY `idx_course_status` (`course_id`,`status`),
  KEY `fk_seq_queue_user` (`user_id`),
  KEY `fk_seq_queue_lesson` (`lesson_id`),
  CONSTRAINT `fk_seq_queue_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seq_queue_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seq_queue_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequential_lesson_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequential_lesson_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `available_at` datetime DEFAULT NULL,
  `notified_at` datetime DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_lesson` (`user_id`,`lesson_id`),
  KEY `idx_course_user` (`course_id`,`user_id`),
  KEY `idx_available_notified` (`available_at`,`notified_at`),
  KEY `fk_sls_lesson` (`lesson_id`),
  CONSTRAINT `fk_sls_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sls_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sls_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequential_reminder_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequential_reminder_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `type` enum('new_lesson','reminder','manual_reminder') NOT NULL,
  `custom_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `email_status` enum('sent','failed') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_course` (`user_id`,`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `api_key_id` int(11) DEFAULT NULL,
  `users_in_payload` int(11) NOT NULL DEFAULT 0,
  `users_created` int(11) NOT NULL DEFAULT 0,
  `users_updated` int(11) NOT NULL DEFAULT 0,
  `users_deactivated` int(11) NOT NULL DEFAULT 0,
  `users_deleted` int(11) NOT NULL DEFAULT 0,
  `users_reactivated` int(11) NOT NULL DEFAULT 0,
  `status` enum('success','partial','failed') NOT NULL DEFAULT 'success',
  `error_message` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_api_key_id` (`api_key_id`),
  CONSTRAINT `fk_sync_log_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `organization_domain` varchar(255) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tag_per_org` (`name`,`organization_domain`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `tags_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `token_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `token_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organization_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `tokens` bigint(20) NOT NULL COMMENT 'Snapshotad paketstorlek',
  `price_sek_cents` int(11) NOT NULL COMMENT 'Snapshotat pris',
  `billing_contact_name` varchar(255) NOT NULL,
  `billing_address` text NOT NULL,
  `billing_gln` varchar(32) DEFAULT NULL,
  `billing_peppol` varchar(64) DEFAULT NULL,
  `billing_email` varchar(255) DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = fyll på den 1:a varje månad',
  `recurring_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 om recurring fortfarande är aktiv (kan avslutas)',
  `last_refilled_at` timestamp NULL DEFAULT NULL COMMENT 'Senaste gången recurring körde påfyllning',
  `created_by` varchar(255) DEFAULT NULL COMMENT 'E-post på admin som beställde',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `billed_at` timestamp NULL DEFAULT NULL COMMENT 'När beställningen markerades debiterad (NULL = ej debiterad)',
  `billed_by` varchar(255) DEFAULT NULL COMMENT 'E-post på superadmin som markerade beställningen debiterad',
  PRIMARY KEY (`id`),
  KEY `idx_org` (`organization_id`),
  KEY `idx_recurring` (`recurring_active`,`is_recurring`),
  KEY `fk_tokenorder_pkg` (`package_id`),
  KEY `idx_billed_at` (`billed_at`),
  CONSTRAINT `fk_tokenorder_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tokenorder_pkg` FOREIGN KEY (`package_id`) REFERENCES `token_packages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `token_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `token_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL COMMENT 'Internt kortnamn: mini, s, m, l, xl, xxl',
  `name` varchar(100) NOT NULL COMMENT 'Visningsnamn',
  `tokens` bigint(20) NOT NULL COMMENT 'Antal tokens i paketet',
  `price_sek_cents` int(11) NOT NULL COMMENT 'Pris i öre SEK ex moms',
  `estimated_lessons` int(11) NOT NULL COMMENT 'Ungefärligt antal AI-genererade lektioner',
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `token_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `token_transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `organization_id` int(11) NOT NULL,
  `type` enum('monthly_refill','purchase','recurring','consume','adjustment') NOT NULL,
  `tokens_delta` bigint(20) NOT NULL COMMENT 'Positivt = påfyllning, negativt = förbrukning',
  `balance_after` bigint(20) NOT NULL COMMENT 'Saldo efter denna transaktion',
  `related_order_id` int(11) DEFAULT NULL,
  `related_usage_log_id` bigint(20) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_created` (`organization_id`,`created_at`),
  KEY `idx_type` (`type`),
  KEY `fk_toktx_order` (`related_order_id`),
  CONSTRAINT `fk_toktx_order` FOREIGN KEY (`related_order_id`) REFERENCES `token_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_toktx_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_badges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_badges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `badge_id` int(11) NOT NULL,
  `earned_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_badge` (`user_id`,`badge_id`),
  KEY `badge_id` (`badge_id`),
  CONSTRAINT `user_badges_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_badges_ibfk_2` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_org_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_org_tags` (
  `user_id` int(11) NOT NULL,
  `tag` varchar(255) NOT NULL,
  PRIMARY KEY (`user_id`,`tag`),
  KEY `idx_tag` (`tag`),
  CONSTRAINT `fk_user_org_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `status` enum('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  `last_accessed` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_lesson` (`user_id`,`lesson_id`),
  KEY `lesson_id` (`lesson_id`),
  CONSTRAINT `user_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_progress_ibfk_2` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total_xp` int(11) DEFAULT 0,
  `current_streak` int(11) DEFAULT 0,
  `longest_streak` int(11) DEFAULT 0,
  `last_activity_date` date DEFAULT NULL,
  `lessons_completed` int(11) DEFAULT 0,
  `courses_completed` int(11) DEFAULT 0,
  `quizzes_passed` int(11) DEFAULT 0,
  `total_time_minutes` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `user_stats_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `verification_token` varchar(64) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_admin` tinyint(1) DEFAULT 0,
  `is_editor` tinyint(1) DEFAULT 0,
  `is_viewer` tinyint(1) NOT NULL DEFAULT 0,
  `role` enum('student','teacher','admin','super_admin') DEFAULT 'student',
  `access_mode` enum('domain','public_only') NOT NULL DEFAULT 'domain',
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferences`)),
  `is_synced` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Markerar API-synkade användare',
  `sync_status` enum('active','inactive') DEFAULT 'active' COMMENT 'Synkstatus (påverkar INTE inloggning)',
  `synced_at` datetime DEFAULT NULL COMMENT 'Senaste synktidpunkt',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_is_synced` (`is_synced`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `idx_users_is_viewer` (`is_viewer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

