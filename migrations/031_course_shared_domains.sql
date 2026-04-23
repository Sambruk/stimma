-- Migration 031: Begränsa kursdelning till utvalda domäner
--
-- Default: en kurs delas med hela organisationen (alla domäner som ingår i
-- samma organisation som kursens organization_domain).
--
-- Om raderna i course_shared_domains för en specifik kurs är icke-tom, syns
-- kursen ENDAST för användare vars e-postdomän finns i den listan.
-- Detta ersätter det tidigare tagg-baserade sättet att begränsa per domän
-- (taggarna finns kvar för annan segmentering som avdelning/roll).

CREATE TABLE course_shared_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    domain VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_course_domain (course_id, domain),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_domain (domain)
);
