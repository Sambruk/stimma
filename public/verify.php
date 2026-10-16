<?php
/**
 * Stimma - Learn in small steps
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

/**
 * Gamla inloggningslänkar (/verify.php?token=…&email=…).
 *
 * Sedan 2026-10-16 loggar man in med en engångskod (se sendLoginCode() i
 * include/auth.php). Länkar i äldre mejl loggar inte in längre; sidan förklarar
 * det och leder till inloggningen.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

if (isLoggedIn()) {
    redirect('index.php');
    exit;
}

$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$page_title = $systemName . ' - Logga in';
require_once 'include/header.php';
?>
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body text-center p-4">
                    <div class="mb-3"><i class="bi bi-envelope-paper text-primary" style="font-size: 3rem;" aria-hidden="true"></i></div>
                    <h1 class="h4 mb-3">Inloggningen har ändrats</h1>
                    <p class="text-muted"><?= htmlspecialchars($systemName) ?> skickar nu en kod med e-post i stället för en länk.
                        Länken du klickade på gäller inte längre.</p>
                    <a href="index.php" class="btn btn-primary w-100">Logga in med kod</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once 'include/footer.php';
