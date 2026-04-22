<?php
/**
 * Stimma - Organisationshantering (superadmin)
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Skapa organisationer och gruppera flera e-postdomäner till samma organisation.
 * Domäner som inte tillhör någon organisation behandlas som tidigare (en implicit
 * single-domain "org"). När en domän tilldelas en organisation expanderas alla
 * org-scope-filter (admin/index.php, admin/users.php, admin/courses.php osv.) så
 * att kurser, användare, statistik och PUB-avtal delas över alla orgens domäner.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

require_once 'include/auth_check.php';

// Endast superadmin
$currentUser = queryOne("SELECT role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    $_SESSION['message'] = 'Du har inte behörighet. Endast superadministratörer kan hantera organisationer.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$page_title = 'Organisationer';

// Hantera POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: organizations.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $orgNumber = trim($_POST['org_number'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');

        if ($name === '') {
            $_SESSION['message'] = 'Organisationsnamn måste anges.';
            $_SESSION['message_type'] = 'danger';
        } elseif ($orgNumber !== '' && !preg_match('/^\d{6}-\d{4}$/', $orgNumber)) {
            $_SESSION['message'] = 'Organisationsnummer måste vara i format NNNNNN-NNNN.';
            $_SESSION['message_type'] = 'danger';
        } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['message'] = 'Kontakt-e-postadressen är ogiltig.';
            $_SESSION['message_type'] = 'danger';
        } else {
            $orgId = createOrganization($name, $orgNumber ?: null, $contactEmail ?: null);
            if ($orgId) {
                $_SESSION['message'] = "Organisationen \"{$name}\" har skapats.";
                $_SESSION['message_type'] = 'success';
                logActivity($_SESSION['user_email'], "Skapade organisation: {$name}", [
                    'action' => 'organization_create',
                    'organization_id' => $orgId,
                ]);
            } else {
                $_SESSION['message'] = 'Kunde inte skapa organisationen (är org-numret unikt?).';
                $_SESSION['message_type'] = 'danger';
            }
        }
    } elseif ($action === 'update') {
        $orgId = (int)($_POST['organization_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $orgNumber = trim($_POST['org_number'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');

        if ($orgId <= 0 || $name === '') {
            $_SESSION['message'] = 'Ogiltiga uppgifter.';
            $_SESSION['message_type'] = 'danger';
        } elseif ($orgNumber !== '' && !preg_match('/^\d{6}-\d{4}$/', $orgNumber)) {
            $_SESSION['message'] = 'Organisationsnummer måste vara i format NNNNNN-NNNN.';
            $_SESSION['message_type'] = 'danger';
        } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['message'] = 'Kontakt-e-postadressen är ogiltig.';
            $_SESSION['message_type'] = 'danger';
        } else {
            if (updateOrganization($orgId, $name, $orgNumber ?: null, $contactEmail ?: null)) {
                $_SESSION['message'] = 'Organisationen har uppdaterats.';
                $_SESSION['message_type'] = 'success';
                logActivity($_SESSION['user_email'], "Uppdaterade organisation #{$orgId}", [
                    'action' => 'organization_update',
                    'organization_id' => $orgId,
                ]);
            } else {
                $_SESSION['message'] = 'Kunde inte uppdatera organisationen.';
                $_SESSION['message_type'] = 'danger';
            }
        }
    } elseif ($action === 'delete') {
        $orgId = (int)($_POST['organization_id'] ?? 0);
        if ($orgId > 0 && deleteOrganization($orgId)) {
            $_SESSION['message'] = 'Organisationen har raderats. Domänerna är nu fristående igen.';
            $_SESSION['message_type'] = 'success';
            logActivity($_SESSION['user_email'], "Raderade organisation #{$orgId}", [
                'action' => 'organization_delete',
                'organization_id' => $orgId,
            ]);
        } else {
            $_SESSION['message'] = 'Kunde inte radera organisationen.';
            $_SESSION['message_type'] = 'danger';
        }
    } elseif ($action === 'assign_domain') {
        $orgId = (int)($_POST['organization_id'] ?? 0);
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        if ($orgId > 0 && $domain !== '' && assignDomainToOrg($orgId, $domain)) {
            $_SESSION['message'] = "Domänen {$domain} har tilldelats organisationen.";
            $_SESSION['message_type'] = 'success';
            logActivity($_SESSION['user_email'], "Tilldelade domän {$domain} till organisation #{$orgId}", [
                'action' => 'organization_assign_domain',
                'organization_id' => $orgId,
                'domain' => $domain,
            ]);
        } else {
            $_SESSION['message'] = 'Kunde inte tilldela domänen.';
            $_SESSION['message_type'] = 'danger';
        }
    } elseif ($action === 'remove_domain') {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        if ($domain !== '' && removeDomainFromOrg($domain)) {
            $_SESSION['message'] = "Domänen {$domain} har tagits bort från organisationen.";
            $_SESSION['message_type'] = 'success';
            logActivity($_SESSION['user_email'], "Tog bort domän {$domain} från organisation", [
                'action' => 'organization_remove_domain',
                'domain' => $domain,
            ]);
        } else {
            $_SESSION['message'] = 'Kunde inte ta bort domänen.';
            $_SESSION['message_type'] = 'danger';
        }
    } elseif ($action === 'set_primary') {
        $orgId = (int)($_POST['organization_id'] ?? 0);
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        if ($orgId > 0 && $domain !== '' && setPrimaryDomainForOrg($orgId, $domain)) {
            $_SESSION['message'] = "{$domain} är nu primär domän för organisationen.";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Kunde inte sätta primär domän.';
            $_SESSION['message_type'] = 'danger';
        }
    }

    header('Location: organizations.php');
    exit;
}

// Hämta alla organisationer
$organizations = getAllOrganizations();

// Hämta domäner per organisation
$orgDomainsMap = [];
foreach ($organizations as $org) {
    $orgDomainsMap[$org['id']] = query(
        "SELECT * FROM " . DB_DATABASE . ".organization_domains
         WHERE organization_id = ? ORDER BY is_primary DESC, domain ASC",
        [$org['id']]
    );
}

// Räkna användare per organisation (alla domäner kombinerat)
$orgUserCounts = [];
foreach ($organizations as $org) {
    $domains = array_column($orgDomainsMap[$org['id']] ?? [], 'domain');
    if (empty($domains)) {
        $orgUserCounts[$org['id']] = 0;
        continue;
    }
    $clause = buildEmailDomainInClause($domains, 'email');
    $row = queryOne(
        "SELECT COUNT(*) as cnt FROM " . DB_DATABASE . ".users WHERE {$clause['fragment']}",
        $clause['params']
    );
    $orgUserCounts[$org['id']] = $row ? (int)$row['cnt'] : 0;
}

// Hämta vilka domäner som redan är tilldelade en org (för "lediga domäner"-listan)
$assignedDomains = array_column(
    query("SELECT domain FROM " . DB_DATABASE . ".organization_domains") ?: [],
    'domain'
);

// Lista över alla vitlistade domäner från allowed_domains.txt
$domainsFile = __DIR__ . '/../allowed_domains.txt';
$allDomains = [];
if (file_exists($domainsFile)) {
    foreach (file($domainsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $allDomains[] = $line;
        }
    }
}
sort($allDomains);
$availableDomains = array_values(array_diff($allDomains, $assignedDomains));

require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-diagram-3 me-2"></i>Organisationer
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Skapa en organisation och tilldela en eller flera e-postdomäner. Användare, kurser, statistik och PUB-avtal delas mellan alla domäner som tillhör samma organisation. Domäner som inte tilldelats någon organisation fungerar som tidigare.
                </div>

                <!-- Skapa ny organisation -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h6 class="m-0"><i class="bi bi-plus-circle me-2"></i>Skapa ny organisation</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="organizations.php" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create">
                            <div class="col-md-5">
                                <label class="form-label">Organisationens namn</label>
                                <input type="text" class="form-control" name="name" placeholder="Exempel: Testkommun" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Organisationsnummer</label>
                                <input type="text" class="form-control" name="org_number" placeholder="NNNNNN-NNNN" pattern="\d{6}-\d{4}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Kontakt-e-post (valfri)</label>
                                <input type="email" class="form-control" name="contact_email" placeholder="kontakt@kommun.se">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista över organisationer -->
                <?php if (empty($organizations)): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Inga organisationer har skapats ännu. Skapa en organisation ovan och tilldela domäner till den.
                </div>
                <?php else: ?>
                <?php foreach ($organizations as $org):
                    $orgDomains = $orgDomainsMap[$org['id']] ?? [];
                    $userCount = $orgUserCounts[$org['id']] ?? 0;
                    $hasPub = (int)$org['has_pub_agreement'] === 1;
                ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="m-0 fw-bold">
                                <i class="bi bi-building me-2"></i><?= htmlspecialchars($org['name']) ?>
                                <?php if ($org['org_number']): ?>
                                <small class="text-muted ms-2"><?= htmlspecialchars($org['org_number']) ?></small>
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="badge bg-secondary"><?= count($orgDomains) ?> domän<?= count($orgDomains) === 1 ? '' : 'er' ?></span>
                            <span class="badge bg-info"><?= $userCount ?> användare</span>
                            <?php if ($hasPub): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>PUB tecknat</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">PUB saknas</span>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editOrgModal_<?= $org['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="organizations.php" class="d-inline" onsubmit="return confirm('Är du säker? Domänerna blir fristående igen, men kurser, användare och PUB-artefakter behålls.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="organization_id" value="<?= $org['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($orgDomains)): ?>
                        <p class="text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Inga domäner har tilldelats denna organisation ännu.</p>
                        <?php else: ?>
                        <table class="table table-sm mb-3">
                            <thead class="table-light">
                                <tr>
                                    <th>Domän</th>
                                    <th class="text-center" style="width: 120px;">Primär</th>
                                    <th class="text-end" style="width: 120px;">Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orgDomains as $od): ?>
                                <tr>
                                    <td>
                                        <i class="bi bi-globe2 text-primary me-1"></i>
                                        <strong><?= htmlspecialchars($od['domain']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int)$od['is_primary'] === 1): ?>
                                            <span class="badge bg-success"><i class="bi bi-star-fill"></i></span>
                                        <?php else: ?>
                                            <form method="POST" action="organizations.php" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="set_primary">
                                                <input type="hidden" name="organization_id" value="<?= $org['id'] ?>">
                                                <input type="hidden" name="domain" value="<?= htmlspecialchars($od['domain']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Sätt som primär">
                                                    <i class="bi bi-star"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="organizations.php" class="d-inline" onsubmit="return confirm('Ta bort <?= htmlspecialchars($od['domain']) ?> från organisationen?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="remove_domain">
                                            <input type="hidden" name="domain" value="<?= htmlspecialchars($od['domain']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>

                        <!-- Tilldela ny domän -->
                        <form method="POST" action="organizations.php" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="assign_domain">
                            <input type="hidden" name="organization_id" value="<?= $org['id'] ?>">
                            <div class="col-md-9">
                                <select name="domain" class="form-select form-select-sm" required>
                                    <option value="">— Välj en ledig domän att tilldela —</option>
                                    <?php foreach ($availableDomains as $d): ?>
                                    <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="bi bi-plus-lg me-1"></i>Tilldela domän
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Redigera organisationsmodal -->
                <div class="modal fade" id="editOrgModal_<?= $org['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="organizations.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="organization_id" value="<?= $org['id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Redigera organisation</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Namn</label>
                                        <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($org['name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Organisationsnummer</label>
                                        <input type="text" class="form-control" name="org_number" value="<?= htmlspecialchars($org['org_number'] ?? '') ?>" pattern="\d{6}-\d{4}" placeholder="NNNNNN-NNNN">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Kontakt-e-post</label>
                                        <input type="email" class="form-control" name="contact_email" value="<?= htmlspecialchars($org['contact_email'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                    <button type="submit" class="btn btn-primary">Spara</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
