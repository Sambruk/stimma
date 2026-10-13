<?php
/**
 * Ärende 770: man kan redigera andras kurser genom att byta kurs-id.
 * Org A:s admin, redaktör, läsbehörig och användare får inte se redigeringsläget för,
 * ändra, sortera, ladda upp till, exportera eller ta bort kurser/lektioner i org B.
 * De behörigheter som ska finnas ska fortfarande fungera.
 */
require __DIR__ . '/lib.php';
reseed();
// Förberedelser: B1 stegvis och med diplombild, så att de åtgärderna går att pröva
dbExec("UPDATE courses SET sequential_mode = 1, certificate_image_url = 'upload/b1.png' WHERE id = 200");

$B = '/B1 Kurs i org B|Lektion \d i kurs 20[012]|Vilket är rätt\?/';
function denied(Client $c, string $pattern): bool {
    return !preg_match($pattern, $c->body);
}
function lessonTitle(int $id) { return dbOne("SELECT title, course_id FROM lessons WHERE id = ?", [$id]); }

$readPages = [
    '/admin/edit_course.php?id=200',
    '/admin/edit_lesson.php?id=2001',
    '/admin/edit_lesson.php?course_id=200',
    '/admin/lessons.php?course_id=200',
    '/admin/edit_quiz.php?lesson_id=2001',
    '/admin/export.php?id=200',
    '/admin/certificates.php?preview=200',
];

foreach (['admin-a@orga.test', 'red-a@orga.test', 'las-a@orga.test', 'anv-a@orga.test'] as $email) {
    section("Läsning av org B som $email");
    $c = new Client($email);
    foreach ($readPages as $p) {
        $c->get($p);
        check(denied($c, $B), "$email nekas $p", $c->status . ' ' . snippet($c->body, 100));
    }
}

