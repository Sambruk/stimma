<?php
/**
 * Stimma - Organisationens varumärke (ikon + headertext)
 *
 * Tillgänglig för admins. Laddar upp en ikon OCH sätter headertexten för den
 * organisation som användarens domän tillhör. Samma inställningar används för
 * alla domäner som är grupperade i organisationen och visas i top-nav för alla
 * användare (inklusive publika deltagare i kurser som tillhör organisationen).
 *
 * Om domänen inte är grupperad i en organisation sparas headertexten istället
 * på domain_settings för domänen (ikon kräver dock en organisation).
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once 'include/auth_check.php';

$currentUser = queryOne(
    "SELECT id, email, role, is_admin FROM " . DB_DATABASE . ".users WHERE id = ?",
    [$_SESSION['user_id']]
);
$isSuper = ($currentUser['role'] ?? '') === 'super_admin';
if (!$isSuper && empty($currentUser['is_admin'])) {
    $_SESSION['message'] = 'Endast administratörer kan hantera organisationsikonen.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$userDomain = getUserDomain($currentUser['email']);
$organization = getOrganizationByDomain($userDomain);

$uploadError = null;
$uploadSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $uploadError = 'Ogiltig säkerhetstoken. Försök igen.';
    } elseif (($_POST['action'] ?? '') === 'save_header') {
        // Spara headertext. Fungerar även utan organisation — då lagras
        // texten på domain_settings för den aktuella domänen.
        $headerText = trim($_POST['header_text'] ?? '');
        if (mb_strlen($headerText) > 500) {
            $uploadError = 'Texten är för lång (max 500 tecken).';
        } else {
            $storeValue = $headerText !== '' ? $headerText : null;
            if ($organization) {
                execute(
                    "UPDATE " . DB_DATABASE . ".organizations SET header_text = ? WHERE id = ?",
                    [$storeValue, $organization['id']]
                );
                $organization['header_text'] = $storeValue;
            } else {
                // Sätt på domain_settings istället
                $existing = queryOne(
                    "SELECT id FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?",
                    [$userDomain]
                );
                if ($existing) {
                    execute(
                        "UPDATE " . DB_DATABASE . ".domain_settings SET header_text = ? WHERE domain = ?",
                        [$storeValue, $userDomain]
                    );
                } else {
                    execute(
                        "INSERT INTO " . DB_DATABASE . ".domain_settings (domain, header_text) VALUES (?, ?)",
                        [$userDomain, $storeValue]
                    );
                }
            }
            $uploadSuccess = $storeValue === null ? 'Headertexten återställd till standard.' : 'Headertexten är sparad.';
        }
    } elseif (!$organization) {
        $uploadError = 'Din domän är inte grupperad i en organisation. Kontakta superadmin för att skapa en organisation först.';
    } elseif (($_POST['action'] ?? '') === 'remove') {
        // Radera befintlig ikon
        if (!empty($organization['icon_url'])) {
            $oldPath = __DIR__ . '/../upload/org_icons/' . basename($organization['icon_url']);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
            execute(
                "UPDATE " . DB_DATABASE . ".organizations SET icon_url = NULL WHERE id = ?",
                [$organization['id']]
            );
        }
        $uploadSuccess = 'Ikonen är borttagen.';
        $organization = getOrganizationByDomain($userDomain); // refresh
    } elseif (isset($_FILES['icon']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['icon'];
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        $maxFileSize = 2 * 1024 * 1024; // 2 MB

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $uploadError = 'Ogiltigt format. Tillåtna: PNG, JPG, SVG, WebP.';
        } elseif ($file['size'] > $maxFileSize) {
            $uploadError = 'Filen är för stor. Max 2 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            $allowedMime = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp', 'text/plain', 'text/xml', 'application/xml'];
            if (!in_array($mimeType, $allowedMime, true)) {
                $uploadError = 'Ogiltig filtyp (' . htmlspecialchars($mimeType) . ').';
            } else {
                // Om SVG: grundläggande sanering — blockera <script>-taggar
                if ($extension === 'svg') {
                    $svgContent = file_get_contents($file['tmp_name']);
                    if (stripos($svgContent, '<script') !== false || stripos($svgContent, 'onload') !== false || stripos($svgContent, 'javascript:') !== false) {
                        $uploadError = 'SVG-filen innehåller potentiellt skadligt innehåll.';
                    }
                }

                if (!$uploadError) {
                    // Radera gammal fil innan vi sparar den nya
                    if (!empty($organization['icon_url'])) {
                        $oldPath = __DIR__ . '/../upload/org_icons/' . basename($organization['icon_url']);
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }

                    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                    $targetPath = __DIR__ . '/../upload/org_icons/' . $filename;
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        chmod($targetPath, 0644);
                        execute(
                            "UPDATE " . DB_DATABASE . ".organizations SET icon_url = ? WHERE id = ?",
                            [$filename, $organization['id']]
                        );
                        logActivity($_SESSION['user_email'], 'Uppdaterade organisationsikon för ' . $organization['name'] . ' (filename: ' . $filename . ')');
                        $uploadSuccess = 'Ikonen är uppdaterad.';
                        $organization = getOrganizationByDomain($userDomain); // refresh
                    } else {
                        $uploadError = 'Kunde inte spara filen.';
                    }
                }
            }
        }
    } else {
        $uploadError = 'Ingen fil vald eller uppladdningsfel.';
    }
}

$page_title = 'Organisationens varumärke';

// Hämta nuvarande headertext (org först, annars domain_settings)
$currentHeaderText = '';
if ($organization && !empty($organization['header_text'])) {
    $currentHeaderText = $organization['header_text'];
} else {
    $ds = queryOne(
        "SELECT header_text FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?",
        [$userDomain]
    );
    if ($ds && !empty($ds['header_text'])) {
        $currentHeaderText = $ds['header_text'];
    }
}
require_once 'include/header.php';
?>

<div class="container py-4">
    <?php if ($uploadSuccess): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($uploadSuccess) ?></div>
    <?php endif; ?>
    <?php if ($uploadError): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($uploadError) ?></div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">

            <!-- Headertext -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-type me-2 text-primary"></i>Headertext</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Ersätter default-texten <em>"Stimma - en utbildningsplattform från Sambruk"</em> som visas mitt i top-nav:en.
                        <?php if ($organization): ?>
                        Gäller alla domäner i <strong><?= htmlspecialchars($organization['name']) ?></strong>.
                        <?php else: ?>
                        Gäller domänen <strong><?= htmlspecialchars($userDomain) ?></strong>.
                        <?php endif; ?>
                    </p>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="save_header">
                        <div class="mb-3">
                            <label for="header_text" class="form-label">Egen headertext</label>
                            <input type="text" class="form-control" id="header_text" name="header_text"
                                   maxlength="500"
                                   placeholder="Lämna tom för standardtext"
                                   value="<?= htmlspecialchars($currentHeaderText) ?>">
                            <div class="form-text">
                                Tillåtna platshållare (substitueras automatiskt vid visning):
                                <code>{{domain}}</code> = användarens e-postdomän,
                                <code>{{organization}}</code> = organisationens namn,
                                <code>{{date}}</code> = dagens datum (t.ex. "22 april 2026").
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>Spara headertext
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('header_text').value=''; this.form.submit();">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Återställ till standard
                            </button>
                        </div>
                    </form>

                    <div class="mt-3 p-3 bg-light rounded border">
                        <small class="text-muted d-block mb-1">Exempel:</small>
                        <ul class="small mb-0">
                            <li><code>Utbildningsportal för {{organization}}</code> → <em>Utbildningsportal för Säters kommun</em></li>
                            <li><code>Stimma – {{domain}} – {{date}}</code> → <em>Stimma – sater.se – 22 april 2026</em></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Organisationsikon -->
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-image me-2 text-primary"></i>Organisationsikon</h5>
                </div>
                <div class="card-body">

                    <?php if (!$organization): ?>
                        <div class="alert alert-warning mb-0">
                            <strong>Din domän (<?= htmlspecialchars($userDomain) ?>) är inte grupperad i en organisation.</strong><br>
                            Kontakta en superadmin för att skapa en organisation och gruppera domänen — då kan du ladda upp en ikon för den.
                        </div>
                    <?php else: ?>
                        <p class="text-muted">
                            Ikonen visas i top-nav:en för alla användare vars e-postdomän tillhör
                            <strong><?= htmlspecialchars($organization['name']) ?></strong>,
                            samt för externa deltagare i publika kurser som publicerats av organisationen.
                        </p>

                        <div class="row align-items-center mb-4">
                            <div class="col-md-4 text-center">
                                <div class="p-3 bg-light rounded border" style="min-height: 120px; display: flex; align-items: center; justify-content: center;">
                                    <?php if (!empty($organization['icon_url'])): ?>
                                        <img src="../upload/org_icons/<?= htmlspecialchars($organization['icon_url']) ?>"
                                             alt="<?= htmlspecialchars($organization['name']) ?>"
                                             style="max-width: 100%; max-height: 90px;">
                                    <?php else: ?>
                                        <span class="text-muted"><i class="bi bi-image" style="font-size: 2rem;"></i><br><small>Ingen ikon uppladdad</small></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                    <div class="mb-3">
                                        <label for="icon" class="form-label">Välj ny ikon</label>
                                        <input type="file" class="form-control" id="icon" name="icon"
                                               accept="image/png,image/jpeg,image/svg+xml,image/webp" required>
                                        <div class="form-text">PNG, JPG, SVG eller WebP. Max 2 MB. Rekommenderad storlek: minst 120×120 px. SVG är bäst för skarp skalning.</div>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-cloud-upload me-1"></i>Ladda upp
                                    </button>
                                </form>

                                <?php if (!empty($organization['icon_url'])): ?>
                                <form method="post" class="d-inline mt-2" onsubmit="return confirm('Ta bort ikonen?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        <i class="bi bi-trash me-1"></i>Ta bort nuvarande ikon
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-muted">Domäner som delar denna ikon</h6>
                        <?php
                        $domains = query(
                            "SELECT domain, is_primary FROM " . DB_DATABASE . ".organization_domains WHERE organization_id = ? ORDER BY is_primary DESC, domain ASC",
                            [$organization['id']]
                        );
                        ?>
                        <?php if (empty($domains)): ?>
                            <p class="text-muted small">Inga domäner är tilldelade den här organisationen ännu.</p>
                        <?php else: ?>
                            <ul class="list-unstyled small mb-0">
                                <?php foreach ($domains as $d): ?>
                                <li>
                                    <i class="bi bi-dot"></i><?= htmlspecialchars($d['domain']) ?>
                                    <?php if (!empty($d['is_primary'])): ?>
                                        <span class="badge bg-primary ms-1" style="font-size: 0.7em;">Primär</span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
