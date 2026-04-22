<?php
/**
 * Stimma - Lär dig i små steg
 *
 * AJAX endpoint: Individuell inskrivning i stegvis kurs (rolling enrollment)
 * Stöder enskild användare (via email) eller hel org-tagg (via org_tag).
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

require_once '../include/ajax_auth_check.php';

header('Content-Type: application/json');

// Verifiera CSRF
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

// Kontrollera behörighet
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;

// Org-scope: alla domäner i adminens organisation (eller bara $userDomain om
// domänen inte är grupperad). Används för att tillåta inskrivning av användare
// från alla orgens domäner, inte bara adminens egen.
$adminScopeDomains = getOrgScopeDomains($_SESSION['user_email']);

if (!$isAdmin && !$isEditor) {
    echo json_encode(['success' => false, 'message' => 'Ingen behörighet.']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$orgTag = trim($_POST['org_tag'] ?? '');
$startDate = trim($_POST['start_date'] ?? '');

if (!$courseId) {
    echo json_encode(['success' => false, 'message' => 'Kurs-ID saknas.']);
    exit;
}

// Validera startdatum
if ($startDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    echo json_encode(['success' => false, 'message' => 'Ogiltigt datumformat. Använd ÅÅÅÅ-MM-DD.']);
    exit;
}
$startDateTime = $startDate ? $startDate . ' 08:00:00' : date('Y-m-d H:i:s');

// Hämta kurs
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$course || !$course['sequential_mode']) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte eller är inte stegvis.']);
    exit;
}

if (($course['enrollment_type'] ?? 'bulk_start') !== 'rolling') {
    echo json_encode(['success' => false, 'message' => 'Kursen är inte inställd på löpande registrering.']);
    exit;
}

// Redaktörskontroll
if (!$isAdmin) {
    $hasAccess = queryOne(
        "SELECT c.id FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
         WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
        [$courseId, $currentUser['id'], $_SESSION['user_email']]
    );
    if (!$hasAccess) {
        echo json_encode(['success' => false, 'message' => 'Du har inte behörighet till denna kurs.']);
        exit;
    }
}

// Samla användare att skriva in
$usersToEnroll = [];

if ($orgTag) {
    // Gruppregistrering via org-tagg (utvidgat till alla orgens domäner)
    $orgTagEmailClause = buildEmailDomainInClause($adminScopeDomains, 'u.email');
    $usersToEnroll = query(
        "SELECT DISTINCT u.id, u.email, u.name FROM " . DB_DATABASE . ".users u
         JOIN " . DB_DATABASE . ".user_org_tags uot ON u.id = uot.user_id
         WHERE uot.tag = ? AND {$orgTagEmailClause['fragment']}",
        array_merge([$orgTag], $orgTagEmailClause['params'])
    );
    if (empty($usersToEnroll)) {
        echo json_encode(['success' => false, 'message' => 'Inga användare hittades med den organisationstaggen.']);
        exit;
    }
} elseif ($email) {
    // Enskild användare
    $user = queryOne(
        "SELECT id, email, name FROM " . DB_DATABASE . ".users WHERE email = ?",
        [$email]
    );
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Användaren hittades inte.']);
        exit;
    }
    // Kontrollera att användaren tillhör adminens organisation (alla orgens domäner)
    $targetDomain = substr(strrchr($user['email'], "@"), 1);
    if (!in_array($targetDomain, $adminScopeDomains, true)) {
        echo json_encode(['success' => false, 'message' => 'Användaren tillhör inte din organisation.']);
        exit;
    }
    $usersToEnroll = [$user];
} else {
    echo json_encode(['success' => false, 'message' => 'Ange en e-postadress eller organisationstagg.']);
    exit;
}

// Hämta första lektionen
$firstLesson = queryOne(
    "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1",
    [$courseId]
);

if (!$firstLesson) {
    echo json_encode(['success' => false, 'message' => 'Kursen har inga lektioner.']);
    exit;
}

// Skriv in användare
$enrolledCount = 0;
$alreadyEnrolled = 0;
$firstLessonPairs = [];

foreach ($usersToEnroll as $u) {
    // Kontrollera om redan inskriven
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".sequential_lesson_schedule WHERE user_id = ? AND course_id = ? LIMIT 1",
        [$u['id'], $courseId]
    );
    if ($existing) {
        $alreadyEnrolled++;
        continue;
    }

    $result = enrollUserInSequentialCourse($u['id'], $courseId, $startDateTime);
    if ($result) {
        $enrolledCount++;
        $firstLessonPairs[] = ['user_id' => $u['id'], 'lesson_id' => $firstLesson['id']];
    }
}

// Om startdatum är idag eller passerat: köa och skicka e-post direkt
$sentCount = 0;
if (!empty($firstLessonPairs) && strtotime($startDateTime) <= time()) {
    $queuedCount = queueSequentialEmails($courseId, $firstLessonPairs, 'new_lesson');

    $batchSize = 10;
    $settingBatch = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'sequential_batch_size'");
    if ($settingBatch) $batchSize = max(1, (int)$settingBatch['setting_value']);

    $processResult = processEmailQueue($courseId, $batchSize, 0, null);
    $sentCount = $processResult['sent'] ?? 0;
}

// Logga
logActivity($_SESSION['user_email'], 'Löpande inskrivning i stegvis kurs', [
    'action' => 'rolling_enrollment',
    'course_id' => $courseId,
    'enrolled' => $enrolledCount,
    'already_enrolled' => $alreadyEnrolled,
    'start_date' => $startDate,
    'org_tag' => $orgTag ?: null,
]);

// Bygg meddelande
$parts = [];
if ($enrolledCount > 0) {
    $parts[] = "$enrolledCount " . ($enrolledCount === 1 ? 'användare inskriven' : 'användare inskrivna');
}
if ($alreadyEnrolled > 0) {
    $parts[] = "$alreadyEnrolled redan " . ($alreadyEnrolled === 1 ? 'inskriven' : 'inskrivna');
}
if ($sentCount > 0) {
    $parts[] = "$sentCount e-post skickade";
}
if (!empty($firstLessonPairs) && strtotime($startDateTime) > time()) {
    $parts[] = "Kursstart schemalagd till $startDate";
}

$message = implode('. ', $parts) . '.';

echo json_encode(['success' => true, 'message' => $message, 'enrolled' => $enrolledCount, 'already_enrolled' => $alreadyEnrolled]);
