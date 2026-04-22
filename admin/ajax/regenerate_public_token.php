<?php
/**
 * Generera ett nytt publikt registreringstoken för en kurs. Den gamla länken
 * slutar fungera omedelbart. Befintliga deltagare (public_course_access)
 * påverkas inte.
 */
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Ej inloggad']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig begäran']);
    exit;
}

$currentUser = queryOne(
    "SELECT id, email, role, is_admin FROM " . DB_DATABASE . ".users WHERE id = ?",
    [$_SESSION['user_id']]
);
if (!$currentUser || (empty($currentUser['is_admin']) && ($currentUser['role'] ?? '') !== 'super_admin')) {
    echo json_encode(['success' => false, 'message' => 'Otillräcklig behörighet']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$course = queryOne(
    "SELECT id, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte']);
    exit;
}

if (($currentUser['role'] ?? '') !== 'super_admin') {
    $scope = getOrgScopeDomains($currentUser['email']);
    if (!in_array($course['organization_domain'], $scope, true)) {
        echo json_encode(['success' => false, 'message' => 'Kursen tillhör inte din organisation']);
        exit;
    }
}

$token = generatePublicRegistrationToken($courseId);
$systemUrl = rtrim(getenv('SYSTEM_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? '')), '/');
echo json_encode([
    'success' => true,
    'public_url' => $systemUrl . '/public_register.php?course_id=' . $courseId . '&token=' . $token
]);
