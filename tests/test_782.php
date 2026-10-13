<?php
/**
 * Ärende 782: framsteg verkar inte registreras / kursen markeras inte som klar.
 * Kurs 100 (org A): 1001 en fråga (enkelval, rätt = 1), 1002 utan quiz,
 * 1003 två frågor (flerval rätt = [0,2], sant/falskt rätt = sant).
 */
require __DIR__ . '/lib.php';
reseed();

function newUser(string $email): Client {
    dbExec("INSERT INTO users (email, name, verified_at) VALUES (?, ?, NOW())", [$email, $email]);
    return new Client($email);
}
function uid(string $email): int { return (int)dbOne("SELECT id FROM users WHERE email = ?", [$email])['id']; }
function done_(string $email, int $lessonId): bool {
    return (bool)dbOne("SELECT 1 FROM progress WHERE user_id = ? AND lesson_id = ? AND status = 'completed'", [uid($email), $lessonId]);
}
function answer(Client $c, int $lessonId, int $qid, $value) {
    $c->post("/lesson.php?id=$lessonId", ['answer_question_id' => $qid, "q{$qid}_answer" => $value], ['X-Requested-With: XMLHttpRequest']);
    return $c->json();
}
function courseDone(string $email, int $courseId): array {
    $u = uid($email);
    return [
        'enrollment' => dbOne("SELECT status FROM course_enrollments WHERE user_id = ? AND course_id = ?", [$u, $courseId])['status'] ?? null,
        'certificate' => (bool)dbOne("SELECT 1 FROM certificates WHERE user_id = ? AND course_id = ?", [$u, $courseId]),
    ];
}
/** Gå igenom hela kurs 100 med rätt svar. */
function completeCourse100(Client $c): void {
    $c->get('/lesson.php?id=1001'); answer($c, 1001, 10011, 1);
    $c->get('/lesson.php?id=1002');
    $c->get('/lesson.php?id=1003'); answer($c, 1003, 10031, [0, 2]); answer($c, 1003, 10032, 1);
}

section('Lektion med quiz, fel svar följt av rätt svar');
$c = newUser('p1@orga.test');
$c->get('/lesson.php?id=1001');
$r = answer($c, 1001, 10011, 0);
check(($r['correct'] ?? null) === false && !done_('p1@orga.test', 1001), 'fel svar: lektionen inte klar', json_encode($r));
$r = answer($c, 1001, 10011, 1);
check(($r['correct'] ?? null) === true && done_('p1@orga.test', 1001), 'rätt svar efter fel: lektionen klar', json_encode($r));

section('Lektion utan quiz');
$c->get('/lesson.php?id=1002');
check(done_('p1@orga.test', 1002), 'lektion utan quiz blir klar när den läses', $c->status . ' ' . $c->redirectedTo());

section('Flera frågor');
$c->get('/lesson.php?id=1003');
answer($c, 1003, 10031, [0]);
check(!done_('p1@orga.test', 1003), 'en fråga fel: inte klar');
answer($c, 1003, 10031, [0, 2]);
check(!done_('p1@orga.test', 1003), 'en av två frågor rätt: inte klar');
$r = answer($c, 1003, 10032, 1);
check(done_('p1@orga.test', 1003), 'båda frågorna rätt: klar', json_encode($r));

section('Kursen klar och diplom');
$st = courseDone('p1@orga.test', 100);
check($st['certificate'], 'diplom skapat', json_encode($st));
check($st['enrollment'] === 'completed', 'inskrivningen markerad som klar', json_encode($st));

section('Svar i en ny session räknas (quizläget sparas i databasen, inte i sessionen)');
$c = newUser('p2@orga.test');
completeCourse100($c);
$c2 = newUser('p2b@orga.test');
$c2->get('/lesson.php?id=1001'); answer($c2, 1001, 10011, 1);
$c2->get('/lesson.php?id=1002');
$c2->get('/lesson.php?id=1003'); answer($c2, 1003, 10031, [0, 2]);
$c2b = new Client('p2b@orga.test');               // ny inloggning = ny session
$c2b->get('/lesson.php?id=1003'); answer($c2b, 1003, 10032, 1);
check(done_('p2b@orga.test', 1003), 'andra frågan i ny session: lektionen klar');

section('Inaktiv lektion tidigare i kursen blockerar inte');
dbExec("UPDATE lessons SET status = 'inactive' WHERE id = 1002");
$c = newUser('p3@orga.test');
$c->get('/lesson.php?id=1001'); answer($c, 1001, 10011, 1);
$c->get('/lesson.php?id=1003'); answer($c, 1003, 10031, [0, 2]); answer($c, 1003, 10032, 1);
check(done_('p3@orga.test', 1003), 'lektion efter inaktiv lektion sparas');
$st = courseDone('p3@orga.test', 100);
check($st['certificate'] && $st['enrollment'] === 'completed', 'kursen klar trots inaktiv lektion', json_encode($st));
dbExec("UPDATE lessons SET status = 'active' WHERE id = 1002");

