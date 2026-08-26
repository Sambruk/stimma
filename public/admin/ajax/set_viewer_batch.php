<?php
/**
 * Stimma - Admin AJAX: Läsbehörighet i batch
 *
 * Sätter eller tar bort flaggan is_viewer på befintliga konton — och RÖR
 * INGENTING ANNAT. Ingen roll, inget namn, inga organisationstaggar, ingen
 * synkstatus. Det är hela poängen med att den här ändringen inte gjordes till
 * ett fjärde värde i synkverktygets rollfält: läsbehörighet är en egen flagga i
 * datamodellen (users.is_viewer), och users.role är en enum utan sådant värde.
 *
 * Endast e-postadresser som finns i listan påverkas. Den som inte nämns behåller
 * sin läsbehörighet oförändrad, så en ofullständig fil kan aldrig råka nollställa
 * någon annans behörighet.
 *
 * Behörighet: samma som synkverktyget — admin på organisationens primärdomän,
 * eller superadmin. Varje e-post skärs dessutom mot orgens domäner, så en admin
 * kan aldrig ändra ett konto utanför sin egen organisation.
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

if (!isset($body['entries']) || !is_array($body['entries']) || count($body['entries']) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Listan saknas eller är tom.']);
    exit;
}

$isSuperAdmin = ($user['role'] === 'super_admin');
$userEmail = $_SESSION['user_email'];
$userDomain = getUserDomain($userEmail);
$orgName = '';

// Samma domängrind som synkverktyget: en vanlig admin måste stå på orgens
// primärdomän och når då orgens samtliga domäner.
if ($isSuperAdmin) {
    $rows = query("SELECT DISTINCT domain FROM " . DB_DATABASE . ".organization_domains");
    $allowedDomains = array_column($rows ?: [], 'domain');
    $orgName = 'Superadmin (alla organisationer)';
} else {
    $org = getOrganizationByDomain($userDomain);
    if (!$org) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Din domän (' . $userDomain . ') är inte grupperad i någon organisation. Kontakta superadmin.',
        ]);
        exit;
    }

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
            'error' => 'Läsbehörighet i batch får endast sättas av en admin på organisationens primärdomän' . ($primaryDomain ? " ({$primaryDomain})" : '') . '.',
        ]);
        exit;
    }

    $allowedDomains = getOrganizationDomains($org['id']);
    $orgName = $org['name'] ?? '';
}

$allowedDomainsLower = array_map('strtolower', $allowedDomains);

// Validera hela listan innan något skrivs. En fil med felaktiga rader ska inte
// resultera i en halvt genomförd körning som admin får gissa sig till.
$parsed = [];
$errors = [];
$seen = [];

foreach ($body['entries'] as $i => $entry) {
    $rad = $i + 1;
    $email = strtolower(trim((string)($entry['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Rad ' . $rad . ': ogiltig e-post "' . ($entry['email'] ?? '') . '"';
        continue;
    }
    if (isset($seen[$email])) {
        $errors[] = 'Rad ' . $rad . ': dubblett "' . $email . '"';
        continue;
    }
    $seen[$email] = true;

    // Värdet får saknas — då tolkas raden som "ge läsbehörighet", vilket är det
    // vanliga fallet när någon klistrar in en ren adresslista.
    $raw = $entry['viewer'] ?? true;
    $viewer = parseViewerFlagValue($raw);
    if ($viewer === null) {
        $errors[] = 'Rad ' . $rad . ' (' . $email . '): oläsbart värde "' . (is_scalar($raw) ? $raw : gettype($raw)) . '". Använd ja/nej.';
        continue;
    }

    $parsed[$email] = $viewer;
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Valideringsfel — ingenting har ändrats.',
        'validation_errors' => array_slice($errors, 0, 20),
    ]);
    exit;
}

$satta = 0;
$borttagna = 0;
$oforandrade = 0;
$saknas = [];
$utanforScope = [];
$andrade = [];

$db = getDb();
$db->beginTransaction();
try {
    foreach ($parsed as $email => $viewer) {
        $emailDomain = strtolower(substr($email, strrpos($email, '@') + 1));
        if (!in_array($emailDomain, $allowedDomainsLower, true)) {
            $utanforScope[] = $email;
            continue;
        }

        $target = queryOne(
            "SELECT id, is_viewer FROM " . DB_DATABASE . ".users WHERE email = ?",
            [$email]
        );

        // Kontot måste finnas. Den här funktionen ändrar behörighet på befintliga
        // konton och skapar aldrig nya — det är synkverktygets uppgift.
        if (!$target) {
            $saknas[] = $email;
            continue;
        }

        $nuvarande = (int)$target['is_viewer'];
        if ($nuvarande === $viewer) {
            $oforandrade++;
            continue;
        }

        // Enda skrivningen i hela filen. Ingen annan kolumn nämns.
        $stmt = $db->prepare("UPDATE " . DB_DATABASE . ".users SET is_viewer = ? WHERE id = ?");
        $stmt->execute([$viewer, $target['id']]);

        if ($viewer === 1) {
            $satta++;
        } else {
            $borttagna++;
        }
        $andrade[] = ['email' => $email, 'viewer' => $viewer === 1];
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('set_viewer_batch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ett fel uppstod — ingenting har ändrats.']);
    exit;
}

logActivity($userEmail, 'Läsbehörighet uppdaterad i batch', [
    'action' => 'viewer_batch',
    'organization' => $orgName,
    'antal_i_fil' => count($parsed),
    'satta' => $satta,
    'borttagna' => $borttagna,
    'oforandrade' => $oforandrade,
    'saknas' => count($saknas),
    'utanfor_scope' => count($utanforScope),
    'andrade' => array_slice($andrade, 0, 200),
]);

echo json_encode([
    'success' => true,
    'organization' => $orgName,
    'antal_i_fil' => count($parsed),
    'satta' => $satta,
    'borttagna' => $borttagna,
    'oforandrade' => $oforandrade,
    'saknas' => $saknas,
    'utanfor_scope' => $utanforScope,
]);
