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
                    <div class="card-header">
                        <h6 class="m-0">
                            <i class="bi bi-list-ul me-2"></i>Registrerade domäner
                            <span class="badge bg-secondary ms-2"><?= count($domains) ?></span>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($domains)): ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Inga domäner är vitlistade. Lägg till minst en domän för att tillåta användare att registrera sig.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Domän</th>
                                            <th class="text-end" style="width: 120px;">Åtgärd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($domains as $domain): ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-globe2 text-primary me-2"></i>
                                                    <strong><?= htmlspecialchars($domain) ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <form method="POST" action="domains.php" class="d-inline"
                                                          onsubmit="return confirm('Är du säker på att du vill ta bort domänen <?= htmlspecialchars($domain) ?>? Befintliga användare påverkas inte, men nya användare från denna domän kommer inte kunna registrera sig.');">
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
