-- Migration: Gamification System
-- Adds support for streaks, badges, XP and certificates

SET NAMES utf8mb4;
USE stimma;

-- Tabell för användarstatistik och streaks
CREATE TABLE IF NOT EXISTS `user_stats` (
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

-- Tabell för daglig aktivitet (för streak-tracking)
CREATE TABLE IF NOT EXISTS `daily_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_date` date NOT NULL,
  `lessons_completed` int(11) DEFAULT 0,
  `xp_earned` int(11) DEFAULT 0,
  `time_spent_minutes` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_date` (`user_id`, `activity_date`),
  KEY `activity_date` (`activity_date`),
  CONSTRAINT `daily_activity_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Tabell för badge-definitioner
CREATE TABLE IF NOT EXISTS `badges` (
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

-- Tabell för användarens badges
CREATE TABLE IF NOT EXISTS `user_badges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `badge_id` int(11) NOT NULL,
  `earned_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_badge` (`user_id`, `badge_id`),
  KEY `badge_id` (`badge_id`),
  CONSTRAINT `user_badges_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_badges_ibfk_2` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Tabell för certifikat
CREATE TABLE IF NOT EXISTS `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `issued_at` timestamp NULL DEFAULT current_timestamp(),
  `course_title` varchar(255) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `completion_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_course` (`user_id`, `course_id`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Sätt in standard-badges
INSERT INTO `badges` (`slug`, `name`, `description`, `icon`, `color`, `category`, `requirement_type`, `requirement_value`, `xp_reward`, `sort_order`) VALUES
-- Streak badges
('streak_3', 'Tre i rad', '3 dagar i rad', 'bi-fire', 'warning', 'streak', 'streak', 3, 50, 1),
('streak_7', 'Veckomästare', '7 dagar i rad', 'bi-fire', 'warning', 'streak', 'streak', 7, 100, 2),
('streak_14', 'Tvåveckorskrigare', '14 dagar i rad', 'bi-fire', 'warning', 'streak', 'streak', 14, 200, 3),
('streak_30', 'Månadsmästare', '30 dagar i rad', 'bi-fire', 'danger', 'streak', 'streak', 30, 500, 4),
('streak_100', 'Legendär streak', '100 dagar i rad', 'bi-fire', 'danger', 'streak', 'streak', 100, 1000, 5),

-- Lesson completion badges
('lessons_1', 'Första steget', 'Slutför din första lektion', 'bi-check-circle', 'success', 'completion', 'lessons', 1, 25, 10),
('lessons_10', 'På god väg', 'Slutför 10 lektioner', 'bi-check-circle', 'success', 'completion', 'lessons', 10, 100, 11),
('lessons_25', 'Kunskapstörstig', 'Slutför 25 lektioner', 'bi-check-circle', 'success', 'completion', 'lessons', 25, 250, 12),
('lessons_50', 'Halvvägs dit', 'Slutför 50 lektioner', 'bi-check-circle', 'info', 'completion', 'lessons', 50, 500, 13),
('lessons_100', 'Läromästare', 'Slutför 100 lektioner', 'bi-check-circle', 'primary', 'completion', 'lessons', 100, 1000, 14),

-- Course completion badges
('courses_1', 'Kursklart!', 'Slutför din första kurs', 'bi-mortarboard', 'primary', 'completion', 'courses', 1, 100, 20),
('courses_3', 'Trehörning', 'Slutför 3 kurser', 'bi-mortarboard', 'primary', 'completion', 'courses', 3, 300, 21),
('courses_5', 'Femstjärnig', 'Slutför 5 kurser', 'bi-mortarboard', 'info', 'completion', 'courses', 5, 500, 22),
('courses_10', 'Kunskapsjägare', 'Slutför 10 kurser', 'bi-mortarboard', 'warning', 'completion', 'courses', 10, 1000, 23),

-- XP milestones
('xp_100', 'Nybörjare', 'Samla 100 XP', 'bi-star', 'secondary', 'milestone', 'xp', 100, 0, 30),
('xp_500', 'Lärling', 'Samla 500 XP', 'bi-star', 'info', 'milestone', 'xp', 500, 0, 31),
('xp_1000', 'Adept', 'Samla 1000 XP', 'bi-star', 'primary', 'milestone', 'xp', 1000, 0, 32),
('xp_2500', 'Expert', 'Samla 2500 XP', 'bi-star', 'warning', 'milestone', 'xp', 2500, 0, 33),
('xp_5000', 'Mästare', 'Samla 5000 XP', 'bi-star-fill', 'danger', 'milestone', 'xp', 5000, 0, 34),

-- Special badges
('early_bird', 'Morgonfågel', 'Lär dig före kl 07:00', 'bi-sunrise', 'warning', 'special', 'special', 1, 50, 40),
('night_owl', 'Nattuggla', 'Lär dig efter kl 22:00', 'bi-moon-stars', 'info', 'special', 'special', 1, 50, 41),
('perfect_quiz', 'Perfektionist', 'Svara rätt på första försöket 10 gånger', 'bi-bullseye', 'success', 'special', 'perfect_quiz', 10, 200, 42)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Skapa user_stats för befintliga användare
INSERT IGNORE INTO user_stats (user_id)
SELECT id FROM users;
