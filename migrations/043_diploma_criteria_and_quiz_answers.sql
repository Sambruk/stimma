-- Migration 043: Diplom-kriterier, quiz_answers och retry-flagga
-- Skapad: 2026-06-05
--
-- Tre delar:
--
-- 1. courses.allow_quiz_retry — om deltagare får besvara samma quiz-fråga
--    flera gånger. Default 1 (tillåts) bevarar nuvarande beteende.
--
-- 2. course_completion_criteria — generisk tabell för diplom-kriterier
--    utöver "alla aktiva lektioner klara". Start med en typ:
--    'min_quiz_percentage' (heltal 0-100). Designad för utbyggnad — fler
--    criterion_type kan läggas till via ALTER ... MODIFY senare.
--
-- 3. quiz_answers — per-fråge-svar med UNIQUE-constraint på
--    (user_id, lesson_id, question_id) så senaste svaret automatiskt
--    skriver över via INSERT ... ON DUPLICATE KEY UPDATE. answered_at
--    uppdateras automatiskt vid varje skrivning.

ALTER TABLE courses
    ADD COLUMN allow_quiz_retry TINYINT(1) NOT NULL DEFAULT 1 AFTER completion_content;

CREATE TABLE course_completion_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    criterion_type ENUM('min_quiz_percentage') NOT NULL,
    threshold_value INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_course (course_id),
    CONSTRAINT fk_criteria_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lesson_id INT NOT NULL,
    question_id INT NOT NULL,
    answer TEXT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_lesson_question (user_id, lesson_id, question_id),
    INDEX idx_user_lesson (user_id, lesson_id),
    INDEX idx_lesson (lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
