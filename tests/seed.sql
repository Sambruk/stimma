-- Testdata för Stimmas testmiljö (2026-10-13). Körs mot stimma_test, aldrig mot produktionen.
-- Fasta id:n så att testerna kan peka på dem direkt.
--
-- Organisationer: 1 Sambruk (sambruk.se), 2 Org A (orga.test), 3 Org B (orgb.test)
-- Användare per org: admin, redaktör (is_editor + course_editors), vanlig användare, läsbehörig
-- Kurser:
--   100 A1 (orga.test)           redaktör red-a
--   101 A2 (orga.test, publik)   redaktör red-a – anv-b (org B) deltar via publik åtkomst
--   200 B1 (orgb.test)           redaktör red-b
--   201 B2 (orgb.test, publik)
--   202 B3 (orgb.test, kopierad från orga.test)
-- Lektioner per kurs (id = kurs*10 + n):
--   n=1 en fråga (enkelval, rätt = 1)
--   n=2 utan quiz
--   n=3 två frågor (flerval rätt = [0,2], sant/falskt rätt = sant)

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO organizations (id, name, has_pub_agreement) VALUES
 (1, 'Sambruk', 1), (2, 'Org A', 1), (3, 'Org B', 1);
INSERT INTO organization_domains (organization_id, domain, is_primary) VALUES
 (1, 'sambruk.se', 1), (2, 'orga.test', 1), (3, 'orgb.test', 1);
INSERT INTO domain_settings (domain, has_pub_agreement) VALUES
 ('sambruk.se', 1), ('orga.test', 1), ('orgb.test', 1);

INSERT INTO users (id, email, name, verified_at, role, is_admin, is_editor, is_viewer) VALUES
 (1,  'super@sambruk.se',  'Super Sambruk',   NOW(), 'super_admin', 1, 1, 0),
 (10, 'admin-a@orga.test', 'Admin A',         NOW(), 'admin',       1, 0, 0),
 (11, 'red-a@orga.test',   'Redaktör A',      NOW(), 'student',     0, 1, 0),
 (12, 'anv-a@orga.test',   'Användare A',     NOW(), 'student',     0, 0, 0),
 (13, 'las-a@orga.test',   'Läsbehörig A',    NOW(), 'student',     0, 0, 1),
 (20, 'admin-b@orgb.test', 'Admin B',         NOW(), 'admin',       1, 0, 0),
 (21, 'red-b@orgb.test',   'Redaktör B',      NOW(), 'student',     0, 1, 0),
 (22, 'anv-b@orgb.test',   'Användare B',     NOW(), 'student',     0, 0, 0),
 (23, 'las-b@orgb.test',   'Läsbehörig B',    NOW(), 'student',     0, 0, 1);

INSERT INTO courses (id, title, description, status, organization_domain, original_organization_domain, is_public, public_registration_token, author_id, sort_order) VALUES
 (100, 'A1 Kurs i org A',           'Testkurs', 'active', 'orga.test', NULL,        0, NULL,          11, 1),
 (101, 'A2 Publik kurs i org A',    'Testkurs', 'active', 'orga.test', NULL,        1, 'pubtokena2', 11, 2),
 (200, 'B1 Kurs i org B',           'Testkurs', 'active', 'orgb.test', NULL,        0, NULL,          21, 1),
 (201, 'B2 Publik kurs i org B',    'Testkurs', 'active', 'orgb.test', NULL,        1, 'pubtokenb2', 21, 2),
 (202, 'B3 Kopia av A1 i org B',    'Testkurs', 'active', 'orgb.test', 'orga.test', 0, NULL,          21, 3);

INSERT INTO course_editors (course_id, email, created_by) VALUES
 (100, 'red-a@orga.test', 'admin-a@orga.test'),
 (101, 'red-a@orga.test', 'admin-a@orga.test'),
 (200, 'red-b@orgb.test', 'admin-b@orgb.test'),
 (201, 'red-b@orgb.test', 'admin-b@orgb.test'),
 (202, 'red-b@orgb.test', 'admin-b@orgb.test');

-- Lektioner
INSERT INTO lessons (id, course_id, title, content, status, sort_order, lesson_type)
SELECT c.id * 10 + n.n, c.id, CONCAT('Lektion ', n.n, ' i kurs ', c.id), '<p>Innehåll</p>', 'active', n.n, 'lesson'
FROM courses c JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3) n;

INSERT INTO quiz_questions (id, lesson_id, sort_order, question_type, question_text, quiz_data)
SELECT l.id * 10 + 1, l.id, 1, 'single_choice', 'Vilket är rätt?', '{"answers":["Fel","Rätt","Fel"],"correct":1}'
FROM lessons l WHERE l.sort_order = 1;
INSERT INTO quiz_questions (id, lesson_id, sort_order, question_type, question_text, quiz_data)
SELECT l.id * 10 + 1, l.id, 1, 'multiple_choice', 'Vilka är rätt?', '{"answers":["Rätt","Fel","Rätt","Fel"],"correct":[0,2]}'
FROM lessons l WHERE l.sort_order = 3;
INSERT INTO quiz_questions (id, lesson_id, sort_order, question_type, question_text, quiz_data)
SELECT l.id * 10 + 2, l.id, 2, 'true_false', 'Stämmer det?', '{"correct":true}'
FROM lessons l WHERE l.sort_order = 3;

-- Deltagande
INSERT INTO course_enrollments (user_id, course_id, status) VALUES
 (12, 100, 'active'),
 (22, 200, 'active'),
 (22, 101, 'active');   -- org B-användare i org A:s publika kurs
INSERT INTO public_course_access (user_id, course_id, organization_id) VALUES (22, 101, 3);

-- Framsteg: anv-a klar med lektion 1 i A1, anv-b klar med lektion 1 i A2 och B1
INSERT INTO progress (user_id, lesson_id, status, score) VALUES (12, 1001, 'completed', 1), (22, 1011, 'completed', 1), (22, 2001, 'completed', 1);
INSERT INTO user_progress (user_id, lesson_id, status) VALUES (12, 1001, 'completed'), (22, 1011, 'completed'), (22, 2001, 'completed');

SET FOREIGN_KEY_CHECKS = 1;
