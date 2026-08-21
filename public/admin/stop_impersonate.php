<?php
/**
 * Superadmin "Visa som" — stopp
 *
 * Återställer superadmin-sessionen från $_SESSION['impersonator_*']. Anropas från
 * banner-knappen i header.php. POST + CSRF krävs. Om ingen impersonation pågår
 * görs inget utöver en redirect tillbaka till användarlistan.
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
    header('Location: ../index.php');
    exit;
}

if (isImpersonating()) {
    error_log(sprintf(
        '[impersonate] superadmin %s (id=%d) avslutar "visa som" %s (id=%d)',
        $_SESSION['impersonator_user_email'] ?? 'okänd',
        (int)($_SESSION['impersonator_user_id'] ?? 0),
        $_SESSION['user_email'] ?? 'okänd',
        (int)($_SESSION['user_id'] ?? 0)
    ));

    $_SESSION['user_id'] = (int)$_SESSION['impersonator_user_id'];
    $_SESSION['user_email'] = $_SESSION['impersonator_user_email'];

    if (!empty($_SESSION['impersonator_admin_logged_in'])) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        unset($_SESSION['admin_logged_in']);
    }

    unset(
        $_SESSION['impersonator_user_id'],
        $_SESSION['impersonator_user_email'],
        $_SESSION['impersonator_admin_logged_in']
    );
}

header('Location: users.php');
exit;
