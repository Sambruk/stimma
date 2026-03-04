-- Migration 020: E-postmallar, kö och autostart för stegvisa kurser
-- Lägger till redigerbara mallar, e-postkö med throttling, startdatum och autostatus

-- Nya kolumner på courses
ALTER TABLE courses
  ADD COLUMN start_date DATE DEFAULT NULL AFTER deadline,
  ADD COLUMN seq_new_lesson_subject VARCHAR(500) DEFAULT NULL AFTER start_date,
  ADD COLUMN seq_new_lesson_body TEXT DEFAULT NULL AFTER seq_new_lesson_subject,
  ADD COLUMN seq_reminder_subject VARCHAR(500) DEFAULT NULL AFTER seq_new_lesson_body,
  ADD COLUMN seq_reminder_body TEXT DEFAULT NULL AFTER seq_reminder_subject,
  ADD COLUMN sequential_status ENUM('pending','sending','active','completed') DEFAULT NULL AFTER seq_reminder_body;

-- E-postkö (throttlad utskick)
CREATE TABLE IF NOT EXISTS sequential_email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    lesson_id INT NOT NULL,
    email_type ENUM('new_lesson','reminder') NOT NULL,
    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    error_message TEXT DEFAULT NULL,
    batch_id INT DEFAULT NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_scheduled (status, scheduled_at),
    KEY idx_course_status (course_id, status),
    CONSTRAINT fk_seq_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_seq_queue_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_seq_queue_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Superadmin-inställningar
INSERT IGNORE INTO ai_settings (setting_key, setting_value, description, updated_by)
VALUES
  ('sequential_cron_hour', '8', 'Timme (0-23) för nattligt stegvist utskick', 'system'),
  ('sequential_batch_size', '10', 'Antal e-post per batch', 'system'),
  ('sequential_batch_delay_seconds', '30', 'Sekunder mellan batchar', 'system');

-- Cron-jobb för automatisk kursstart
INSERT INTO cron_jobs (name, display_name, description, script_path, enabled, schedule_type, run_at_hour)
VALUES ('process_sequential_starts', 'Stegvisa kurser - automatisk start',
        'Startar kurser som når startdatum, registrerar användare, köar e-post',
        'cron/process_sequential_starts.php', 1, 'daily', 8);
