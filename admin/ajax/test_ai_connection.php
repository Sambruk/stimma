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

/**
 * AJAX endpoint för att testa AI-anslutningen
 * Skickar ett enkelt testmeddelande till AI-leverantören
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json');

try {
    // Kontrollera att användaren är inloggad och är superadmin
    if (!isLoggedIn()) {
        throw new Exception('Du måste vara inloggad.');
    }

    $userId = $_SESSION['user_id'];
    $user = queryOne("SELECT role FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);

    if (!$user || $user['role'] !== 'super_admin') {
        throw new Exception('Endast superadministratörer kan testa AI-anslutningen.');
    }

    // Validera CSRF-token
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';

    if (empty($csrfToken) || !validateCsrfToken($csrfToken)) {
        throw new Exception('Ogiltig säkerhetstoken.');
    }

    // Hämta AI-konfiguration för att visa i responsen
    $provider = getenv('AI_PROVIDER') ?: 'openai';
    $apiServer = getenv('AI_SERVER') ?: '';
    if (empty($apiServer)) {
        $apiServer = getDefaultApiUrl($provider);
    }
    $model = getenv('AI_MODEL') ?: 'gpt-4';
    $apiKey = getenv('AI_API_KEY') ?: '';

    if (empty($apiKey)) {
        throw new Exception('API-nyckel saknas i konfigurationen (.env). Kontrollera att AI_API_KEY är konfigurerad.');
    }

    // Skapa ett enkelt testmeddelande
    $messages = [
        ['role' => 'system', 'content' => 'Du är en hjälpsam AI-assistent. Svara kort och koncist.'],
        ['role' => 'user', 'content' => 'Säg bara "Anslutningen fungerar!" på svenska.']
    ];

    // Mät svarstiden
    $startTime = microtime(true);

    // Skicka testförfrågan
    $response = sendOpenAIRequest($messages);

    $endTime = microtime(true);
    $responseTime = round(($endTime - $startTime) * 1000); // millisekunder

    // Returnera framgångsresultat
    echo json_encode([
        'success' => true,
        'message' => 'AI-anslutningen fungerar!',
        'details' => [
            'server' => parse_url($apiServer, PHP_URL_HOST),
            'model' => $model,
            'response_time_ms' => $responseTime,
            'ai_response' => strip_tags($response)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'AI-anslutningen misslyckades',
        'error' => $e->getMessage()
    ]);
}
