<?php
/**
 * Ärende 781: statistikmenyn visar mer än den egna organisationen.
 * Ingen statistikvy och ingen export får visa uppgifter om användare i en annan
 * organisation för någon annan än Sambruks centrala administratör.
 * Beslut 2026-10-13 (Thomas): andra organisationers deltagare i egen kurs visas bara som antal.
 */
require __DIR__ . '/lib.php';
reseed();
// anv-b (org B) har gått klart org A:s publika kurs och fått diplom
dbExec("UPDATE progress SET status = 'completed' WHERE user_id = 22");
dbExec("INSERT INTO progress (user_id, lesson_id, status) VALUES (22, 1012, 'completed'), (22, 1013, 'completed')");
dbExec("INSERT INTO certificates (user_id, course_id, certificate_number, course_title, user_name, completion_date) VALUES (22, 101, 'TEST-B-1', 'A2 Publik kurs i org A', 'Användare B', CURDATE())");
// anv-a har en certifikat i A1 så att egna uppgifter syns
dbExec("INSERT INTO certificates (user_id, course_id, certificate_number, course_title, user_name, completion_date) VALUES (12, 100, 'TEST-A-1', 'A1 Kurs i org A', 'Användare A', CURDATE())");
// en gmail-deltagare (ingen organisation) i A:s publika kurs – ska synas för kursens org
dbExec("INSERT INTO users (id, email, name, verified_at, access_mode) VALUES (30, 'extern@gmail.com', 'Extern Person', NOW(), 'public_only')");
dbExec("INSERT INTO public_course_access (user_id, course_id) VALUES (30, 101)");
dbExec("INSERT INTO course_enrollments (user_id, course_id, status) VALUES (30, 101, 'active')");
dbExec("INSERT INTO progress (user_id, lesson_id, status) VALUES (30, 1011, 'completed')");

$B_USER = '/anv-b|Användare B|Anv&auml;ndare B|Anv\\\\u00e4ndare B/';
function leaks(string $body, string $p): bool { return (bool)preg_match($p, $body); }

$views = [
    '/admin/index.php',
    '/admin/statistics.php',
    '/admin/statistics.php?course_id=101',
    '/admin/statistics.php?course_id=200',
    '/admin/course_stats.php',
    '/admin/course_stats.php?course_id=101',
    '/admin/course_stats.php?course_id=200',
    '/admin/certificates.php',
    '/admin/users.php',
    '/admin/public_participants.php?course_id=101',
    '/admin/public_participants.php?course_id=201',
];
$exports = [
    '/admin/export_statistics.php?course_id=101',
    '/admin/export_statistics.php?course_id=200',
    '/admin/export_users.php',
];

foreach (['admin-a@orga.test', 'red-a@orga.test', 'las-a@orga.test', 'anv-a@orga.test'] as $email) {
    section("Statistik som $email");
    $c = new Client($email);
    foreach ($views as $p) {
        $c->get($p);
        check(!leaks($c->body, $B_USER), "$email: $p visar inte org B:s användare", $c->status);
    }
    foreach ($exports as $p) {
        $c->get($p);
        $t = xlsxText($c->body);
        check(!leaks($t, $B_USER), "$email: $p exporterar inte org B:s användare", $c->status);
    }
    $c->get('/admin/export_statistics.php?course_id=200');
    check(!preg_match('/Lektion \d i kurs 200|B1 Kurs i org B/', xlsxText($c->body)), "$email: export_statistics visar inte B:s kurs", $c->status);
    $c->get('/admin/course_stats.php?course_id=200');
    check(!preg_match('/Lektion \d i kurs 200/', $c->body), "$email: course_stats visar inte B:s kurs");
}

section('Extern kursredaktör (red-a redaktör för B1) ser inte B:s statistik');
dbExec("INSERT INTO course_editors (course_id, email, created_by) VALUES (200, 'red-a@orga.test', 'admin-b@orgb.test')");
$r = new Client('red-a@orga.test');
foreach (['/admin/statistics.php?course_id=200', '/admin/course_stats.php?course_id=200'] as $p) {
    $r->get($p);
    check(!leaks($r->body, $B_USER), "extern redaktör: $p visar inte B:s deltagare");
}
$r->get('/admin/export_statistics.php?course_id=200');
check(!leaks(xlsxText($r->body), $B_USER), 'extern redaktör: export_statistics visar inte B:s deltagare');
dbExec("DELETE FROM course_editors WHERE course_id = 200 AND email = 'red-a@orga.test'");

section('Andra organisationers deltagare visas som antal');
$a = new Client('admin-a@orga.test');
$a->get('/admin/course_stats.php?course_id=101');
check((bool)preg_match('/andra organisationer/i', $a->body), 'course_stats för egen publik kurs nämner deltagare från andra organisationer som antal', snippet($a->body, 0));
check(strpos($a->body, 'extern@gmail.com') !== false, 'publik deltagare utan organisation syns för kursens organisation');

section('Det som ska synas');
$a->get('/admin/course_stats.php?course_id=100');
check(strpos($a->body, 'anv-a@orga.test') !== false || strpos($a->body, 'Användare A') !== false, 'admin A ser egen användare i egen kurs');
$a->get('/admin/certificates.php');
check(strpos($a->body, 'anv-a@orga.test') !== false || strpos($a->body, 'Användare A') !== false, 'admin A ser egna diplom');
$r = new Client('red-a@orga.test');
$r->get('/admin/statistics.php?course_id=100');
check(strpos($r->body, 'anv-a') !== false, 'redaktör A ser egen org:s deltagare i sin kurs');
$l = new Client('las-a@orga.test');
$l->get('/admin/course_stats.php?course_id=100');
check(strpos($l->body, 'anv-a@orga.test') !== false || strpos($l->body, 'Användare A') !== false, 'läsbehörig A ser egen org:s deltagare');
$s = new Client('super@sambruk.se');
$s->get('/admin/course_stats.php?course_id=101');
check(leaks($s->body, $B_USER), 'superadmin ser alla deltagare i A:s publika kurs');

done();
