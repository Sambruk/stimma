<?php
/**
 * Toggla is_public på en kurs. Genererar automatiskt ett token första gången
 * kursen markeras publik. Att stänga av behåller token (oförändrad) men
 * registreringslänken blir inaktiv eftersom validatePublicRegistrationToken
 * kräver is_public=1. Befintliga deltagare påverkas inte.
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
$makePublic = !empty($_POST['is_public']);

$course = queryOne(
    "SELECT id, organization_domain, public_registration_token FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte']);
    exit;
}

// Org-scope-check: vanlig admin får bara toggla kurser i sin org
if (($currentUser['role'] ?? '') !== 'super_admin') {
    $scope = getOrgScopeDomains($currentUser['email']);
    if (!in_array($course['organization_domain'], $scope, true)) {
        echo json_encode(['success' => false, 'message' => 'Kursen tillhör inte din organisation']);
        exit;
    }
}

if ($makePublic) {
    $token = $course['public_registration_token'];
    if (empty($token)) {
        $token = generatePublicRegistrationToken($courseId);
    }
    execute("UPDATE " . DB_DATABASE . ".courses SET is_public = 1 WHERE id = ?", [$courseId]);
    $systemUrl = rtrim(getenv('SYSTEM_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? '')), '/');
    $publicUrl = $systemUrl . '/public_register.php?course_id=' . $courseId . '&token=' . $token;
    echo json_encode(['success' => true, 'public_url' => $publicUrl]);
} else {
    execute("UPDATE " . DB_DATABASE . ".courses SET is_public = 0 WHERE id = ?", [$courseId]);
    echo json_encode(['success' => true, 'public_url' => null]);
}
