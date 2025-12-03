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

// Generera AI-bild
$result = generateAIImage($lessonTitle, $courseName);

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

/**
 * Generate AI image using DALL-E
 */
function generateAIImage($lessonTitle, $courseName) {
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    $imageApiServer = 'https://api.openai.com/v1/images/generations';

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'API-nyckel saknas.'];
    }

    $prompt = "Educational illustration for a lesson about '{$lessonTitle}'" .
              ($courseName ? " in a course about '{$courseName}'" : "") .
              ". Clean, professional, minimalist style suitable for e-learning. No text in image.";

    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'n' => 1,
        'size' => '1024x1024',
        'quality' => 'standard'
    ];

    $ch = curl_init($imageApiServer);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Curl-fel: ' . $curlError];
    }

    if ($httpCode !== 200) {
        $errorResult = json_decode($response, true);
        $errorMessage = $errorResult['error']['message'] ?? 'HTTP-fel ' . $httpCode;
        return ['success' => false, 'error' => 'API-fel: ' . $errorMessage];
    }

    $result = json_decode($response, true);

    if (!isset($result['data'][0]['url'])) {
        return ['success' => false, 'error' => 'Ingen bild-URL i API-svaret.'];
    }

    // Download and save image locally
    $imageUrl = $result['data'][0]['url'];
    $imageContent = @file_get_contents($imageUrl);

    if (!$imageContent) {
        return ['success' => false, 'error' => 'Kunde inte ladda ner bilden från OpenAI.'];
    }

    $uploadDir = __DIR__ . '/../../upload/';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'error' => 'Kunde inte skapa upload-mappen.'];
        }
    }

    $fileName = 'ai_' . uniqid() . '.png';
    $filePath = $uploadDir . $fileName;

    if (!file_put_contents($filePath, $imageContent)) {
        return ['success' => false, 'error' => 'Kunde inte spara bildfilen.'];
    }

    return ['success' => true, 'image_url' => $fileName];
}
