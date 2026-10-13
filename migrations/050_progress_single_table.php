<?php
/**
 * Migrering 050 (ärende 782, 2026-10-13): EN tabell för framsteg, med unikt index.
 *
 * - progress är appens framstegstabell. user_progress skrevs aldrig (bara api/course_status.php
 *   och död kod läste den) och avvecklas.
 * - Steg 1: klara rader i user_progress förs över till progress (inget framsteg går förlorat).
 * - Steg 2: dubbletter i progress slås ihop – en rad per (user_id, lesson_id); 'completed' vinner.
 * - Steg 3: UNIQUE (user_id, lesson_id) på progress.
 * - Steg 4: user_progress döps om till user_progress_avvecklad_20261013 (tas bort senare för hand).
 *
 * Kan köras om: varje steg kontrollerar läget först. Standard är torrkörning.
 *   docker exec stimma-web-1 php /var/www/html/migrations/050_progress_single_table.php
 *   docker exec stimma-web-1 php /var/www/html/migrations/050_progress_single_table.php --apply
 * Ta backup först (backup/backup.sh körs varje natt; manuellt: se backup/README).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Endast CLI.'); }
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';

$apply = in_array('--apply', $argv, true);
$db = DB_DATABASE;
echo "=== 050: en framstegstabell ===\n" . ($apply ? "LÄGE: --apply\n" : "LÄGE: torrkörning (inget ändras). Använd --apply.\n");

$hasUp = (bool)queryOne("SHOW TABLES LIKE 'user_progress'");
$hasIndex = (bool)queryOne("SHOW INDEX FROM $db.progress WHERE Key_name = 'uk_progress_user_lesson'");

// Steg 1
if ($hasUp) {
    $n = (int)queryOne("SELECT COUNT(*) n FROM $db.user_progress up WHERE up.status = 'completed'
        AND NOT EXISTS (SELECT 1 FROM $db.progress p WHERE p.user_id = up.user_id AND p.lesson_id = up.lesson_id AND p.status = 'completed')")['n'];
    echo "Steg 1: $n klara rader i user_progress saknas i progress\n";
    if ($apply && $n > 0) {
        execute("UPDATE $db.progress p JOIN $db.user_progress up ON up.user_id = p.user_id AND up.lesson_id = p.lesson_id
                 SET p.status = 'completed' WHERE up.status = 'completed' AND p.status <> 'completed'");
        execute("INSERT INTO $db.progress (user_id, lesson_id, status, score)
                 SELECT up.user_id, up.lesson_id, 'completed', 1 FROM $db.user_progress up
                 WHERE up.status = 'completed'
                   AND NOT EXISTS (SELECT 1 FROM $db.progress p WHERE p.user_id = up.user_id AND p.lesson_id = up.lesson_id)");
    }
} else {
    echo "Steg 1: user_progress finns inte längre – hoppar över\n";
}

// Steg 2
$dupes = (int)queryOne("SELECT COUNT(*) n FROM (SELECT 1 FROM $db.progress GROUP BY user_id, lesson_id HAVING COUNT(*) > 1) d")['n'];
echo "Steg 2: $dupes (användare, lektion)-par med dubbletter\n";
if ($apply && $dupes > 0) {
    // Behåll en rad per par: helst en klar rad, annars den äldsta
    execute("UPDATE $db.progress p JOIN (SELECT user_id, lesson_id FROM $db.progress WHERE status = 'completed' GROUP BY user_id, lesson_id) k
             ON k.user_id = p.user_id AND k.lesson_id = p.lesson_id SET p.status = 'completed'");
    execute("DELETE p FROM $db.progress p JOIN $db.progress keep
             ON keep.user_id = p.user_id AND keep.lesson_id = p.lesson_id AND keep.id < p.id");
}

// Steg 3
echo "Steg 3: unikt index " . ($hasIndex ? "finns redan\n" : "saknas\n");
if ($apply && !$hasIndex) {
    execute("ALTER TABLE $db.progress ADD UNIQUE KEY uk_progress_user_lesson (user_id, lesson_id)");
}

// Steg 4
if ($hasUp) {
    echo "Steg 4: user_progress döps om till user_progress_avvecklad_20261013\n";
    if ($apply) execute("RENAME TABLE $db.user_progress TO $db.user_progress_avvecklad_20261013");
}

if ($apply) {
    $ok = (bool)queryOne("SHOW INDEX FROM $db.progress WHERE Key_name = 'uk_progress_user_lesson'");
    echo $ok ? "Klart: progress har unikt index.\n" : "VARNING: indexet saknas – kontrollera felloggen.\n";
}
