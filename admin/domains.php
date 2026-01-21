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

// Kontrollera att användaren är inloggad och är admin
require_once 'include/auth_check.php';

// Kontrollera att användaren är super_admin
$currentUser = queryOne("SELECT role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    $_SESSION['message'] = 'Du har inte behörighet att komma åt denna sida. Endast superadministratörer kan hantera vitlistade domäner.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Sökväg till domänfilen
$domainsFile = __DIR__ . '/../allowed_domains.txt';

// Sätt sidtitel
$page_title = 'Vitlistade domäner';

// Hantera formulärinlämning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: domains.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        // Lägg till ny domän
        $newDomain = trim(strtolower($_POST['domain'] ?? ''));

        // Validera domänformat
        if (empty($newDomain)) {
            $_SESSION['message'] = 'Domännamn kan inte vara tomt.';
            $_SESSION['message_type'] = 'danger';
        } elseif (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $newDomain)) {
            $_SESSION['message'] = 'Ogiltigt domänformat. Ange en giltig domän (t.ex. example.se).';
            $_SESSION['message_type'] = 'danger';
        } else {
            // Läs befintliga domäner
            $domains = getDomainsList($domainsFile);

            if (in_array($newDomain, $domains)) {
                $_SESSION['message'] = 'Domänen finns redan i listan.';
                $_SESSION['message_type'] = 'warning';
            } else {
                // Lägg till domänen
                $domains[] = $newDomain;
                sort($domains);

                if (saveDomainsList($domainsFile, $domains)) {
                    $_SESSION['message'] = "Domänen <strong>{$newDomain}</strong> har lagts till.";
                    $_SESSION['message_type'] = 'success';

                    // Logga aktiviteten
                    logActivity($_SESSION['user_email'], "Lade till domän: {$newDomain}", [
                        'action' => 'domain_add',
                        'domain' => $newDomain
                    ]);
                } else {
                    $_SESSION['message'] = 'Kunde inte spara domänlistan. Kontrollera filrättigheter.';
                    $_SESSION['message_type'] = 'danger';
                }
            }
        }
    } elseif ($action === 'update_pub') {
        // Uppdatera PUB-avtalsstatus
        $domainToUpdate = trim($_POST['domain'] ?? '');
        $hasPub = isset($_POST['has_pub']) && $_POST['has_pub'] === '1';
        $pubDate = !empty($_POST['pub_date']) ? $_POST['pub_date'] : null;
        $pubNotes = trim($_POST['pub_notes'] ?? '');

        if (!empty($domainToUpdate)) {
            if (updatePubAgreement($domainToUpdate, $hasPub, $pubDate, $pubNotes)) {
                $status = $hasPub ? 'aktiverat' : 'inaktiverat';
                $_SESSION['message'] = "PUB-avtal för <strong>{$domainToUpdate}</strong> har {$status}.";
                $_SESSION['message_type'] = 'success';

                logActivity($_SESSION['user_email'], "Uppdaterade PUB-avtal för domän: {$domainToUpdate} ({$status})", [
                    'action' => 'pub_agreement_update',
                    'domain' => $domainToUpdate,
                    'has_pub' => $hasPub
                ]);
            } else {
                $_SESSION['message'] = 'Kunde inte uppdatera PUB-avtalsstatus.';
                $_SESSION['message_type'] = 'danger';
            }
        }
    } elseif ($action === 'delete') {
        // Ta bort domän
        $domainToDelete = trim($_POST['domain'] ?? '');

        if (!empty($domainToDelete)) {
            $domains = getDomainsList($domainsFile);
            $key = array_search($domainToDelete, $domains);

            if ($key !== false) {
                unset($domains[$key]);

                if (saveDomainsList($domainsFile, $domains)) {
                    $_SESSION['message'] = "Domänen <strong>{$domainToDelete}</strong> har tagits bort.";
                    $_SESSION['message_type'] = 'success';

                    // Logga aktiviteten
                    logActivity($_SESSION['user_email'], "Tog bort domän: {$domainToDelete}", [
                        'action' => 'domain_delete',
                        'domain' => $domainToDelete
                    ]);
                } else {
                    $_SESSION['message'] = 'Kunde inte spara domänlistan. Kontrollera filrättigheter.';
                    $_SESSION['message_type'] = 'danger';
                }
            } else {
                $_SESSION['message'] = 'Domänen hittades inte i listan.';
                $_SESSION['message_type'] = 'warning';
            }
        }
    }

    header('Location: domains.php');
    exit;
}

/**
 * Läs domänlistan från fil
 */
function getDomainsList($file) {
    if (!file_exists($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $domains = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && $line[0] !== '#') {
            $domains[] = $line;
        }
    }

    return array_unique($domains);
}

/**
 * Spara domänlistan till fil
 */
