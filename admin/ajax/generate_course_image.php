<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Generate AI image for a course
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
$courseId = (int)($_POST['course_id'] ?? 0);
$courseTitle = trim($_POST['course_title'] ?? '');
$courseDescription = trim($_POST['course_description'] ?? '');

if ($courseId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Ogiltigt kurs-ID.']);
    exit;
}

if (empty($courseTitle)) {
    echo json_encode(['success' => false, 'message' => 'Kursnamn saknas.']);
    exit;
}

// Generera AI-bild
$result = generateAIImage($courseTitle, $courseDescription);

if ($result['success']) {
    // Uppdatera kursen med bilden
    execute(
        "UPDATE " . DB_DATABASE . ".courses SET image_url = ? WHERE id = ?",
        [$result['image_url'], $courseId]
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
function generateAIImage($courseTitle, $courseDescription) {
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    $imageApiServer = 'https://api.openai.com/v1/images/generations';

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'API-nyckel saknas.'];
    }

    $prompt = "Educational course cover illustration for a course called '{$courseTitle}'" .
              ($courseDescription ? ". Course description: '{$courseDescription}'" : "") .
              ". Clean, professional, modern style suitable for e-learning platform. No text in image. Abstract or conceptual visualization.";

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

    $fileName = 'ai_course_' . uniqid() . '.png';
    $filePath = $uploadDir . $fileName;

    if (!file_put_contents($filePath, $imageContent)) {
        return ['success' => false, 'error' => 'Kunde inte spara bildfilen.'];
    }

    return ['success' => true, 'image_url' => $fileName];
}
