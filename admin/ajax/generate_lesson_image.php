<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Generate AI image for a lesson
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Kontrollera att användaren är inloggad
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Du måste vara inloggad.']);
    exit;
}

// Hämta användarinfo
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'Användare hittades inte.']);
    exit;
}

$isAdmin = $currentUser['is_admin'] == 1;
$isEditor = $currentUser['is_editor'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';

// Kontrollera behörighet
if (!$isAdmin && !$isEditor && !$isSuperAdmin) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet.']);
    exit;
}

// Validera CSRF
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

// Hämta parametrar
$lessonId = (int)($_POST['lesson_id'] ?? 0);
$lessonTitle = trim($_POST['lesson_title'] ?? '');
$courseName = trim($_POST['course_name'] ?? '');

if ($lessonId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Ogiltigt lektions-ID.']);
    exit;
}

if (empty($lessonTitle)) {
    echo json_encode(['success' => false, 'message' => 'Lektionsnamn saknas.']);
    exit;
}

// Slå upp course_id för loggning
$lessonRow = queryOne("SELECT course_id FROM " . DB_DATABASE . ".lessons WHERE id = ?", [$lessonId]);
$lessonCourseId = $lessonRow ? (int)$lessonRow['course_id'] : null;

// Generera AI-bild via centraliserad helper (kvotkontroll + loggning ingår)
require_once '../../include/ai_lesson_helper.php';
$result = aiGenerateLessonImage($lessonTitle, $courseName, $lessonCourseId);

if ($result['success']) {
    // Uppdatera lektionen med bilden
    execute(
        "UPDATE " . DB_DATABASE . ".lessons SET image_url = ? WHERE id = ?",
        [$result['image_url'], $lessonId]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Bild genererad!',
        'image_url' => $result['image_url']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $result['error']]);
}

// Bildgenereringsfunktionen ligger nu centralt i include/ai_lesson_helper.php
// (aiGenerateLessonImage). Återanvänds från ai_generate_lesson.php så lektions-
// bildgenereringen kan triggas både via knappen i editorn och som del av
// AI-skapa-lektion-flödet.
