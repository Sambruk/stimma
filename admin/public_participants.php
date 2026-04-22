<?php
/**
 * Admin — hantera publika deltagare för en specifik kurs.
 *
 * Lista alla användare som registrerat sig via den publika länken, visa
 * progress (ikoner per lektion för stegvisa kurser), tillåta bulk-val och
 * radering med två spärrar (kryssruta + skriv RADERA).
 */
require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

if (!isLoggedIn()) {
    redirect('../index.php');
    exit;
}

$currentUser = queryOne(
    "SELECT id, email, role, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?",
    [$_SESSION['user_id']]
);
if (!$currentUser || (empty($currentUser['is_admin']) && empty($currentUser['is_editor']) && ($currentUser['role'] ?? '') !== 'super_admin')) {
    $_SESSION['message'] = 'Otillräcklig behörighet.';
    $_SESSION['message_type'] = 'danger';
    redirect('index.php');
    exit;
}

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$course = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    $_SESSION['message'] = 'Kursen hittades inte.';
    $_SESSION['message_type'] = 'danger';
    redirect('courses.php');
    exit;
}

// Org-scope: vanlig admin/redaktör får bara se kurser i sin org
$isSuperAdmin = ($currentUser['role'] ?? '') === 'super_admin';
if (!$isSuperAdmin) {
    $scope = getOrgScopeDomains($currentUser['email']);
    if (!in_array($course['organization_domain'], $scope, true)) {
        $_SESSION['message'] = 'Kursen tillhör inte din organisation.';
        $_SESSION['message_type'] = 'danger';
        redirect('courses.php');
        exit;
    }
}

// Lektioner för progress-rendering
$lessons = query(
    "SELECT id, title FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC",
    [$courseId]
);
$lessonIds = array_map('intval', array_column($lessons, 'id'));

// Deltagare: alla med public_course_access för denna kurs
$participants = query("
    SELECT u.id, u.email, u.name, u.access_mode, u.verified_at, u.last_login_at,
           pca.registered_at, pca.organization_id
    FROM " . DB_DATABASE . ".public_course_access pca
    JOIN " . DB_DATABASE . ".users u ON u.id = pca.user_id
    WHERE pca.course_id = ?
    ORDER BY pca.registered_at DESC
", [$courseId]);

// Hämta progress per (user, lesson) för snabb rendering
$progressMap = [];
if (!empty($participants) && !empty($lessonIds)) {
    $userIds = array_map('intval', array_column($participants, 'id'));
    $userPh = implode(',', array_fill(0, count($userIds), '?'));
    $lessonPh = implode(',', array_fill(0, count($lessonIds), '?'));
    $progressRows = query(
        "SELECT user_id, lesson_id, status FROM " . DB_DATABASE . ".progress
         WHERE user_id IN ($userPh) AND lesson_id IN ($lessonPh)",
        array_merge($userIds, $lessonIds)
    );
    foreach ($progressRows as $p) {
        $progressMap[$p['user_id']][$p['lesson_id']] = $p['status'];
    }
}

// För stegvisa kurser: hämta sequential_lesson_schedule för ikon-beräkning
$isSequential = !empty($course['sequential_mode']);
$scheduleMap = [];
if ($isSequential && !empty($participants) && !empty($lessonIds)) {
    $userIds = array_map('intval', array_column($participants, 'id'));
    $userPh = implode(',', array_fill(0, count($userIds), '?'));
    $scheduleRows = query(
        "SELECT user_id, lesson_id, available_at, completed_at
         FROM " . DB_DATABASE . ".sequential_lesson_schedule
         WHERE user_id IN ($userPh) AND course_id = ?",
        array_merge($userIds, [$courseId])
    );
    foreach ($scheduleRows as $s) {
        $scheduleMap[$s['user_id']][$s['lesson_id']] = $s;
    }
}

$page_title = 'Publika deltagare — ' . $course['title'];
require_once 'include/header.php';
?>

<div class="container-fluid py-3">
    <?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type'] ?? 'info') ?> alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message'], $_SESSION['message_type']); endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-globe me-2 text-info"></i>Publika deltagare</h4>
            <div class="text-muted">
                <a href="edit_course.php?id=<?= (int)$course['id'] ?>" class="text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($course['title']) ?>
                </a>
                <?php if (!empty($course['is_public'])): ?>
                <span class="badge bg-info text-dark ms-2">Publik kurs</span>
                <?php else: ?>
                <span class="badge bg-secondary ms-2">Ej längre publik (gamla deltagare kvar)</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><strong><?= count($participants) ?></strong> registrerade deltagare</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($participants)): ?>
            <p class="text-muted p-4 mb-0 text-center">Inga deltagare har registrerat sig ännu.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="participantsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" class="form-check-input" id="selectAll">
                            </th>
                            <th>E-post</th>
                            <th>Namn</th>
                            <th>Registrerad</th>
                            <?php if ($isSequential): ?>
                                <?php foreach ($lessons as $idx => $l): ?>
                                <th class="text-center" title="<?= htmlspecialchars($l['title']) ?>" style="min-width: 40px;">L<?= $idx + 1 ?></th>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <th>Progress</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $p):
                            $completedCount = 0;
                            foreach ($lessonIds as $lid) {
                                if (($progressMap[$p['id']][$lid] ?? null) === 'completed') $completedCount++;
                            }
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input participant-check"
                                       value="<?= (int)$p['id'] ?>" data-email="<?= htmlspecialchars($p['email']) ?>">
                            </td>
                            <td>
                                <?= htmlspecialchars($p['email']) ?>
                                <?php if (($p['access_mode'] ?? 'domain') === 'public_only'): ?>
                                    <span class="badge bg-light text-dark border ms-1" style="font-size: 0.65em;" title="Endast publik åtkomst">publik</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($p['registered_at']))) ?></small></td>
                            <?php if ($isSequential): ?>
                                <?php foreach ($lessons as $l):
                                    $status = $progressMap[$p['id']][$l['id']] ?? null;
                                    $sched = $scheduleMap[$p['id']][$l['id']] ?? null;
                                    if ($status === 'completed') {
                                        $icon = 'bi-check-circle-fill text-success'; $title = 'Avklarad';
                                    } elseif ($sched && $sched['available_at'] && strtotime($sched['available_at']) <= time()) {
                                        $icon = 'bi-clock text-primary'; $title = 'Tillgänglig';
                                    } elseif ($sched && !$sched['available_at']) {
                                        $icon = 'bi-lock text-muted'; $title = 'Låst';
                                    } else {
                                        $icon = 'bi-circle text-muted'; $title = 'Ej påbörjad';
                                    }
                                ?>
                                <td class="text-center"><i class="bi <?= $icon ?>" title="<?= $title ?>"></i></td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td>
                                    <?php $total = max(count($lessonIds), 1); $pct = round(($completedCount / $total) * 100); ?>
                                    <div class="progress" style="height: 16px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $pct ?>%;"><?= $completedCount ?>/<?= count($lessonIds) ?></div>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sticky action bar -->
