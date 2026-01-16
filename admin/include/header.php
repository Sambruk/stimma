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

// Kontrollera att användaren är inloggad
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

// Hämta användarinformation för att visa admin/redaktör status i menyn
$user = queryOne("SELECT is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;
$isEditor = $user && $user['is_editor'] == 1;
$isSuperAdmin = $user && $user['role'] === 'super_admin';

// Enkel session timeout (30 minuter)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    $_SESSION['message'] = 'Din session har gått ut på grund av inaktivitet. Var god logga in igen.';
    $_SESSION['message_type'] = 'warning';
    header('Location: ../index.php');
    exit;
}

// Uppdatera senaste aktiviteten
$_SESSION['last_activity'] = time();

// Generera CSRF-token om den inte redan finns
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Bestäm aktiv sida
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Admin - <?= $page_title ?? 'Administration' ?></title>
    <link rel="mask-icon" href="images/safari-pinned-tab.svg" color="#007bff">
    <meta name="msapplication-TileColor" content="#007bff">
    <meta name="theme-color" content="#007bff">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="include/css/style.css">
    
    <!-- CSRF Token för AJAX-anrop -->
    <script>
        const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    </script>
</head>
<body>
    <div class="sidebar d-flex flex-column h-100">
        <div class="px-3 mb-4 text-center">
            <h3 class="text-white"><img src="../images/stimma-logo.png" alt="Stimma" class="me-2" height="75"></h3>
        </div>
        <div class="d-flex flex-column h-100">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a href="index.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'index.php' ? 'active' : '' ?>">
                        <i class="bi bi-graph-up me-2"></i> Översikt
                    </a>
                </li>
                <li class="nav-item">
                    <a href="courses.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= ($current_page === 'courses.php' || $current_page === 'lessons.php') ? 'active' : '' ?>">
                        <i class="bi bi-journal-text me-2"></i> Kurser
                    </a>
                </li>
                <li class="nav-item">
                    <a href="copy_course.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'copy_course.php' ? 'active' : '' ?>">
                        <i class="bi bi-copy me-2"></i> Kopiera kurs
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tags.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'tags.php' ? 'active' : '' ?>">
                        <i class="bi bi-tags me-2"></i> Taggar
                    </a>
                </li>
                <?php if ($isAdmin || $isEditor): ?>
                <li class="nav-item">
                    <a href="statistics.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'statistics.php' ? 'active' : '' ?>">
                        <i class="bi bi-bar-chart me-2"></i> Statistik
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($isAdmin || $isSuperAdmin): ?>
                <li class="nav-item">
                    <a href="reminders.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'reminders.php' ? 'active' : '' ?>">
                        <i class="bi bi-bell me-2"></i> Påminnelser
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($isAdmin || $isSuperAdmin): ?>
                <li class="nav-item">
                    <a href="users.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'users.php' ? 'active' : '' ?>">
                        <i class="bi bi-people me-2"></i> Användare
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($isSuperAdmin): ?>
                <li class="nav-item">
                    <a href="logs.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'logs.php' ? 'active' : '' ?>">
                        <i class="bi bi-clipboard-data me-2"></i> Loggar
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($isSuperAdmin): ?>
                <li class="nav-item">
                    <a href="domains.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'domains.php' ? 'active' : '' ?>">
                        <i class="bi bi-globe me-2"></i> Domäner
                    </a>
                </li>
                <li class="nav-item">
                    <a href="ai_settings.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'ai_settings.php' ? 'active' : '' ?>">
                        <i class="bi bi-robot me-2"></i> AI-inställningar
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="nav flex-column mt-auto">
                <li class="nav-item">
                    <a href="user_guide.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'user_guide.php' ? 'active' : '' ?>">
                        <i class="bi bi-book me-2"></i> Handbok
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../index.php" class="nav-link text-white px-3 py-2 d-flex align-items-center">
                        <i class="bi bi-house-gear me-2"></i> Användarvyn
                    </a>
                </li>
                <li class="nav-item">
                    <div class="nav-link text-white px-3 py-2 d-flex align-items-center">
                        <i class="bi bi-person me-2"></i>
                        <?= $_SESSION['user_email'] ?>
                    </div>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link text-white px-3 py-2 d-flex align-items-center mb-3">
                        <i class="bi bi-box-arrow-right me-2"></i> Logga ut
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <nav class="navbar navbar-expand-lg navbar-light bg-white py-3">
            <div class="container-fluid px-4">
                <h5 class="mb-0"><?= $page_title ?? 'Administration' ?></h5>
            </div>
        </nav>

        <div class="container-fluid px-4 py-4">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?= $_SESSION['message_type'] ?? 'info' ?>">
                    <?= $_SESSION['message'] ?>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
