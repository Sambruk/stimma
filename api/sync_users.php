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

// 6. Utför synk i transaktion
$db = getDb();
$created = 0;
$updated = 0;
$deactivated = 0;
$reactivated = 0;
$syncLogId = null;

try {
    $db->beginTransaction();

    $processedEmails = [];

    foreach ($users as $userData) {
        $email = strtolower(trim($userData['email']));
        $name = trim($userData['name'] ?? '');
        $role = $userData['role'] ?? 'student';
        $organization = trim($userData['organization'] ?? '');

        $processedEmails[] = $email;

        // Kolla om användaren redan finns
        $existingUser = queryOne(
            "SELECT id, is_synced, sync_status, role FROM " . DB_DATABASE . ".users WHERE email = ?",
            [$email]
        );

        // Bestäm is_admin och is_editor baserat på roll
        $isAdmin = ($role === 'admin') ? 1 : 0;
        $isEditor = ($role === 'admin' || $role === 'teacher') ? 1 : 0;

        if (!$existingUser) {
            // Skapa ny användare med is_admin/is_editor baserat på roll
            $stmt = $db->prepare(
                "INSERT INTO " . DB_DATABASE . ".users (email, name, role, is_admin, is_editor, is_synced, sync_status, synced_at, verified_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, 'active', NOW(), NOW(), NOW())"
            );
            $stmt->execute([$email, $name, $role, $isAdmin, $isEditor]);
            $userId = $db->lastInsertId();
            $created++;
        } else {
            $userId = $existingUser['id'];

            // Kontrollera om användaren reaktiveras
            if ($existingUser['is_synced'] == 1 && $existingUser['sync_status'] === 'inactive') {
                $reactivated++;
            }

            // Uppdatera existerande - bevara super_admin-roll
            $newRole = $role;
            $newIsAdmin = $isAdmin;
            $newIsEditor = $isEditor;
            if ($existingUser['role'] === 'super_admin') {
                $newRole = 'super_admin'; // Bevara super_admin
                $newIsAdmin = 1;
                $newIsEditor = 1;
            }

            $stmt = $db->prepare(
                "UPDATE " . DB_DATABASE . ".users
                 SET name = ?, role = ?, is_admin = ?, is_editor = ?, is_synced = 1, sync_status = 'active', synced_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$name, $newRole, $newIsAdmin, $newIsEditor, $userId]);
            $updated++;
        }

        // Hantera organisationstaggar
        // Rensa gamla taggar
        $stmt = $db->prepare("DELETE FROM " . DB_DATABASE . ".user_org_tags WHERE user_id = ?");
        $stmt->execute([$userId]);

        // Sätt nya taggar från organization-fältet
        if (!empty($organization)) {
            $segments = array_map('trim', explode('/', $organization));
            $segments = array_filter($segments, function($s) { return $s !== ''; });

            foreach ($segments as $tag) {
                $stmt = $db->prepare(
                    "INSERT IGNORE INTO " . DB_DATABASE . ".user_org_tags (user_id, tag) VALUES (?, ?)"
                );
                $stmt->execute([$userId, $tag]);
            }
        }
    }

    // 7. Markera saknade användare som inaktiva (kan stängas av med deactivate_missing=false)
    if ($deactivateMissing && !empty($processedEmails)) {
        $placeholders = implode(',', array_fill(0, count($processedEmails), '?'));
        $params = array_merge($processedEmails, [$domain]);

        $stmt = $db->prepare(
            "UPDATE " . DB_DATABASE . ".users
             SET sync_status = 'inactive'
             WHERE is_synced = 1
               AND sync_status = 'active'
               AND role != 'super_admin'
               AND email NOT IN ($placeholders)
               AND SUBSTRING_INDEX(email, '@', -1) = ?"
        );
        $stmt->execute($params);
        $deactivated = $stmt->rowCount();
    }

    // 8. Logga synk
    $durationMs = (int)((microtime(true) - $startTime) * 1000);

    $stmt = $db->prepare(
        "INSERT INTO " . DB_DATABASE . ".sync_log
         (domain, api_key_id, users_in_payload, users_created, users_updated, users_deactivated, users_reactivated, status, ip_address, duration_ms)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'success', ?, ?)"
    );
    $stmt->execute([$domain, $apiKeyId, $userCount, $created, $updated, $deactivated, $reactivated, $ipAddress, $durationMs]);
    $syncLogId = $db->lastInsertId();

    $db->commit();

    // Logga i activity_log
    logActivity('api@' . $domain, 'API-synk genomförd', [
        'action' => 'api_sync',
        'domain' => $domain,
        'sync_id' => $syncLogId,
        'users_in_payload' => $userCount,
        'created' => $created,
        'updated' => $updated,
        'deactivated' => $deactivated,
        'reactivated' => $reactivated
    ]);

    apiResponse(200, [
        'success' => true,
        'summary' => [
            'total_in_payload' => $userCount,
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'reactivated' => $reactivated
        ],
        'sync_id' => (int)$syncLogId
    ]);

} catch (Exception $e) {
    $db->rollBack();

    // Logga fel
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $errorMsg = $e->getMessage();

    execute(
        "INSERT INTO " . DB_DATABASE . ".sync_log
         (domain, api_key_id, users_in_payload, users_created, users_updated, users_deactivated, users_reactivated, status, error_message, ip_address, duration_ms)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'failed', ?, ?, ?)",
        [$domain, $apiKeyId, $userCount, $created, $updated, $deactivated, $reactivated, $errorMsg, $ipAddress, $durationMs]
    );

    error_log("Stimma API sync error: " . $errorMsg);

    apiResponse(500, [
        'success' => false,
        'error' => 'Ett internt fel uppstod vid synkronisering.'
    ]);
}
