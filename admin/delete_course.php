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

// Kontrollera om användaren har behörighet att radera kursen
if (!$isAdmin) {
    $isSpecificEditor = queryOne("SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?", [$courseId, $_SESSION['user_email']]);
    if (!$isSpecificEditor) {
        $_SESSION['message'] = 'Du har inte behörighet att radera denna kurs.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }
}

// Radera kursen och alla dess lektioner
try {
    $lessonCount = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$courseId])['count'];

    execute("DELETE FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$courseId]);
    execute("DELETE FROM " . DB_DATABASE . ".course_editors WHERE course_id = ?", [$courseId]);
    execute("DELETE FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);

    logActivity($_SESSION['user_email'], "Raderade kursen '" . $course['title'] . "' (ID: " . $courseId . ") med " . $lessonCount . " lektioner");

    if ($lessonCount > 0) {
        $_SESSION['message'] = 'Kursen och ' . $lessonCount . ' lektion' . ($lessonCount > 1 ? 'er' : '') . ' har raderats.';
    } else {
        $_SESSION['message'] = 'Kursen har raderats.';
    }
    $_SESSION['message_type'] = 'success';
} catch (Exception $e) {
    error_log("Course deletion error: " . $e->getMessage());
    $_SESSION['message'] = 'Ett fel uppstod när kursen skulle raderas.';
    $_SESSION['message_type'] = 'danger';
}

header('Location: courses.php');
exit;
