<?php
/**
 * Stimma — AJAX: SCORM-paket (.zip) → ai_course_jobs
 *
 * Tar emot ett SCORM-paket, läser imsmanifest.xml, extraherar text och media
 * ur varje SCO och skapar ett ai_course_jobs-jobb som cron processar precis
 * som PPTX-importen och AI-kursgenereringen.
 *
 * Paketets egen HTML/JS körs eller serveras aldrig — bara text och bilder
 * plockas ut. Se include/scorm_extractor.php.
 */

require_once __DIR__ . '/../../include/config.php';
require_once __DIR__ . '/../../include/database.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/auth.php';

require_once __DIR__ . '/../include/ajax_auth_check.php';
require_once __DIR__ . '/../../include/scorm_extractor.php';
require_once __DIR__ . '/../../include/scorm_course_builder.php';

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

// Behörighet kontrolleras före filhanteringen — annars svarar endpointen på
// uppladdningsfel för användare som inte får importera alls.
$currentUser = queryOne(
    "SELECT id, email, is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE email = ?",
    [$_SESSION['user_email']]
);
// Tills vidare superadmin-only: funktionen behöver utvärderas mot fler paket
// innan den släpps till redaktörer och domänadmins. Knappen döljs på samma
// villkor i admin/courses.php — den här kontrollen är den som faktiskt gäller.
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'SCORM-import är begränsad till superadministratörer.']);
    exit;
}

$userDomain = substr(strrchr($currentUser['email'], '@'), 1);

if (!isset($_FILES['scorm_file']) || $_FILES['scorm_file']['error'] !== UPLOAD_ERR_OK) {
    $phpErr = $_FILES['scorm_file']['error'] ?? -1;
    $msg = ($phpErr === UPLOAD_ERR_INI_SIZE || $phpErr === UPLOAD_ERR_FORM_SIZE)
        ? 'Filen är större än vad servern tillåter (max 100 MB).'
        : 'Ingen fil mottagen eller uppladdningsfel.';
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$file = $_FILES['scorm_file'];
$origName = (string)($file['name'] ?? 'scorm.zip');
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($ext !== 'zip') {
    echo json_encode(['success' => false, 'error' => 'Endast .zip-filer stöds (SCORM-paket är alltid zip).']);
    exit;
}

$maxBytes = 100 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'Filen är för stor (max 100 MB).']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$validMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'multipart/x-zip'];
if (!in_array($mime, $validMimes, true)) {
    echo json_encode(['success' => false, 'error' => "Filtypen ser inte ut som en zip ({$mime}).", 'mime' => $mime]);
    exit;
}


$uploadDir = __DIR__ . '/../../upload';

// Diskutrymme: SCORM-paket är stora och servern är trång. Kräv marginal.
$freeBytes = @disk_free_space($uploadDir);
if ($freeBytes !== false && $freeBytes < 2 * 1024 * 1024 * 1024) {
    echo json_encode([
        'success' => false,
        'error'   => 'För lite diskutrymme kvar på servern (' . round($freeBytes / 1073741824, 1) . ' GB). Kontakta administratör.',
    ]);
    exit;
}

// Kopiera tmp-filen till kontrollerad plats — ZipArchive behöver en stabil path.
$tmpDir = $uploadDir . '/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
$tmpPath = $tmpDir . '/' . bin2hex(random_bytes(12)) . '.zip';
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    echo json_encode(['success' => false, 'error' => 'Kunde inte spara uppladdad fil.']);
    exit;
}

// Läge: 'copy' behåller originalets text, bilder och filmer (standard).
// 'ai' skickar innehållet till AI-pipelinen som skriver om det till lektioner.
$importMode = ($_POST['import_mode'] ?? 'copy') === 'ai' ? 'ai' : 'copy';

