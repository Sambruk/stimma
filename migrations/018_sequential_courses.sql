-- Migration 018: Stegvisa kurser (sequential courses)
-- Lägger till stöd för att leverera en lektion i taget med tidsstyrt intervall

ALTER TABLE courses
  ADD COLUMN sequential_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER deadline,
  ADD COLUMN sequential_interval_days INT NOT NULL DEFAULT 7 AFTER sequential_mode,
  ADD COLUMN sequential_reminder_delay_days INT NOT NULL DEFAULT 3 AFTER sequential_interval_days;

CREATE TABLE IF NOT EXISTS sequential_lesson_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    lesson_id INT NOT NULL,
    available_at DATETIME DEFAULT NULL,
    notified_at DATETIME DEFAULT NULL,
    reminded_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_lesson (user_id, lesson_id),
    KEY idx_course_user (course_id, user_id),
    KEY idx_available_notified (available_at, notified_at),
    CONSTRAINT fk_sls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sls_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_sls_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequential_reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    lesson_id INT NOT NULL,
    type ENUM('new_lesson','reminder','manual_reminder') NOT NULL,
    custom_message TEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    email_status ENUM('sent','failed') DEFAULT 'sent',
    error_message TEXT DEFAULT NULL,
    sent_by INT DEFAULT NULL,
    KEY idx_user_course (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_jobs (name, display_name, description, script_path, enabled, schedule_type, run_at_hour)
VALUES ('send_sequential_notifications', 'Stegvisa lektioner - notifieringar',
        'Skickar e-post vid ny tillgänglig lektion och påminnelser',
        'cron/send_sequential_notifications.php', 1, 'daily', 8);
