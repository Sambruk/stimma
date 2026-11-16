<?php
/**
 * Stimma - Nattlig gallring av personuppgifter (2026-11-16)
 *
 * Lagringstiderna i PUB-avtalets bilaga 1, punkt 3.2:
 *   - Aktivitetsloggar (logs)                         12 månader
 *   - Påminnelsehistorik (reminder_log,
 *     sequential_reminder_log)                         6 månader
 *   - AI-användningslogg (ai_usage_log)               24 månader
 *
 * Två undantag, för att inte ändra hur tjänsten fungerar:
 *   - reminder_log: den SENASTE raden per deltagare och kurs sparas. send_reminders.php räknar
 *     redan skickade påminnelser med MAX(reminder_number) – raderas allt får deltagaren
 *     påminnelserna en gång till. Raden försvinner när kursen eller deltagaren tas bort.
 *   - logs: raderna "Kopierade kurs | Kontext:" raderas inte utan avpersonifieras (bara
 *     källkurs, ny kurs och måldomän kvar; e-post blir "gallrad"). Insyns-API:t
 *     (insynCopySources) läser dem för all tid för att knyta en kopia till sin källkurs.
 *
 * Körs av Ofelia kl 02:00, före backupen 02:30, så att gallrade uppgifter inte följer med
 * i nattens säkerhetskopia. Säkerhetskopiorna gallras själva inom tre månader (restic).
 *
 * Användning: php cron/gallring.php [--torrkorning]
 * Använder PDO med undantag direkt: query()/execute() sväljer SQL-fel.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}
define('CLI_MODE', true);
chdir(dirname(__DIR__));
require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';

const GALLRING_LOGS_MANADER = 12;
const GALLRING_PAMINNELSER_MANADER = 6;
const GALLRING_AI_MANADER = 24;
const GALLRING_KOPIA_PREFIX = 'Kopierade kurs | Kontext: ';
const GALLRING_BATCH = 5000;

$torr = in_array('--torrkorning', $argv, true);
$db = getDb();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$t = DB_DATABASE;

function gLogg(string $m): void { echo '[' . date('Y-m-d H:i:s') . "] $m\n"; }
function gAntal(PDO $db, string $sql): int { return (int)$db->query($sql)->fetchColumn(); }
/** DELETE i omgångar så att tabellen inte låses länge. */
function gRadera(PDO $db, string $sql): int {
    $n = 0;
    do { $k = $db->exec($sql . ' LIMIT ' . GALLRING_BATCH); $n += $k; } while ($k === GALLRING_BATCH);
    return $n;
}

$gransLogs = "NOW() - INTERVAL " . GALLRING_LOGS_MANADER . " MONTH";
$gransPam = "NOW() - INTERVAL " . GALLRING_PAMINNELSER_MANADER . " MONTH";
$gransAi = "NOW() - INTERVAL " . GALLRING_AI_MANADER . " MONTH";
$kopiaLike = $db->quote(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], GALLRING_KOPIA_PREFIX) . '%');
$ej_avpers = "NOT (email = 'gallrad' AND message LIKE $kopiaLike)";

$res = [];
try {
    // 1. Kopieringsloggen: avpersonifiera i stället för att radera
    $kopior = $db->query("SELECT id, message FROM $t.logs
                           WHERE created_at < $gransLogs AND message LIKE $kopiaLike AND email <> 'gallrad'")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $db->prepare("UPDATE $t.logs SET email = 'gallrad', message = ? WHERE id = ?");
    $avpers = 0;
    foreach ($kopior as $r) {
        $j = json_decode(substr($r['message'], strlen(GALLRING_KOPIA_PREFIX)), true);
        $kvar = [
            'action' => 'course_copied',
            'source_course_id' => (int)($j['source_course_id'] ?? 0),
            'new_course_id' => (int)($j['new_course_id'] ?? 0),
            'target_domain' => (string)($j['target_domain'] ?? ''),
        ];
        if (!$kvar['source_course_id'] || !$kvar['new_course_id']) {
            continue; // oläslig rad: raderas med övriga loggar nedan
        }
        if (!$torr) {
            $upd->execute([GALLRING_KOPIA_PREFIX . json_encode($kvar, JSON_UNESCAPED_UNICODE), $r['id']]);
        }
        $avpers++;
    }
    $res['logs avpersonifierade (kurskopior)'] = $avpers;

    // 2. Övriga aktivitetsloggar äldre än 12 månader
    $villkor = "created_at < $gransLogs AND $ej_avpers"
        . ($avpers && $torr ? " AND NOT (message LIKE $kopiaLike)" : '');
    $res['logs raderade'] = $torr
        ? gAntal($db, "SELECT COUNT(*) FROM $t.logs WHERE $villkor")
        : gRadera($db, "DELETE FROM $t.logs WHERE $villkor");

    // 3. Stegvisa påminnelser: bara logg och statistik, raderas rakt av
    $res['sequential_reminder_log raderade'] = $torr
        ? gAntal($db, "SELECT COUNT(*) FROM $t.sequential_reminder_log WHERE sent_at < $gransPam")
        : gRadera($db, "DELETE FROM $t.sequential_reminder_log WHERE sent_at < $gransPam");

    // 4. Påminnelser: behåll senaste raden per deltagare och kurs (räknaren i send_reminders.php)
    // (JOIN mot grupperad tabell: MariaDB nekar DELETE med underfråga mot samma tabell, fel 1093)
    $fran = "FROM $t.reminder_log r
             JOIN (SELECT user_id, course_id, MAX(id) AS senaste FROM $t.reminder_log GROUP BY user_id, course_id) k
               ON k.user_id = r.user_id AND k.course_id = r.course_id
            WHERE r.sent_at < $gransPam AND r.id < k.senaste";
    $res['reminder_log raderade'] = $torr
        ? gAntal($db, "SELECT COUNT(*) $fran")
        : $db->exec("DELETE r $fran");

    // 5. AI-användningsloggen
    $res['ai_usage_log raderade'] = $torr
        ? gAntal($db, "SELECT COUNT(*) FROM $t.ai_usage_log WHERE created_at < $gransAi")
        : gRadera($db, "DELETE FROM $t.ai_usage_log WHERE created_at < $gransAi");
} catch (Throwable $e) {
    gLogg('FEL: ' . $e->getMessage());
    if (!$torr) {
        logActivity('system', 'Gallring misslyckades: ' . $e->getMessage());
    }
    exit(1);
}

$sammanfattning = implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($res), $res));
gLogg(($torr ? 'TORRKÖRNING (inget ändrat): ' : 'Gallring klar: ') . $sammanfattning);
if (!$torr) {
    logActivity('system', 'Gallring: ' . $sammanfattning);
}
