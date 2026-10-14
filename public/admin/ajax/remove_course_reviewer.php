<?php
/** Ta bort en granskare från en kurs (migration 052). Samma behörighet som add_course_reviewer.php. */
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

require_once '../include/ajax_auth_check.php';

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig CSRF-token.']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$email = strtolower(trim($_POST['email'] ?? ''));

$course = queryOne(
    "SELECT id, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte.']);
    exit;
}
if (!userCanModifyCourse($course) || isExternalCourseEditor($course)) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att ta bort granskare från denna kurs.']);
    exit;
}

execute("DELETE FROM " . DB_DATABASE . ".course_reviewers WHERE course_id = ? AND email = ?", [$courseId, $email]);
logActivity($_SESSION['user_email'], "Tog bort granskare {$email} från kurs {$courseId}");
echo json_encode(['success' => true]);
