-- Ärende 782: hur många har gått klart en kurs men saknar status "klar"?
-- Bara antal, inga personuppgifter. Läser, ändrar ingenting. Kör t.ex.:
--   docker exec -i stimma-db-1 mariadb -ustimma_user -p stimma < docs/arenden-770-781-782/782_antal_berorda.sql
--
-- Regeln (include/progress.php): en kurs är klar när alla aktiva lektioner av typen
-- 'lesson' är klara. Infosidor krävs inte.

-- A. Alla krävda lektioner klara, men inget diplom och/eller inskrivningen inte 'completed'
SELECT
  COUNT(*)                                             AS a_alla_lektioner_klara,
  SUM(cert.id IS NULL)                                 AS a_varav_utan_diplom,
  SUM(COALESCE(ce.status, '') <> 'completed')          AS a_varav_inskrivning_ej_klar
FROM (
  SELECT p.user_id, l.course_id, COUNT(DISTINCT p.lesson_id) AS klara
  FROM progress p JOIN lessons l ON l.id = p.lesson_id
  WHERE p.status = 'completed' AND l.status = 'active' AND l.lesson_type = 'lesson'
  GROUP BY p.user_id, l.course_id
) k
JOIN (SELECT course_id, COUNT(*) AS kravs FROM lessons
      WHERE status = 'active' AND lesson_type = 'lesson' GROUP BY course_id) t ON t.course_id = k.course_id
JOIN courses c ON c.id = k.course_id AND c.status = 'active'
LEFT JOIN certificates cert ON cert.user_id = k.user_id AND cert.course_id = k.course_id
LEFT JOIN course_enrollments ce ON ce.user_id = k.user_id AND ce.course_id = k.course_id
WHERE k.klara >= t.kravs;

-- B. Alla lektioner MED quiz klara, men någon lektion UTAN quiz saknas
--    (lektioner utan quiz kunde aldrig bli klara före rättelsen 2026-10-13)
SELECT COUNT(*) AS b_fast_pa_lektion_utan_quiz, COUNT(DISTINCT q.course_id) AS b_kurser
FROM (
  SELECT p.user_id, l.course_id, COUNT(DISTINCT p.lesson_id) AS klara_med_quiz
  FROM progress p JOIN lessons l ON l.id = p.lesson_id
  WHERE p.status = 'completed' AND l.status = 'active' AND l.lesson_type = 'lesson'
    AND EXISTS (SELECT 1 FROM quiz_questions qq WHERE qq.lesson_id = l.id)
  GROUP BY p.user_id, l.course_id
) q
JOIN (SELECT l.course_id,
             SUM(EXISTS (SELECT 1 FROM quiz_questions qq WHERE qq.lesson_id = l.id)) AS med_quiz,
             SUM(NOT EXISTS (SELECT 1 FROM quiz_questions qq WHERE qq.lesson_id = l.id)) AS utan_quiz
      FROM lessons l WHERE l.status = 'active' AND l.lesson_type = 'lesson' GROUP BY l.course_id) t
  ON t.course_id = q.course_id
JOIN courses c ON c.id = q.course_id AND c.status = 'active'
WHERE t.utan_quiz > 0 AND q.klara_med_quiz >= t.med_quiz
  AND NOT EXISTS (
    SELECT 1 FROM lessons l2
    WHERE l2.course_id = q.course_id AND l2.status = 'active' AND l2.lesson_type = 'lesson'
      AND NOT EXISTS (SELECT 1 FROM quiz_questions qq WHERE qq.lesson_id = l2.id)
      AND EXISTS (SELECT 1 FROM progress p2 WHERE p2.user_id = q.user_id AND p2.lesson_id = l2.id AND p2.status = 'completed')
    HAVING COUNT(*) >= t.utan_quiz);

-- C. Deltagare som har börjat en kurs och står still framför en lektion utan quiz
SELECT COUNT(DISTINCT p.user_id, l.course_id) AS c_har_borjat_kurs_med_lektion_utan_quiz
FROM progress p JOIN lessons l ON l.id = p.lesson_id
JOIN courses c ON c.id = l.course_id AND c.status = 'active'
WHERE p.status = 'completed'
  AND EXISTS (SELECT 1 FROM lessons lq WHERE lq.course_id = l.course_id AND lq.status = 'active'
              AND lq.lesson_type = 'lesson' AND NOT EXISTS (SELECT 1 FROM quiz_questions qq WHERE qq.lesson_id = lq.id)
              AND NOT EXISTS (SELECT 1 FROM progress pq WHERE pq.user_id = p.user_id AND pq.lesson_id = lq.id AND pq.status = 'completed'));

-- D. Kurser helt utan quiz: ingen kunde någonsin registreras som klar
SELECT COUNT(DISTINCT c.id) AS d_aktiva_kurser_utan_quiz,
       COUNT(DISTINCT ce.user_id, ce.course_id) AS d_inskrivna_deltagare
FROM courses c
JOIN lessons l ON l.course_id = c.id AND l.status = 'active' AND l.lesson_type = 'lesson'
LEFT JOIN course_enrollments ce ON ce.course_id = c.id
WHERE c.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM lessons lq JOIN quiz_questions qq ON qq.lesson_id = lq.id
                  WHERE lq.course_id = c.id AND lq.status = 'active');

-- E. De 13 utan diplom i A: hur många beror på diplomkriteriet (x % rätt)?
SELECT COUNT(*) AS e_utan_diplom_med_kriterium
FROM (
  SELECT p.user_id, l.course_id, COUNT(DISTINCT p.lesson_id) AS klara
  FROM progress p JOIN lessons l ON l.id = p.lesson_id
  WHERE p.status = 'completed' AND l.status = 'active' AND l.lesson_type = 'lesson'
  GROUP BY p.user_id, l.course_id
) k
JOIN (SELECT course_id, COUNT(*) AS kravs FROM lessons WHERE status = 'active' AND lesson_type = 'lesson' GROUP BY course_id) t ON t.course_id = k.course_id
JOIN courses c ON c.id = k.course_id AND c.status = 'active'
LEFT JOIN certificates cert ON cert.user_id = k.user_id AND cert.course_id = k.course_id
WHERE k.klara >= t.kravs AND cert.id IS NULL
  AND EXISTS (SELECT 1 FROM course_completion_criteria cc WHERE cc.course_id = k.course_id);
