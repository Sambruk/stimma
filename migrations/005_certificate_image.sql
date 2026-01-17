-- Migration: Certificate Image per Course
-- Adds support for custom certificate images per course

SET NAMES utf8mb4;
USE stimma;

-- Add certificate_image column to courses table
ALTER TABLE `courses`
ADD COLUMN IF NOT EXISTS `certificate_image_url` varchar(255) DEFAULT NULL
COMMENT 'URL to custom certificate image/logo for this course';
