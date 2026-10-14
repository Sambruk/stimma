<?php
/**
 * Granskare per kurs (migration 052, 2026-10-14).
 *
 * Visar att:
 *  - kursens admin kan lägga till en granskare från en annan organisation, och att ett
 *    konto skapas om domänen är ansluten
 *  - granskaren läser kursen i förhandsgranskning, även när den är inaktiv, och att inga
 *    framsteg, inskrivningar eller quizsvar sparas
 *  - granskaren inte kan ändra kursen, nå adminytan eller lägga till fler granskare
 *  - granskningen bara gäller den kursen, och upphör när granskaren tas bort
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/052_course_reviewers.sql'));
dbExec("DELETE FROM course_reviewers");

$sparat = fn(string $email) => (int)dbOne(
    "SELECT (SELECT COUNT(*) FROM progress p JOIN users u ON u.id = p.user_id WHERE u.email = ? AND p.lesson_id IN (1001,1002,1003))
          + (SELECT COUNT(*) FROM course_enrollments e JOIN users u ON u.id = e.user_id WHERE u.email = ? AND e.course_id = 100)
          + (SELECT COUNT(*) FROM quiz_answers q JOIN users u ON u.id = q.user_id WHERE u.email = ? AND q.lesson_id IN (1001,1002,1003)) AS n",
    [$email, $email, $email])['n'];

// Kursen A1 är inte publicerad – det är så en baskurs granskas
dbExec("UPDATE courses SET status = 'inactive' WHERE id = 100");

section('Utan inbjudan når anv-b (org B) inte kursen');
$b = new Client('anv-b@orgb.test');
$b->get('/lesson.php?id=1001&preview=1');
check($b->status === 302 && strpos($b->body, 'Innehåll') === false, 'förhandsgranskning nekas', $b->status . ' ' . $b->redirectedTo());
$b->get('/course.php?id=100');
check(strpos($b->body, 'Du granskar kursen') === false, 'kurssidan visar ingen granskning');

section('Admin A lägger till granskare');
$a = new Client('admin-a@orga.test');
$a->get('/admin/edit_course.php?id=100');
check($a->status === 200 && strpos($a->body, 'Lägg till granskare') !== false, 'rutan Granskare finns i kursredigeringen');
$a->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'anv-b@orgb.test']);
$j = $a->json();
check(!empty($j['success']) && !empty($j['extern']), 'granskare från annan organisation tillagd', $a->body);
check((bool)dbOne("SELECT 1 FROM course_reviewers WHERE course_id = 100 AND email = 'anv-b@orgb.test' AND created_by = 'admin-a@orga.test'"), 'raden sparad med vem som lade till');
check(dbOne("SELECT is_editor, is_admin, is_viewer FROM users WHERE email = 'anv-b@orgb.test'") == ['is_editor' => 0, 'is_admin' => 0, 'is_viewer' => 0], 'kontot får ingen roll');
$a->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'anv-b@orgb.test']);
check(empty($a->json()['success']), 'samma granskare två gånger nekas');
$a->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'ny-granskare@orgb.test']);
check(!empty($a->json()['success']) && !empty($a->json()['nytt_konto'])
    && dbOne("SELECT 1 FROM users WHERE email = 'ny-granskare@orgb.test'"), 'konto skapas för ansluten domän', $a->body);
$a->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'x@inte-ansluten.test']);
check(empty($a->json()['success']) && !dbOne("SELECT 1 FROM users WHERE email = 'x@inte-ansluten.test'"), 'domän som inte är ansluten nekas');
$a->get('/admin/edit_course.php?id=100');
check(strpos($a->body, 'anv-b@orgb.test') !== false, 'granskaren listas i kursredigeringen');

section('Granskaren läser kursen');
$b->get('/index.php');
check(strpos($b->body, 'Kurser att granska') !== false && strpos($b->body, 'course.php?id=100') !== false, 'startsidan visar Kurser att granska');
$b->get('/course.php?id=100');
check(strpos($b->body, 'Du granskar kursen') !== false && strpos($b->body, 'lesson.php?id=1001&amp;preview=1') !== false, 'kurssidan leder till förhandsgranskning', snippet($b->body, 300));
check(strpos($b->body, 'mailto:admin-a@orga.test') !== false, 'synpunkter går till den som bjöd in');
$b->get('/lesson.php?id=1002');
check($b->status === 302 && strpos($b->redirectedTo(), 'preview=1') !== false, 'vanlig lektionslänk skickas till förhandsgranskning', $b->redirectedTo());
$b->get('/lesson.php?id=1002&preview=1');
check($b->status === 200 && strpos($b->body, 'Lektion 2 i kurs 100') !== false, 'lektionen visas i inaktiv kurs', $b->status . ' ' . $b->redirectedTo());
check(strpos($b->body, 'Förhandsgranskningsläge') !== false && strpos($b->body, 'admin/edit_lesson.php') === false, 'förhandsgranskning utan redigeringslänk');
$b->get('/lesson.php?id=1003&preview=1');
check($b->status === 200, 'lektionerna kan läsas i valfri ordning');

section('Inget sparas');
$b->post('/lesson.php?id=1001&preview=1', ['answer_question_id' => 10011, 'q10011_answer' => 1], ['X-Requested-With: XMLHttpRequest']);
$b->post('/lesson.php?id=1001', ['answer_question_id' => 10011, 'q10011_answer' => 1], ['X-Requested-With: XMLHttpRequest']);
$b->post('/lesson.php?id=1002&preview=1', ['mark_info_page_complete' => 1], ['X-Requested-With: XMLHttpRequest']);
check($sparat('anv-b@orgb.test') === 0, 'inga framsteg, inskrivningar eller svar sparade');

section('Granskaren ändrar ingenting');
$b->get('/admin/edit_course.php?id=100');
check($b->status === 302 && strpos($b->body, 'Lektion 1') === false, 'adminytan nekas', $b->status . ' ' . $b->redirectedTo());
$b->get('/admin/edit_lesson.php?id=1001');
check($b->status === 302, 'lektionsredigering nekas');
$b->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'red-b@orgb.test']);
check(empty($b->json()['success']) && !dbOne("SELECT 1 FROM course_reviewers WHERE email = 'red-b@orgb.test'"), 'kan inte bjuda in fler');
$b->post('/admin/ajax/remove_course_reviewer.php', ['course_id' => 100, 'email' => 'ny-granskare@orgb.test']);
check((bool)dbOne("SELECT 1 FROM course_reviewers WHERE email = 'ny-granskare@orgb.test'"), 'kan inte ta bort andra granskare');
$b->get('/lesson.php?id=2001&preview=1');
check($b->status === 302, 'förhandsgranskning av andra kurser nekas (B1 i egen org)');

section('Extern redaktör hanterar inte granskare');
dbExec("INSERT INTO course_editors (course_id, email, created_by) VALUES (100, 'red-b@orgb.test', 'admin-a@orga.test')");
$rb = new Client('red-b@orgb.test');
$rb->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'las-b@orgb.test']);
check(empty($rb->json()['success']) && !dbOne("SELECT 1 FROM course_reviewers WHERE email = 'las-b@orgb.test'"), 'extern redaktör nekas', $rb->body);
$rb->get('/admin/edit_course.php?id=100');
check(strpos($rb->body, 'Lägg till granskare') === false, 'rutan visas inte för extern redaktör');
$b2 = new Client('admin-b@orgb.test');
$b2->post('/admin/ajax/add_course_reviewer.php', ['course_id' => 100, 'email' => 'las-b@orgb.test']);
check(empty($b2->json()['success']), 'admin i annan organisation nekas');

section('Borttagen granskare når inte kursen');
$a->post('/admin/ajax/remove_course_reviewer.php', ['course_id' => 100, 'email' => 'anv-b@orgb.test']);
check(!empty($a->json()['success']) && !dbOne("SELECT 1 FROM course_reviewers WHERE email = 'anv-b@orgb.test'"), 'granskaren borttagen');
$b->get('/lesson.php?id=1001&preview=1');
check($b->status === 302 && strpos($b->body, 'Lektion 1 i kurs 100') === false, 'förhandsgranskning nekas igen');
$b->get('/index.php');
check(strpos($b->body, 'Kurser att granska') === false, 'startsidan visar inte längre kursen');

section('Kursen raderas → granskarna försvinner');
dbExec("DELETE FROM courses WHERE id = 100");
check(!dbOne("SELECT 1 FROM course_reviewers WHERE course_id = 100"), 'ON DELETE CASCADE');

done();