<div id="bulkActionBar" class="d-none fixed-bottom bg-light border-top py-2 shadow-lg" style="z-index: 1040;">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <span><strong id="selectedCount">0</strong> deltagare valda</span>
        <button type="button" class="btn btn-danger" id="deleteSelectedBtn" data-bs-toggle="modal" data-bs-target="#confirmDeleteModal">
            <i class="bi bi-trash me-1"></i>Ta bort valda
        </button>
    </div>
</div>

<!-- Bekräftelsemodal (två spärrar) -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Ta bort publika deltagare</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Du kommer att ta bort <strong id="modalCount">0</strong> deltagare från kursen <strong><?= htmlspecialchars($course['title']) ?></strong>.</p>
                <p class="text-muted small">All deras data raderas permanent: framsteg, anmälningar, lektionsschema och registreringen. Bekräftelsemail skickas till varje deltagare.</p>
                <ul id="modalEmailList" class="small text-muted" style="max-height: 140px; overflow-y: auto;"></ul>

                <hr>

                <div class="form-check mb-3">
                    <input class="form-check-input confirm-understand" type="checkbox" id="confirmUnderstand">
                    <label class="form-check-label" for="confirmUnderstand">
                        Jag förstår att detta inte kan ångras
                    </label>
                </div>
                <label class="form-label small">Skriv <code>RADERA</code> för att bekräfta:</label>
                <input type="text" class="form-control confirm-type-radera" placeholder="RADERA">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                <button type="button" class="btn btn-danger confirm-destructive-btn" id="confirmDeleteBtn" disabled>
                    <i class="bi bi-trash me-1"></i>Ta bort
                </button>
            </div>
        </div>
    </div>
</div>

<script src="include/confirm_destructive.js"></script>
<script>
(function() {
    const selectAll = document.getElementById('selectAll');
    const checks = document.querySelectorAll('.participant-check');
    const bar = document.getElementById('bulkActionBar');
    const count = document.getElementById('selectedCount');
    const modalCount = document.getElementById('modalCount');
    const modalList = document.getElementById('modalEmailList');
    const modal = document.getElementById('confirmDeleteModal');
    const confirmBtn = document.getElementById('confirmDeleteBtn');

    function updateBar() {
        const selected = Array.from(checks).filter(c => c.checked);
        count.textContent = selected.length;
        bar.classList.toggle('d-none', selected.length === 0);
    }
    if (selectAll) selectAll.addEventListener('change', function() {
        checks.forEach(c => c.checked = selectAll.checked);
        updateBar();
    });
    checks.forEach(c => c.addEventListener('change', updateBar));

    modal.addEventListener('show.bs.modal', function() {
        const selected = Array.from(checks).filter(c => c.checked);
        modalCount.textContent = selected.length;
        modalList.innerHTML = selected.map(c => '<li>' + c.dataset.email + '</li>').join('');
    });

    initConfirmDestructive(modal);

    confirmBtn.addEventListener('click', async function() {
        const userIds = Array.from(checks).filter(c => c.checked).map(c => c.value);
        const fd = new FormData();
        fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token']) ?>);
        fd.append('course_id', '<?= (int)$course['id'] ?>');
        userIds.forEach(uid => fd.append('user_ids[]', uid));
        confirmBtn.disabled = true;
        try {
            const r = await fetch('ajax/delete_public_participants.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (data.success) {
                alert('Borttagning klar: ' + data.deleted + ' deltagare.');
                location.reload();
            } else {
                alert(data.message || 'Kunde inte radera.');
                confirmBtn.disabled = false;
            }
        } catch (e) {
            alert('Nätverksfel.');
            confirmBtn.disabled = false;
        }
    });
})();
</script>

<?php require_once 'include/footer.php'; ?>
