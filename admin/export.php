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

// Include centralized authentication and authorization check
require_once 'include/auth_check.php';

// Kontrollera att ett kurs-ID har skickats med
if (!isset($_GET['id'])) {
    $_SESSION['message'] = 'Inget kurs-ID angivet.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

$courseId = (int)$_GET['id'];

// Kontrollera behörighet
$user = queryOne("SELECT is_admin FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$isAdmin = $user && $user['is_admin'] == 1;

if (!$isAdmin) {
    // Kontrollera om användaren är redaktör för kursen
    $isEditor = queryOne("SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?", [$courseId, $_SESSION['user_email']]);
    if (!$isEditor) {
        $_SESSION['message'] = 'Du har inte behörighet att exportera denna kurs.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }
}

// Hämta kursinformation
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);

if (!$course) {
    $_SESSION['message'] = 'Kursen hittades inte.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Hämta alla lektioner för kursen
$lessons = queryAll("SELECT * FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order", [$courseId]);

// Samla alla bilder som behövs
$uploadDir = realpath(__DIR__ . '/../upload') . '/';
$images = []; // filnamn => true (deduplicering)

// Kursens bild
if (!empty($course['image_url'])) {
    $imgFile = basename($course['image_url']);
    if (file_exists($uploadDir . $imgFile)) {
        $images[$imgFile] = true;
    }
}

// Lektionsbilder och inline-bilder
foreach ($lessons as $lesson) {
    // Lektionens image_url
    if (!empty($lesson['image_url'])) {
        $imgFile = basename($lesson['image_url']);
        if (file_exists($uploadDir . $imgFile)) {
            $images[$imgFile] = true;
        }
    }

    // Inline-bilder i content: <img src="upload/filename.ext">
    if (!empty($lesson['content'])) {
        if (preg_match_all('/src=["\'](?:\.\.\/)?upload\/([^"\']+)["\']/i', $lesson['content'], $matches)) {
            foreach ($matches[1] as $inlineImg) {
                $imgFile = basename($inlineImg);
                if (file_exists($uploadDir . $imgFile)) {
                    $images[$imgFile] = true;
                }
            }
        }
    }
}

// Bygg exportobjektet
$exportData = [
    'export_version' => 2,
    'course' => [
        'title' => $course['title'],
        'description' => $course['description'],
        'difficulty_level' => $course['difficulty_level'],
        'duration_minutes' => $course['duration_minutes'],
        'prerequisites' => $course['prerequisites'],
        'tags' => $course['tags'],
        'image_url' => $course['image_url'] ?? '',
        'status' => 'inactive', // Sätt alltid till inaktiv vid export
        'sort_order' => $course['sort_order'],
        'featured' => $course['featured'],
        'created_at' => $course['created_at'],
        'updated_at' => $course['updated_at']
    ],
    'lessons' => []
];

// Lägg till lektionerna
foreach ($lessons as $lesson) {
    // For local videos: skip the video (can't be portably exported)
    $lessonVideoUrl = $lesson['video_url'];
    $lessonVideoType = $lesson['video_type'] ?? null;
    if ($lessonVideoType === 'local') {
        $lessonVideoUrl = null;
        $lessonVideoType = null;
    }

    $exportData['lessons'][] = [
        'title' => $lesson['title'],
        'estimated_duration' => $lesson['estimated_duration'],
        'image_url' => $lesson['image_url'] ?? '',
        'video_url' => $lessonVideoUrl,
        'video_type' => $lessonVideoType,
        'content' => $lesson['content'],
        'resource_links' => $lesson['resource_links'],
        'tags' => $lesson['tags'],
        'status' => $lesson['status'],
        'sort_order' => $lesson['sort_order'],
        'ai_instruction' => $lesson['ai_instruction'],
        'ai_prompt' => $lesson['ai_prompt'],
        'quiz_question' => $lesson['quiz_question'],
        'quiz_answer1' => $lesson['quiz_answer1'],
        'quiz_answer2' => $lesson['quiz_answer2'],
        'quiz_answer3' => $lesson['quiz_answer3'],
        'quiz_correct_answer' => $lesson['quiz_correct_answer'],
        'created_at' => $lesson['created_at'],
        'updated_at' => $lesson['updated_at']
    ];
}

// Konvertera till JSON med UTF-8
$json = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Skapa ZIP-fil
$tmpFile = tempnam(sys_get_temp_dir(), 'stimma_export_');
$zip = new ZipArchive();

if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    $_SESSION['message'] = 'Kunde inte skapa ZIP-fil för export.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Lägg till course.json
$zip->addFromString('course.json', $json);

// Lägg till alla bilder under images/
foreach (array_keys($images) as $imgFile) {
    $fullPath = $uploadDir . $imgFile;
    if (file_exists($fullPath)) {
        $zip->addFile($fullPath, 'images/' . $imgFile);
    }
}

$zip->close();

// Skicka ZIP som nedladdning
$safeName = preg_replace('/[^a-zA-Z0-9_\-åäöÅÄÖ]/', '_', $course['title']);
$filename = 'course_' . $courseId . '_' . $safeName . '_' . date('Y-m-d') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
