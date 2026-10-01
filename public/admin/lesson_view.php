<?php
/**
 * Stimma — växel mellan klassisk lektionsvy och C2 (kortformatet). Endast superadmin.
 * Logiken ligger i include/lesson_view.php.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/lesson_view.php';

require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT email, role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    $_SESSION['message'] = 'Endast superadministratörer kan byta lektionsvy.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $default = ($_POST['default'] ?? 'klassisk') === 'c2' ? 'c2' : 'klassisk';
        $kurser = array_map('intval', (array)($_POST['c2_courses'] ?? []));
        lessonViewSave($default, $kurser, $currentUser['email']);
        logActivity($currentUser['email'], "Lektionsvy: standard $default, C2 i förväg för " . count($kurser) . " kurser", [
            'action' => 'lesson_view', 'default' => $default, 'c2_courses' => $kurser,
        ]);
        $_SESSION['message'] = $default === 'c2'
            ? 'Sparat. Alla kurser visas nu i den nya lektionsvyn (C2).'
            : 'Sparat. Kurserna visas i den klassiska vyn' . ($kurser ? ', utom ' . count($kurser) . ' valda som visas i C2.' : '.');
        $_SESSION['message_type'] = 'success';
    }
    header('Location: lesson_view.php');
    exit;
}

$installning = lessonViewSettings();
$kurser = query(
    "SELECT c.id, c.title, c.status, c.organization_domain,
            (SELECT MIN(l.id) FROM " . DB_DATABASE . ".lessons l
              WHERE l.course_id = c.id AND l.status = 'active'
                AND l.sort_order = (SELECT MIN(l2.sort_order) FROM " . DB_DATABASE . ".lessons l2 WHERE l2.course_id = c.id AND l2.status = 'active')) AS forsta_lektion
     FROM " . DB_DATABASE . ".courses c
     ORDER BY c.status = 'active' DESC, c.title"
) ?: [];

$page_title = 'Lektionsvy';
require_once 'include/header.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-muted"><i class="bi bi-layout-text-window me-2"></i>Lektionsvy</h6>
    </div>
    <div class="card-body">
        <p class="mb-3">
            Välj hur lektionerna visas för deltagarna. <strong>Klassisk</strong> är dagens vy med sidomeny.
            <strong>C2</strong> är det nya kortformatet: en idé per kort, topprad i stället för sidomeny och en
            "Lektion klar"-vy. Framstegen sparas likadant i båda vyerna, så det går att byta när som helst.
        </p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <fieldset class="mb-4">
                <legend class="h6">Standard för alla kurser</legend>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="default" id="vyKlassisk" value="klassisk" <?= $installning['default'] === 'klassisk' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="vyKlassisk">Klassisk</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="default" id="vyC2" value="c2" <?= $installning['default'] === 'c2' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="vyC2">C2 — det nya kortformatet</label>
                </div>
            </fieldset>

            <fieldset class="mb-3" id="kursval">
                <legend class="h6">Kurser som visas i C2 redan nu</legend>
                <p class="small text-muted mb-2">
                    Gäller när standarden är <em>Klassisk</em> — bra för att prova C2 på några kurser först.
                    När standarden är C2 visas alla kurser i C2.
                </p>
                <div class="input-group input-group-sm mb-2" style="max-width: 420px;">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" class="form-control" id="kursSok" placeholder="Sök kurs…" aria-label="Sök kurs">
                </div>
                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0;">
                            <tr><th style="width: 3rem;">C2</th><th>Kurs</th><th>Organisation</th><th>Status</th><th>Prova</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kurser as $k): $vald = in_array((int)$k['id'], $installning['c2_courses'], true); ?>
                            <tr data-sok="<?= htmlspecialchars(mb_strtolower($k['title'] . ' ' . $k['id'])) ?>">
                                <td><input class="form-check-input" type="checkbox" name="c2_courses[]" value="<?= (int)$k['id'] ?>" id="k<?= (int)$k['id'] ?>" <?= $vald ? 'checked' : '' ?>></td>
                                <td><label for="k<?= (int)$k['id'] ?>" class="mb-0"><?= htmlspecialchars($k['title']) ?> <span class="text-muted small">#<?= (int)$k['id'] ?></span></label></td>
                                <td class="small text-muted"><?= htmlspecialchars($k['organization_domain'] ?? '') ?></td>
                                <td><span class="badge bg-<?= $k['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $k['status'] === 'active' ? 'Aktiv' : 'Inaktiv' ?></span></td>
                                <td class="text-nowrap small">
                                    <?php if ($k['forsta_lektion']): ?>
                                    <a href="../lesson.php?id=<?= (int)$k['forsta_lektion'] ?>&amp;preview=1&amp;ui=c2" target="_blank" rel="noopener">C2</a> ·
                                    <a href="../lesson.php?id=<?= (int)$k['forsta_lektion'] ?>&amp;preview=1&amp;ui=klassisk" target="_blank" rel="noopener">Klassisk</a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary">Spara</button>
        </form>

        <hr class="my-4">
        <h2 class="h6">Jämföra utan att ändra något</h2>
        <p class="small mb-0">
            Lägg till <code>?ui=c2</code> eller <code>?ui=klassisk</code> i adressen till en lektion. Valet gäller bara
            dig och minns tills du skriver <code>?ui=auto</code> eller loggar ut. Länkarna "Prova" ovan öppnar kursens
            första lektion i förhandsgranskning, så att inga framsteg sparas.
        </p>
    </div>
</div>

<script>
document.getElementById('kursSok').addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('#kursval tbody tr').forEach(function (tr) {
        tr.hidden = q !== '' && tr.dataset.sok.indexOf(q) === -1;
    });
});
</script>

<?php require_once 'include/footer.php'; ?>
