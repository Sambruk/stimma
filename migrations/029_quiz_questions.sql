-- Migration 029: Flera frågor per lektion + typade quizfrågor
--
-- Tidigare lagrades en enda fråga direkt på lessons-raden via kolumnerna
-- quiz_type, quiz_question, quiz_answer1..5, quiz_correct_answer osv. Med
-- utökat stöd för fler frågetyper (true/false, fill_blank, image_choice,
-- order, match_pairs, categorize, numeric, hotspot, short_text) och
-- möjlighet att ha flera frågor per lektion flyttar vi nu ALL quiz-data till
-- en egen tabell.
--
-- quiz_data (LONGTEXT/JSON) bär typ-specifik struktur. Se include/quiz.php
-- för schemat per typ.
--
-- Gamla kolumner på lessons behålls tills dataflyttnings-skript har kört.
-- De kan tas bort i en senare migration.

CREATE TABLE quiz_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    question_type ENUM(
        'single_choice','multiple_choice','true_false',
        'fill_blank','image_choice','order',
        'match_pairs','categorize','numeric',
        'hotspot','short_text'
    ) NOT NULL DEFAULT 'single_choice',
    question_text TEXT NULL,
    question_image VARCHAR(255) NULL,
    quiz_data LONGTEXT NULL,
    points INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    INDEX idx_lesson_sort (lesson_id, sort_order)
);
