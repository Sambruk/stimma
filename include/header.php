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

    <!-- Tema-init: läs sparad temapreferens och sätt data-bs-theme INNAN
         Bootstrap CSS parsas så vi slipper "flash of light theme". -->
    <script>
    (function() {
        try {
            var saved = localStorage.getItem('stimma_theme');
            if (saved === 'dark' || (saved !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            }
        } catch (e) {}
    })();
    </script>

    <!-- Preconnect to CDN domains for better performance -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Typsnitt: Inter (sans) + Fraunces (serif) — redesign v3 -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Fraunces:opsz,wght@9..144,400;9..144,500&display=swap" rel="stylesheet">

    <!-- External CSS dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- External JavaScript dependencies -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js" defer></script>

    <!-- Custom CSS for site-specific styles -->
    <link href="include/css/style.css" rel="stylesheet">
    <link href="include/css/redesign.css" rel="stylesheet">
</head>
<body>

<?php if (isLoggedIn()): ?>
    <a href="#rdMain" class="rd-skip-link">Hoppa till innehåll</a>
    <?php if (isImpersonating()): ?>
    <?php
    // Beräkna rätt relativ sökväg till stop_impersonate.php beroende på om
    // sidan ligger i roten eller i admin/.
    $impersonateScript = basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin'
        ? 'stop_impersonate.php'
        : 'admin/stop_impersonate.php';
    ?>
    <div class="alert alert-danger mb-0 rounded-0 py-2" role="alert" style="z-index: 1032;">
        <div class="container-fluid">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <i class="bi bi-eye-fill me-2 fs-5"></i>
                    <div>
                        <strong>Du visar som <?= htmlspecialchars($_SESSION['user_email']) ?></strong>
                        <span class="d-none d-md-inline">
                            — inloggad som superadmin <?= htmlspecialchars($_SESSION['impersonator_user_email'] ?? '') ?>.
                            Allt du ser här är vad användaren ser.
                        </span>
                    </div>
                </div>
                <form method="post" action="<?= htmlspecialchars($impersonateScript) ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-light btn-sm text-nowrap">
                        <i class="bi bi-box-arrow-left me-1"></i>Sluta visa som
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php
    // Kontrollera PUB-avtalsstatus för användarens organisation (eller domän
    // om domänen inte är grupperad). Banner visas så länge varken org eller
    // domän har tecknat PUB. Publika kursdeltagare (access_mode='public_only')
    // tillhör ingen organisation och ska inte se bannern.
    $userDomainForPub = getUserDomain($_SESSION['user_email']);
    $userHasPubAgreementForBanner = userHasPubAgreement($_SESSION['user_email']);
    $headerUserOrganization = getOrganizationByDomain($userDomainForPub);
    $pubBannerUser = queryOne("SELECT is_admin, is_editor, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
    $isPubAdmin = $pubBannerUser && $pubBannerUser['is_admin'] == 1;
    $isPubEditor = $pubBannerUser && $pubBannerUser['is_editor'] == 1;
    $isHeaderPublicOnly = $pubBannerUser && ($pubBannerUser['access_mode'] ?? 'domain') === 'public_only';

    // Informationsmeddelande från superadmin (modal-popup) — bara admin/redaktör.
    if ($isPubAdmin || $isPubEditor) {
        require_once __DIR__ . '/announcements.php';
        renderAnnouncementModal((int)$_SESSION['user_id'], generateCsrfToken());
    }
    ?>
    <?php if (!$userHasPubAgreementForBanner && !$isHeaderPublicOnly): ?>
    <!-- PUB-avtalsvarning -->
    <div class="alert alert-<?= $isPubAdmin ? 'danger' : 'warning' ?> mb-0 rounded-0 py-2" role="alert" style="z-index: 1031;">
        <div class="container-fluid">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                    <div>
                        <strong>Er organisation har inte tecknat ett PUB-avtal med Sambruk ännu.</strong>
                        <?php if ($isPubAdmin): ?>
                        Stimma får därför bara nyttjas för att skapa utbildningar och att testa dem.
                        <?php else: ?>
                        Kontakta er organisations administratör för att teckna avtalet.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-2 ms-3 flex-shrink-0">
                    <?php if ($isPubAdmin): ?>
                    <a href="pub_agreement.php" class="btn btn-light btn-sm text-nowrap">
                        <i class="bi bi-pen me-1"></i>Teckna PUB-avtal
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-light btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#pubInfoModal">
                        <i class="bi bi-info-circle me-1"></i>Information om att teckna PUB-avtal
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- PUB-informationsmodal -->
    <div class="modal fade" id="pubInfoModal" tabindex="-1" aria-labelledby="pubInfoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="pubInfoModalLabel"><i class="bi bi-file-earmark-lock me-2"></i>Om PUB-avtal</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Stäng"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold">Vad är ett PUB-avtal?</h6>
                    <p>Ett personuppgiftsbiträdesavtal (PUB-avtal) reglerar hur personuppgifter hanteras mellan er organisation och Sambruk. Avtalet krävs enligt GDPR för att Stimma ska få användas fullt ut.</p>

                    <h6 class="fw-bold mt-3">Hur tecknas avtalet?</h6>
                    <ol>
                        <li>En <strong>administratör</strong> för er organisation loggar in i Stimma.</li>
                        <li>Administratören klickar på <strong>"Teckna PUB-avtal"</strong> i varningsrutan som visas överst på sidan.</li>
                        <li>PUB-avtalet granskas genom att öppna PDF-dokumentet.</li>
                        <li>Administratören verifierar sin identitet via <strong>SMS-kod</strong>.</li>
                        <li>Uppgifter om organisation och undertecknare fylls i och avtalet tecknas digitalt.</li>
                    </ol>

                    <h6 class="fw-bold mt-3">Vem kan teckna avtalet?</h6>
                    <p class="mb-0">Endast användare med <strong>administratörsrollen</strong> kan teckna PUB-avtalet. Om du inte är administratör, kontakta den person i er organisation som ansvarar för Stimma.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Stäng</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php
    // Roll-flaggor och user-data — användbart för sidebar.php och org-bar
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

    $userDomain = '';
    if (isset($_SESSION['user_email'])) {
        $emailParts = explode('@', $_SESSION['user_email']);
        $userDomain = isset($emailParts[1]) ? $emailParts[1] : '';
    }

    // Org-data till org-bar (steg 3) — slå upp organisation om domänen tillhör en
    $headerOrganization = null;
    if ($userDomain !== '' && function_exists('getOrganizationByDomain')) {
        $headerOrganization = getOrganizationByDomain($userDomain);
    }
    $headerOrgIcon = getHeaderOrganizationIcon($_SESSION['user_id']);
    $headerText = getHeaderText($_SESSION['user_id']);
    ?>

    <!-- App-shell: sidebar (vänster) + main (höger). Strukturen stängs i footer.php.
         Outer-wrappern är <div> (inte <main>) eftersom enskilda sidor redan har
         egna <main>-element — vi vill inte få nested main. -->
    <div class="rd-shell">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="rd-main" id="rdMain">

            <!-- Mobil-only hamburger för sidebar — visas via CSS @media -->
            <button type="button" class="rd-sidebar-toggle d-md-none" id="rdSidebarToggle"
                    aria-label="Öppna meny" aria-expanded="false" aria-controls="rdSidebar">
                <i class="bi bi-list" aria-hidden="true"></i>
                <span>Meny</span>
            </button>

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
                <?php if ($headerUserOrganization): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span class="text-muted">Organisation</span>
                    <span class="fw-semibold"><?= htmlspecialchars($headerUserOrganization['name']) ?></span>
                </li>
                <?php endif; ?>
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
                        <span class="badge bg-secondary">Användare</span>
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
