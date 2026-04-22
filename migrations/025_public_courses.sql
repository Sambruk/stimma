-- Migration 025: Publika kurser
--
-- Låter en organisation publicera en enskild kurs öppet. Externa användare
-- registrerar sig via en unik länk och får tillgång ENDAST till den kurs de
-- registrerat sig för. Samma e-post kan vara kopplad till flera publika kurser
-- över olika organisationer.
--
-- Se /root/.claude/plans/det-finns-ett-nskem-l-quiet-kazoo.md för fullständig
-- design. Alla ändringar är additiva.

-- 1. Nytt åtkomstläge på users.
--    'domain' (default): befintligt beteende (domänskopad åtkomst)
--    'public_only': ser ENDAST kurser i public_course_access (ingen domänscope,
--                   ingen PUB-banner, ingen admin-länk).
--    Auto-promoteras till 'domain' om användaren senare loggar in från en
--    domän i organization_domains (envägs, aldrig demotering).
ALTER TABLE users
  ADD COLUMN access_mode ENUM('domain','public_only') NOT NULL DEFAULT 'domain'
  AFTER role;

-- 2. Publik-flagga + per-kurs registreringstoken.
ALTER TABLE courses
  ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN public_registration_token VARCHAR(64) NULL,
  ADD UNIQUE KEY uk_pub_token (public_registration_token);

-- 3. Huvud-ACL: vilka user_id får se vilka course_id via publik registrering.
CREATE TABLE public_course_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  course_id INT NOT NULL,
  organization_id INT NULL,
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_course (user_id, course_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
  INDEX idx_course (course_id)
);

-- 4. Cross-device magic-link-stöd: registreringsintentionen bor här så att
--    verify.php kan slå upp per verifieringstoken (session-oberoende — t.ex.
--    när användaren registrerar på laptop men klickar mailet på mobilen).
CREATE TABLE public_registration_intents (
  verification_token VARCHAR(64) PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  course_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  INDEX idx_expires (expires_at)
);
