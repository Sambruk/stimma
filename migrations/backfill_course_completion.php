<?php
/**
 * Backfill (ärende 782, 2026-10-13): rätta status för deltagare som redan gått klart en kurs.
 *
 * Före rättelsen markerades course_enrollments aldrig som 'completed', och kursavslutet
 * kontrollerades bara när användaren gjorde klart kursens sista sida. Skriptet kör den
 * gemensamma regeln (checkAndCompleteCourse i include/gamification.php, se include/progress.php)
 * för varje (användare, kurs) där alla krävda lektioner är klara:
 *   - course_enrollments.status = 'completed' (påminnelserna läser den)
 *   - diplom utfärdas om diplomkriterierna (x % rätt) är uppfyllda och diplom saknas
 *
 * Kan köras om (checkAndCompleteCourse är idempotent). Skriver bara antal, inga personuppgifter.
 *   docker exec stimma-web-1 php /var/www/html/migrations/backfill_course_completion.php
 *   docker exec stimma-web-1 php /var/www/html/migrations/backfill_course_completion.php --apply
 * Antal före körning: docs/arenden-770-781-782/782_antal_berorda.sql
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Endast CLI.'); }
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/progress.php';

$apply = in_array('--apply', $argv, true);
$db = DB_DATABASE;
echo "=== Backfill av kursavslut ===\n" . ($apply ? "LÄGE: --apply\n" : "LÄGE: torrkörning (inget ändras). Använd --apply.\n");

$candidates = query(
    "SELECT k.user_id, k.course_id FROM (
        SELECT p.user_id, l.course_id, COUNT(DISTINCT p.lesson_id) AS klara
          FROM $db.progress p JOIN $db.lessons l ON l.id = p.lesson_id
         WHERE p.status = 'completed' AND " . requiredLessonSql('l') . "
         GROUP BY p.user_id, l.course_id) k
     JOIN (SELECT course_id, COUNT(*) AS kravs FROM $db.lessons l WHERE " . requiredLessonSql('l') . " GROUP BY course_id) t
       ON t.course_id = k.course_id
     JOIN $db.users u ON u.id = k.user_id
     WHERE k.klara >= t.kravs"
) ?: [];

$stats = ['kandidater' => count($candidates), 'inskrivning_ej_klar' => 0, 'utan_diplom' => 0,
          'diplom_utfardade' => 0, 'kriterium_ej_uppfyllt' => 0];
foreach ($candidates as $c) {
    $uid = (int)$c['user_id']; $cid = (int)$c['course_id'];
    $enr = queryOne("SELECT status FROM $db.course_enrollments WHERE user_id = ? AND course_id = ?", [$uid, $cid]);
    $hadCert = (bool)queryOne("SELECT 1 FROM $db.certificates WHERE user_id = ? AND course_id = ?", [$uid, $cid]);
    if (($enr['status'] ?? '') !== 'completed') $stats['inskrivning_ej_klar']++;
    if (!$hadCert) $stats['utan_diplom']++;
    if ($apply) {
        $r = checkAndCompleteCourse($uid, $cid);
        if (!$hadCert && !empty($r['certificate_number'])) $stats['diplom_utfardade']++;
        if (isset($r['criteria_passed']) && !$r['criteria_passed']) $stats['kriterium_ej_uppfyllt']++;
    }
}
foreach ($stats as $k => $v) echo str_pad($k, 24) . $v . "\n";
