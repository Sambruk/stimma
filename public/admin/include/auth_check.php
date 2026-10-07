<?php
/**
 * Authentication and authorization check for admin pages
 * 
 * This file should be included at the beginning of all admin PHP files
 * to ensure consistent security logic across the admin area.
 */

// Kontrollera om användaren är inloggad
if (!isLoggedIn()) {
    redirect('../index.php');
    exit;
}

// Hämta användarens roller
$user = queryOne("SELECT is_admin, is_editor, is_viewer FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;
$isEditor = $user && $user['is_editor'] == 1;
// Läsbehörig: får se kursstatistik, diplom och användarinformation inom sitt
// domänscope, men kan inte ändra något. Se migrations/045_viewer_role.sql.
$isViewer = $user && $user['is_viewer'] == 1;

// Kursredaktör utan egen roll – typiskt en person från en annan organisation som
// lagts till som redaktör för en viss kurs (2026-10-07). Släpps bara in till
// kurssidorna; vad hen får göra där avgörs per kurs av userCanModifyCourse().
$isCourseEditorOnly = !$isAdmin && !$isEditor && !$isViewer
    && hasCourseEditorAssignments($_SESSION['user_email']);

// Kontrollera om användaren har admin-, redaktörs- eller läsrättigheter
if (!$isAdmin && !$isEditor && !$isViewer && !$isCourseEditorOnly) {

    redirect('../index.php');
    exit;
}

if ($isCourseEditorOnly) {
    $courseEditorPages = ['courses.php', 'edit_course.php', 'lessons.php', 'edit_lesson.php', 'edit_quiz.php', 'user_guide.php'];
    if (!in_array(basename($_SERVER['SCRIPT_NAME']), $courseEditorPages, true)) {
        // redirect() utgår från webbplatsens rot, därav admin/
        redirect('admin/courses.php');
        exit;
    }
}
