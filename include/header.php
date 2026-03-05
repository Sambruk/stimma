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
 * Header template file
 * 
 * This file contains the common header elements for all pages including:
 * - HTML head section with meta tags and required CSS/JS
 * - Navigation bar for logged-in users
 * - User information and admin controls
 * 
 * Required variables:
 * - $page_title: Page specific title
 * - SITE_NAME: Global site name constant

 */

// Start of HTML document with Swedish language setting
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <!-- Basic meta tags for character encoding and responsive design -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title ?? SITE_NAME) ?></title>
    
    <!-- Preconnect to CDN domains for better performance -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <!-- External CSS dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- External JavaScript dependencies -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js" defer></script>
    
    <!-- Custom CSS for site-specific styles -->
    <link href="include/css/style.css" rel="stylesheet">
</head>
<body>

<?php if (isLoggedIn()): ?>
    <?php
    // Kontrollera PUB-avtalsstatus för användarens domän
    $userDomainForPub = getUserDomain($_SESSION['user_email']);
    $userHasPubAgreementForBanner = hasPubAgreement($userDomainForPub);
    ?>
    <?php if (!$userHasPubAgreementForBanner): ?>
    <!-- PUB-avtalsvarning -->
    <div class="alert alert-danger mb-0 rounded-0 py-2" role="alert" style="z-index: 1031;">
        <div class="container-fluid">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                    <div>
                        <strong>Er organisation har inte tecknat ett PUB-avtal med Sambruk ännu.</strong>
                        Stimma får därför bara nyttjas för att skapa utbildningar och att testa dem.
                    </div>
                </div>
                <a href="pub_agreement.php" class="btn btn-light btn-sm ms-3 text-nowrap">
                    <i class="bi bi-pen me-1"></i>Teckna PUB-avtal
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Navigation bar for logged-in users with responsive design -->
    <nav class="navbar navbar-expand-sm navbar-light bg-white shadow-sm sticky-top" role="navigation" aria-label="Main navigation" style="z-index: 1030;">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center w-100">
                <!-- Logo section with link to homepage -->
                <h1 class="h3 mb-0">
                    <a href="index.php" aria-label="Hem">
                        <img src="images/stimma-logo.png" height="80px" alt="<?= htmlspecialchars(SITE_NAME) ?>">
                    </a>
                </h1>

                <!-- Centered title with domain -->
                <?php
                $userDomain = '';
                if (isset($_SESSION['user_email'])) {
                    $emailParts = explode('@', $_SESSION['user_email']);
                    $userDomain = isset($emailParts[1]) ? $emailParts[1] : '';
                }
                ?>
                <div class="d-none d-md-block text-center">
                    <span class="fw-bold text-dark" style="font-size: 1.4rem; letter-spacing: 0.5px;">
                        Stimma - en nanolearningsplattform för <span class="text-primary"><?= htmlspecialchars($userDomain) ?></span>
                    </span>
                </div>

                <!-- Right side section with user info and action buttons -->
                <div class="d-flex align-items-center">
                    <?php
                    // Check if user has admin privileges or is a course editor
                    $isAdmin = false;
                    $isCourseEditor = false;
                    $headerUserName = '';
                    $headerUserRole = 'student';
                    $headerUserOrgTags = [];

                    if (isset($_SESSION['user_id'])) {
                        $user = queryOne("SELECT name, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
                        $isAdmin = $user ? (bool)$user['is_admin'] : false;
                        $isCourseEditor = $user ? (bool)$user['is_editor'] : false;
                        $headerUserName = $user['name'] ?? '';
                        if ($isAdmin) {
                            $headerUserRole = 'admin';
                        } elseif ($isCourseEditor) {
                            $headerUserRole = 'editor';
                        }
                        $headerUserOrgTags = getUserOrgTags($_SESSION['user_id']);
                    }
                    ?>
                    <!-- Profile button -->
                    <button class="btn btn-link text-muted p-1 d-inline-flex align-items-center justify-content-center text-decoration-none"
                            type="button" data-bs-toggle="offcanvas" data-bs-target="#profilePanel"
                            aria-controls="profilePanel"
                            title="Min profil">
                        <i class="bi bi-person-circle me-1" style="font-size: 1.2rem;"></i>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($headerUserName ?: ($_SESSION['user_email'] ?? '')) ?></span>
                    </button>
                    <!-- Dashboard link -->
                    <a href="dashboard.php"
                       class="btn btn-link p-1 me-2 d-inline-flex align-items-center justify-content-center"
                       title="Min dashboard"
                       aria-label="Dashboard">
                        <i class="bi bi-speedometer2 text-dark" aria-hidden="true"></i>
                    </a>
                    <!-- Certificates link -->
                    <a href="certificate.php"
                       class="btn btn-link p-1 me-2 d-inline-flex align-items-center justify-content-center"
                       title="Mina diplom"
                       aria-label="Diplom">
                        <i class="bi bi-award text-dark" aria-hidden="true"></i>
                    </a>
                    <?php if ($isAdmin || $isCourseEditor): ?>
                        <!-- Admin panel link (hidden on small screens) -->
                        <a href="admin/index.php"
                           class="btn btn-link p-1 me-2 d-inline-flex align-items-center justify-content-center d-none d-sm-inline-flex"
                           title="Administrera"
                           aria-label="Administration panel">
                            <i class="bi bi-gear text-dark" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                    <!-- Logout button -->
                    <a href="logout.php" 
                       class="btn btn-link p-1 d-inline-flex align-items-center justify-content-center" 
                       title="Logga ut"
                       aria-label="Log out">
                        <i class="bi bi-box-arrow-right text-dark" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Profile Offcanvas Panel -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="profilePanel" aria-labelledby="profilePanelLabel">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title" id="profilePanelLabel">Min profil</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Stäng"></button>
        </div>
        <div class="offcanvas-body">
            <div class="text-center mb-4">
                <?php
                $initial = mb_strtoupper(mb_substr($headerUserName ?: ($_SESSION['user_email'] ?? '?'), 0, 1));
                ?>
                <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 1.8rem;">
                    <?= htmlspecialchars($initial) ?>
                </div>
                <?php if ($headerUserName): ?>
                <h5 class="mb-1"><?= htmlspecialchars($headerUserName) ?></h5>
                <?php endif; ?>
                <p class="text-muted mb-0"><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?></p>
            </div>

            <ul class="list-group list-group-flush mb-3">
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span class="text-muted">Domän</span>
                    <span class="fw-semibold"><?= htmlspecialchars($userDomain) ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span class="text-muted">Roll</span>
                    <?php if ($headerUserRole === 'admin'): ?>
                        <span class="badge bg-danger">Admin</span>
                    <?php elseif ($headerUserRole === 'editor'): ?>
                        <span class="badge bg-warning text-dark">Redaktör</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Student</span>
                    <?php endif; ?>
                </li>
            </ul>

            <?php if (!empty($headerUserOrgTags)): ?>
            <div class="mb-3">
                <h6 class="text-muted mb-2">Organisationstaggar</h6>
                <div>
                    <?php foreach ($headerUserOrgTags as $orgTag): ?>
                    <span class="badge bg-success me-1 mb-1"><?= htmlspecialchars($orgTag['tag']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
