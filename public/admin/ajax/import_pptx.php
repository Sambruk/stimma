<?php
/**
 * Stimma — AJAX: PowerPoint → ai_course_jobs
 *
 * Tar emot en .pptx-uppladdning, extraherar slide-text och bilder,
 * formaterar resultatet som en strukturerad kursbeskrivning och
 * skapar ett ai_course_jobs-jobb som cron processar som vanligt.
 *
 * Återanvänder samma async-pipeline som AI-kursgenerering (se
 * admin/cron/process_ai_jobs.php).
 */

require_once __DIR__ . '/../../include/config.php';
require_once __DIR__ . '/../../include/database.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/auth.php';

require_once __DIR__ . '/../include/ajax_auth_check.php';
require_once __DIR__ . '/../../include/pptx_extractor.php';

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

if (!isset($_FILES['pptx_file']) || $_FILES['pptx_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Ingen fil mottagen eller uppladdningsfel.']);
    exit;
}

$file = $_FILES['pptx_file'];
$origName = (string)($file['name'] ?? 'presentation.pptx');
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($ext !== 'pptx') {
    echo json_encode(['success' => false, 'error' => 'Endast .pptx-filer stöds.']);
    exit;
}
$maxBytes = 50 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'Filen är för stor (max 50 MB).']);
    exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$validMimes = [
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip', // PPTX är en zip; vissa servrar rapporterar generic
    'application/octet-stream',
];
if (!in_array($mime, $validMimes, true)) {
    echo json_encode(['success' => false, 'error' => "Filtypen ser inte ut som .pptx ({$mime}).", 'mime' => $mime]);
    exit;
}

// Behörighet: hämta admin-status och organisation
$currentUser = queryOne(
    "SELECT id, email, is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE email = ?",
    [$_SESSION['user_email']]
);
if (!$currentUser || (empty($currentUser['is_admin']) && empty($currentUser['is_editor']) && ($currentUser['role'] ?? '') !== 'super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Du har inte behörighet att importera kurser.']);
    exit;
}

$userDomain = substr(strrchr($currentUser['email'], '@'), 1);

// Kopiera tmp-filen till en kontrollerad temp-plats så ZipArchive kan
// läsa den ordentligt (PHP rensar $_FILES tmp_name vid request-slut).
$tmpDir = __DIR__ . '/../../upload/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
$tmpPath = $tmpDir . '/' . bin2hex(random_bytes(12)) . '.pptx';
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    echo json_encode(['success' => false, 'error' => 'Kunde inte spara uppladdad fil.']);
    exit;
}

$imageOutDir = __DIR__ . '/../../upload';

try {
    $slides = pptxExtractSlides($tmpPath, $imageOutDir);
} catch (Throwable $e) {
    @unlink($tmpPath);
    echo json_encode(['success' => false, 'error' => 'Kunde inte läsa PPTX: ' . $e->getMessage()]);
    exit;
}

if (count($slides) < 2) {
    @unlink($tmpPath);
    echo json_encode(['success' => false, 'error' => 'PPTX måste innehålla minst 2 slides.']);
    exit;
}

$cap = 25;
$wasCapped = false;
if (count($slides) > $cap) {
    $slides = array_slice($slides, 0, $cap);
    $wasCapped = true;
}
$imageCount = 0;
foreach ($slides as $s) if (!empty($s['image_filename'])) $imageCount++;

$courseName = pathinfo($origName, PATHINFO_FILENAME);
$courseName = preg_replace('/[\s_-]+/', ' ', trim($courseName));
$courseName = mb_substr($courseName, 0, 200);
if ($courseName === '') $courseName = 'Importerad presentation';

$courseDescription = pptxBuildCourseDescription($slides);

// Inställningar från form (samma fält som AI-kursgenereringen erbjuder)
$tone          = $_POST['tone']          ?? 'pedagogical';
$languageStyle = $_POST['language_style'] ?? 'formal';
$textLength    = $_POST['text_length']   ?? 'medium';
$includeQuiz   = !empty($_POST['include_quiz']) ? 1 : 0;
$includeAiTutor = !empty($_POST['include_ai_tutor']) ? 1 : 0;
$includeVideo   = !empty($_POST['include_video_links']) ? 1 : 0;
// Om PPTX redan har bilder — använd dem (image_option = none så cron inte
// AI-genererar och skriver över dem).
$imageOption = $imageCount > 0 ? 'none' : ($_POST['image_option'] ?? 'none');
$generateImages = $imageOption === 'ai' ? 1 : 0;
$difficulty  = $_POST['difficulty_level'] ?? 'beginner';

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
        count($slides),
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
@unlink($tmpPath);

logActivity(
    $_SESSION['user_email'],
    "Importerade PowerPoint '{$origName}' till AI-jobb (id={$jobId})",
    [
        'action'      => 'import_pptx',
        'job_id'      => $jobId,
        'slide_count' => count($slides),
        'image_count' => $imageCount,
        'capped'      => $wasCapped,
    ]
);

echo json_encode([
    'success'     => true,
    'job_id'      => $jobId,
    'slide_count' => count($slides),
    'image_count' => $imageCount,
    'capped'      => $wasCapped,
]);
