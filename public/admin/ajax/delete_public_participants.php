<?php
/**
 * Bulk-radera publika deltagare från en kurs. Rensar ALL deras data för kursen
 * via purgePublicCourseUserData() + skickar ett bekräftelsemail till varje
 * deltagare + kör maybeDeleteOrphanPublicUser() för de som inte har några
 * andra publika kurser kvar.
 *
 * Kräver: superadmin ELLER admin/editor inom kursens organisation. CSRF.
 */
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Ej inloggad']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken']);
    exit;
}

$currentUser = queryOne(
    "SELECT id, email, role, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?",
    [$_SESSION['user_id']]
);
if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'Okänd användare']);
    exit;
}
$isSuper = ($currentUser['role'] ?? '') === 'super_admin';
if (!$isSuper && empty($currentUser['is_admin']) && empty($currentUser['is_editor'])) {
    echo json_encode(['success' => false, 'message' => 'Otillräcklig behörighet']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$userIds = $_POST['user_ids'] ?? [];
if (!is_array($userIds) || empty($userIds)) {
    echo json_encode(['success' => false, 'message' => 'Inga användare valda']);
    exit;
}
$userIds = array_map('intval', $userIds);
$userIds = array_filter($userIds, function($id) { return $id > 0; });

$course = queryOne(
    "SELECT id, title, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte']);
    exit;
}

// Org-scope-check för icke-super
if (!$isSuper) {
    $scope = getOrgScopeDomains($currentUser['email']);
    if (!in_array($course['organization_domain'], $scope, true)) {
        echo json_encode(['success' => false, 'message' => 'Kursen tillhör inte din organisation']);
        exit;
    }
}

$deleted = 0;
$errors = [];
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
$mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: $systemName;

foreach ($userIds as $uid) {
    // Slå upp info INNAN vi raderar (för mailet)
    $target = queryOne(
        "SELECT id, email, name FROM " . DB_DATABASE . ".users WHERE id = ?",
        [$uid]
    );
    if (!$target) continue;

    // Verifiera faktiskt att target har public_course_access för kursen
    if (!hasPublicCourseAccess($uid, $courseId)) continue;

    try {
        purgePublicCourseUserData($uid, $courseId);

        // Bekräftelsemail
        $name = trim($target['name'] ?? '');
        $greeting = $name !== '' ? ('Hej ' . htmlspecialchars($name) . ',') : 'Hej,';
        $subject = 'Din anmälan till ' . $course['title'] . ' har avslutats';
        $body = '<html><body style="font-family: Arial, sans-serif;">'
              . '<p>' . $greeting . '</p>'
              . '<p>Din registrering för kursen <strong>' . htmlspecialchars($course['title']) . '</strong> har tagits bort, och all din data för kursen har raderats.</p>'
              . '<p>Åtgärden utfördes av kursens administratör.</p>'
              . '<p>Om du vill delta i kursen igen, kontakta administratören för en ny inloggningslänk.</p>'
              . '<p>Hälsningar,<br>' . htmlspecialchars($systemName) . '</p>'
              . '</body></html>';
        @sendSmtpMail($target['email'], $subject, $body, $mailFrom, $mailFromName);

        maybeDeleteOrphanPublicUser($uid);
        $deleted++;
    } catch (Exception $e) {
        $errors[] = $target['email'] . ': ' . $e->getMessage();
        error_log('[public_participants] Fel vid borttagning av uid=' . $uid . ' course=' . $courseId . ': ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
    'errors' => $errors,
]);
