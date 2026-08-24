<?php
/**
 * Stimma - Admin AJAX: Direkt användarsynkronisering
 *
 * En admin som tillhör organisationens PRIMÄRDOMÄN kan synka användare till
 * alla domäner i organisationen i ett enda anrop. Varje inkommande e-post
 * filtreras mot orgens domänlista — rader vars domän inte tillhör orgen
 * hoppas över och rapporteras som "skipped".
 *
 * Superadmin får synka fritt utan primärdomän-check.
 */

require_once __DIR__ . '/../../include/config.php';
require_once __DIR__ . '/../../include/database.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/../include/ajax_auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$user = queryOne("SELECT id, email, is_admin, role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$user || (!$user['is_admin'] && $user['role'] !== 'super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Åtkomst nekad.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Använd POST.']);
    exit;
}

// CSRF-skydd: klienten (admin/sync_tool.php) skickar token i X-CSRF-Token-
// headern, som faller tillbaka på en csrf_token-rad i JSON-bodyn om någon
// framtida klient föredrar det. Båda jämförs timing-safe via hash_equals i
// validateCsrfToken().
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ogiltig JSON.']);
    exit;
}

$providedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (is_array($body) ? ($body['csrf_token'] ?? '') : '');
if (!validateCsrfToken($providedCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ogiltig CSRF-token.']);
    exit;
}

if (!isset($body['users']) || !is_array($body['users']) || count($body['users']) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Användarlistan saknas eller är tom.']);
    exit;
}

$users = $body['users'];
$deactivateMissing = $body['deactivate_missing'] ?? false;
$isSuperAdmin = ($user['role'] === 'super_admin');

// Bestäm vilka domäner admin får synka till
$userEmail = $_SESSION['user_email'];
$userDomain = getUserDomain($userEmail);
$allowedDomains = [];
$orgName = '';

if ($isSuperAdmin) {
    // Superadmin: ange allt. Filtrera fortfarande varje e-post mot någon känd
    // organisation-domän (från organization_domains) eller domain_settings —
    // annars kan vilken godtycklig domän som helst skapas. Vi använder bara
    // allt i organization_domains.
    $rows = query("SELECT DISTINCT domain FROM " . DB_DATABASE . ".organization_domains");
    $allowedDomains = array_column($rows ?: [], 'domain');
    $orgName = 'Superadmin (alla organisationer)';
} else {
    // Vanlig admin: hämta orgen, kräv primärdomän
    $org = getOrganizationByDomain($userDomain);
    if (!$org) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Din domän (' . $userDomain . ') är inte grupperad i någon organisation. Kontakta superadmin.',
        ]);
        exit;
    }

    // Kolla att användarens domän är primärdomän
    $primaryRow = queryOne(
        "SELECT domain FROM " . DB_DATABASE . ".organization_domains
         WHERE organization_id = ? AND is_primary = 1 LIMIT 1",
        [$org['id']]
    );
    $primaryDomain = $primaryRow ? $primaryRow['domain'] : null;

    if (!$primaryDomain || strtolower($userDomain) !== strtolower($primaryDomain)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Synkverktyget får endast användas av en admin på organisationens primärdomän' . ($primaryDomain ? " ({$primaryDomain})" : '') . '.',
        ]);
        exit;
    }

    $allowedDomains = getOrganizationDomains($org['id']);
    $orgName = $org['name'] ?? '';
}

$allowedDomainsLower = array_map('strtolower', $allowedDomains);

// Gruppera användare per domän, räkna skippade (domän utanför orgen)
$grouped = [];
$errors = [];
$skipped = [];
$emailsSeen = [];

foreach ($users as $i => $u) {
    $email = strtolower(trim($u['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Rad ' . ($i + 1) . ': ogiltig e-post "' . ($u['email'] ?? '') . '"';
        continue;
    }
    if (isset($emailsSeen[$email])) {
        $errors[] = 'Rad ' . ($i + 1) . ': dubblett "' . $email . '"';
        continue;
    }
    $emailsSeen[$email] = true;

    $emailDomain = strtolower(substr($email, strrpos($email, '@') + 1));
    if (!in_array($emailDomain, $allowedDomainsLower, true)) {
        $skipped[] = $email;
        continue;
    }
    $u['email'] = $email; // normalisera
    // Raderingsflaggan är en API-funktion och gäller inte här. Synkverktyget matas
    // från en uppladdad lista, där en felaktig kolumn annars hade kunnat radera
    // konton förbi adminpanelens egna spärrar (t.ex. "radera inte dig själv").
    unset($u['delete']);
    $grouped[$emailDomain][] = $u;
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valideringsfel.', 'validation_errors' => array_slice($errors, 0, 20)]);
    exit;
}

if (empty($grouped)) {
    echo json_encode([
        'success' => false,
        'error' => 'Inga användare kunde synkas — ingen av e-postadresserna tillhör organisationens domäner.',
        'skipped_count' => count($skipped),
        'skipped_emails' => array_slice($skipped, 0, 50),
    ]);
    exit;
}

// Kör performUserSync per domän
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'admin';
$totalSummary = ['total_in_payload' => 0, 'created' => 0, 'updated' => 0, 'deactivated' => 0, 'reactivated' => 0];
$syncIds = [];
$perDomainResults = [];

foreach ($grouped as $domain => $usersInDomain) {
    $result = performUserSync($usersInDomain, $domain, (bool)$deactivateMissing, null, $ipAddress);
    $perDomainResults[$domain] = $result;
    if (!empty($result['success']) && isset($result['summary'])) {
        foreach ($totalSummary as $k => $_v) {
            $totalSummary[$k] += (int)($result['summary'][$k] ?? 0);
        }
        if (!empty($result['sync_id'])) $syncIds[] = $result['sync_id'];
    }
}

logActivity($userEmail, 'Admin-synk (multi-domän)', [
    'action' => 'admin_sync',
    'organization' => $orgName,
    'domains_synced' => array_keys($grouped),
    'skipped_count' => count($skipped),
    'summary' => $totalSummary,
    'sync_ids' => $syncIds,
]);

echo json_encode([
    'success' => true,
    'organization' => $orgName,
    'domains' => array_keys($grouped),
    'summary' => $totalSummary,
    'sync_ids' => $syncIds,
    'skipped_count' => count($skipped),
    'skipped_emails' => array_slice($skipped, 0, 50),
    'per_domain' => array_map(function($r) {
        return [
            'success' => $r['success'] ?? false,
            'summary' => $r['summary'] ?? null,
            'error' => $r['error'] ?? null,
        ];
    }, $perDomainResults),
]);