if ($importMode === 'copy') {
    try {
        $built = scormBuildFidelityLessons($tmpPath, $uploadDir);
    } catch (Throwable $e) {
        @unlink($tmpPath);
        echo json_encode(['success' => false, 'error' => 'Kunde inte läsa SCORM-paketet: ' . $e->getMessage()]);
        exit;
    }
    @unlink($tmpPath);

    if (empty($built['lessons'])) {
        echo json_encode([
            'success' => false,
            'error'   => 'Hittade inget innehåll att kopiera i paketet'
                . ($built['tool'] !== 'okänt verktyg' ? ' (verktyg: ' . $built['tool'] . ')' : '') . '.',
        ]);
        exit;
    }

    $courseTitle = trim((string)$built['title']);
    if ($courseTitle === '') {
        $courseTitle = preg_replace('/[\s_-]+/', ' ', trim(pathinfo($origName, PATHINFO_FILENAME)));
    }
    $courseTitle = mb_substr($courseTitle !== '' ? $courseTitle : 'Importerat SCORM-paket', 0, 200);

    $description = 'Importerad från SCORM-paketet "' . $origName . '" den ' . date('Y-m-d') . '. '
        . 'Innehållet är kopierat från originalet' . ($built['tool'] !== 'okänt verktyg' ? ' (' . $built['tool'] . ')' : '') . '.';

    try {
        $courseId = scormCreateCourse($courseTitle, $description, $built['lessons'], (int)$currentUser['id'], $userDomain);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte spara kursen: ' . $e->getMessage()]);
        exit;
    }

    logActivity(
        $_SESSION['user_email'],
        "Kopierade SCORM-paket '{$origName}' till kurs {$courseId}",
        [
            'action'       => 'import_scorm_copy',
            'course_id'    => $courseId,
            'tool'         => $built['tool'],
            'schema'       => $built['schema'],
            'lesson_count' => count($built['lessons']),
            'image_count'  => $built['stats']['image_count'],
            'video_count'  => $built['stats']['video_count'],
            'chars'        => $built['stats']['char_count'],
        ]
    );

    echo json_encode([
        'success'      => true,
        'mode'         => 'copy',
        'course_id'    => $courseId,
        'course_name'  => $courseTitle,
        'tool'         => $built['tool'],
        'schema'       => $built['schema'],
        'lesson_count' => count($built['lessons']),
        'image_count'  => $built['stats']['image_count'],
        'video_count'  => $built['stats']['video_count'],
        'chars'        => $built['stats']['char_count'],
        'edit_url'     => 'edit_course.php?id=' . $courseId,
    ]);
    exit;
}

$maxItems = 25;

try {
    $pkg = scormExtractPackage($tmpPath, $uploadDir, ['max_items' => $maxItems]);
} catch (Throwable $e) {
    @unlink($tmpPath);
    echo json_encode(['success' => false, 'error' => 'Kunde inte läsa SCORM-paketet: ' . $e->getMessage()]);
    exit;
}
@unlink($tmpPath);

$items = $pkg['items'];

/** Ta bort redan uttagen media när importen avbryts. */
$cleanupMedia = function (array $items) use ($uploadDir) {
    foreach ($items as $it) {
        if (!empty($it['image_filename'])) @unlink($uploadDir . '/' . $it['image_filename']);
        if (!empty($it['video_filename'])) @unlink($uploadDir . '/videos/' . $it['video_filename']);
    }
};

$totalChars = 0;
foreach ($items as $it) $totalChars += mb_strlen(trim((string)$it['text']));

if ($totalChars < 400) {
    $cleanupMedia($items);
    echo json_encode([
        'success' => false,
        'error'   => 'Hittade nästan ingen text i paketet (' . $totalChars . ' tecken). '
            . 'Innehållet ligger troligen som bilder eller i ett format vi inte kan läsa'
            . ($pkg['tool'] !== 'okänt verktyg' ? ' (verktyg: ' . $pkg['tool'] . ')' : '') . '. '
            . 'Prova att exportera kursen som PowerPoint eller Word istället.',
    ]);
    exit;
}

