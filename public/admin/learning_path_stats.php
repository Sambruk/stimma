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
 * Lärvägsstatistik: matris över vilka användare som genomfört respektive är
 * registrerade för vilka delar av en lärväg.
 *
 * Statusdefinitionerna är desamma som i deltagarvyn (include/learning_paths.php):
 * genomförd = diplom, påbörjad = progress > 0, registrerad = inskriven men
 * ingen progress. Alla värden hämtas batchat — antalet queries är konstant,
 * inte proportionellt mot antalet användare.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/learning_paths.php';

require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userEmail = $_SESSION['user_email'];
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

if (!$isAdmin && !$isEditor && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att se lärvägsstatistik.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Domänfilter — valet skärs alltid mot användarens scope (getStatsDomainScope).
$domainScope = getStatsDomainScope($userEmail);
$orgScopeDomains = $domainScope['scope'];
$selectedDomains = $domainScope['selected'];
$activeDomains = $domainScope['active'];
$domainFilterQs = buildDomainFilterQuery($selectedDomains);

$onlyOwn = (!$isAdmin && !$isSuperAdmin) ? (int)$currentUser['id'] : null;
$paths = getLearningPathsForScope($orgScopeDomains, $isSuperAdmin, $onlyOwn);

$pathId = isset($_GET['path_id']) ? (int)$_GET['path_id'] : 0;
$path = $pathId ? getLearningPath($pathId) : null;

if ($path && !userCanModifyLearningPath($path)) {
    $_SESSION['message'] = 'Du har inte behörighet att se statistik för denna lärväg.';
    $_SESSION['message_type'] = 'danger';
    header('Location: learning_path_stats.php');
    exit;
}

$onlyActive = !isset($_GET['show_all']);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 200;

$courses = [];
$stats = [];
$users = [];
$totalUsers = 0;
$totalPages = 1;
$summary = ['users' => 0, 'completed_path' => 0, 'started' => 0, 'avg_percent' => 0];
$courseSummary = [];

if ($path) {
    $courses = getLearningPathCourses($pathId);
    $totalUsers = countUsersForDomains($activeDomains);
    $totalPages = max(1, (int)ceil($totalUsers / $perPage));
    $page = min($page, $totalPages);
    $users = getUserIdsForDomains($activeDomains, $perPage, ($page - 1) * $perPage);

    if (!empty($courses) && !empty($users)) {
        $userIds = array_map(function ($u) { return (int)$u['id']; }, $users);
        $stats = getLearningPathStatsForUsers($courses, $userIds);

        // Sammanfattning över de användare som visas på sidan
        $percentSum = 0;
        foreach ($stats as $s) {
            if ($s['done'] === $s['total'] && $s['total'] > 0) {
                $summary['completed_path']++;
            }
            if (!empty($s['started'])) {
                $summary['started']++;
            }
            $percentSum += $s['percent'];
        }
        $summary['users'] = count($stats);
        $summary['avg_percent'] = $summary['users'] > 0 ? (int)round($percentSum / $summary['users']) : 0;

        // Per kurs: antal genomförda och registrerade
        foreach ($courses as $c) {
            $cid = (int)$c['id'];
            $courseSummary[$cid] = ['completed' => 0, 'registered' => 0];
            foreach ($stats as $s) {
                $cell = $s['courses'][$cid] ?? null;
                if (!$cell) {
                    continue;
                }
                if ($cell['status'] === 'completed') {
                    $courseSummary[$cid]['completed']++;
                }
                if ($cell['status'] !== 'not_started') {
                    $courseSummary[$cid]['registered']++;
                }
            }
        }
    }
}

$page_title = 'Lärvägsstatistik';
require_once 'include/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-bar-chart me-2"></i>Lärvägsstatistik</h1>
            <?php if ($path): ?>
                <span class="text-muted small"><?= htmlspecialchars($path['title']) ?></span>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ($path): ?>
                <a href="edit_learning_path.php?id=<?= (int)$path['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i>Redigera lärvägen
                </a>
            <?php endif; ?>
            <a href="learning_paths.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Till listan
            </a>
        </div>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header py-3 bg-primary text-white">
        <form method="GET" class="row align-items-center g-2 mb-0">
            <div class="col-md-5">
                <h6 class="m-0 font-weight-bold"><i class="bi bi-signpost-split me-2"></i>Välj lärväg</h6>
            </div>
            <div class="col-md-7">
                <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <?php if (count($orgScopeDomains) > 1): ?>
                        <div class="dropdown">
                            <button class="btn btn-light btn-sm dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                    title="Filtrera på domän/organisation">
                                <i class="bi bi-funnel me-1"></i><?= empty($selectedDomains) ? 'Alla domäner' : (count($selectedDomains) . ' valda') ?>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:240px; max-height:320px; overflow:auto;">
                                <div class="small text-muted px-1 mb-1">Visa endast användare från:</div>
                                <?php foreach ($orgScopeDomains as $d): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="domains[]"
                                               value="<?= htmlspecialchars($d) ?>" id="lpdomf_<?= md5($d) ?>"
                                               <?= in_array($d, $selectedDomains, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="lpdomf_<?= md5($d) ?>"><?= htmlspecialchars($d) ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <div class="d-flex gap-2 mt-2 border-top pt-2">
                                    <button type="submit" class="btn btn-primary btn-sm flex-fill">Tillämpa</button>
                                    <a href="learning_path_stats.php<?= $pathId ? '?path_id=' . $pathId : '' ?>"
                                       class="btn btn-outline-secondary btn-sm">Rensa</a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <select name="path_id" class="form-select form-select-sm" style="max-width:320px;" onchange="this.form.submit()">
                        <option value="">-- Välj lärväg --</option>
                        <?php foreach ($paths as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $pathId === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['title']) ?> (<?= (int)$p['course_count'] ?> kurser)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($path): ?>
                        <input type="hidden" name="path_id" value="<?= (int)$path['id'] ?>">
                        <div class="form-check form-switch text-white mb-0">
                            <input class="form-check-input" type="checkbox" id="show_all" name="show_all" value="1"
                                   <?= $onlyActive ? '' : 'checked' ?> onchange="this.form.submit()">
                            <label class="form-check-label small" for="show_all">Visa alla användare</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <?php if (!$path): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                Välj en lärväg ovan för att se hur deltagarna ligger till.
            </div>
        <?php elseif (empty($courses)): ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Lärvägen innehåller inga kurser ännu.
                <a href="edit_learning_path.php?id=<?= (int)$path['id'] ?>">Lägg till kurser</a>.
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted small">Användare i urvalet</div>
                        <div class="h4 mb-0"><?= (int)$summary['users'] ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted small">Genomfört hela lärvägen</div>
                        <div class="h4 mb-0 text-success"><?= (int)$summary['completed_path'] ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted small">Påbörjat minst en kurs</div>
                        <div class="h4 mb-0 text-info"><?= (int)$summary['started'] ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="text-muted small">Genomsnittlig andel</div>
                        <div class="h4 mb-0"><?= (int)$summary['avg_percent'] ?> %</div>
                    </div>
                </div>
            </div>

            <p class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Genomförd</strong> = diplom utfärdat. <strong>Påbörjad</strong> = har klarat minst en lektion.
                <strong>Registrerad</strong> = inskriven eller tilldelad, men ingen lektion klar ännu.
                Externa deltagare i publika kurser ingår inte i domänurvalet.
            </p>

            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">Användare</th>
                            <?php foreach ($courses as $i => $c): ?>
                                <th class="text-center" style="min-width:110px;">
                                    <a href="course_stats.php?course_id=<?= (int)$c['id'] ?><?= $domainFilterQs ?>"
                                       class="text-decoration-none" title="<?= htmlspecialchars($c['title']) ?>">
                                        <?= ($i + 1) ?>. <?= htmlspecialchars(mb_strimwidth($c['title'], 0, 22, '…')) ?>
                                    </a>
                                    <?php if ($c['status'] !== 'active'): ?>
                                        <span class="badge bg-secondary" title="Inaktiv kurs">Inaktiv</span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center">Klart</th>
                            <th class="text-center" style="min-width:120px;">Andel</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $shown = 0;
                        foreach ($users as $u):
                            $uid = (int)$u['id'];
                            $s = $stats[$uid] ?? null;
                            if (!$s) {
                                continue;
                            }
                            if ($onlyActive && empty($s['started'])) {
                                continue;
                            }
                            $shown++;
                        ?>
                            <tr>
                                <td>
                                    <?php if (!empty($u['name'])): ?>
                                        <div><?= htmlspecialchars($u['name']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
                                </td>
                                <?php foreach ($courses as $c): ?>
                                    <?php $cell = $s['courses'][(int)$c['id']] ?? null; ?>
                                    <td class="text-center">
                                        <?php if (!$cell || $cell['status'] === 'not_started'): ?>
                                            <span class="text-muted">–</span>
                                        <?php elseif ($cell['status'] === 'completed'): ?>
                                            <i class="bi bi-check-circle-fill text-success"
                                               title="Genomförd <?= htmlspecialchars($cell['completion_date'] ?? '') ?>"></i>
                                        <?php elseif ($cell['status'] === 'in_progress'): ?>
                                            <span class="badge bg-info text-dark" title="Påbörjad"><?= (int)$cell['percent'] ?> %</span>
                                        <?php else: ?>
                                            <i class="bi bi-person-check text-warning" title="Registrerad, ej påbörjad"></i>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <span class="badge bg-<?= $s['done'] === $s['total'] ? 'success' : 'secondary' ?>">
                                        <?= (int)$s['done'] ?>/<?= (int)$s['total'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="progress" style="height:16px;" role="progressbar"
                                         aria-valuenow="<?= (int)$s['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar" style="width: <?= (int)$s['percent'] ?>%;">
                                            <?= (int)$s['percent'] ?> %
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($shown === 0): ?>
                            <tr>
                                <td colspan="<?= count($courses) + 3 ?>" class="text-center text-muted py-3">
                                    Ingen av användarna i urvalet har påbörjat någon kurs i lärvägen.
                                    <?php if ($onlyActive): ?>
                                        Slå på "Visa alla användare" för att se hela listan.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Totalt (genomförda / påbörjade+registrerade)</th>
                            <?php foreach ($courses as $c): ?>
                                <?php $cs = $courseSummary[(int)$c['id']] ?? ['completed' => 0, 'registered' => 0]; ?>
                                <th class="text-center small">
                                    <span class="text-success"><?= (int)$cs['completed'] ?></span>
                                    /
                                    <span class="text-muted"><?= (int)$cs['registered'] ?></span>
                                </th>
                            <?php endforeach; ?>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Sidnavigering">
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link"
                                   href="?path_id=<?= (int)$path['id'] ?>&page=<?= $p ?><?= $onlyActive ? '' : '&show_all=1' ?><?= $domainFilterQs ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                    <div class="text-muted small mt-1">
                        Visar användare <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $totalUsers) ?>
                        av <?= (int)$totalUsers ?> i urvalet.
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
