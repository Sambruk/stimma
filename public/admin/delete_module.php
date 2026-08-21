<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

// Kräv POST-metod
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Kontrollera om användaren är inloggad
if (!isLoggedIn()) {
    redirect('../index.php');
    exit;
}

// Kontrollera om användaren har adminrättigheter
$user = queryOne("SELECT is_admin FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;

if (!$isAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att radera moduler. Endast administratörer får göra detta.';
    $_SESSION['message_type'] = 'warning';
    redirect('../index.php');
    exit;
}

// Kontrollera CSRF-token
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    $_SESSION['message'] = 'Ogiltig CSRF-token.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

if (isset($_POST['id'])) {
    try {
        $module = queryOne("SELECT * FROM modules WHERE id = ?", [(int)$_POST['id']]);

        execute("DELETE FROM modules WHERE id = ?", [(int)$_POST['id']]);

        if ($module) {
            logActivity($_SESSION['user_email'], "Raderade modulen '" . $module['title'] . "' (ID: " . (int)$_POST['id'] . ")");
        }

        $_SESSION['message'] = 'Modulen har tagits bort';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        error_log("Module deletion error: " . $e->getMessage());
        $_SESSION['message'] = 'Ett fel uppstod vid radering av modulen.';
        $_SESSION['message_type'] = 'danger';
    }
}

header('Location: index.php');
exit;
