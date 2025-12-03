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

// Kontrollera att användaren är inloggad och är admin/editor
require_once 'include/auth_check.php';

// Hämta användarens information
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isAdmin = $currentUser['is_admin'] == 1;
$isEditor = $currentUser['is_editor'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';

// Kontrollera behörighet - endast redaktörer, admin och superadmin
if (!$isAdmin && !$isEditor && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att hantera taggar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Tagghantering';

// Hantera formulärinlämning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: tags.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_tag') {
        $tagName = trim($_POST['tag_name'] ?? '');

        if (empty($tagName)) {
            $_SESSION['message'] = 'Taggnamnet får inte vara tomt.';
            $_SESSION['message_type'] = 'danger';
        } elseif (strlen($tagName) > 100) {
            $_SESSION['message'] = 'Taggnamnet får inte vara längre än 100 tecken.';
            $_SESSION['message_type'] = 'danger';
        } else {
            // Kontrollera om taggen redan finns för organisationen
            $existingTag = queryOne(
                "SELECT id FROM " . DB_DATABASE . ".tags WHERE name = ? AND organization_domain = ?",
                [$tagName, $userDomain]
            );

            if ($existingTag) {
                $_SESSION['message'] = 'En tagg med detta namn finns redan i din organisation.';
                $_SESSION['message_type'] = 'warning';
            } else {
                $result = execute(
                    "INSERT INTO " . DB_DATABASE . ".tags (name, organization_domain, created_by) VALUES (?, ?, ?)",
                    [$tagName, $userDomain, $currentUser['id']]
                );

                if ($result) {
                    $_SESSION['message'] = "Taggen '{$tagName}' har skapats.";
                    $_SESSION['message_type'] = 'success';

                    // Logga aktiviteten
                    logActivity($_SESSION['user_email'], 'Skapade tagg', [
                        'action' => 'tag_created',
                        'tag_name' => $tagName,
                        'organization_domain' => $userDomain
                    ]);
                } else {
                    $_SESSION['message'] = 'Kunde inte skapa taggen.';
                    $_SESSION['message_type'] = 'danger';
                }
            }
        }
    } elseif ($action === 'edit_tag') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        $tagName = trim($_POST['tag_name'] ?? '');

        if (empty($tagName)) {
            $_SESSION['message'] = 'Taggnamnet får inte vara tomt.';
            $_SESSION['message_type'] = 'danger';
        } elseif (strlen($tagName) > 100) {
            $_SESSION['message'] = 'Taggnamnet får inte vara längre än 100 tecken.';
            $_SESSION['message_type'] = 'danger';
        } else {
            // Kontrollera att taggen tillhör användarens organisation
            $tag = queryOne(
                "SELECT * FROM " . DB_DATABASE . ".tags WHERE id = ? AND organization_domain = ?",
                [$tagId, $userDomain]
            );

            if (!$tag) {
                $_SESSION['message'] = 'Taggen kunde inte hittas eller tillhör inte din organisation.';
                $_SESSION['message_type'] = 'danger';
            } else {
                // Kontrollera om nytt namn redan finns
                $existingTag = queryOne(
                    "SELECT id FROM " . DB_DATABASE . ".tags WHERE name = ? AND organization_domain = ? AND id != ?",
                    [$tagName, $userDomain, $tagId]
                );

                if ($existingTag) {
                    $_SESSION['message'] = 'En tagg med detta namn finns redan.';
                    $_SESSION['message_type'] = 'warning';
                } else {
                    $result = execute(
                        "UPDATE " . DB_DATABASE . ".tags SET name = ? WHERE id = ?",
                        [$tagName, $tagId]
                    );

                    if ($result) {
                        $_SESSION['message'] = 'Taggen har uppdaterats.';
                        $_SESSION['message_type'] = 'success';

                        logActivity($_SESSION['user_email'], 'Uppdaterade tagg', [
                            'action' => 'tag_updated',
                            'tag_id' => $tagId,
                            'old_name' => $tag['name'],
                            'new_name' => $tagName
                        ]);
                    } else {
                        $_SESSION['message'] = 'Kunde inte uppdatera taggen.';
                        $_SESSION['message_type'] = 'danger';
                    }
                }
            }
        }
    } elseif ($action === 'delete_tag') {
        $tagId = (int)($_POST['tag_id'] ?? 0);

        // Kontrollera att taggen tillhör användarens organisation
        $tag = queryOne(
            "SELECT * FROM " . DB_DATABASE . ".tags WHERE id = ? AND organization_domain = ?",
            [$tagId, $userDomain]
        );

        if (!$tag) {
            $_SESSION['message'] = 'Taggen kunde inte hittas eller tillhör inte din organisation.';
            $_SESSION['message_type'] = 'danger';
        } else {
            // Räkna hur många kurser som använder taggen
            $courseCount = queryOne(
                "SELECT COUNT(*) as count FROM " . DB_DATABASE . ".course_tags WHERE tag_id = ?",
                [$tagId]
            )['count'];

            $result = execute("DELETE FROM " . DB_DATABASE . ".tags WHERE id = ?", [$tagId]);

            if ($result) {
                $_SESSION['message'] = "Taggen '{$tag['name']}' har tagits bort" .
                    ($courseCount > 0 ? " (var kopplad till {$courseCount} kurs" . ($courseCount > 1 ? "er" : "") . ")" : "") . ".";
                $_SESSION['message_type'] = 'success';

                logActivity($_SESSION['user_email'], 'Tog bort tagg', [
                    'action' => 'tag_deleted',
                    'tag_id' => $tagId,
                    'tag_name' => $tag['name'],
                    'affected_courses' => $courseCount
                ]);
            } else {
                $_SESSION['message'] = 'Kunde inte ta bort taggen.';
                $_SESSION['message_type'] = 'danger';
            }
        }
    }

    header('Location: tags.php');
    exit;
}