section('Infosida som inte klickats blockerar inte sparandet');
dbExec("INSERT INTO lessons (id, course_id, title, content, status, sort_order, lesson_type) VALUES (1000, 100, 'Infosida först', '<p>Info</p>', 'active', 0, 'info_page')");
$c = newUser('p4@orga.test');
$c->get('/lesson.php?id=1001'); $r = answer($c, 1001, 10011, 1);
check(done_('p4@orga.test', 1001), 'lektion sparas fast infosidan före inte klickats', json_encode($r));
$c->get('/lesson.php?id=1002');
$c->get('/lesson.php?id=1003'); answer($c, 1003, 10031, [0, 2]); answer($c, 1003, 10032, 1);
$st = courseDone('p4@orga.test', 100);
check($st['certificate'], 'kursen klar utan att infosidan klickats', json_encode($st));
dbExec("DELETE FROM lessons WHERE id = 1000");

section('Infosida sist i kursen');
dbExec("INSERT INTO lessons (id, course_id, title, content, status, sort_order, lesson_type) VALUES (1009, 100, 'Avslutning', '<p>Tack</p>', 'active', 9, 'info_page')");
$c = newUser('p5@orga.test');
completeCourse100($c);
check(courseDone('p5@orga.test', 100)['certificate'], 'diplom när sista lektionen är klar, fast en infosida ligger sist');
dbExec("DELETE FROM lessons WHERE id = 1009");

section('Lektion som läggs till efter att användaren börjat');
$c = newUser('p6@orga.test');
$c->get('/lesson.php?id=1001'); answer($c, 1001, 10011, 1);
dbExec("INSERT INTO lessons (id, course_id, title, content, status, sort_order, lesson_type) VALUES (1004, 100, 'Ny lektion', '<p>Ny</p>', 'active', 4, 'lesson')");
dbExec("INSERT INTO quiz_questions (id, lesson_id, sort_order, question_type, question_text, quiz_data) VALUES (10041, 1004, 1, 'true_false', 'Ny fråga', '{\"correct\":true}')");
$c->get('/lesson.php?id=1002');
$c->get('/lesson.php?id=1003'); answer($c, 1003, 10031, [0, 2]); answer($c, 1003, 10032, 1);
check(done_('p6@orga.test', 1003) && !courseDone('p6@orga.test', 100)['certificate'], 'gamla lektionerna klara, kursen inte klar förrän den nya är gjord');
$c->get('/lesson.php?id=1004'); answer($c, 1004, 10041, 1);
check(done_('p6@orga.test', 1004) && courseDone('p6@orga.test', 100)['certificate'], 'ny lektion klar → kursen klar');

section('Lektion som flyttas efter att användaren börjat');
$c = newUser('p7@orga.test');
$c->get('/lesson.php?id=1001'); answer($c, 1001, 10011, 1);
$c->get('/lesson.php?id=1002');
dbExec("UPDATE lessons SET sort_order = 0 WHERE id = 1004");   // nya lektionen flyttas först
$c->get('/lesson.php?id=1004'); answer($c, 1004, 10041, 1);
check(done_('p7@orga.test', 1004), 'flyttad lektion sparas');
$c->get('/lesson.php?id=1003'); answer($c, 1003, 10031, [0, 2]); answer($c, 1003, 10032, 1);
check(done_('p7@orga.test', 1003) && courseDone('p7@orga.test', 100)['certificate'], 'kursen klar efter flytt');
dbExec("DELETE FROM quiz_questions WHERE lesson_id = 1004"); dbExec("DELETE FROM lessons WHERE id = 1004");

section('Stegvis lektion');
dbExec("UPDATE courses SET sequential_mode = 1, sequential_interval_days = 7 WHERE id = 100");
$c = newUser('p8@orga.test');
$c->get('/lesson.php?id=1001');
$sched = dbOne("SELECT available_at FROM sequential_lesson_schedule WHERE user_id = ? AND lesson_id = 1001", [uid('p8@orga.test')]);
check((bool)$sched, 'stegvis kurs: schema skapat när lektionen öppnas', $c->status . ' ' . $c->redirectedTo());
answer($c, 1001, 10011, 1);
check(done_('p8@orga.test', 1001), 'stegvis kurs: lektion 1 sparas');
$s = dbOne("SELECT completed_at FROM sequential_lesson_schedule WHERE user_id = ? AND lesson_id = 1001", [uid('p8@orga.test')]);
check(!empty($s['completed_at']), 'stegvis kurs: schemat markerar lektionen som klar');
dbExec("UPDATE courses SET sequential_mode = 0 WHERE id = 100");

section('Samma regel i kursvyn och statistiken');
$a = new Client('admin-a@orga.test');
$a->get('/admin/course_stats.php');
check((bool)preg_match('/A1 Kurs i org A.*?<\/tr>/s', $a->body, $m), 'kursstatistiken listar kursen');

section('Radering fungerar före och efter migrering 050 (user_progress avvecklad)');
$s = new Client('super@sambruk.se');
$s->post('/admin/delete_lesson.php', ['id' => 2003]);
check(!dbOne("SELECT 1 FROM lessons WHERE id = 2003"), 'superadmin raderar lektion', $s->status . ' ' . snippet($s->body, 80));
$s->post('/admin/delete_course.php', ['id' => 202]);
check(!dbOne("SELECT 1 FROM courses WHERE id = 202"), 'superadmin raderar kurs', $s->status . ' ' . snippet($s->body, 80));

section('API:t läser samma tabell som resten av appen');
$api = file_get_contents('/var/www/html/public/api/course_status.php');
check(!preg_match('/\\.user_progress\\b/', $api), 'api/course_status.php läser inte user_progress', '');

done();