function saveDomainsList($file, $domains) {
    $content = "# Vitlistade domäner för Stimma\n";
    $content .= "# En domän per rad. Rader som börjar med # ignoreras.\n";
    $content .= "# Uppdaterad: " . date('Y-m-d H:i:s') . "\n\n";

    $domains = array_unique(array_filter($domains));
    sort($domains);

    foreach ($domains as $domain) {
        $content .= $domain . "\n";
    }

    return file_put_contents($file, $content) !== false;
}

// Hämta aktuell domänlista
$domains = getDomainsList($domainsFile);
sort($domains);

// Hämta filter från GET-parameter
$showOnlyWithUsers = isset($_GET['filter']) && $_GET['filter'] === 'with_users';

// Hämta användarstatistik per domän
function getUserStatsByDomain() {
    $stats = [];

    // Hämta alla användare grupperat per domän
    $result = query("
        SELECT
            LOWER(SUBSTRING_INDEX(email, '@', -1)) as domain,
            COUNT(*) as total,
            SUM(CASE WHEN is_admin = 1 OR role IN ('admin', 'super_admin') THEN 1 ELSE 0 END) as admins,
            SUM(CASE WHEN is_editor = 1 AND is_admin = 0 AND role NOT IN ('admin', 'super_admin') THEN 1 ELSE 0 END) as editors,
            SUM(CASE WHEN is_admin = 0 AND is_editor = 0 AND (role IS NULL OR role NOT IN ('admin', 'super_admin')) THEN 1 ELSE 0 END) as students
        FROM " . DB_DATABASE . ".users
        GROUP BY LOWER(SUBSTRING_INDEX(email, '@', -1))
        ORDER BY total DESC
    ", []);

    foreach ($result as $row) {
        $stats[$row['domain']] = [
            'total' => (int)$row['total'],
            'admins' => (int)$row['admins'],
            'editors' => (int)$row['editors'],
            'students' => (int)$row['students']
        ];
    }

    return $stats;
}

$userStats = getUserStatsByDomain();

// Hämta PUB-avtalsstatus för alla domäner
$pubSettings = [];
$allPubSettings = getAllDomainSettings();
foreach ($allPubSettings as $setting) {
    $pubSettings[$setting['domain']] = $setting;
}

// Filtrera domäner om filter är aktivt
$filteredDomains = $domains;
if ($showOnlyWithUsers) {
    $filteredDomains = array_filter($domains, function($domain) use ($userStats) {
        return isset($userStats[$domain]) && $userStats[$domain]['total'] > 0;
    });
}

// Sortera domäner efter antal användare (flest först)
usort($filteredDomains, function($a, $b) use ($userStats) {
    $countA = isset($userStats[$a]) ? $userStats[$a]['total'] : 0;
    $countB = isset($userStats[$b]) ? $userStats[$b]['total'] : 0;
    if ($countB !== $countA) {
        return $countB - $countA; // Flest användare först
    }
    return strcmp($a, $b); // Alfabetiskt vid lika antal
});

// Räkna domäner med användare
$domainsWithUsers = count(array_filter($domains, function($domain) use ($userStats) {
    return isset($userStats[$domain]) && $userStats[$domain]['total'] > 0;
}));

// Inkludera header
require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-globe me-2"></i>Vitlistade domäner
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Information:</strong> Endast användare med e-postadresser från dessa domäner kan registrera sig och logga in på plattformen.
                </div>

                <!-- Lägg till ny domän -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h6 class="m-0"><i class="bi bi-plus-circle me-2"></i>Lägg till domän</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="domains.php" class="row g-3 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="add">
                            <div class="col-md-8">
                                <label for="domain" class="form-label">Domännamn</label>
                                <input type="text" class="form-control" id="domain" name="domain"
                                       placeholder="example.se" pattern="[a-zA-Z0-9][a-zA-Z0-9\-\.]*[a-zA-Z0-9]" required>
                                <div class="form-text">Ange domännamnet utan @ eller e-postadress (t.ex. kommun.se)</div>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-plus-lg me-2"></i>Lägg till
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista över domäner -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0">
                            <i class="bi bi-list-ul me-2"></i>Registrerade domäner
                            <span class="badge bg-secondary ms-2"><?= count($filteredDomains) ?> av <?= count($domains) ?></span>
                        </h6>
                        <!-- Filter -->
                        <div class="btn-group" role="group">
                            <a href="domains.php" class="btn btn-sm <?= !$showOnlyWithUsers ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-list me-1"></i>Alla (<?= count($domains) ?>)
                            </a>
                            <a href="domains.php?filter=with_users" class="btn btn-sm <?= $showOnlyWithUsers ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-people me-1"></i>Med användare (<?= $domainsWithUsers ?>)
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($filteredDomains)): ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <?php if ($showOnlyWithUsers): ?>
                                    Inga domäner med registrerade användare hittades.
                                    <a href="domains.php" class="alert-link">Visa alla domäner</a>
                                <?php else: ?>
                                    Inga domäner är vitlistade. Lägg till minst en domän för att tillåta användare att registrera sig.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Domän</th>
                                            <th class="text-center" style="width: 100px;" title="PUB-avtal tecknat">
                                                <i class="bi bi-file-earmark-check text-success"></i> PUB
                                            </th>
                                            <th class="text-center" style="width: 80px;" title="Administratörer">
                                                <i class="bi bi-shield-fill text-danger"></i> Admin
                                            </th>
                                            <th class="text-center" style="width: 80px;" title="Redaktörer">
                                                <i class="bi bi-pencil-fill text-warning"></i> Red.
                                            </th>
                                            <th class="text-center" style="width: 80px;" title="Studenter">
                                                <i class="bi bi-person-fill text-info"></i> Stud.
                                            </th>
                                            <th class="text-center" style="width: 80px;" title="Totalt antal användare">
                                                <i class="bi bi-people-fill text-primary"></i> Totalt
                                            </th>
                                            <th class="text-end" style="width: 120px;">Åtgärd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($filteredDomains as $domain):
                                            $stats = $userStats[$domain] ?? ['total' => 0, 'admins' => 0, 'editors' => 0, 'students' => 0];
                                            $pubInfo = $pubSettings[$domain] ?? ['has_pub_agreement' => 0, 'pub_agreement_date' => null];
                                            $hasPub = $pubInfo['has_pub_agreement'] == 1;
                                        ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-globe2 text-primary me-2"></i>
                                                    <strong><?= htmlspecialchars($domain) ?></strong>
                                                </td>
                                                <td class="text-center">
                                                    <form method="POST" action="domains.php<?= $showOnlyWithUsers ? '?filter=with_users' : '' ?>" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="update_pub">
                                                        <input type="hidden" name="domain" value="<?= htmlspecialchars($domain) ?>">
                                                        <input type="hidden" name="has_pub" value="<?= $hasPub ? '0' : '1' ?>">
                                                        <button type="submit" class="btn btn-sm <?= $hasPub ? 'btn-success' : 'btn-outline-secondary' ?>"
                                                                title="<?= $hasPub ? 'PUB-avtal tecknat' . ($pubInfo['pub_agreement_date'] ? ' (' . $pubInfo['pub_agreement_date'] . ')' : '') : 'Inget PUB-avtal' ?>">
                                                            <?php if ($hasPub): ?>
                                                                <i class="bi bi-check-circle-fill"></i>
                                                            <?php else: ?>
                                                                <i class="bi bi-x-circle"></i>
                                                            <?php endif; ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($stats['admins'] > 0): ?>
                                                        <span class="badge bg-danger"><?= $stats['admins'] ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($stats['editors'] > 0): ?>
                                                        <span class="badge bg-warning text-dark"><?= $stats['editors'] ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($stats['students'] > 0): ?>
                                                        <span class="badge bg-info"><?= $stats['students'] ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($stats['total'] > 0): ?>
                                                        <span class="badge bg-primary"><?= $stats['total'] ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <form method="POST" action="domains.php<?= $showOnlyWithUsers ? '?filter=with_users' : '' ?>" class="d-inline"
                                                          onsubmit="return confirm('Är du säker på att du vill ta bort domänen <?= htmlspecialchars($domain) ?>?<?= $stats['total'] > 0 ? ' Det finns ' . $stats['total'] . ' registrerade användare från denna domän.' : '' ?> Befintliga användare påverkas inte, men nya användare från denna domän kommer inte kunna registrera sig.');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="domain" value="<?= htmlspecialchars($domain) ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            <i class="bi bi-trash"></i> Ta bort
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <?php
                                    // Beräkna totalsummor
                                    $totalAdmins = 0;
                                    $totalEditors = 0;
                                    $totalStudents = 0;
                                    $totalUsers = 0;
                                    $totalPub = 0;
                                    foreach ($filteredDomains as $domain) {
                                        $stats = $userStats[$domain] ?? ['total' => 0, 'admins' => 0, 'editors' => 0, 'students' => 0];
                                        $totalAdmins += $stats['admins'];
                                        $totalEditors += $stats['editors'];
                                        $totalStudents += $stats['students'];
                                        $totalUsers += $stats['total'];
                                        if (isset($pubSettings[$domain]) && $pubSettings[$domain]['has_pub_agreement'] == 1) {
                                            $totalPub++;
                                        }
                                    }
                                    ?>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td>Summa (<?= count($filteredDomains) ?> domäner)</td>
                                            <td class="text-center"><?= $totalPub ?></td>
                                            <td class="text-center"><?= $totalAdmins ?></td>
                                            <td class="text-center"><?= $totalEditors ?></td>
                                            <td class="text-center"><?= $totalStudents ?></td>
                                            <td class="text-center"><?= $totalUsers ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php
// Inkludera footer
require_once 'include/footer.php';
?>
