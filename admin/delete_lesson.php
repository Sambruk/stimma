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
$user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;
$isEditor = $user && $user['is_editor'] == 1;

if (!$isAdmin && !$isEditor) {
    $_SESSION['message'] = 'Du har inte behörighet att radera lektioner.';
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

// Kontrollera att ID finns
if (!isset($_POST['id'])) {
    $_SESSION['message'] = 'Inget ID angivet.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

$lessonId = (int)$_POST['id'];

// Hämta lektionsinformation
$lesson = queryOne("SELECT * FROM " . DB_DATABASE . ".lessons WHERE id = ?", [$lessonId]);

if (!$lesson) {
    $_SESSION['message'] = 'Lektionen kunde inte hittas.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Behörighet: verifiera att användaren får modifiera lektionens kurs.
// userCanModifyCourse täcker super_admin, org-scopade admins OCH kurs-
// specifika redaktörer. Tidigare kontrollerades bara redaktörer mot
// course_editors medan admins släpptes igenom för alla kurser i alla
// organisationer (IDOR/cross-org).
$lessonCourse = queryOne("SELECT id, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?", [$lesson['course_id']]);
if (!$lessonCourse || !userCanModifyCourse($lessonCourse)) {
    $_SESSION['message'] = 'Du har inte behörighet att radera lektioner i denna kurs.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

try {
    // Delete local video file if applicable
    if (($lesson['video_type'] ?? '') === 'local' && !empty($lesson['video_url'])) {
        $videoPath = __DIR__ . '/../upload/videos/' . basename($lesson['video_url']);
        if (file_exists($videoPath)) {
            unlink($videoPath);
        }
    }

    // Delete audio file if applicable
    if (!empty($lesson['audio_url'])) {
        $audioPath = __DIR__ . '/../upload/audio/' . basename($lesson['audio_url']);
        if (file_exists($audioPath)) {
            unlink($audioPath);
        }
    }

    execute("START TRANSACTION");

    // Delete user_progress for this lesson (FK: user_progress.lesson_id -> lessons.id)
    execute("DELETE FROM " . DB_DATABASE . ".user_progress WHERE lesson_id = ?", [$lessonId]);

    // Delete resources for this lesson (FK: resources.lesson_id -> lessons.id)
    execute("DELETE FROM " . DB_DATABASE . ".resources WHERE lesson_id = ?", [$lessonId]);

    // Delete the lesson
    execute("DELETE FROM " . DB_DATABASE . ".lessons WHERE id = ?", [$lessonId]);

    execute("COMMIT");

    logActivity($_SESSION['user_email'], "Raderade lektionen '" . $lesson['title'] . "' (ID: " . $lessonId . ")");

    $_SESSION['message'] = 'Lektionen har raderats.';
    $_SESSION['message_type'] = 'success';

    $remainingLessons = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$lesson['course_id']]);

    if ($remainingLessons['count'] > 0) {
        header('Location: lessons.php?course_id=' . $lesson['course_id']);
    } else {
        header('Location: courses.php');
    }
    exit;
} catch (Exception $e) {
    execute("ROLLBACK");
    error_log("Lesson deletion error: " . $e->getMessage());
    $_SESSION['message'] = 'Ett fel uppstod vid radering av lektionen.';
    $_SESSION['message_type'] = 'danger';
    header('Location: lessons.php?course_id=' . $lesson['course_id']);
    exit;
}
