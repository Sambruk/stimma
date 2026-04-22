<?php
/**
 * Stimma - Admin AJAX: Direkt användarsynkronisering
 *
 * Utför samma synklogik som api/sync_users.php men med sessionsautentisering
 * istället för API-nyckel. Används av det integrerade synkverktyget.
 */

require_once __DIR__ . '/../include/ajax_auth_check.php';
require_once __DIR__ . '/../../include/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Kräv admin eller super_admin
$user = queryOne("SELECT id, email, is_admin, role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$user || (!$user['is_admin'] && $user['role'] !== 'super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Åtkomst nekad.']);
    exit;
}

// Kräv POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Använd POST.']);
    exit;
}

// Läs request body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ogiltig JSON.']);
    exit;
}

if (!isset($body['users']) || !is_array($body['users']) || count($body['users']) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Användarlistan saknas eller är tom.']);
    exit;
}

$users = $body['users'];
$deactivateMissing = $body['deactivate_missing'] ?? false;
$domain = $body['domain'] ?? null;

// Bestäm domän
$userDomain = getUserDomain($_SESSION['user_email']);
$isSuperAdmin = ($user['role'] === 'super_admin');

if ($isSuperAdmin && !empty($domain)) {
    // Super_admin kan välja domän
    $targetDomain = $domain;
} else {
    // Admin synkar till sin egen domän
    $targetDomain = $userDomain;
}

if (empty($targetDomain)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Kunde inte bestämma måldomän.']);
    exit;
}

// Validera e-postdomäner
$errors = [];
$emailsSeen = [];
foreach ($users as $i => $u) {
    $email = strtolower(trim($u['email'] ?? ''));
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Rad " . ($i + 1) . ": Ogiltig e-post '{$email}'.";
        continue;
    }
    $emailDomain = substr($email, strrpos($email, '@') + 1);
    if ($emailDomain !== $targetDomain) {
        $errors[] = "Rad " . ($i + 1) . ": E-post '{$email}' matchar inte domänen '{$targetDomain}'.";
    }
    if (isset($emailsSeen[$email])) {
        $errors[] = "Rad " . ($i + 1) . ": Dubblettadress '{$email}'.";
    }
    $emailsSeen[$email] = true;
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valideringsfel.', 'validation_errors' => array_slice($errors, 0, 10)]);
    exit;
}

// Utför synk
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'admin';
$result = performUserSync($users, $targetDomain, $deactivateMissing, null, $ipAddress);

if ($result['success']) {
    logActivity($_SESSION['user_email'], 'Admin-synk genomförd', [
        'action' => 'admin_sync',
        'domain' => $targetDomain,
        'sync_id' => $result['sync_id'],
        'users_in_payload' => $result['summary']['total_in_payload'],
        'created' => $result['summary']['created'],
        'updated' => $result['summary']['updated'],
        'deactivated' => $result['summary']['deactivated'],
        'reactivated' => $result['summary']['reactivated']
    ]);
}

echo json_encode($result);
