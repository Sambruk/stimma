<?php
/**
 * Stimma - REST API: Användarsynkronisering
 *
 * POST /api/sync_users.php
 * Authorization: Bearer stm_...
 *
 * Synkroniserar en komplett användarlista per domän.
 * Användare som saknas i listan markeras som sync_status='inactive'.
 * Autentiseringen (inloggning via magic link) påverkas INTE.
 */

// CORS-headers MÅSTE sättas FÖRE require (config.php gör HTTPS-redirect)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Hantera OPTIONS preflight (innan config.php redirect)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../include/api_helpers.php';
require_once __DIR__ . '/../include/functions.php';

// Tillåt enbart POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(405, ['success' => false, 'error' => 'Metoden stöds inte. Använd POST.']);
}

$startTime = microtime(true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// 1. Autentisera API-nyckel
$apiKey = authenticateApiKey();
$domain = $apiKey['domain'];
$apiKeyId = $apiKey['id'];

// 2. Kontrollera att sync_enabled=1 för domänen
$domainSettings = queryOne(
    "SELECT sync_enabled FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?",
    [$domain]
);

if (!$domainSettings || (int)$domainSettings['sync_enabled'] !== 1) {
    apiResponse(403, [
        'success' => false,
        'error' => "Synkronisering är inte aktiverad för domänen '{$domain}'. Aktivera i admin-panelen."
    ]);
}

// 3. Kontrollera rate limit
checkRateLimit($apiKeyId);

// 4. Läs och validera request body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    apiResponse(400, ['success' => false, 'error' => 'Ogiltig JSON i request body.']);
}

if (!isset($body['users']) || !is_array($body['users'])) {
    apiResponse(400, ['success' => false, 'error' => 'Fältet "users" saknas eller är inte en array.']);
}

$users = $body['users'];
$userCount = count($users);
$deactivateMissing = $body['deactivate_missing'] ?? true;

if ($userCount === 0) {
    apiResponse(400, ['success' => false, 'error' => 'Användarlistan är tom.']);
}

if ($userCount > 10000) {
    apiResponse(413, ['success' => false, 'error' => 'Max 10 000 användare per synk. Skickade: ' . $userCount]);
}

// 5. Validera alla användare
$validation = validateSyncUsers($users, $domain);
if (!$validation['valid']) {
    apiResponse(400, [
        'success' => false,
        'error' => 'Valideringsfel.',
        'validation_errors' => $validation['errors']
    ]);
}

// 6. Utför synk via delad funktion.
// Sista argumentet: nyckeln är utfärdad för primärdomänen och omfattar hela
// organisationen, alltså även underdomäner — både när användare läggs till och
// när saknade användare avaktiveras.
$result = performUserSync($users, $domain, $deactivateMissing, $apiKeyId, $ipAddress, true);

// Varningar: sådant som inte gör payloaden ogiltig men nästan alltid betyder att
// anroparen menade något annat. Ett felstavat fältnamn passerade tidigare med
// HTTP 200 och "success": true, och användaren skapades utan organisationstaggar
// — det fanns ingenting i svaret att felsöka på.
$warnings = collectSyncUserWarnings($users);

if ($result['success']) {
    // Logga i activity_log
    logActivity('api@' . $domain, 'API-synk genomförd', [
        'action' => 'api_sync',
        'domain' => $domain,
        'sync_id' => $result['sync_id'],
        'users_in_payload' => $result['summary']['total_in_payload'],
        'created' => $result['summary']['created'],
        'updated' => $result['summary']['updated'],
        'deactivated' => $result['summary']['deactivated'],
        'deleted' => $result['summary']['deleted'],
        'reactivated' => $result['summary']['reactivated']
    ]);

    $svar = [
        'success' => true,
        'summary' => $result['summary'],
        'sync_id' => $result['sync_id']
    ];
    if (!empty($warnings)) {
        $svar['warnings'] = $warnings;
    }
    apiResponse(200, $svar);
} else {
    apiResponse(500, [
        'success' => false,
        'error' => $result['error']
    ]);
}
