<?php
/**
 * Stimma — AJAX-endpoint: AI-generera enstaka lektion till befintlig kurs
 *
 * Synkron generering — anropet blockerar i ~30-60 s medan OpenAI svarar.
 * UI:t visar spinner och laddar om vid framgång.
 */

require_once __DIR__ . '/../../include/config.php';
require_once __DIR__ . '/../../include/database.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/auth.php';

require_once __DIR__ . '/../include/ajax_auth_check.php';
require_once __DIR__ . '/../../include/ai_lesson_helper.php';

// CSRF
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ogiltig säkerhetstoken.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Använd POST.']);
    exit;
}

$courseId      = (int)($_POST['course_id'] ?? 0);
$lessonIdea    = trim($_POST['lesson_idea'] ?? '');
$lessonType    = ($_POST['lesson_type'] ?? 'lesson') === 'info_page' ? 'info_page' : 'lesson';
$includeQuiz   = !empty($_POST['include_quiz']);
$generateImage = !empty($_POST['generate_image']);
$textLength    = $_POST['text_length'] ?? 'medium';
$tone          = $_POST['tone']        ?? 'pedagogical';
$belongsToRaw  = trim((string)($_POST['belongs_to_lesson_id'] ?? ''));
$belongsTo     = ($belongsToRaw !== '' && (int)$belongsToRaw > 0) ? (int)$belongsToRaw : null;

if ($courseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Saknad kurs-ID.']);
    exit;
}

// Hämta kursen
$course = queryOne(
    "SELECT id, title, description, organization_domain, author_id FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Kursen hittades inte.']);
    exit;
}

// Behörighet: admin/editor i kursens org, ELLER kursens författare, ELLER
// uttryckligen tilldelad redaktör i course_editors. Speglar exakt
// edit_lesson.php-mönstret.
$orgScope = getOrgScopeDomains($_SESSION['user_email']);
if (!in_array($course['organization_domain'], $orgScope, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Kursen tillhör inte din organisation.']);
    exit;
}
if (!$isAdmin) {
    $isAuthor = ((int)$course['author_id'] === (int)$_SESSION['user_id']);
    $isCourseEditor = queryOne(
        "SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ? LIMIT 1",
        [$courseId, $_SESSION['user_email']]
    );
    if (!$isAuthor && !$isCourseEditor) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Du är varken författare eller redaktör för kursen.']);
        exit;
    }
}

// Om belongs_to_lesson_id angetts: säkerställ att den lektionen tillhör
// samma kurs och är av typen 'lesson'.
if ($belongsTo !== null) {
    $parent = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".lessons
         WHERE id = ? AND course_id = ? AND lesson_type = 'lesson' LIMIT 1",
        [$belongsTo, $courseId]
    );
    if (!$parent) {
        echo json_encode(['success' => false, 'error' => 'Vald parent-lektion finns inte i kursen.']);
        exit;
    }
}

// Soft-cap för PHP-execution så vi inte timeoutar mid-anrop. Apache + cURL
// har 300 s default; helpern sätter cURL-timeout 180 s. Bildgenerering kan
// lägga till ytterligare ~120 s, så vi tar 360 s om bilden är vald.
@set_time_limit($generateImage ? 360 : 240);

try {
    $lessonData = aiLessonGenerateContent([
        'course'       => $course,
        'lesson_idea'  => $lessonIdea,
        'lesson_type'  => $lessonType,
        'include_quiz' => $includeQuiz,
        'text_length'  => $textLength,
        'tone'         => $tone,
    ]);

    $lessonId = aiLessonInsert(
        $courseId,
        $lessonData,
        (int)$_SESSION['user_id'],
        [
            'lesson_type'          => $lessonType,
            'belongs_to_lesson_id' => $belongsTo,
        ]
    );

    // Generera AI-bild om användaren bockat i det. Bildfel ska INTE stoppa
    // hela lektionsskapandet — vi rapporterar bara warning till klienten.
    $imageWarning = null;
    if ($generateImage) {
        $imgResult = aiGenerateLessonImage(
            (string)($lessonData['title'] ?? ''),
            (string)$course['title'],
            $courseId
        );
        if (!empty($imgResult['success']) && !empty($imgResult['image_url'])) {
            execute(
                "UPDATE " . DB_DATABASE . ".lessons SET image_url = ? WHERE id = ?",
                [$imgResult['image_url'], $lessonId]
            );
        } else {
            $imageWarning = $imgResult['error'] ?? 'Okänt fel vid bildgenerering.';
        }
    }
} catch (Throwable $e) {
    error_log('[ai_generate_lesson] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}

logActivity(
    $_SESSION['user_email'],
    "Genererade AI-lektion '" . ($lessonData['title'] ?? '?') . "' (id={$lessonId}) i kursen '{$course['title']}' (id={$courseId})",
    [
        'action'      => 'ai_generate_lesson',
        'course_id'   => $courseId,
        'lesson_id'   => $lessonId,
        'lesson_type' => $lessonType,
        'include_quiz'=> $includeQuiz,
        'text_length' => $textLength,
        'tone'        => $tone,
        'idea_chars'  => mb_strlen($lessonIdea),
    ]
);

echo json_encode([
    'success'        => true,
    'lesson_id'      => $lessonId,
    'title'          => $lessonData['title'],
    'edit_url'       => 'edit_lesson.php?id=' . $lessonId,
    'image_warning'  => $imageWarning,
]);
