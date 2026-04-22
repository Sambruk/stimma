<?php
/**
 * Superadmin "Visa som" — start
 *
 * POST-endpoint som låter en superadmin tillfälligt ta över en annan användares
 * session för att se exakt det användaren ser (kurskatalog, profil, lektioner
 * osv). Originalsessionen sparas i $_SESSION['impersonator_*'] och återställs
 * av stop_impersonate.php.
 *
 * Säkerhet:
 * - Kräver POST + giltig CSRF-token
 * - Endast role=super_admin får starta
 * - Målet får inte vara sig själv eller en annan super_admin
 * - Nested impersonation är förbjuden (avbryter existerande först)
 */
require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

if (!isLoggedIn()) {
    redirect('../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
    $_SESSION['message_type'] = 'danger';
    header('Location: users.php');
    exit;
}

// Om superadmin redan visar som någon — stoppa den först så vi alltid utgår
// från riktig superadmin-session.
if (isImpersonating()) {
    $_SESSION['user_id'] = $_SESSION['impersonator_user_id'];
    $_SESSION['user_email'] = $_SESSION['impersonator_user_email'];
    if (!empty($_SESSION['impersonator_admin_logged_in'])) {
        $_SESSION['admin_logged_in'] = true;
    }
    unset(
        $_SESSION['impersonator_user_id'],
        $_SESSION['impersonator_user_email'],
        $_SESSION['impersonator_admin_logged_in']
    );
}

$currentUser = queryOne(
    "SELECT id, email, role FROM " . DB_DATABASE . ".users WHERE id = ?",
    [$_SESSION['user_id']]
);

if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    $_SESSION['message'] = 'Endast superadmin kan använda "Visa som"-funktionen.';
    $_SESSION['message_type'] = 'danger';
    header('Location: users.php');
    exit;
}

$targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if ($targetUserId <= 0 || $targetUserId === (int)$currentUser['id']) {
    $_SESSION['message'] = 'Du kan inte visa som dig själv.';
    $_SESSION['message_type'] = 'warning';
    header('Location: users.php');
    exit;
}

$targetUser = queryOne(
    "SELECT id, email, role, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?",
    [$targetUserId]
);

if (!$targetUser) {
    $_SESSION['message'] = 'Användaren hittades inte.';
    $_SESSION['message_type'] = 'danger';
    header('Location: users.php');
    exit;
}

if (($targetUser['role'] ?? '') === 'super_admin') {
    $_SESSION['message'] = 'Du kan inte visa som en annan superadmin.';
    $_SESSION['message_type'] = 'warning';
    header('Location: users.php');
    exit;
}

error_log(sprintf(
    '[impersonate] superadmin %s (id=%d) visar som %s (id=%d)',
    $currentUser['email'],
    $currentUser['id'],
    $targetUser['email'],
    $targetUser['id']
));

// Spara originalsessionen
$_SESSION['impersonator_user_id'] = (int)$currentUser['id'];
$_SESSION['impersonator_user_email'] = $currentUser['email'];
$_SESSION['impersonator_admin_logged_in'] = !empty($_SESSION['admin_logged_in']);

// Byt till målet
$_SESSION['user_id'] = (int)$targetUser['id'];
$_SESSION['user_email'] = $targetUser['email'];

// admin_logged_in ska följa målets rättigheter, inte superadminens
if (!empty($targetUser['is_admin']) || !empty($targetUser['is_editor'])) {
    $_SESSION['admin_logged_in'] = true;
} else {
    unset($_SESSION['admin_logged_in']);
}

header('Location: ../index.php');
exit;
