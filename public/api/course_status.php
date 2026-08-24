<?php
/**
 * Stimma - REST API: Kursstatus
 *
 * GET /api/course_status.php?email=...&course_id=...
 * Authorization: Bearer stm_...
 *
 * Returnerar huruvida en användare har slutfört en kurs.
 * Om certifikat finns → status 1 + completed_at.
 * Annars → status 0 + progress (t.ex. "3/5").
 */

// CORS-headers MÅSTE sättas FÖRE require (config.php gör HTTPS-redirect)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Hantera OPTIONS preflight (innan config.php redirect)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../include/api_helpers.php';
require_once __DIR__ . '/../include/functions.php';

// Tillåt enbart GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(405, ['success' => false, 'error' => 'Metoden stöds inte. Använd GET.']);
}

// 1. Autentisera API-nyckel
$apiKey = authenticateApiKey();
$domain = $apiKey['domain'];
$apiKeyId = $apiKey['id'];

// 2. Kontrollera rate limit
checkRateLimit($apiKeyId);

// 3. Läs och validera query-parametrar
$email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : '';
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if (empty($email)) {
    apiResponse(400, ['success' => false, 'error' => 'Parametern "email" saknas.']);
}

if ($courseId <= 0) {
    apiResponse(400, ['success' => false, 'error' => 'Parametern "course_id" saknas eller ogiltig.']);
}

// 4. Validera e-postformat
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiResponse(400, ['success' => false, 'error' => 'Ogiltig e-postadress.']);
}

// 5. Kontrollera domänmatch (nyckelns domän täcker även underdomäner)
$emailDomain = substr(strrchr($email, '@'), 1);
if (!domainCoversEmailDomain($domain, $emailDomain)) {
    apiResponse(403, [
        'success' => false,
        'error' => "E-postdomänen '{$emailDomain}' tillhör varken '{$domain}' eller någon underdomän till '{$domain}'."
    ]);
}

// 6. Kontrollera att kursen finns
$course = queryOne(
    "SELECT id, title FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);

if (!$course) {
    apiResponse(404, ['success' => false, 'error' => 'Kursen hittades inte.']);
}

// 7. Kontrollera att användaren finns
$user = queryOne(
    "SELECT id FROM " . DB_DATABASE . ".users WHERE email = ?",
    [$email]
);

if (!$user) {
    apiResponse(404, ['success' => false, 'error' => 'Användaren hittades inte.']);
}

$userId = (int)$user['id'];

// 8. Kolla om certifikat finns (= kurs slutförd)
$certificate = queryOne(
    "SELECT completion_date FROM " . DB_DATABASE . ".certificates WHERE user_id = ? AND course_id = ?",
    [$userId, $courseId]
);

if ($certificate) {
    apiResponse(200, [
        'success'      => true,
        'status'       => 1,
        'email'        => $email,
        'course_id'    => $courseId,
        'course_title' => $course['title'],
        'completed_at' => $certificate['completion_date']
    ]);
}

// 9. Räkna progress: avklarade lektioner vs totalt antal aktiva lektioner
$totalLessons = queryOne(
    "SELECT COUNT(*) as cnt FROM " . DB_DATABASE . ".lessons WHERE course_id = ? AND status = 'active'",
    [$courseId]
);
$totalCount = (int)($totalLessons['cnt'] ?? 0);

$completedLessons = queryOne(
    "SELECT COUNT(DISTINCT up.lesson_id) as cnt
     FROM " . DB_DATABASE . ".user_progress up
     JOIN " . DB_DATABASE . ".lessons l ON up.lesson_id = l.id
     WHERE up.user_id = ? AND l.course_id = ? AND l.status = 'active' AND up.status = 'completed'",
    [$userId, $courseId]
);
$completedCount = (int)($completedLessons['cnt'] ?? 0);

apiResponse(200, [
    'success'      => true,
    'status'       => 0,
    'email'        => $email,
    'course_id'    => $courseId,
    'course_title' => $course['title'],
    'progress'     => $completedCount . '/' . $totalCount
]);
