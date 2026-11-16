<?php
/**
 * Nattlig gallring (cron/gallring.php, 2026-11-16): lagringstiderna i PUB-avtalets bilaga 1 p. 3.2.
 * logs 12 mån (kurskopior avpersonifieras), påminnelser 6 mån (senaste per deltagare+kurs kvar),
 * ai_usage_log 24 mån. Motprov: torrkörning ändrar inget, nya rader rörs inte, andra körningen är tom.
 */
require __DIR__ . '/lib.php';
reseed();

function kor(string $arg = ''): string {
    return (string)shell_exec('php /var/www/html/cron/gallring.php ' . $arg . ' 2>&1');
}
function antal(string $sql): int { return (int)dbOne($sql)['n']; }

$kopia = 'Kopierade kurs | Kontext: ' . json_encode(['action' => 'course_copied', 'source_course_id' => 11, 'new_course_id' => 22,
    'target_domain' => 'orga.test', 'user_id' => 7, 'ip' => '192.0.2.44', 'user_agent' => 'Mozilla/5.0 Testwebbläsare']);
$kopiaNy = str_replace(['"new_course_id":22', '192.0.2.44'], ['"new_course_id":23', '192.0.2.45'], $kopia);

dbExec("INSERT INTO logs (email, message, created_at) VALUES
    ('gammal@orga.test', 'Loggade in', NOW() - INTERVAL 13 MONTH),
    ('ny@orga.test', 'Loggade in', NOW() - INTERVAL 1 MONTH),
    ('kopierare@orga.test', ?, NOW() - INTERVAL 13 MONTH),
    ('kopierare@orga.test', ?, NOW() - INTERVAL 1 MONTH)", [$kopia, $kopiaNy]);
dbExec("INSERT INTO sequential_reminder_log (user_id, course_id, lesson_id, type, sent_at) VALUES
    (1, 1, 1, 'reminder', NOW() - INTERVAL 7 MONTH), (1, 1, 2, 'new_lesson', NOW() - INTERVAL 5 MONTH)");
// Deltagare 1/kurs 1: tre gamla påminnelser (bara nr 3 ska bli kvar). 1/2: en gammal (kvar).
// 2/1: en gammal och en ny (den gamla bort).
dbExec("INSERT INTO reminder_log (user_id, course_id, reminder_number, sent_at) VALUES
    (1, 1, 1, NOW() - INTERVAL 9 MONTH), (1, 1, 2, NOW() - INTERVAL 8 MONTH), (1, 1, 3, NOW() - INTERVAL 7 MONTH),
    (1, 2, 1, NOW() - INTERVAL 7 MONTH),
    (2, 1, 1, NOW() - INTERVAL 8 MONTH), (2, 1, 2, NOW() - INTERVAL 1 MONTH)");
dbExec("INSERT INTO ai_usage_log (user_email, feature, model, created_at) VALUES
    ('gammal@orga.test', 'chat', 'm', NOW() - INTERVAL 25 MONTH), ('ny@orga.test', 'chat', 'm', NOW() - INTERVAL 23 MONTH)");

$fore = [antal("SELECT COUNT(*) n FROM logs"), antal("SELECT COUNT(*) n FROM sequential_reminder_log"),
         antal("SELECT COUNT(*) n FROM reminder_log"), antal("SELECT COUNT(*) n FROM ai_usage_log")];

section('Torrkörning');
$ut = kor('--torrkorning');
check(strpos($ut, 'TORRKÖRNING') !== false, 'torrkörningen säger att inget ändras', $ut);
foreach (['kurskopior) 1', 'logs raderade 1', 'sequential_reminder_log raderade 1', 'reminder_log raderade 3', 'ai_usage_log raderade 1'] as $v) {
    check(strpos($ut, $v) !== false, "torrkörningen räknar: $v", $ut);
}
$efter = [antal("SELECT COUNT(*) n FROM logs"), antal("SELECT COUNT(*) n FROM sequential_reminder_log"),
          antal("SELECT COUNT(*) n FROM reminder_log"), antal("SELECT COUNT(*) n FROM ai_usage_log")];
check($fore === $efter && !dbOne("SELECT 1 FROM logs WHERE email = 'gallrad'"), 'torrkörningen ändrar inget');

section('Gallring');
$ut = kor();
check(strpos($ut, 'Gallring klar') !== false, 'jobbet går igenom', $ut);
check(!dbOne("SELECT 1 FROM logs WHERE email = 'gammal@orga.test'"), 'logg äldre än 12 månader raderad');
check((bool)dbOne("SELECT 1 FROM logs WHERE email = 'ny@orga.test'"), 'ny logg kvar');
$rad = dbOne("SELECT email, message FROM logs WHERE message LIKE 'Kopierade kurs | Kontext:%' AND message LIKE '%\"new_course_id\":22%'");
check($rad && $rad['email'] === 'gallrad', 'gammal kurskopia kvar men e-posten borttagen', json_encode($rad));
check($rad && strpos($rad['message'], '192.0.2.44') === false && strpos($rad['message'], 'user_id') === false
    && strpos($rad['message'], 'Testwebbläsare') === false && strpos($rad['message'], 'orga.test') !== false,
    'kurskopian: IP, användar-id och webbläsare borta, måldomänen kvar', $rad['message'] ?? '');
// Samma reguljära uttryck som insynCopySources() i public/api/v1/insyn/index.php
check($rad && preg_match('/"source_course_id":"?(\d+)"?.*?"new_course_id":"?(\d+)"?/', $rad['message'], $m) && $m[1] === '11' && $m[2] === '22',
    'insyns-API:t hittar fortfarande källkursen');
check((bool)dbOne("SELECT 1 FROM logs WHERE email = 'kopierare@orga.test' AND message LIKE '%192.0.2.45%'"), 'ny kurskopia orörd');

check(antal("SELECT COUNT(*) n FROM sequential_reminder_log") === 1
    && (bool)dbOne("SELECT 1 FROM sequential_reminder_log WHERE lesson_id = 2"), 'stegvisa påminnelser: gammal bort, ny kvar');

$kvar = array_map(fn($r) => $r['user_id'] . '/' . $r['course_id'] . '#' . $r['reminder_number'],
    dbAll("SELECT user_id, course_id, reminder_number FROM reminder_log ORDER BY user_id, course_id, reminder_number"));
check($kvar === ['1/1#3', '1/2#1', '2/1#2'], 'påminnelser: senaste per deltagare och kurs kvar', implode(' ', $kvar));
// Räknaren i cron/send_reminders.php
check((int)dbOne("SELECT MAX(reminder_number) n FROM reminder_log WHERE user_id = 1 AND course_id = 1")['n'] === 3,
    'påminnelseräknaren står kvar på 3 (inga nya påminnelser skickas)');

check(antal("SELECT COUNT(*) n FROM ai_usage_log") === 1 && (bool)dbOne("SELECT 1 FROM ai_usage_log WHERE user_email = 'ny@orga.test'"),
    'AI-användningslogg: äldre än 24 månader bort, 23 månader kvar');
check((bool)dbOne("SELECT 1 FROM logs WHERE message LIKE 'Gallring: %'"), 'körningen loggas');

section('Andra körningen');
$ut = kor();
foreach (['kurskopior) 0', 'logs raderade 0', 'sequential_reminder_log raderade 0', 'reminder_log raderade 0', 'ai_usage_log raderade 0'] as $v) {
    check(strpos($ut, $v) !== false, "inget mer att gallra: $v", $ut);
}
check((bool)dbOne("SELECT 1 FROM logs WHERE email = 'gallrad'"), 'den avpersonifierade kurskopian raderas inte');

section('Bara från kommandoraden');
// cron/ ligger utanför webbroten (public/); dessutom stoppar php_sapi_name()-kontrollen
$fore = antal("SELECT COUNT(*) n FROM logs WHERE message LIKE 'Gallring: %'");
$ch = curl_init(BASE . '/cron/gallring.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . HOST]]);
$svar = (string)curl_exec($ch);
$kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
check($kod === 404 && strpos($svar, 'Gallring') === false, 'går inte att nå som webbsida', "HTTP $kod");
check(antal("SELECT COUNT(*) n FROM logs WHERE message LIKE 'Gallring: %'") === $fore, 'webbanropet körde inte jobbet');

done();
