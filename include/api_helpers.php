<?php
/**
 * Stimma - API-hjälpfunktioner
 *
 * Funktioner för REST API-autentisering, validering och svar.
 * Används av api/sync_users.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Skicka JSON API-svar med HTTP-statuskod
 *
 * @param int $httpCode HTTP-statuskod
 * @param array $data Svarsdata
 */
function apiResponse($httpCode, $data) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Autentisera API-nyckel via Bearer-token
 *
 * Läser Authorization-header, hashar token med SHA-256 och slår upp i api_keys.
 * Uppdaterar last_used_at vid lyckad autentisering.
 *
 * @return array API-nyckelrad vid lyckad autentisering, annars avslutas med 401
 */
function authenticateApiKey() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (empty($authHeader) || !preg_match('/^Bearer\s+(stm_.+)$/i', $authHeader, $matches)) {
        apiResponse(401, [
            'success' => false,
            'error' => 'Ogiltig eller saknad API-nyckel. Använd Authorization: Bearer stm_...'
        ]);
    }

    $token = $matches[1];
    $hash = hash('sha256', $token);

    $apiKey = queryOne(
        "SELECT * FROM " . DB_DATABASE . ".api_keys WHERE api_key_hash = ? AND is_active = 1",
        [$hash]
    );

    if (!$apiKey) {
        apiResponse(401, [
            'success' => false,
            'error' => 'Ogiltig eller inaktiverad API-nyckel.'
        ]);
    }

    // Uppdatera last_used_at
    execute(
        "UPDATE " . DB_DATABASE . ".api_keys SET last_used_at = NOW() WHERE id = ?",
        [$apiKey['id']]
    );

    return $apiKey;
}

/**
 * Kontrollera rate limit för API-nyckel
 *
 * Max 10 förfrågningar per timme per API-nyckel.
 *
 * @param int $apiKeyId API-nyckelns ID
 */
function checkRateLimit($apiKeyId) {
    $count = queryOne(
        "SELECT COUNT(*) as cnt FROM " . DB_DATABASE . ".sync_log
         WHERE api_key_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        [$apiKeyId]
    );

    if ($count && (int)$count['cnt'] >= 10) {
        apiResponse(429, [
            'success' => false,
            'error' => 'Rate limit överskriden. Max 10 förfrågningar per timme.'
        ]);
    }
}

/**
 * Avgör om en API-nyckels domän täcker en e-postdomän
 *
 * En nyckel utfärdas alltid för organisationens primärdomän (t.ex. sater.se)
 * och gäller då även alla underdomäner (edu.sater.se, utb.sater.se, ...).
 *
 * Matchningen är antingen exakt, eller ett suffix som föregås av punkt. Punkten
 * är nödvändig: utan den skulle nyckeln för sater.se även täcka storsater.se,
 * som är en helt annan organisation.
 *
 * @param string $keyDomain Domänen som API-nyckeln är utfärdad för
 * @param string $emailDomain Domändelen av en e-postadress
 * @return bool
 */
function domainCoversEmailDomain($keyDomain, $emailDomain) {
    $keyDomain = strtolower(trim((string)$keyDomain));
    $emailDomain = strtolower(trim((string)$emailDomain));

    if ($keyDomain === '' || $emailDomain === '') {
        return false;
    }

    if ($emailDomain === $keyDomain) {
        return true;
    }

    $suffix = '.' . $keyDomain;
    return substr($emailDomain, -strlen($suffix)) === $suffix;
}

/**
 * Validera användararray för synk
 *
 * Kontrollerar att varje användare har giltig e-post som matchar domänen,
 * giltig roll, och att det inte finns dubbletter.
 *
 * @param array $users Användararray från request body
 * @param string $domain Domänen som API-nyckeln tillhör
 * @return array ['valid' => bool, 'errors' => [...]]
 */
function validateSyncUsers($users, $domain) {
    $errors = [];
    $seenEmails = [];

    foreach ($users as $i => $user) {
        $idx = $i + 1;

        // Kontrollera obligatoriska fält
        if (empty($user['email'])) {
            $errors[] = "Användare #{$idx}: e-post saknas.";
            continue;
        }

        $email = strtolower(trim($user['email']));

        // Validera e-postformat
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Användare #{$idx}: ogiltig e-postadress '{$email}'.";
            continue;
        }

        // Kontrollera domänmatch (nyckelns domän täcker även underdomäner)
        $emailDomain = substr(strrchr($email, '@'), 1);
        if (!domainCoversEmailDomain($domain, $emailDomain)) {
            $errors[] = "Användare #{$idx}: e-post '{$email}' tillhör varken '{$domain}' eller någon underdomän till '{$domain}'.";
            continue;
        }

        // Kontrollera dubbletter
        if (isset($seenEmails[$email])) {
            $errors[] = "Användare #{$idx}: dubblettadress '{$email}'.";
            continue;
        }
        $seenEmails[$email] = true;

        // Validera namn
        if (empty($user['name'])) {
            $errors[] = "Användare #{$idx} ({$email}): namn saknas.";
        }

        // Validera roll
        $allowedRoles = ['student', 'teacher', 'admin'];
        if (!empty($user['role']) && !in_array($user['role'], $allowedRoles)) {
            $errors[] = "Användare #{$idx} ({$email}): ogiltig roll '{$user['role']}'. Tillåtna: student, teacher, admin.";
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
