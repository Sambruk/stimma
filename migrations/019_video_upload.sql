-- Migration 019: Video upload support
-- Adds video_type column to lessons table for local video uploads

ALTER TABLE lessons ADD COLUMN video_type ENUM('youtube','local') DEFAULT NULL AFTER video_url;

-- Backfill: existing rows with video_url are YouTube
UPDATE lessons SET video_type = 'youtube' WHERE video_url IS NOT NULL AND video_url != '';
