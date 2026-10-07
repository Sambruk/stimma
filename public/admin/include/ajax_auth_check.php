<?php
/**
 * Authentication and authorization check for AJAX requests
 * 
 * This file should be included at the beginning of admin AJAX files
 * to ensure consistent security logic while maintaining proper JSON responses.
 */

header('Content-Type: application/json');

// Kontrollera om användaren är inloggad
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Du måste vara inloggad för att utföra denna åtgärd.']);
    exit;
}

// Hämta användarens roller
$user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;
$isEditor = $user && $user['is_editor'] == 1;

// Kursredaktör utan egen roll (se auth_check.php): bara anropen som kurs- och
// lektionsredigeringen behöver. Varje anrop kontrollerar själv kursbehörigheten.
$isCourseEditorOnly = !$isAdmin && !$isEditor
    && hasCourseEditorAssignments($_SESSION['user_email']);
if ($isCourseEditorOnly) {
    $courseEditorAjax = ['upload_image.php', 'update_lesson_order.php', 'update_info_page_owner.php', 'ai_generate_lesson.php', 'add_course_editor.php'];
    if (!in_array(basename($_SERVER['SCRIPT_NAME']), $courseEditorAjax, true)) {
        echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att utföra denna åtgärd.']);
        exit;
    }
}

// Kontrollera om användaren har admin- eller redaktörsrättigheter
if (!$isAdmin && !$isEditor && !$isCourseEditorOnly) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att utföra denna åtgärd.']);
    exit;
}
