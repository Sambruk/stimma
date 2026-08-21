<?php
/**
 * Stimma — Admin AJAX: swap ägarskap för en informationssida.
 *
 * Bytet cyklar mellan tre lägen:
 *   1) Tillhör föregående lektion (närmaste lektion med lägre sort_order)
 *   2) Tillhör nästa lektion (närmaste lektion med högre sort_order)
 *   3) Fristående (belongs_to_lesson_id = NULL)
 *
 * Om det bara finns en angränsande lektion (t.ex. en infosida först eller
 * sist i kursen) cyklas bara mellan den lektionen och fristående.
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

require_once '../include/ajax_auth_check.php';

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

$lessonId = (int)($_POST['lesson_id'] ?? 0);
if ($lessonId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Saknad lektions-ID.']);
    exit;
}

$page = queryOne(
    "SELECT id, course_id, lesson_type, belongs_to_lesson_id, sort_order
     FROM " . DB_DATABASE . ".lessons WHERE id = ?",
    [$lessonId]
);
if (!$page) {
    echo json_encode(['success' => false, 'message' => 'Sidan hittades inte.']);
    exit;
}
if (($page['lesson_type'] ?? 'lesson') !== 'info_page') {
    echo json_encode(['success' => false, 'message' => 'Endast informationssidor kan ha en ägare.']);
    exit;
}

// Behörighet via userCanModifyCourse (täcker super_admin, org-scopade admins
// och kurs-specifika redaktörer). Plus extra: kursens författare får alltid
// modifiera. Tidigare släpptes alla is_admin igenom oavsett kursens org —
// klassisk IDOR.
$course = queryOne(
    "SELECT id, author_id, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$page['course_id']]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte.']);
    exit;
}
$isAuthor = ((int)$course['author_id'] === (int)$_SESSION['user_id']);
if (!$isAuthor && !userCanModifyCourse($course)) {
    echo json_encode(['success' => false, 'message' => 'Ingen behörighet till kursen.']);
    exit;
}

// Hitta angränsande lektioner (med typ = 'lesson') i sort_order
$prev = queryOne(
    "SELECT id FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND lesson_type = 'lesson' AND sort_order < ?
     ORDER BY sort_order DESC LIMIT 1",
    [$page['course_id'], $page['sort_order']]
);
$next = queryOne(
    "SELECT id FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND lesson_type = 'lesson' AND sort_order > ?
     ORDER BY sort_order ASC LIMIT 1",
    [$page['course_id'], $page['sort_order']]
);
$prevId = $prev ? (int)$prev['id'] : null;
$nextId = $next ? (int)$next['id'] : null;
$current = $page['belongs_to_lesson_id'] !== null ? (int)$page['belongs_to_lesson_id'] : null;

// Bygg cykel-ordning: föregående → nästa → fristående → ...
// Utelämna saknade grannar så cykeln fortfarande ger mening för kantfall.
$cycle = [];
if ($prevId !== null) $cycle[] = $prevId;
if ($nextId !== null && $nextId !== $prevId) $cycle[] = $nextId;
$cycle[] = null; // fristående

// Hitta nuvarande position i cykeln och stega ett steg
$idx = array_search($current, $cycle, true);
if ($idx === false) {
    $newOwner = $cycle[0];
} else {
    $newOwner = $cycle[($idx + 1) % count($cycle)];
}

execute(
    "UPDATE " . DB_DATABASE . ".lessons SET belongs_to_lesson_id = ? WHERE id = ?",
    [$newOwner, $lessonId]
);

logActivity(
    $_SESSION['user_email'],
    "Bytte tillhörighet för informationssida (ID: {$lessonId})",
    ['new_belongs_to' => $newOwner]
);

echo json_encode([
    'success' => true,
    'new_owner' => $newOwner,
]);
