<?php
/**
 * Stimma — Informationsmeddelanden (superadmin)
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/announcements.php';
require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

if (!$isSuperAdmin) {
    $_SESSION['message'] = 'Endast superadmin har tillgång till informationsmeddelanden.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken.';
        $_SESSION['message_type'] = 'danger';
        header('Location: announcements.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $active = !empty($_POST['active']);
        if ($title === '' || $body === '') {
            $_SESSION['message'] = 'Titel och innehåll krävs.';
            $_SESSION['message_type'] = 'danger';
        } else {
            createAnnouncement($title, $body, $active, $currentUser['email']);
            $_SESSION['message'] = $active
                ? 'Nytt meddelande publicerat — alla admin/redaktörer ser det vid nästa sidladdning.'
                : 'Meddelande sparat (inaktivt).';
            $_SESSION['message_type'] = 'success';
        }
        header('Location: announcements.php');
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($id > 0 && $title !== '' && $body !== '') {
            updateAnnouncement($id, $title, $body);
            $_SESSION['message'] = 'Meddelande uppdaterat (dismissals nollställs INTE — skapa nytt om alla ska se det igen).';
            $_SESSION['message_type'] = 'info';
        }
        header('Location: announcements.php');
        exit;
    }

    if ($action === 'activate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            setAnnouncementActive($id, 1);
            $_SESSION['message'] = 'Meddelande aktiverat. Notera: användare som tidigare dismissat detta id ser det INTE igen.';
            $_SESSION['message_type'] = 'warning';
        }
        header('Location: announcements.php');
        exit;
    }

    if ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            setAnnouncementActive($id, 0);
            $_SESSION['message'] = 'Meddelande inaktiverat.';
            $_SESSION['message_type'] = 'success';
        }
        header('Location: announcements.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            deleteAnnouncement($id);
            $_SESSION['message'] = 'Meddelande borttaget.';
            $_SESSION['message_type'] = 'success';
        }
        header('Location: announcements.php');
        exit;
    }
}

$announcements = listAnnouncements();
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId ? getAnnouncement($editId) : null;

$page_title = 'Informationsmeddelanden';
require_once 'include/header.php';
?>

<div class="card mb-4">
    <div class="card-header">
        <strong><?= $editing ? 'Redigera meddelande' : 'Skapa nytt meddelande' ?></strong>
        <?php if ($editing): ?>
            <a href="announcements.php" class="btn btn-sm btn-link float-end">Avbryt redigering</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            <strong>Logik:</strong> Ett <em>nytt</em> meddelande visas för alla admin/redaktörer
            tills de klickar "förstått, visa inte igen". Att <em>redigera</em> ett befintligt
            meddelande nollställer INTE dismiss-statusen — skapa ett nytt om alla ska se det igen.
            Endast ett meddelande kan vara aktivt åt gången.
        </p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">Titel</label>
                <input type="text" name="title" class="form-control" maxlength="255" required
                       value="<?= htmlspecialchars($editing['title'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Innehåll</label>
                <textarea name="body" class="form-control" rows="6" required><?= htmlspecialchars($editing['body'] ?? '') ?></textarea>
                <small class="text-muted">Tillåtna HTML-taggar: &lt;p&gt; &lt;br&gt; &lt;strong&gt; &lt;em&gt; &lt;u&gt; &lt;ul&gt; &lt;ol&gt; &lt;li&gt; &lt;a&gt; &lt;h2&gt; &lt;h3&gt; &lt;h4&gt; &lt;code&gt; &lt;pre&gt; &lt;blockquote&gt; &lt;hr&gt;</small>
            </div>
            <?php if (!$editing): ?>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="ann_active" name="active" value="1" checked>
                    <label class="form-check-label" for="ann_active">Aktivera direkt (inaktiverar tidigare aktivt meddelande)</label>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <?= $editing ? 'Uppdatera' : 'Skapa meddelande' ?>
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Alla meddelanden</strong></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>Titel</th>
                <th>Skapad</th>
                <th>Av</th>
                <th>Status</th>
                <th class="text-end">Dismissats av</th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ((array)$announcements as $a): ?>
                    <tr class="<?= $a['active'] ? 'table-success' : '' ?>">
                        <td>
                            <strong><?= htmlspecialchars($a['title']) ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars(mb_substr(strip_tags($a['body']), 0, 100)) ?><?= mb_strlen(strip_tags($a['body'])) > 100 ? '…' : '' ?></small>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($a['created_at']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($a['created_by'] ?? '–') ?></td>
                        <td>
                            <?php if ($a['active']): ?>
                                <span class="badge bg-success">Aktivt</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inaktivt</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <span class="badge bg-light text-dark"><?= (int)$a['dismiss_count'] ?> st</span>
                        </td>
                        <td class="text-end">
                            <a href="?edit=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-primary">Redigera</a>
                            <?php if ($a['active']): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Inaktivera</button>
                                </form>
                            <?php else: ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Aktivera detta meddelande? Användare som tidigare dismissat det ser det INTE igen — bara nya användare som inte sett det.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">Aktivera</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Ta bort meddelandet permanent (dismissals raderas också)?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Ta bort</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($announcements)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Inga meddelanden ännu.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
