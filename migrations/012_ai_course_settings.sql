-- Migration 012: AI Course Generation Settings
-- Adds columns to ai_course_jobs for tone, color theme, target audience,
-- language style, image generation toggle, and AI question/answer flow.

ALTER TABLE ai_course_jobs
  ADD COLUMN tone VARCHAR(50) NULL DEFAULT 'pedagogical',
  ADD COLUMN color_theme VARCHAR(7) NULL DEFAULT '#007bff',
  ADD COLUMN target_audience VARCHAR(255) NULL,
  ADD COLUMN language_style VARCHAR(50) NULL DEFAULT 'formal',
  ADD COLUMN generate_images TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN ai_questions JSON NULL,
  ADD COLUMN ai_answers JSON NULL;
