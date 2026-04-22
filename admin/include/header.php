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

// Hämta användarens domän och PUB-avtalsstatus (org-scope om grupperad)
$userDomain = getUserDomain($_SESSION['user_email']);
$userHasPubAgreement = userHasPubAgreement($_SESSION['user_email']);

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
    <title>Admin - <?= htmlspecialchars($page_title ?? 'Administration') ?></title>
    <link rel="mask-icon" href="images/safari-pinned-tab.svg" color="#007bff">
    <meta name="msapplication-TileColor" content="#007bff">
    <meta name="theme-color" content="#007bff">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js"></script>
    
    <!-- TinyMCE Editor -->
    <script src="include/js/tinymce/tinymce.min.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="include/css/style.css">
    
    <!-- CSRF Token för AJAX-anrop -->
    <script>
        const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    </script>
</head>
<body>
    <?php if (isImpersonating()): ?>
    <div class="alert alert-danger mb-0 rounded-0 py-2" role="alert" style="position: sticky; top: 0; z-index: 1042;">
        <div class="container-fluid d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center">
                <i class="bi bi-eye-fill me-2 fs-5"></i>
                <div>
                    <strong>Du visar som <?= htmlspecialchars($_SESSION['user_email']) ?></strong>
                    <span class="d-none d-md-inline">
                        — inloggad som superadmin <?= htmlspecialchars($_SESSION['impersonator_user_email'] ?? '') ?>.
                    </span>
                </div>
            </div>
            <form method="post" action="stop_impersonate.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn btn-light btn-sm text-nowrap">
                    <i class="bi bi-box-arrow-left me-1"></i>Sluta visa som
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($isSuperAdmin && isMaintenanceModeActive()): ?>
    <!-- Underhållsläge-banner (visas endast för superadmin när läget är aktivt) -->
    <div class="alert alert-warning border-0 rounded-0 mb-0 py-2" role="alert" style="position: sticky; top: 0; z-index: 1040;">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-tools me-2"></i>
                <strong>Underhållsläge är aktivt.</strong>
                Endast superadministratörer kan använda systemet just nu.
            </div>
            <a href="<?= $current_page === 'maintenance.php' ? '#' : 'maintenance.php' ?>" class="btn btn-sm btn-outline-dark">
                <i class="bi bi-gear me-1"></i>Hantera
            </a>
        </div>
    </div>
    <?php endif; ?>
    <div class="sidebar d-flex flex-column h-100">
        <div class="px-3 mb-4 text-center">
            <h3 class="text-white"><img src="../images/stimma-logo.png" alt="Stimma" class="me-2" height="75"></h3>
        </div>
        <div class="d-flex flex-column h-100">
            <ul class="nav flex-column">
                <?php if ($isAdmin || $isEditor): ?>
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
                <li class="nav-item">
                    <a href="statistics.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'statistics.php' ? 'active' : '' ?>">
                        <i class="bi bi-bar-chart me-2"></i> Statistik
                    </a>
                </li>
                <li class="nav-item">
                    <a href="course_stats.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'course_stats.php' ? 'active' : '' ?>">
                        <i class="bi bi-diagram-3 me-2"></i> Kursstatistik
                    </a>
                </li>
                <li class="nav-item">
                    <a href="certificates.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'certificates.php' ? 'active' : '' ?>">
                        <i class="bi bi-award me-2"></i> Diplom
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
                <li class="nav-item">
                    <a href="api_keys.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'api_keys.php' ? 'active' : '' ?>">
                        <i class="bi bi-key me-2"></i> API-nycklar
                    </a>
                </li>
                <li class="nav-item">
                    <a href="sync_tool.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'sync_tool.php' ? 'active' : '' ?>">
                        <i class="bi bi-arrow-left-right me-2"></i> Synkverktyg
                    </a>
                </li>
                <li class="nav-item">
                    <a href="sync_logs.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'sync_logs.php' ? 'active' : '' ?>">
                        <i class="bi bi-arrow-repeat me-2"></i> Synkloggar
                    </a>
                </li>
                <li class="nav-item">
                    <a href="pub_documents.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'pub_documents.php' ? 'active' : '' ?>">
                        <i class="bi bi-file-earmark-lock me-2"></i> PUB-dokument
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
                    <a href="organizations.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'organizations.php' ? 'active' : '' ?>">
                        <i class="bi bi-diagram-3 me-2"></i> Organisationer
                    </a>
                </li>
                <li class="nav-item">
                    <a href="ai_settings.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'ai_settings.php' ? 'active' : '' ?>">
                        <i class="bi bi-robot me-2"></i> AI-inställningar
                    </a>
                </li>
                <li class="nav-item">
                    <a href="maintenance.php" class="nav-link text-white px-3 py-2 d-flex align-items-center <?= $current_page === 'maintenance.php' ? 'active' : '' ?>">
                        <i class="bi bi-tools me-2"></i> Underhållsläge
                        <?php if (isMaintenanceModeActive()): ?>
                        <span class="badge bg-danger ms-auto">PÅ</span>
                        <?php endif; ?>
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
                        <?= htmlspecialchars($_SESSION['user_email']) ?>
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
                <h5 class="mb-0"><?= htmlspecialchars($page_title ?? 'Administration') ?></h5>
                <div class="d-flex align-items-center">
                    <?php if ($userHasPubAgreement): ?>
                        <span class="badge bg-success" title="PUB-avtal tecknat för <?= htmlspecialchars($userDomain) ?>">
                            <i class="bi bi-file-earmark-check me-1"></i>PUB-avtal: Ja
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark" title="Inget PUB-avtal för <?= htmlspecialchars($userDomain) ?>">
                            <i class="bi bi-exclamation-triangle me-1"></i>PUB-avtal: Nej
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4 py-4">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type'] ?? 'info') ?>">
                    <?= htmlspecialchars($_SESSION['message']) ?>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
