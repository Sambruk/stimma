<?php
/**
 * Backfill: utfärda diplom för användare som slutfört en kurs men saknar cert.
 *
 * Bakgrund: före refaktorn 2026-05-13 (commit a201cbe — "Lektions-UX:
 * multipage…") triggade vissa codepaths inte recordCourseCompletion när
 * sista lektionen markerades klar. Resultat: användare med 100 % klara
 * lektioner men inget diplom. course_complete.php har en backfill men kräver
 * att användaren besöker den sidan — det gör inte alla.
 *
 * Skriptet:
 *   1. Hittar (user_id, course_id) där SAMTLIGA aktiva lektioner i kursen
 *      har progress.status='completed' OCH ingen rad finns i certificates.
 *   2. I dry-run listar det vad som SKULLE utfärdas.
 *   3. Med --apply utfärdar diplom via recordCourseCompletion().
 *
 * Körning:
 *   docker exec stimma-web-1 php /var/www/html/migrations/backfill_missing_certificates.php
 *   docker exec stimma-web-1 php /var/www/html/migrations/backfill_missing_certificates.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Detta skript körs endast via CLI.');
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/connect.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/gamification.php';

$apply = in_array('--apply', $argv, true);

echo "=== Backfill av saknade diplom ===" . PHP_EOL;
echo ($apply ? "LÄGE: --apply (utfärdar diplom)" : "LÄGE: dry-run (inga diplom utfärdas). Använd --apply för att verkställa.") . PHP_EOL;
echo PHP_EOL;

// Hitta (user, course) där alla aktiva lektioner är klara men cert saknas.
// totals = antal aktiva lektioner per kurs.
// dones  = antal aktiva lektioner som usern har p.status='completed' på.
// Match: totals.total > 0 AND dones.done = totals.total AND ingen cert.
$candidates = query(
    "SELECT p.user_id, l.course_id, c.title AS course_title, u.email AS user_email, u.name AS user_name,
            COUNT(*) AS done_count,
            (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons l2
             WHERE l2.course_id = l.course_id AND l2.status = 'active') AS total_count
     FROM " . DB_DATABASE . ".progress p
     JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id AND l.status = 'active'
     JOIN " . DB_DATABASE . ".courses c ON c.id = l.course_id
     JOIN " . DB_DATABASE . ".users u ON u.id = p.user_id
     WHERE p.status = 'completed'
     GROUP BY p.user_id, l.course_id, c.title, u.email, u.name
     HAVING done_count = total_count AND total_count > 0
        AND NOT EXISTS (
            SELECT 1 FROM " . DB_DATABASE . ".certificates ce
            WHERE ce.user_id = p.user_id AND ce.course_id = l.course_id
        )
     ORDER BY l.course_id, p.user_id"
);

if (empty($candidates)) {
    echo "Inga saknade diplom hittades — alla användare som slutfört kurser har redan diplom." . PHP_EOL;
    exit(0);
}

printf("Hittade %d (användare, kurs)-kombinationer utan diplom:%s%s", count($candidates), PHP_EOL, PHP_EOL);

$issued = 0;
$failed = 0;
$skipped = 0;

foreach ($candidates as $row) {
    $userId = (int)$row['user_id'];
    $courseId = (int)$row['course_id'];
    $label = sprintf(
        "  user=%d (%s%s) course=%d \"%s\" [%d/%d klara]",
        $userId,
        $row['user_email'],
        !empty($row['user_name']) ? ' — ' . $row['user_name'] : '',
        $courseId,
        $row['course_title'],
        (int)$row['done_count'],
        (int)$row['total_count']
    );

    if (!$apply) {
        echo $label . PHP_EOL;
        continue;
    }

    $res = recordCourseCompletion($userId, $courseId);
    if (!empty($res['certificate_number'])) {
        echo $label . "  →  " . $res['certificate_number'] . PHP_EOL;
        $issued++;
    } elseif (!empty($res['already_completed'])) {
        echo $label . "  (redan utfärdat — skippad)" . PHP_EOL;
        $skipped++;
    } else {
        echo $label . "  !! MISSLYCKADES: " . json_encode($res) . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL;
if ($apply) {
    printf("Klart. Utfärdade: %d, redan utfärdade: %d, misslyckade: %d%s", $issued, $skipped, $failed, PHP_EOL);
} else {
    echo "Dry-run klar. Kör med --apply för att utfärda diplomen." . PHP_EOL;
}
