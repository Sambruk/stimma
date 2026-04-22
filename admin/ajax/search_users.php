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