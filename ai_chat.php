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

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

// Sätt header för JSON
header('Content-Type: application/json');

/**
 * Hämta AI-inställning från databasen
 */
function getAiSetting($key, $default = '') {
    $result = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = ?", [$key]);
    return $result ? $result['setting_value'] : $default;
}

/**
 * Bygg systemprompt med guardrails
 */
function buildSystemPromptWithGuardrails($lessonTitle, $lessonAiPrompt = '') {
    // Hämta guardrails-inställningar
    $guardrailsEnabled = getAiSetting('guardrails_enabled', '1') === '1';
    $systemPromptPrefix = getAiSetting('system_prompt_prefix', '');
    $blockedTopics = getAiSetting('blocked_topics', '');
    $responseGuidelines = getAiSetting('response_guidelines', '');
    $topicRestrictions = getAiSetting('topic_restrictions', '');
    $customInstructions = getAiSetting('custom_instructions', '');

    // Börja med grundprompten
    if (!empty($systemPromptPrefix)) {
        $systemPrompt = $systemPromptPrefix;
    } else {
        $systemPrompt = "Du är en hjälpsam AI-assistent som hjälper användaren med lektionen '{$lessonTitle}'.";
    }

    // Lägg till lektionskontext
    $systemPrompt .= "\n\nDu hjälper just nu användaren med lektionen: '{$lessonTitle}'.";

    // Lägg till guardrails om aktiverat
    if ($guardrailsEnabled) {
        if (!empty($responseGuidelines)) {
            $systemPrompt .= "\n\n**Svarsriktlinjer:**\n" . $responseGuidelines;
        }

        if (!empty($topicRestrictions)) {
            $systemPrompt .= "\n\n**Ämnesbegränsningar:**\n" . $topicRestrictions;
        }

        if (!empty($blockedTopics)) {
            $systemPrompt .= "\n\n**VIKTIGT - Blockerade ämnen:** Du får INTE diskutera eller ge information om följande ämnen: " . $blockedTopics . ". Om användaren frågar om dessa ämnen, avböj vänligt och förklara att du endast kan hjälpa till med frågor relaterade till lektionens innehåll.";
        }
    }

    // Lägg till anpassade instruktioner
    if (!empty($customInstructions)) {
        $systemPrompt .= "\n\n**Ytterligare instruktioner:**\n" . $customInstructions;
    }

    // Lägg till lektionsspecifik AI-prompt
    if (!empty($lessonAiPrompt)) {
        $systemPrompt .= "\n\n**Instruktioner för denna specifika lektion:**\n" . $lessonAiPrompt;
    }

    return $systemPrompt;
}

try {
    // Kontrollera om användaren är inloggad
    if (!isLoggedIn()) {
        throw new Exception('Du måste vara inloggad för att använda AI-chatten.');
    }

    // Hämta och validera inkommande data
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ogiltig JSON-data.');
    }

    $lessonId = $data['lesson_id'] ?? 0;
    $message = $data['message'] ?? '';
    $aiPrompt = $data['ai_prompt'] ?? '';

    // Validera inkommande data
    if (!$lessonId || !$message) {
        throw new Exception('Ogiltig förfrågan. Lektion och meddelande krävs.');
    }

    // Validera meddelandelängd
    $maxMessageLength = (int)getenv('AI_MAX_MESSAGE_LENGTH') ?: 500;
    if (strlen($message) > $maxMessageLength) {
        throw new Exception("Meddelandet är för långt. Max {$maxMessageLength} tecken tillåtna.");
    }

    // Implementera rate limiting
    $userId = $_SESSION['user_id'];
    $now = time();
    $lastRequest = $_SESSION['ai_last_request'] ?? 0;
    $requestCount = $_SESSION['ai_request_count'] ?? 0;

    // Hämta konfigureringsvariabler
    $maxRequests = (int)getenv('AI_RATE_LIMIT_REQUESTS') ?: 10;
    $timeWindow = (int)getenv('AI_RATE_LIMIT_MINUTES') ?: 5;
    $timeWindowSeconds = $timeWindow * 60;

    // Begränsa till maxantal förfrågningar under tidsfönstret
    if ($now - $lastRequest < $timeWindowSeconds) {
        if ($requestCount >= $maxRequests) {
            throw new Exception("För många förfrågningar. Vänligen försök igen senare.");
        }
        $_SESSION['ai_request_count'] = $requestCount + 1;
    } else {
        // Återställ räknaren om det var mer än tidsfönstret sedan senaste frågan
        $_SESSION['ai_request_count'] = 1;
    }

    $_SESSION['ai_last_request'] = $now;

    // Hämta lektionsinformation
    $lesson = queryOne("SELECT * FROM " . DB_DATABASE . ".lessons WHERE id = ?", [$lessonId]);
    if (!$lesson) {
        throw new Exception('Lektionen kunde inte hittas.');
    }

    // Bygg systemprompt med guardrails
    $systemPrompt = buildSystemPromptWithGuardrails($lesson['title'], $aiPrompt);

    // Skapa meddelanden för OpenAI
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $message]
    ];

    // Skicka förfrågan till OpenAI
    $response = sendOpenAIRequest($messages);


    // Formatera svaret med vår egen markdown-parser
    $formattedResponse = parseMarkdown($response);


    // Returnera svaret
    echo json_encode(['response' => $formattedResponse]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
