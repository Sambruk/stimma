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

// IDOR-skydd: verifiera att användaren får modifiera just denna kurs innan
// vi genererar (och debiterar AI-kvot) och skriver image_url. Generell
// admin/editor-behörighet räcker inte — ägarskap måste kontrolleras.
$courseRow = queryOne("SELECT id, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$courseRow || !userCanModifyCourse($courseRow)) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att ändra denna kurs.']);
    exit;
}

if (empty($courseTitle)) {
    echo json_encode(['success' => false, 'message' => 'Kursnamn saknas.']);
    exit;
}

// Kvotkontroll
require_once '../../include/ai_quota.php';
try {
    enforceAiQuotaForCurrentSession();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// Generera AI-bild
$result = generateAIImage($courseTitle, $courseDescription, $courseId);

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
function generateAIImage($courseTitle, $courseDescription, $courseId = null) {
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    $imageApiServer = 'https://api.openai.com/v1/images/generations';

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'API-nyckel saknas.'];
    }

    require_once __DIR__ . '/../../include/ai_image_helper.php';
    require_once __DIR__ . '/../../include/ai_quota.php';

    // Kvotkontroll innan bilden genereras — annars blir det en billing-bypass
    // (vi skulle ändå logga via logAiUsage längre ned men kvoten skulle aldrig
    // stoppa generering, vilket gör att en organisation kan skena iväg).
    try {
        enforceAiQuotaForCurrentSession();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $context = ['feature' => 'image', 'course_id' => $courseId, 'is_image' => true];
    $imageModel = getModelForFeature('image', 'gpt-image-1-mini');

    $prompt = "Cover illustration for a course called '{$courseTitle}'" .
              ($courseDescription ? ". The course is about: '{$courseDescription}'" : "") .
              ". " . aiImageStyleDirective();

    $data = aiImageBuildPayload($imageModel, $prompt, '1024x1024');

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
        logAiUsage($context, [], $imageModel, 'error');
        return ['success' => false, 'error' => 'Curl-fel: ' . $curlError];
    }

    if ($httpCode !== 200) {
        logAiUsage($context, [], $imageModel, 'error');
        $errorResult = json_decode($response, true);
        $errorMessage = $errorResult['error']['message'] ?? 'HTTP-fel ' . $httpCode;
        return ['success' => false, 'error' => 'API-fel: ' . $errorMessage];
    }

    $result = json_decode($response, true);
    $imageContent = aiImageExtractBytes(is_array($result) ? $result : []);

    if (!$imageContent) {
        logAiUsage($context, [], $imageModel, 'error');
        return ['success' => false, 'error' => 'Inga bilddata i API-svaret.'];
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

    logAiUsage($context, [], $imageModel, 'ok');
    return ['success' => true, 'image_url' => $fileName];
}