// Hämta alla taggar för organisationen
$tags = query(
    "SELECT t.*, u.email as creator_email,
            (SELECT COUNT(*) FROM " . DB_DATABASE . ".course_tags ct WHERE ct.tag_id = t.id) as course_count
     FROM " . DB_DATABASE . ".tags t
     LEFT JOIN " . DB_DATABASE . ".users u ON t.created_by = u.id
     WHERE t.organization_domain = ?
     ORDER BY t.name ASC",
    [$userDomain]
);

// Inkludera header
require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-tags me-2"></i>Skapa ny tagg
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_tag">
                    <div class="col-md-8">
                        <label for="tag_name" class="form-label">Taggnamn</label>
                        <input type="text" name="tag_name" id="tag_name" class="form-control"
                               placeholder="Ange taggnamn..." maxlength="100" required>
                        <div class="form-text">Taggar är unika inom din organisation (<?= htmlspecialchars($userDomain) ?>)</div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-circle me-2"></i>Skapa tagg
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
                    <i class="bi bi-list-ul me-2"></i>Taggar för <?= htmlspecialchars($userDomain) ?>
                    <span class="badge bg-secondary ms-2"><?= count($tags) ?> taggar</span>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($tags)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Inga taggar har skapats ännu. Använd formuläret ovan för att skapa din första tagg.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Tagg</th>
                                <th>Används i</th>
                                <th>Skapad av</th>
                                <th>Skapad</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tags as $tag): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary fs-6"><?= htmlspecialchars($tag['name']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $tag['course_count'] > 0 ? 'success' : 'secondary' ?>">
                                        <?= $tag['course_count'] ?> kurs<?= $tag['course_count'] != 1 ? 'er' : '' ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($tag['creator_email'] ?? 'Okänd') ?>
                                    </small>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= date('Y-m-d H:i', strtotime($tag['created_at'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#editModal<?= $tag['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#deleteModal<?= $tag['id'] ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- Edit Modal -->
                            <div class="modal fade" id="editModal<?= $tag['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="edit_tag">
                                            <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Redigera tagg</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label for="edit_tag_name_<?= $tag['id'] ?>" class="form-label">Taggnamn</label>
                                                    <input type="text" name="tag_name" id="edit_tag_name_<?= $tag['id'] ?>"
                                                           class="form-control" value="<?= htmlspecialchars($tag['name']) ?>"
                                                           maxlength="100" required>
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

                            <!-- Delete Modal -->
                            <div class="modal fade" id="deleteModal<?= $tag['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete_tag">
                                            <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Ta bort tagg</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p>Är du säker på att du vill ta bort taggen <strong><?= htmlspecialchars($tag['name']) ?></strong>?</p>
                                                <?php if ($tag['course_count'] > 0): ?>
                                                <div class="alert alert-warning">
                                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                                    Denna tagg är kopplad till <?= $tag['course_count'] ?> kurs<?= $tag['course_count'] > 1 ? 'er' : '' ?>.
                                                    Taggen kommer att tas bort från dessa kurser.
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                                <button type="submit" class="btn btn-danger">Ta bort</button>
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
// Inkludera footer
require_once 'include/footer.php';
?>
