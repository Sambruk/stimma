<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 *
 * POST-endpoint: återuppta en avbruten kurs.
 * Återställer course_enrollments.status från 'abandoned' till 'active'.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
    exit;
}

if (!isLoggedIn()) {
    redirect('index.php');
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    redirect('index.php');
    exit;
}

$userId = $_SESSION['user_id'];
$courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;

if ($courseId <= 0) {
    redirect('index.php');
    exit;
}

$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$course) {
    redirect('index.php');
    exit;
}

$enrollment = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".course_enrollments
      WHERE user_id = ? AND course_id = ? AND status = 'abandoned'",
    [$userId, $courseId]
);

if ($enrollment) {
    execute(
        "UPDATE " . DB_DATABASE . ".course_enrollments
            SET status = 'active',
                abandoned_at = NULL,
                abandon_reason = NULL,
                opt_out_reminders = 0
          WHERE user_id = ? AND course_id = ?",
        [$userId, $courseId]
    );

    logActivity($_SESSION['user_email'], 'Återupptog kurs', [
        'action' => 'course_resumed',
        'course_id' => $courseId,
        'course_title' => $course['title']
    ]);
}

redirect('index.php#mina-kurser');
