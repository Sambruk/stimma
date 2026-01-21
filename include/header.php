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
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div>
                    <strong>Din domän (organisation) har inte tecknat ett PUB-avtal med Sambruk ännu.</strong>
                    Stimma får därför bara nyttjas för att skapa utbildningar och att testa dem. Om Stimma ska nyttjas av er organisation för att medarbetare ska genomföra utbildningar så måste ett PUB-avtal tecknas. Kontakta <a href="mailto:hjalp@sambruksupport.se" class="alert-link">hjalp@sambruksupport.se</a>.
                </div>
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
                    <!-- User email display with truncation for long addresses -->
                    <div class="btn btn-text text-muted p-1 d-inline-flex align-items-center justify-content-center" 
                         title="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>"
                         aria-label="Your email address">
                        <?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>
                    </div>
                    
                    <!-- Admin and logout buttons -->
                    <?php 
                    // Check if user has admin privileges or is a course editor
                    $isAdmin = false;
                    $isCourseEditor = false;
                    
                    if (isset($_SESSION['user_id'])) {
                        // Check admin status
                        $user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
                        $isAdmin = $user ? (bool)$user['is_admin'] : false;
                        $isCourseEditor = $user ? (bool)$user['is_editor'] : false;
                    }
                    
                    ?>
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
                       title="Mina certifikat"
                       aria-label="Certifikat">
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
<?php endif; ?>
