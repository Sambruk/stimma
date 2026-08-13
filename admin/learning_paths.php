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

/**
 * Lärvägar — lista, skapa och radera.
 *
 * En lärväg grupperar flera kurser i en rekommenderad ordning. Kurserna
 * kopplas i edit_learning_path.php. Se include/learning_paths.php för
 * datamodell och behörighetsregler.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/learning_paths.php';

// Kontrollera att användaren är inloggad och är admin/editor
require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = getUserDomain($currentUser['email']);
$isAdmin = $currentUser['is_admin'] == 1;
$isEditor = $currentUser['is_editor'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';

if (!$isAdmin && !$isEditor && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att hantera lärvägar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Scope: huvuddomän-admin ser hela orgen, sub-domän bara sin egen domän.
// Redaktör utan admin-flagga ser endast lärvägar hen själv skapat.
$orgScopeDomains = getEffectiveOrgScopeDomains($_SESSION['user_email']);
$onlyOwn = (!$isAdmin && !$isSuperAdmin) ? (int)$currentUser['id'] : null;

$page_title = 'Lärvägar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: learning_paths.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_path') {
        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $_SESSION['message'] = 'Lärvägen måste ha ett namn.';
            $_SESSION['message_type'] = 'danger';
        } elseif (mb_strlen($title) > 255) {
            $_SESSION['message'] = 'Namnet får inte vara längre än 255 tecken.';
            $_SESSION['message_type'] = 'danger';
        } else {
            $newId = createLearningPath([
                'title' => $title,
                'status' => 'inactive', // ny lärväg är inaktiv tills kurser lagts till
                'organization_domain' => $userDomain,
                'created_by' => (int)$currentUser['id'],
            ]);

            // Sub-domänanvändare får inte styra delning — lärvägen låses till
            // den egna domänen, samma regel som för kurser.
            if (!isUserOnPrimaryOrgDomain($_SESSION['user_email'])) {
                setLearningPathSharedDomains($newId, [$userDomain]);
            }

            logActivity($_SESSION['user_email'], 'Skapade lärväg', [
                'action' => 'learning_path_created',
                'learning_path_id' => $newId,
                'title' => $title,
            ]);

            $_SESSION['message'] = "Lärvägen '{$title}' har skapats. Lägg till kurser nedan.";
            $_SESSION['message_type'] = 'success';
            header('Location: edit_learning_path.php?id=' . $newId);
            exit;
        }
    } elseif ($action === 'delete_path') {
        $pathId = (int)($_POST['path_id'] ?? 0);
        $path = getLearningPath($pathId);

        if (!$path || !userCanModifyLearningPath($path)) {
            $_SESSION['message'] = 'Lärvägen kunde inte hittas eller så saknar du behörighet.';
            $_SESSION['message_type'] = 'danger';
        } else {
            deleteLearningPath($pathId);
            logActivity($_SESSION['user_email'], 'Raderade lärväg', [
                'action' => 'learning_path_deleted',
                'learning_path_id' => $pathId,
                'title' => $path['title'],
            ]);
            $_SESSION['message'] = "Lärvägen '{$path['title']}' har tagits bort. Kurserna finns kvar.";
            $_SESSION['message_type'] = 'success';
        }
    }

    header('Location: learning_paths.php');
    exit;
}

$paths = getLearningPathsForScope($orgScopeDomains, $isSuperAdmin, $onlyOwn);

require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-signpost-split me-2"></i>Lärvägar</h1>
                <p class="text-muted mb-0">
                    En lärväg samlar flera kurser i en rekommenderad ordning. Ordningen är en
                    rekommendation — deltagaren kan ta kurserna i valfri ordning.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3 bg-success text-white">
                <h6 class="m-0 font-weight-bold"><i class="bi bi-plus-circle me-2"></i>Skapa ny lärväg</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_path">
                    <div class="col-md-8">
                        <label for="new_path_title" class="form-label">Namn på lärvägen</label>
                        <input type="text" name="title" id="new_path_title" class="form-control"
                               placeholder="t.ex. Introduktion för nyanställda" maxlength="255" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-plus-lg me-1"></i>Skapa och lägg till kurser
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="bi bi-list-ul me-2"></i>Lärvägar
                    <span class="badge bg-secondary ms-2"><?= count($paths) ?> st</span>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($paths)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Inga lärvägar har skapats ännu. Använd formuläret ovan för att skapa din första.
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-2">
                        <i class="bi bi-grip-vertical"></i> Dra raderna för att ändra i vilken ordning
                        lärvägarna visas för deltagarna.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width:32px;"></th>
                                    <th style="width:60px;">ID</th>
                                    <th>Lärväg</th>
                                    <th>Kurser</th>
                                    <th>Status</th>
                                    <th>Synlighet</th>
                                    <th style="width:150px;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody id="sortable-paths">
                                <?php foreach ($paths as $path): ?>
                                    <?php
                                        $courseCount = (int)$path['course_count'];
                                        $sharedCount = (int)$path['shared_domain_count'];
                                    ?>
                                    <tr data-id="<?= (int)$path['id'] ?>">
                                        <td class="grip-handle" style="cursor:move;">
                                            <i class="bi bi-grip-vertical text-muted"></i>
                                        </td>
                                        <td><span class="text-muted small">#<?= (int)$path['id'] ?></span></td>
                                        <td>
                                            <a href="edit_learning_path.php?id=<?= (int)$path['id'] ?>" class="fw-bold text-decoration-none">
                                                <?= htmlspecialchars($path['title']) ?>
                                            </a>
                                            <?php if (!empty($path['description'])): ?>
                                                <div class="text-muted small text-truncate" style="max-width:420px;">
                                                    <?= htmlspecialchars($path['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $courseCount > 0 ? 'primary' : 'secondary' ?>">
                                                <?= $courseCount ?> kurs<?= $courseCount != 1 ? 'er' : '' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($path['status'] === 'active'): ?>
                                                <span class="badge bg-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" title="Visas inte för deltagare">Inaktiv</span>
                                            <?php endif; ?>
                                            <?php if ($courseCount === 0 && $path['status'] === 'active'): ?>
                                                <span class="badge bg-warning text-dark" title="Lärvägen har inga kurser och visas därför inte">Tom</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($path['is_global'])): ?>
                                                <span class="badge bg-info text-dark" title="Syns för alla organisationer">Global</span>
                                            <?php elseif ($sharedCount > 0): ?>
                                                <span class="badge bg-warning text-dark" title="Syns endast för valda domäner">
                                                    <?= $sharedCount ?> domän<?= $sharedCount != 1 ? 'er' : '' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border" title="Syns för hela organisationen">Hela org.</span>
                                            <?php endif; ?>
                                            <div class="text-muted small"><?= htmlspecialchars($path['organization_domain']) ?></div>
                                        </td>
                                        <td>
                                            <a href="edit_learning_path.php?id=<?= (int)$path['id'] ?>"
                                               class="btn btn-sm btn-outline-primary" title="Redigera">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="learning_path_stats.php?path_id=<?= (int)$path['id'] ?>"
                                               class="btn btn-sm btn-outline-secondary" title="Statistik">
                                                <i class="bi bi-bar-chart"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal" data-bs-target="#deletePathModal<?= (int)$path['id'] ?>"
                                                    title="Ta bort">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Raderingsmodal -->
                                    <div class="modal fade" id="deletePathModal<?= (int)$path['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete_path">
                                                    <input type="hidden" name="path_id" value="<?= (int)$path['id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Ta bort lärväg</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Är du säker på att du vill ta bort lärvägen
                                                            <strong><?= htmlspecialchars($path['title']) ?></strong>?</p>
                                                        <div class="alert alert-info mb-0">
                                                            <i class="bi bi-info-circle me-2"></i>
                                                            Endast grupperingen tas bort.
                                                            <?= $courseCount ?> kurs<?= $courseCount != 1 ? 'er' : '' ?>,
                                                            deltagarnas resultat och utfärdade diplom påverkas inte.
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                                        <button type="submit" class="btn btn-danger">
                                                            <i class="bi bi-trash me-1"></i>Ta bort
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$extra_scripts = '
<script>
    $(function() {
        $("#sortable-paths").sortable({
            items: "tr",
            handle: ".grip-handle",
            axis: "y",
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            update: function() {
                const paths = [];
                $("#sortable-paths tr").each(function(index) {
                    const id = $(this).data("id");
                    if (id) {
                        paths.push({ id: id, order: index });
                    }
                });

                $.ajax({
                    url: "ajax/update_learning_path_order.php",
                    method: "POST",
                    headers: { "X-CSRF-Token": CSRF_TOKEN },
                    data: { paths: JSON.stringify(paths) },
                    error: function(err) {
                        console.error("Fel vid uppdatering av lärvägsordning", err);
                    }
                });
            }
        });
    });
</script>';

require_once 'include/footer.php';
?>