// Hur många lektioner? Är paketet uppdelat i flera SCO:er blir det 1:1.
// Rise 360 m.fl. packar hela kursen som EN SCO — då får AI:n dela upp texten.
$scoCount = count($items);
if ($scoCount >= 3) {
    $lessonCount = $scoCount;
    $splitMode = false;
} else {
    $lessonCount = max(3, min(12, (int)ceil($totalChars / 2000)));
    $splitMode = true;
}

$courseName = trim((string)$pkg['title']);
if ($courseName === '') {
    $courseName = preg_replace('/[\s_-]+/', ' ', trim(pathinfo($origName, PATHINFO_FILENAME)));
}
$courseName = mb_substr($courseName, 0, 200);
if ($courseName === '') $courseName = 'Importerat SCORM-paket';

$courseDescription = scormBuildCourseDescription($items);

// Inställningar från formuläret (samma fält som PPTX-/AI-generering)
$tone           = $_POST['tone']            ?? 'pedagogical';
$languageStyle  = $_POST['language_style']  ?? 'formal';
$textLength     = $_POST['text_length']     ?? 'medium';
$includeQuiz    = !empty($_POST['include_quiz']) ? 1 : 0;
$includeAiTutor = !empty($_POST['include_ai_tutor']) ? 1 : 0;
$includeVideo   = !empty($_POST['include_video_links']) ? 1 : 0;
$difficulty     = $_POST['difficulty_level'] ?? 'beginner';

// Har paketet egna bilder används de — då ska cron inte AI-generera över dem.
$imageCount = (int)$pkg['stats']['image_count'];
$imageOption = $imageCount > 0 ? 'none' : ($_POST['image_option'] ?? 'none');
$generateImages = $imageOption === 'ai' ? 1 : 0;

execute(
    "INSERT INTO " . DB_DATABASE . ".ai_course_jobs
        (user_id, organization_domain, status, course_name, course_description,
         lesson_count, include_quiz, include_video_links, image_option,
         difficulty_level, include_ai_tutor, tone, language_style, text_length,
         generate_images, created_at)
     VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
    [
        (int)$currentUser['id'],
        $userDomain,
        $courseName,
        $courseDescription,
        $lessonCount,
        $includeQuiz,
        $includeVideo,
        $imageOption,
        $difficulty,
        $includeAiTutor,
        $tone,
        $languageStyle,
        $textLength,
        $generateImages,
    ]
);

$jobId = (int)(queryOne("SELECT LAST_INSERT_ID() AS id")['id'] ?? 0);

logActivity(
    $_SESSION['user_email'],
    "Importerade SCORM-paket '{$origName}' till AI-jobb (id={$jobId})",
    [
        'action'      => 'import_scorm',
        'job_id'      => $jobId,
        'schema'      => $pkg['schema'],
        'tool'        => $pkg['tool'],
        'sco_count'   => $scoCount,
        'lesson_count'=> $lessonCount,
        'split_mode'  => $splitMode,
        'image_count' => $imageCount,
        'video_count' => (int)$pkg['stats']['video_count'],
        'chars'       => $totalChars,
        'capped'      => (int)$pkg['stats']['manifest_items'] > $maxItems,
    ]
);

echo json_encode([
    'success'      => true,
    'mode'         => 'ai',
    'job_id'       => $jobId,
    'course_name'  => $courseName,
    'schema'       => $pkg['schema'],
    'tool'         => $pkg['tool'],
    'sco_count'    => $scoCount,
    'lesson_count' => $lessonCount,
    'split_mode'   => $splitMode,
    'image_count'  => $imageCount,
    'video_count'  => (int)$pkg['stats']['video_count'],
    'empty_items'  => (int)$pkg['stats']['empty_items'],
    'chars'        => $totalChars,
    'capped'       => (int)$pkg['stats']['manifest_items'] > $maxItems,
]);
