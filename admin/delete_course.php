<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

// Kräv POST-metod
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: courses.php');
    exit;
}

// Kontrollera om användaren är inloggad
if (!isLoggedIn()) {
    redirect('../index.php');
    exit;
}

// Kontrollera om användaren har admin- eller redaktörsrättigheter
$user = queryOne("SELECT is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;
$isEditor = $user && $user['is_editor'] == 1;
$isSuperAdmin = $user && ($user['role'] ?? '') === 'super_admin';
$orgScopeDomains = getOrgScopeDomains($_SESSION['user_email']);

if (!$isAdmin && !$isEditor) {
    $_SESSION['message'] = 'Du har inte behörighet att radera kurser.';
    $_SESSION['message_type'] = 'warning';
    redirect('../index.php');
    exit;
}

// Kontrollera CSRF-token
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    $_SESSION['message'] = 'Ogiltig CSRF-token.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Kontrollera om ID finns
if (!isset($_POST['id'])) {
    $_SESSION['message'] = 'Ingen kurs specificerad.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

$courseId = (int)$_POST['id'];

// Hämta kursinformation
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);

if (!$course) {
    $_SESSION['message'] = 'Kursen kunde inte hittas.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Behörighetskontroll via userCanModifyCourse — täcker super_admin,
// org-scopade admins och kurs-specifika redaktörer. Fixar IDOR-buggen
// där alla admins (oavsett org) kunde radera vilken kurs som helst.
if (!userCanModifyCourse($course)) {
    $_SESSION['message'] = 'Du har inte behörighet att radera denna kurs.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Radera kursen och alla relaterade data
try {
    execute("START TRANSACTION");

    $lessonCount = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$courseId])['count'];

    // Get lesson IDs for cascade deletion
    $lessonIds = query("SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$courseId]);

    // Delete user_progress for all lessons in this course (FK: user_progress.lesson_id -> lessons.id)
    foreach ($lessonIds as $lid) {
        execute("DELETE FROM " . DB_DATABASE . ".user_progress WHERE lesson_id = ?", [$lid['id']]);
    }

    // Delete resources linked to lessons or course (FK: resources.lesson_id -> lessons.id, resources.course_id -> courses.id)
    foreach ($lessonIds as $lid) {
        execute("DELETE FROM " . DB_DATABASE . ".resources WHERE lesson_id = ?", [$lid['id']]);
    }
    execute("DELETE FROM " . DB_DATABASE . ".resources WHERE course_id = ?", [$courseId]);

    // Delete lessons (FK: lessons.course_id -> courses.id)
    execute("DELETE FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$courseId]);

    // Delete course_editors (FK: course_editors.course_id -> courses.id)
    execute("DELETE FROM " . DB_DATABASE . ".course_editors WHERE course_id = ?", [$courseId]);

    // Delete course_tags (FK: course_tags.course_id -> courses.id)
    execute("DELETE FROM " . DB_DATABASE . ".course_tags WHERE course_id = ?", [$courseId]);

    // Delete certificates (FK: certificates.course_id -> courses.id)
    execute("DELETE FROM " . DB_DATABASE . ".certificates WHERE course_id = ?", [$courseId]);

    // Unlink ai_course_jobs (FK: ai_course_jobs.result_course_id -> courses.id)
    execute("UPDATE " . DB_DATABASE . ".ai_course_jobs SET result_course_id = NULL WHERE result_course_id = ?", [$courseId]);

    // Delete course_enrollments (no FK but contains course data)
    execute("DELETE FROM " . DB_DATABASE . ".course_enrollments WHERE course_id = ?", [$courseId]);

    // Delete reminder_log entries (no FK but contains course data)
    execute("DELETE FROM " . DB_DATABASE . ".reminder_log WHERE course_id = ?", [$courseId]);

    // Koppla loss kursen ur eventuella lärvägar. FK:n cascadar visserligen,
    // men filen raderar allt explicit — håll mönstret. Lärvägarna själva blir
    // kvar, bara ett steg kortare.
    execute("DELETE FROM " . DB_DATABASE . ".learning_path_courses WHERE course_id = ?", [$courseId]);

    // Samla public_course_access-användare INNAN kursen raderas (FK cascade
    // tömmer tabellen) så vi kan göra orphan-sweep efteråt.
    $publicUserIds = array_column(
        query("SELECT user_id FROM " . DB_DATABASE . ".public_course_access WHERE course_id = ?", [$courseId]),
        'user_id'
    );

    // Finally delete the course — FK cascadar public_course_access +
    // public_registration_intents.
    execute("DELETE FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);

    execute("COMMIT");

    // Orphan-sweep: radera public_only-användare som inte har några kurser kvar.
    foreach ($publicUserIds as $uid) {
        try { maybeDeleteOrphanPublicUser((int)$uid); } catch (Exception $e) {}
    }

    logActivity($_SESSION['user_email'], "Raderade kursen '" . $course['title'] . "' (ID: " . $courseId . ") med " . $lessonCount . " lektioner");

    if ($lessonCount > 0) {
        $_SESSION['message'] = 'Kursen och ' . $lessonCount . ' lektion' . ($lessonCount > 1 ? 'er' : '') . ' har raderats.';
    } else {
        $_SESSION['message'] = 'Kursen har raderats.';
    }
    $_SESSION['message_type'] = 'success';
} catch (Exception $e) {
    execute("ROLLBACK");
    error_log("Course deletion error: " . $e->getMessage());
    $_SESSION['message'] = 'Ett fel uppstod när kursen skulle raderas.';
    $_SESSION['message_type'] = 'danger';
}

header('Location: courses.php');
exit;
