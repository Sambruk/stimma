-- Migration: 017_course_org_tags.sql
-- Skapad: 2026-03-03
-- Beskrivning: Skapar course_org_tags-tabell för att begränsa kurser till specifika organisationsdelar

CREATE TABLE IF NOT EXISTS course_org_tags (
    course_id INT NOT NULL,
    tag VARCHAR(255) NOT NULL,
    PRIMARY KEY (course_id, tag),
    INDEX idx_tag (tag),
    CONSTRAINT fk_course_org_tags_course FOREIGN KEY (course_id)
        REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
