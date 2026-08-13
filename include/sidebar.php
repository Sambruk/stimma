<?php
/**
 * Stimma redesign v3 — sidebar
 *
 * Inkluderas av include/header.php inuti <body> när användaren är inloggad
 * (och inte är public_only). Behåller befintliga JS-referenser:
 *   - #stimmaThemeToggle  (tema-växling, footer.php JS)
 *   - data-bs-target="#profilePanel"  (offcanvas profilpanel)
 *
 * Sidor sätter $rdActive = 'overview' | 'courses' | 'catalog' | 'dashboard' | 'admin'
 * INNAN include av header.php för att få aktiv-markering korrekt.
 */

$rdActive = $rdActive ?? '';

// Roll-flaggor — beräknas redan i header.php men vi backar upp här om
// någon inkluderar sidebar.php direkt.
if (!isset($isAdmin) || !isset($isCourseEditor)) {
    $isAdmin = false;
    $isCourseEditor = false;
    if (isset($_SESSION['user_id'])) {
        $sbUser = queryOne(
            "SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?",
            [$_SESSION['user_id']]
        );
        $isAdmin = $sbUser ? (bool)$sbUser['is_admin'] : false;
        $isCourseEditor = $sbUser ? (bool)$sbUser['is_editor'] : false;
    }
}
$showAdminLink = ($isAdmin || $isCourseEditor) && empty($isHeaderPublicOnly);

// Användarinitialer för avatar
$rdUserName = $headerUserName ?? '';
if (empty($rdUserName) && isset($_SESSION['user_email'])) {
    $rdUserName = $_SESSION['user_email'];
}
$rdInitials = '';
foreach (preg_split('/\s+|@/', trim($rdUserName), -1, PREG_SPLIT_NO_EMPTY) as $part) {
    $rdInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($rdInitials) >= 2) break;
}
if ($rdInitials === '') $rdInitials = '?';

// Org-namn för user-card
$rdUserDomain = isset($_SESSION['user_email'])
    ? substr(strrchr($_SESSION['user_email'], '@'), 1)
    : '';
$rdUserOrg = $rdUserDomain;
if ($rdUserDomain !== '' && function_exists('getOrganizationByDomain')) {
    $orgRow = getOrganizationByDomain($rdUserDomain);
    if ($orgRow && !empty($orgRow['name'])) $rdUserOrg = $orgRow['name'];
}
?>
<aside class="rd-sidebar" id="rdSidebar" aria-label="Huvudnavigation">
    <div class="rd-brand">
        <a href="index.php" aria-label="Hem">
            <img src="images/stimma-logo-transparent.png" alt="Stimma">
        </a>
    </div>

    <nav class="rd-nav" aria-label="Sektioner">
        <div class="rd-nav-section">Lärande</div>
        <a href="index.php" class="rd-nav-link <?= $rdActive === 'overview' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Översikt
        </a>
        <a href="index.php#mina-kurser" class="rd-nav-link <?= $rdActive === 'courses' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Mina kurser
        </a>
        <?php
        // Lärvägar visas bara för den som faktiskt har någon — annars är
        // menyposten en återvändsgränd.
        $rdShowPaths = false;
        if (!empty($_SESSION['user_id']) && empty($isHeaderPublicOnly)) {
            require_once __DIR__ . '/learning_paths.php';
            $rdShowPaths = hasVisibleLearningPaths((int)$_SESSION['user_id']);
        }
        ?>
        <?php if ($rdShowPaths): ?>
        <a href="learning_paths.php" class="rd-nav-link <?= $rdActive === 'paths' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6h9l3 3-3 3H9"/><path d="M15 12H6l-3 3 3 3h9"/><path d="M12 3v3"/><path d="M12 18v3"/></svg>
            Lärvägar
        </a>
        <?php endif; ?>
        <a href="index.php#kurskatalog" class="rd-nav-link <?= $rdActive === 'catalog' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            Katalog
        </a>

        <div class="rd-nav-section">Mig</div>
        <a href="dashboard.php" class="rd-nav-link <?= $rdActive === 'dashboard' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-5"/></svg>
            Min dashboard
        </a>

        <?php if ($showAdminLink): ?>
        <div class="rd-nav-section">System</div>
        <a href="admin/index.php" class="rd-nav-link <?= $rdActive === 'admin' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg>
            Administration
        </a>
        <?php endif; ?>
    </nav>

    <div class="rd-user-card">
        <div class="rd-user-card-row">
            <div class="rd-avatar" aria-hidden="true"><?= htmlspecialchars($rdInitials) ?></div>
            <div style="min-width: 0; flex: 1;">
                <div class="rd-user-name text-truncate" title="<?= htmlspecialchars($rdUserName) ?>">
                    <?= htmlspecialchars($rdUserName) ?>
                </div>
                <div class="rd-user-org text-truncate" title="<?= htmlspecialchars($rdUserOrg) ?>">
                    <?= htmlspecialchars($rdUserOrg) ?>
                </div>
            </div>
        </div>
        <div class="rd-user-actions">
            <button id="stimmaThemeToggle"
                    class="rd-icon-btn"
                    type="button"
                    title="Växla mellan ljust och mörkt läge"
                    aria-label="Växla tema"
                    style="width: 30px; height: 30px;">
                <i class="bi bi-moon-stars" aria-hidden="true"></i>
            </button>
            <button class="rd-icon-btn"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#profilePanel"
                    aria-controls="profilePanel"
                    title="Min profil"
                    aria-label="Min profil"
                    style="width: 30px; height: 30px;">
                <i class="bi bi-person" aria-hidden="true"></i>
            </button>
            <a href="logout.php"
               class="rd-icon-btn"
               title="Logga ut"
               aria-label="Logga ut"
               style="width: 30px; height: 30px;">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</aside>
<div class="rd-sidebar-backdrop" aria-hidden="true"></div>
