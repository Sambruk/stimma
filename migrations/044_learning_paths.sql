-- Migration 044: Lärvägar (learning paths)
-- Skapad: 2026-08-13
--
-- Bakgrund: kurser i Stimma är fristående. Organisationer vill kunna paketera
-- flera kurser till en namngiven "lärväg" med en rekommenderad ordning, t.ex.
-- "Introduktion för nyanställda" (3 kurser i följd). Idag går det bara att
-- antyda med taggar och muntliga instruktioner — det finns ingen vy där en
-- deltagare ser sin samlade väg, och ingen vy där admin ser vilka delar av
-- upplägget olika deltagare genomfört.
--
-- Designbeslut:
--
-- 1. REKOMMENDERAD ORDNING, INGEN LÅSNING.
--    sort_order styr numrering och presentation, men ingen låsningslogik finns
--    i lesson.php — deltagaren får ta kurserna i valfri ordning. (Stegvisa
--    kurser har fortfarande sin egen interna lektionslåsning; den rörs inte.)
--
-- 2. IMPLICIT TILLDELNING.
--    Ingen enrollment-tabell för lärvägar. En lärväg syns för alla användare i
--    rätt org/domän-scope, och status per kurs härleds från befintliga
--    signaler: rad i certificates = genomförd, 4-vägs-unionen
--    course_enrollments ∪ sequential_lesson_schedule ∪ public_course_access ∪
--    (progress JOIN lessons) = registrerad.
--
-- 3. EGET SCOPE, EJ ÄRVT FRÅN KURSERNA.
--    Lärvägen scopas som en kurs: organization_domain + is_global +
--    learning_path_shared_domains (tom lista = hela organisationen, icke-tom =
--    ENDAST dessa domäner). Att härleda synligheten ur de ingående kurserna
--    ger oförutsägbara effekter — en tillagd global kurs skulle göra hela
--    lärvägen global.
--
-- 4. INGET LÄRVÄGSDIPLOM.
--    Statusvyn länkar till kursernas befintliga diplom.
--
-- Collation: utf8mb4_swedish_ci för att matcha courses-tabellen. DB-default är
-- utf8mb4_general_ci, och jämförelser/joins mot courses.organization_domain ger
-- annars "Illegal mix of collations".

CREATE TABLE IF NOT EXISTS learning_paths (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL COMMENT 'Ren text - renderas med nl2br(sanitize()), inget HTML',
    image_url VARCHAR(255) NULL COMMENT 'Sökväg relativt projektroten, samma som courses.image_url',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    organization_domain VARCHAR(150) NOT NULL COMMENT 'Ägardomän - sätts från skaparens e-postdomän',
    is_global TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Endast superadmin: syns för alla organisationer',
    created_by INT NULL COMMENT 'users.id - NULL om användaren raderats',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lp_org_status (organization_domain, status),
    INDEX idx_lp_is_global (is_global),
    INDEX idx_lp_sort (sort_order),
    CONSTRAINT fk_lp_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

CREATE TABLE IF NOT EXISTS learning_path_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    learning_path_id INT NOT NULL,
    course_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Rekommenderad ordning, 0-baserad',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lpc_path_course (learning_path_id, course_id),
    INDEX idx_lpc_path_order (learning_path_id, sort_order),
    INDEX idx_lpc_course (course_id),
    CONSTRAINT fk_lpc_path FOREIGN KEY (learning_path_id)
        REFERENCES learning_paths(id) ON DELETE CASCADE,
    CONSTRAINT fk_lpc_course FOREIGN KEY (course_id)
        REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Spegling av course_shared_domains (migration 031). Samma semantik:
-- inga rader = lärvägen delas med hela organisationen,
-- rader = lärvägen syns ENDAST för användare vars e-postdomän finns i listan.
CREATE TABLE IF NOT EXISTS learning_path_shared_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    learning_path_id INT NOT NULL,
    domain VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lpsd_path_domain (learning_path_id, domain),
    INDEX idx_lpsd_domain (domain),
    CONSTRAINT fk_lpsd_path FOREIGN KEY (learning_path_id)
        REFERENCES learning_paths(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- progress har idag inget index utöver PRIMARY KEY(id). Lärvägarnas
-- batch-queries grupperar över (user_id, lesson_id) för N kurser × M användare
-- och skulle annars bli full table scan. Tabellen är liten, ALTER går snabbt.
ALTER TABLE progress
    ADD INDEX idx_progress_user_lesson (user_id, lesson_id),
    ADD INDEX idx_progress_lesson (lesson_id);