section('Ändringar i org B som admin A');
$a = new Client('admin-a@orga.test');
$a->fetchCsrf();
$a->post('/admin/edit_lesson.php?id=2001', ['title' => 'KAPAD', 'course_id' => 0, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
$l = lessonTitle(2001);
check($l['title'] !== 'KAPAD' && (int)$l['course_id'] === 200, 'edit_lesson POST id=2001 course_id=0 ändrar inte B:s lektion', json_encode($l));
$a->post('/admin/edit_lesson.php?id=2001', ['title' => 'FLYTTAD', 'course_id' => 100, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
$l = lessonTitle(2001);
check($l['title'] !== 'FLYTTAD' && (int)$l['course_id'] === 200, 'edit_lesson flyttar inte B:s lektion till egen kurs', json_encode($l));
$a->post('/admin/edit_lesson.php?id=1001', ['title' => 'TILL B', 'course_id' => 200, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
$l = lessonTitle(1001);
check((int)$l['course_id'] === 100, 'edit_lesson flyttar inte egen lektion till B:s kurs', json_encode($l));
$a->post('/admin/edit_lesson.php', ['title' => 'NY I B', 'course_id' => 200, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
check(!dbOne("SELECT 1 FROM lessons WHERE title = 'NY I B'"), 'edit_lesson skapar ingen lektion i B:s kurs');
$a->post('/admin/edit_course.php?id=200', ['title' => 'KAPAD KURS', 'description' => 'x', 'status' => 'active']);
check(dbOne("SELECT title FROM courses WHERE id = 200")['title'] === 'B1 Kurs i org B', 'edit_course POST ändrar inte B:s kurs');
$a->post('/admin/delete_lesson.php', ['id' => 2002]);
check((bool)dbOne("SELECT 1 FROM lessons WHERE id = 2002"), 'delete_lesson tar inte bort B:s lektion');
$a->post('/admin/delete_course.php', ['id' => 201]);
check((bool)dbOne("SELECT 1 FROM courses WHERE id = 201"), 'delete_course tar inte bort B:s kurs');
$a->post('/admin/update_course_order.php', ['courses' => json_encode([['id' => 200, 'sort_order' => 99]])]);
check((int)dbOne("SELECT sort_order FROM courses WHERE id = 200")['sort_order'] !== 99, 'update_course_order ändrar inte B:s kurs');
$a->post('/admin/update_lesson_order.php', ['course_id' => 200, 'lessons' => json_encode([['id' => 2001, 'sort_order' => 99]])]);
check((int)dbOne("SELECT sort_order FROM lessons WHERE id = 2001")['sort_order'] !== 99, 'update_lesson_order ändrar inte B:s lektioner');
$a->post('/admin/certificates.php', ['action' => 'remove_image', 'course_id' => 200]);
check(dbOne("SELECT certificate_image_url u FROM courses WHERE id = 200")['u'] === 'upload/b1.png', 'certificates remove_image rör inte B:s diplombild');
$a->post('/admin/ajax/add_course_editor.php', ['course_id' => 200, 'email' => 'red-a@orga.test']);
check(!dbOne("SELECT 1 FROM course_editors WHERE course_id = 200 AND email = 'red-a@orga.test'"), 'add_course_editor lägger inte till redaktör i B:s kurs');
$a->post('/admin/ajax/toggle_public_course.php', ['course_id' => 200, 'is_public' => 1]);
check((int)dbOne("SELECT is_public FROM courses WHERE id = 200")['is_public'] === 0, 'toggle_public_course ändrar inte B:s kurs');
$a->post('/admin/ajax/enroll_user_sequential.php', ['course_id' => 200, 'email' => 'anv-a@orga.test']);
check(!dbOne("SELECT 1 FROM course_enrollments WHERE course_id = 200 AND user_id = 12"), 'enroll_user_sequential skriver inte in i B:s kurs', snippet($a->body, 120));
$a->post('/admin/ajax/trigger_sequential_start.php', ['course_id' => 200]);
check(!(($a->json()['success'] ?? false)), 'trigger_sequential_start nekas för B:s kurs', snippet($a->body, 120));
$a->post('/admin/ajax/send_manual_sequential_reminder.php', ['course_id' => 200, 'custom_message' => 'hej']);
check(!(($a->json()['success'] ?? false)), 'send_manual_sequential_reminder nekas för B:s kurs', snippet($a->body, 120));
$a->post('/admin/ajax/send_sequential_test_email.php', ['course_id' => 200, 'test_email' => 'admin-a@orga.test']);
check(!(($a->json()['success'] ?? false)), 'send_sequential_test_email nekas för B:s kurs', snippet($a->body, 120));
// Kopiering av andra organisationers AKTIVA kurser är avsiktlig (Sambruks delningsmodell,
// 41 kopior i produktionen 2026-10-13). Inaktiva kurser får bara kopieras inom egen org.
dbExec("UPDATE courses SET status = 'inactive' WHERE id = 202");
$a->post('/admin/copy_course.php', ['action' => 'copy_course', 'course_id' => 202]);
check(!dbOne("SELECT 1 FROM courses WHERE title LIKE 'B3 Kopia%' AND organization_domain = 'orga.test'"), 'copy_course kopierar inte B:s inaktiva kurs');
dbExec("UPDATE courses SET status = 'active' WHERE id = 202");

section('Ändringar i org B som redaktör A och läsbehörig A');
foreach (['red-a@orga.test', 'las-a@orga.test'] as $email) {
    $c = new Client($email);
    $c->fetchCsrf();
    $c->post('/admin/edit_lesson.php?id=2001', ['title' => 'KAPAD2', 'course_id' => 0, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
    check(lessonTitle(2001)['title'] !== 'KAPAD2', "$email: edit_lesson ändrar inte B:s lektion");
    $c->post('/admin/edit_course.php?id=200', ['title' => 'KAPAD KURS2', 'description' => 'x', 'status' => 'active']);
    check(dbOne("SELECT title FROM courses WHERE id = 200")['title'] === 'B1 Kurs i org B', "$email: edit_course ändrar inte B:s kurs");
}

section('Övriga hål från inventeringen');
$u = new Client('anv-a@orga.test');
$u->get('/admin/ajax/search_users.php?search=orga&lage=redaktor');
check(empty($u->json()['users']), 'vanlig användare kan inte söka användare', snippet($u->body, 100));
$u->get('/admin/ajax/search_users.php?search=anv-b@orgb.test&lage=redaktor');
check(empty($u->json()['users']), 'vanlig användare får inte konto i annan org', snippet($u->body, 100));
$a->get('/admin/ajax/search_users.php?search=anv-b@orgb.test&lage=redaktor');
$hit = $a->json()['users'][0] ?? [];
check(($hit['email'] ?? '') === 'anv-b@orgb.test' && ($hit['name'] ?? '') === '', 'admin A hittar extern adress för redaktörsrutan men inte namnet', snippet($a->body, 120));
$u->post('/admin/ajax/trigger_ai_processor.php', []);
check(!(($u->json()['success'] ?? false)), 'vanlig användare kan inte starta AI-jobbprocessorn', snippet($u->body, 100));
dbExec("INSERT INTO logs (email, message) VALUES ('anv-b@orgb.test', 'Hemlig händelse i org B')");
$a->get('/admin/logs.php');
check(strpos($a->body, 'Hemlig händelse i org B') === false, 'admin A ser inte org B:s aktivitetslogg');
dbExec("INSERT INTO cron_jobs (id, name, display_name, script_path, enabled) VALUES (1, 'test_noop', 'Test', 'tests/cron_noop.php', 1)");
$a->get('/admin/cron_jobs.php');
check(strpos($a->body, 'tests/cron_noop.php') === false && strpos($a->body, 'test_noop') === false, 'org-admin ser inte de globala cronjobben', $a->status . ' ' . snippet($a->body, 60));
$a->post('/admin/ajax/run_cron_job.php', ['job_id' => 1]);
check(!(($a->json()['success'] ?? false)) && strpos($a->body, 'NOOP-UTDATA') === false, 'org-admin kan inte köra globala cronjobb', snippet($a->body, 100));
$s0 = new Client('super@sambruk.se');
$s0->post('/admin/ajax/run_cron_job.php', ['job_id' => 1]);
check(strpos($s0->body, 'NOOP-UTDATA') !== false, 'superadmin kan köra cronjobb', snippet($s0->body, 100));

// Rollväxling utan CSRF-token (förfalskad begäran från en annan webbplats)
$a->request('POST', '/admin/users.php', ['action' => 'toggle_admin', 'user_id' => 12, 'is_admin' => 1]);
check((int)dbOne("SELECT is_admin FROM users WHERE id = 12")['is_admin'] === 0, 'users.php toggle_admin utan CSRF-token nekas');
$a->post('/admin/users.php', ['action' => 'toggle_editor', 'user_id' => 12, 'is_editor' => 1]);
check((int)dbOne("SELECT is_editor FROM users WHERE id = 12")['is_editor'] === 1, 'users.php toggle_editor med token fungerar för admin A');
dbExec("UPDATE users SET is_editor = 0 WHERE id = 12");
$a->post('/admin/users.php', ['action' => 'toggle_editor', 'user_id' => 22, 'is_editor' => 1]);
check((int)dbOne("SELECT is_editor FROM users WHERE id = 22")['is_editor'] === 0, 'users.php: admin A kan inte ändra roll för användare i org B');

section('Behörigheter som ska finnas kvar');
$a = new Client('admin-a@orga.test');
$a->get('/admin/edit_course.php?id=100');
check(strpos($a->body, 'A1 Kurs i org A') !== false, 'admin A öppnar egen kurs');
$a->get('/admin/edit_lesson.php?id=1001');
check(strpos($a->body, 'Lektion 1 i kurs 100') !== false, 'admin A öppnar egen lektion');
$a->post('/admin/edit_lesson.php?id=1002', ['title' => 'Ändrad av admin A', 'course_id' => 100, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
check(lessonTitle(1002)['title'] === 'Ändrad av admin A', 'admin A ändrar egen lektion', snippet($a->body, 100));
$a->post('/admin/edit_lesson.php', ['title' => 'Ny lektion A', 'course_id' => 100, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
check((bool)dbOne("SELECT 1 FROM lessons WHERE title = 'Ny lektion A' AND course_id = 100"), 'admin A skapar lektion i egen kurs');
$a->get('/admin/lessons.php?course_id=100');
check(strpos($a->body, 'Lektion 1 i kurs 100') !== false, 'admin A ser egna lektionslistan');
$a->get('/admin/export.php?id=100');
check($a->status === 200 && strlen($a->body) > 100, 'admin A exporterar egen kurs', $a->status . ' ' . snippet($a->body, 60));
$a->post('/admin/copy_course.php', ['action' => 'copy_course', 'course_id' => 201]);
$kopia = dbOne("SELECT id, organization_domain FROM courses WHERE title LIKE '%B2 Publik kurs i org B%' AND id <> 201");
check($kopia && $kopia['organization_domain'] === 'orga.test', 'admin A kopierar B:s publika kurs; kopian tillhör org A', json_encode($kopia));

$r = new Client('red-a@orga.test');
$r->get('/admin/edit_lesson.php?id=1001');
check(strpos($r->body, 'Lektion 1 i kurs 100') !== false, 'redaktör A öppnar lektion i sin kurs');
$r->post('/admin/edit_lesson.php?id=1003', ['title' => 'Ändrad av redaktör A', 'course_id' => 100, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
check(lessonTitle(1003)['title'] === 'Ändrad av redaktör A', 'redaktör A ändrar lektion i sin kurs');

$s = new Client('super@sambruk.se');
$s->get('/admin/edit_lesson.php?id=2001');
check(strpos($s->body, 'Lektion 1 i kurs 200') !== false, 'superadmin öppnar B:s lektion');
$s->post('/admin/edit_course.php?id=200', ['title' => 'B1 ändrad av Sambruk', 'description' => 'Testkurs', 'status' => 'active']);
check(dbOne("SELECT title FROM courses WHERE id = 200")['title'] === 'B1 ändrad av Sambruk', 'superadmin ändrar B:s kurs', snippet($s->body, 100));

// Extern kursredaktör: red-a läggs till som redaktör på B1 (cross-org-delegering)
dbExec("INSERT INTO course_editors (course_id, email, created_by) VALUES (200, 'red-a@orga.test', 'admin-b@orgb.test')");
$r = new Client('red-a@orga.test');
$r->get('/admin/edit_lesson.php?id=2001');
check(strpos($r->body, 'Lektion 1 i kurs 200') !== false, 'extern kursredaktör öppnar lektion i kursen hen är redaktör för');
$r->post('/admin/edit_lesson.php?id=2003', ['title' => 'Ändrad av extern redaktör', 'course_id' => 200, 'content' => 'x', 'status' => 'active', 'lesson_type' => 'lesson']);
check(lessonTitle(2003)['title'] === 'Ändrad av extern redaktör', 'extern kursredaktör ändrar lektion i kursen');
$r->get('/admin/edit_lesson.php?id=2011');
check(denied($r, '/Lektion 1 i kurs 201/'), 'extern kursredaktör nekas annan kurs i samma org');

done();
