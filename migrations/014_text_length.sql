-- Migration 014: Text length preference for AI course generation
-- Allows users to choose short, medium, or long lesson text.

ALTER TABLE ai_course_jobs
  ADD COLUMN text_length VARCHAR(20) NULL DEFAULT 'medium';
