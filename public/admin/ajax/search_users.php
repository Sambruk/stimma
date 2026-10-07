<?php
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json');

// Kontrollera om användaren är inloggad
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Du måste vara inloggad för att utföra denna åtgärd.']);
    exit;
}

// Validera input
if (!isset($_GET['search'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig förfrågan.']);
    exit;
}

$search = trim($_GET['search']);

// SECURITY FIX: Validate search length to prevent abuse
if (strlen($search) > 100) {
    echo json_encode(['success' => false, 'message' => 'Söksträngen är för lång (max 100 tecken).']);
    exit;
}

if (strlen($search) < 2) {
    echo json_encode(['success' => false, 'message' => 'Söksträngen måste vara minst 2 tecken.']);
    exit;
}

// SECURITY FIX: Escape LIKE pattern special characters to prevent SQL wildcard injection
// This prevents attackers from using % or _ to enumerate all users
$search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

// Hämta inloggad användares domän för organisationsfiltrering
$userDomain = substr(strrchr($_SESSION['user_email'], "@"), 1);

// Org-scope: alla domäner i adminens organisation
$orgScopeDomains = getOrgScopeDomains($_SESSION['user_email']);
$emailDomClause = buildEmailDomainInClause($orgScopeDomains, 'email');

// Sök efter användare inom hela organisationen (alla orgens domäner)
$users = queryAll(
    "SELECT email, name FROM " . DB_DATABASE . ".users
     WHERE (email LIKE ? OR name LIKE ?)
       AND email != ?
       AND {$emailDomClause['fragment']}
     ORDER BY name ASC
     LIMIT 10",
    array_merge(["%$search%", "%$search%", $_SESSION['user_email']], $emailDomClause['params'])
);

// Redaktörsrutan i edit_course.php (?lage=redaktor): en hel e-postadress hittar
// personen även i en annan organisation, så att hen kan bli redaktör för en
// enskild kurs (2026-10-07). Bara exakt adress – det går inte att bläddra
// bland andra organisationers användare. Finns inget konto men domänen är
// ansluten till Stimma skapas kontot när redaktören läggs till.
$exaktAdress = strtolower(trim($_GET['search']));
if (($_GET['lage'] ?? '') === 'redaktor' && filter_var($exaktAdress, FILTER_VALIDATE_EMAIL)
    && $exaktAdress !== strtolower($_SESSION['user_email'])
    && !in_array($exaktAdress, array_map('strtolower', array_column($users, 'email')), true)) {
    $adressDoman = substr(strrchr($exaktAdress, '@'), 1);
    $arExtern = !in_array($adressDoman, $orgScopeDomains, true);
    $finns = queryOne("SELECT email, name FROM " . DB_DATABASE . ".users WHERE email = ?", [$exaktAdress]);
    if ($finns) {
        $users[] = ['email' => $finns['email'], 'name' => $finns['name'], 'extern' => $arExtern];
    } elseif (isDomainAllowed($adressDoman)) {
        $users[] = ['email' => $exaktAdress, 'name' => null, 'extern' => $arExtern, 'nytt_konto' => true];
    } else {
        echo json_encode([
            'success' => true,
            'users' => [],
            'message' => 'Domänen ' . $adressDoman . ' är inte ansluten till Stimma, så personen kan inte bli redaktör.'
        ]);
        exit;
    }
}

// Om inga användare hittades, kontrollera om söksträngen är en e-postadress
if (empty($users) && filter_var($search, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => true, 
        'users' => [], 
        'message' => 'Ingen användare hittades med denna e-postadress. Användaren måste skapas först.'
    ]);
} else {
    echo json_encode(['success' => true, 'users' => $users]);
} 