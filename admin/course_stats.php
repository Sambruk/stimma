<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 *
 * Kursstatistik med org-tagg-drill-down
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userEmail = $_SESSION['user_email'];
$userDomain = substr(strrchr($userEmail, "@"), 1);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;

if (!$isAdmin && !$isEditor) {
    $_SESSION['message'] = 'Du har inte behörighet att se kursstatistik.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$selectedCourseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

// Org-scope: alla domäner i adminens organisation
$orgScopeDomains = getOrgScopeDomains($userEmail);
$courseDomClause = buildDomainInClause($orgScopeDomains, 'c.organization_domain');

// Hämta kurser baserat på behörighet
if ($isAdmin) {
    $courses = query(
        "SELECT c.*, (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = c.id) AS lesson_count
         FROM " . DB_DATABASE . ".courses c
         WHERE {$courseDomClause['fragment']}
         ORDER BY c.id ASC",
        $courseDomClause['params']
    );
} else {
    $courses = query(
        "SELECT DISTINCT c.*, (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = c.id) AS lesson_count
         FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
         WHERE {$courseDomClause['fragment']}
           AND (c.author_id = ? OR ce.email = ?)
         ORDER BY c.id ASC",
        array_merge($courseDomClause['params'], [$currentUser['id'], $userEmail])
    );
}

// Om redaktör försöker se en kurs de inte har tillgång till
if ($selectedCourseId && !$isAdmin) {
    $hasAccess = queryOne(
        "SELECT c.id FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
         WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
        [$selectedCourseId, $currentUser['id'], $userEmail]
    );
    if (!$hasAccess) {
        $_SESSION['message'] = 'Du har inte behörighet att se statistik för denna kurs.';
        $_SESSION['message_type'] = 'danger';
        header('Location: course_stats.php');
        exit;
    }
}

$page_title = 'Kursstatistik';
require_once 'include/header.php';
?>

<div class="container-fluid">

<?php if (!$selectedCourseId): ?>
    <!-- VY 1: Kursöversikt -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-muted">Kursöversikt</h6>
        </div>
        <div class="card-body">
            <?php if (empty($courses)): ?>
                <div class="alert alert-info">Inga kurser hittades.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Titel</th>
                            <th>Typ</th>
                            <th>Status</th>
                            <th>Lektioner</th>
                            <th>Inskrivna</th>
                            <th>Klara</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course):
                            // Räkna inskrivna: unika användare som har progress ELLER
                            // publik registrering för kursen. UNION fångar publika
                            // deltagare som ännu inte öppnat någon lektion.
                            $enrolled = queryOne(
                                "SELECT COUNT(*) AS cnt FROM (
                                    SELECT p.user_id FROM " . DB_DATABASE . ".progress p
                                    JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                                    WHERE l.course_id = ?
                                    UNION
                                    SELECT user_id FROM " . DB_DATABASE . ".public_course_access WHERE course_id = ?
                                ) AS u",
                                [$course['id'], $course['id']]
                            );
                            $enrolledCount = $enrolled ? (int)$enrolled['cnt'] : 0;

                            // Räkna klara (alla lektioner completed)
                            $totalLessons = (int)$course['lesson_count'];
                            $completedUsers = 0;
                            if ($totalLessons > 0) {
                                $completed = queryOne(
                                    "SELECT COUNT(*) AS cnt FROM (
                                        SELECT p.user_id
                                        FROM " . DB_DATABASE . ".progress p
                                        JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                                        WHERE l.course_id = ? AND p.status = 'completed'
                                        GROUP BY p.user_id
                                        HAVING COUNT(DISTINCT p.lesson_id) >= ?
                                    ) AS completed_users",
                                    [$course['id'], $totalLessons]
                                );
                                $completedUsers = $completed ? (int)$completed['cnt'] : 0;
                            }
                        ?>
                        <tr>
                            <td><span class="text-muted"><?= (int)$course['id'] ?></span></td>
                            <td>
                                <a href="?course_id=<?= $course['id'] ?>" class="text-decoration-none fw-bold">
                                    <?= htmlspecialchars($course['title']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($course['sequential_mode']): ?>
                                    <span class="badge bg-info">Stegvis</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Normal</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($course['status'] === 'active'): ?>
                                    <span class="badge bg-success">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $totalLessons ?></td>
                            <td><?= $enrolledCount ?></td>
                            <td><?= $completedUsers ?></td>
                            <td>
                                <a href="?course_id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i>Detaljer
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <?php
    // VY 2: Kursdetalj
    $course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$selectedCourseId]);
    if (!$course) {
        echo '<div class="alert alert-danger">Kursen hittades inte.</div>';
        require_once 'include/footer.php';
        exit;
    }

    $courseLessons = query(
        "SELECT * FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC",
        [$selectedCourseId]
    );

    // Hämta kursens org-taggar
    $courseOrgTags = query(
        "SELECT tag FROM " . DB_DATABASE . ".course_org_tags WHERE course_id = ?",
        [$selectedCourseId]
    );
    $courseOrgTagValues = array_column($courseOrgTags, 'tag');
    $hasCourseOrgTags = !empty($courseOrgTagValues);

    // Bygg användargrupper baserat på org-taggar
    $userGroups = [];

    // Org-scope e-postfilter (alla orgens domäner)
    $statsUserEmailClause = buildEmailDomainInClause($orgScopeDomains, 'u.email');
    $statsUserEmailClauseUnprefixed = buildEmailDomainInClause($orgScopeDomains, 'email');

    if ($hasCourseOrgTags) {
        // Gruppera per org-tagg — utvidgat till alla orgens domäner
        foreach ($courseOrgTagValues as $tag) {
            $usersInTag = query(
                "SELECT u.id, u.name, u.email
                 FROM " . DB_DATABASE . ".users u
                 JOIN " . DB_DATABASE . ".user_org_tags uot ON u.id = uot.user_id
                 WHERE uot.tag = ? AND {$statsUserEmailClause['fragment']}
                 ORDER BY u.name ASC, u.email ASC",
                array_merge([$tag], $statsUserEmailClause['params'])
            );
            $userGroups[$tag] = $usersInTag;
        }
    } else {
        // Alla användare inom orgens samtliga domäner
        $allDomainUsers = query(
            "SELECT id, name, email FROM " . DB_DATABASE . ".users
             WHERE {$statsUserEmailClauseUnprefixed['fragment']}
             ORDER BY name ASC, email ASC",
            $statsUserEmailClauseUnprefixed['params']
        );
        $userGroups['Alla användare'] = $allDomainUsers;
    }

    // Publika deltagare: registrerade via publik länk, har oftast extern e-postdomän
    // och syns därför inte i domän-scope-filtret ovan. Lägg dem i en egen grupp
    // så admin alltid ser vilka som är externa deltagare. Körs även när is_public=0
    // eftersom en kurs kan ha avaktiverats med kvarvarande deltagare.
    {
        $publicParticipants = query(
            "SELECT u.id, u.name, u.email
             FROM " . DB_DATABASE . ".public_course_access pca
             JOIN " . DB_DATABASE . ".users u ON u.id = pca.user_id
             WHERE pca.course_id = ?
             ORDER BY u.name ASC, u.email ASC",
            [$selectedCourseId]
        );
        if (!empty($publicParticipants)) {
            // Filtrera bort ev. dubbletter som redan finns i org-grupper (interna
            // användare som också registrerat sig via publik länk).
            $existingIds = [];
            foreach ($userGroups as $groupUsers) {
                foreach ($groupUsers as $gu) {
                    $existingIds[(int)$gu['id']] = true;
                }
            }
            $filtered = array_filter($publicParticipants, function($u) use ($existingIds) {
                return !isset($existingIds[(int)$u['id']]);
            });
            if (!empty($filtered)) {
                $userGroups['Publika deltagare'] = array_values($filtered);
            }
        }
    }

    // Hämta all progress för denna kurs
    $allProgress = query(
        "SELECT p.user_id, p.lesson_id, p.status
         FROM " . DB_DATABASE . ".progress p
         JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
         WHERE l.course_id = ?",
        [$selectedCourseId]
    );
    $progressMap = [];
    foreach ($allProgress as $p) {
        $progressMap[$p['user_id']][$p['lesson_id']] = $p['status'];
    }

    // Hämta sequential schedule data
    $allSchedules = [];
    if ($course['sequential_mode']) {
        $scheduleRows = query(
            "SELECT * FROM " . DB_DATABASE . ".sequential_lesson_schedule WHERE course_id = ?",
            [$selectedCourseId]
        );
        foreach ($scheduleRows as $s) {
            $allSchedules[$s['user_id']][$s['lesson_id']] = $s;
        }
    }

    // Hämta enrollment-data för rolling-kurser
    $isRollingCourse = $course['sequential_mode'] && ($course['enrollment_type'] ?? 'bulk_start') === 'rolling';
    $allEnrollments = [];
    if ($isRollingCourse) {
        $enrollmentRows = query(
            "SELECT * FROM " . DB_DATABASE . ".course_enrollments WHERE course_id = ?",
            [$selectedCourseId]
        );
        foreach ($enrollmentRows as $e) {
            $allEnrollments[$e['user_id']] = $e;
        }
    }

    // Räkna sammanfattning. "Inskrivna" = endast användare som FAKTISKT är
    // inskrivna i kursen (rad i course_enrollments, sequential_lesson_schedule,
    // public_course_access eller har progress för någon av kursens lektioner).
    // Att bara vara medlem i orgen räcker inte — en användare som aldrig
    // öppnat eller skrivits in i kursen ska inte räknas som inskriven.
    $enrolledIdsRows = query(
        "SELECT DISTINCT user_id FROM (
            SELECT user_id FROM " . DB_DATABASE . ".course_enrollments WHERE course_id = ?
            UNION
            SELECT user_id FROM " . DB_DATABASE . ".sequential_lesson_schedule WHERE course_id = ?
            UNION
            SELECT user_id FROM " . DB_DATABASE . ".public_course_access WHERE course_id = ?
            UNION
            SELECT p.user_id FROM " . DB_DATABASE . ".progress p
            JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
            WHERE l.course_id = ?
        ) AS src",
        [$selectedCourseId, $selectedCourseId, $selectedCourseId, $selectedCourseId]
    );
    $enrolledIds = [];
    foreach ($enrolledIdsRows as $r) { $enrolledIds[(int)$r['user_id']] = true; }

    $totalNotStarted = 0;
    $totalCompleted = 0;
    $totalLessonsInCourse = count($courseLessons);

    foreach ($enrolledIds as $uid => $_v) {
        $userLessonsCompleted = 0;
        $hasAnyProgress = false;
        foreach ($courseLessons as $cl) {
            if (isset($progressMap[$uid][$cl['id']]) && $progressMap[$uid][$cl['id']] === 'completed') {
                $userLessonsCompleted++;
                $hasAnyProgress = true;
            } elseif (isset($progressMap[$uid][$cl['id']])) {
                $hasAnyProgress = true;
            }
        }

        if (!$hasAnyProgress) {
            $totalNotStarted++;
        } elseif ($totalLessonsInCourse > 0 && $userLessonsCompleted >= $totalLessonsInCourse) {
            $totalCompleted++;
        }
    }

    $totalEnrolled = count($enrolledIds);
    $totalUniqueUsers = $totalEnrolled;
    // $seenUserIds behövs fortfarande senare för avg-progress-beräkning
    $seenUserIds = $enrolledIds;

    $avgProgress = 0;
    if ($totalEnrolled > 0 && $totalLessonsInCourse > 0) {
        $totalCompletedLessons = 0;
        foreach ($seenUserIds as $uid => $v) {
            foreach ($courseLessons as $cl) {
                if (isset($progressMap[$uid][$cl['id']]) && $progressMap[$uid][$cl['id']] === 'completed') {
                    $totalCompletedLessons++;
                }
            }
        }
        $avgProgress = round(($totalCompletedLessons / ($totalEnrolled * $totalLessonsInCourse)) * 100);
    }
    ?>

    <div class="mb-3 d-flex gap-2">
        <a href="course_stats.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Tillbaka till översikt
        </a>
        <a href="export_statistics.php?course_id=<?= $selectedCourseId ?>&return=course_stats" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Exportera Excel
        </a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-muted">
                <?= htmlspecialchars($course['title']) ?>
                <?php if ($course['sequential_mode']): ?>
                    <span class="badge bg-info ms-2">Stegvis</span>
                <?php endif; ?>
            </h6>
            <div class="d-flex gap-2">
                <?php if ($isRollingCourse): ?>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#enrollUserModal">
                    <i class="bi bi-person-plus me-1"></i>Skriv in användare
                </button>
                <?php endif; ?>
                <?php if ($course['sequential_mode']): ?>
                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#manualReminderModal">
                    <i class="bi bi-bell me-1"></i>Skicka påminnelse
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- Sammanfattningskort -->
            <div class="row g-3 mb-4">
                <div class="col-sm-3">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <div class="h3 text-primary"><?= $totalEnrolled ?></div>
                            <small class="text-muted">Inskrivna</small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="card border-secondary">
                        <div class="card-body text-center">
                            <div class="h3 text-secondary"><?= $totalNotStarted ?></div>
                            <small class="text-muted">Ej påbörjat</small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <div class="h3 text-info"><?= $avgProgress ?>%</div>
                            <small class="text-muted">Genomsnitt</small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <div class="h3 text-success"><?= $totalCompleted ?></div>
                            <small class="text-muted">Klara</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Användargrupper -->
            <?php
            $groupIndex = 0;
            foreach ($userGroups as $groupName => $users):
                $groupIndex++;
            ?>
            <?php if ($hasCourseOrgTags): ?>
            <div class="accordion mb-3" id="orgTagAccordion<?= $groupIndex ?>">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $groupIndex > 1 ? 'collapsed' : '' ?>" type="button"
                                data-bs-toggle="collapse" data-bs-target="#orgTagCollapse<?= $groupIndex ?>">
                            <i class="bi bi-people me-2"></i>
                            <?= htmlspecialchars($groupName) ?>
                            <span class="badge bg-secondary ms-2"><?= count($users) ?> användare</span>
                        </button>
                    </h2>
                    <div id="orgTagCollapse<?= $groupIndex ?>" class="accordion-collapse collapse <?= $groupIndex === 1 ? 'show' : '' ?>">
                        <div class="accordion-body p-0">
            <?php endif; ?>

            <?php if (empty($users)): ?>
                <div class="p-3 text-muted">Inga användare i denna grupp.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Namn</th>
                                <th>E-post</th>
                                <th>Status</th>
                                <?php if ($isRollingCourse): ?>
                                <th>Startdatum</th>
                                <th>Senaste lektion</th>
                                <th>Beräknat slut</th>
                                <?php endif; ?>
                                <?php foreach ($courseLessons as $cl): ?>
                                <th class="text-center" style="min-width: 40px;" title="<?= htmlspecialchars($cl['title']) ?>">
                                    L<?= $cl['sort_order'] ?>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u):
                                $userLessonsCompleted = 0;
                                $hasAnyProgress = false;
                                foreach ($courseLessons as $cl) {
                                    if (isset($progressMap[$u['id']][$cl['id']]) && $progressMap[$u['id']][$cl['id']] === 'completed') {
                                        $userLessonsCompleted++;
                                        $hasAnyProgress = true;
                                    } elseif (isset($progressMap[$u['id']][$cl['id']])) {
                                        $hasAnyProgress = true;
                                    }
                                }

                                if ($totalLessonsInCourse > 0 && $userLessonsCompleted >= $totalLessonsInCourse) {
                                    $statusBadge = '<span class="badge bg-success">Klar</span>';
                                } elseif ($hasAnyProgress) {
                                    $statusBadge = '<span class="badge bg-primary">Pågår</span>';
                                } else {
                                    $statusBadge = '<span class="badge bg-light text-dark">Ej påbörjad</span>';
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($u['name'] ?: '-') ?></td>
                                <td class="small"><?= htmlspecialchars($u['email']) ?></td>
                                <td><?= $statusBadge ?></td>
                                <?php if ($isRollingCourse):
                                    $enrollment = $allEnrollments[$u['id']] ?? null;
                                    $startedAt = $enrollment ? $enrollment['started_at'] : null;

                                    // Senaste tillgängliga lektion
                                    $latestAvailable = null;
                                    if (isset($allSchedules[$u['id']])) {
                                        foreach ($allSchedules[$u['id']] as $sched) {
                                            if ($sched['available_at'] && strtotime($sched['available_at']) <= time()) {
                                                if (!$latestAvailable || strtotime($sched['available_at']) > strtotime($latestAvailable)) {
                                                    $latestAvailable = $sched['available_at'];
                                                }
                                            }
                                        }
                                    }

                                    $projectedEnd = getProjectedEndDate(
                                        $startedAt,
                                        $totalLessonsInCourse,
                                        (int)$course['sequential_interval_days']
                                    );
                                ?>
                                <td class="small"><?= $startedAt ? date('Y-m-d', strtotime($startedAt)) : '-' ?></td>
                                <td class="small"><?= $latestAvailable ? date('Y-m-d', strtotime($latestAvailable)) : '-' ?></td>
                                <td class="small"><?= $projectedEnd ?: '-' ?></td>
                                <?php endif; ?>
                                <?php foreach ($courseLessons as $cl):
                                    $lessonStatus = $progressMap[$u['id']][$cl['id']] ?? null;
                                    $schedule = $allSchedules[$u['id']][$cl['id']] ?? null;

                                    if ($lessonStatus === 'completed') {
                                        $icon = '<i class="bi bi-check-circle-fill text-success" title="Klar"></i>';
                                    } elseif ($course['sequential_mode'] && $schedule) {
                                        if ($schedule['available_at'] !== null && strtotime($schedule['available_at']) <= time()) {
                                            $icon = '<i class="bi bi-clock text-primary" title="Tillgänglig"></i>';
                                        } elseif ($schedule['available_at'] !== null) {
                                            $icon = '<i class="bi bi-lock text-muted" title="Öppnas ' . date('j/n', strtotime($schedule['available_at'])) . '"></i>';
                                        } else {
                                            $icon = '<i class="bi bi-lock text-muted" title="Ej tillgänglig"></i>';
                                        }
                                    } elseif ($hasAnyProgress || $lessonStatus !== null) {
                                        $icon = '<i class="bi bi-circle text-muted" title="Ej påbörjad"></i>';
                                    } else {
                                        $icon = '<i class="bi bi-circle text-muted" title="Ej påbörjad"></i>';
                                    }
                                ?>
                                <td class="text-center"><?= $icon ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($hasCourseOrgTags): ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($course['sequential_mode']): ?>
    <!-- E-postlogg -->
    <?php
    // Kö-status
    $queueStats = queryOne(
        "SELECT
            SUM(status = 'queued') AS queued,
            SUM(status = 'sending') AS sending,
            SUM(status = 'sent') AS sent,
            SUM(status = 'failed') AS failed
         FROM " . DB_DATABASE . ".sequential_email_queue WHERE course_id = ?",
        [$selectedCourseId]
    );
    // Logg grupperad per datum + typ
    $emailLog = query(
        "SELECT DATE_FORMAT(sent_at, '%Y-%m-%d %H:%i') AS log_date, type,
                SUM(email_status = 'sent') AS sent_count,
                SUM(email_status = 'failed') AS failed_count
         FROM " . DB_DATABASE . ".sequential_reminder_log
         WHERE course_id = ?
         GROUP BY DATE_FORMAT(sent_at, '%Y-%m-%d %H:%i'), type
         ORDER BY log_date DESC
         LIMIT 100",
        [$selectedCourseId]
    );
    ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 text-muted"><i class="bi bi-envelope-paper me-2"></i>E-postlogg</h6>
        </div>
        <div class="card-body">
            <?php if ($queueStats && ($queueStats['queued'] > 0 || $queueStats['sending'] > 0)): ?>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-hourglass-split me-1"></i>
                <strong>Kö:</strong>
                <?= (int)$queueStats['queued'] ?> väntande,
                <?= (int)$queueStats['sending'] ?> skickas just nu
            </div>
            <?php endif; ?>

            <?php if (empty($emailLog)): ?>
            <p class="text-muted mb-0">Inga e-postutskick har loggats för denna kurs ännu.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Typ</th>
                            <th class="text-end">Skickade</th>
                            <th class="text-end">Misslyckade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emailLog as $logRow):
                            $typeBadge = match($logRow['type']) {
                                'new_lesson' => '<span class="badge bg-success">Ny lektion</span>',
                                'reminder' => '<span class="badge bg-warning text-dark">Påminnelse</span>',
                                'manual_reminder' => '<span class="badge bg-info">Manuell</span>',
                                default => '<span class="badge bg-secondary">' . htmlspecialchars($logRow['type']) . '</span>',
                            };
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($logRow['log_date']) ?></td>
                            <td><?= $typeBadge ?></td>
                            <td class="text-end"><?= (int)$logRow['sent_count'] ?></td>
                            <td class="text-end"><?= (int)$logRow['failed_count'] > 0 ? '<span class="text-danger">' . (int)$logRow['failed_count'] . '</span>' : '0' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal för manuell påminnelse -->
    <div class="modal fade" id="manualReminderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bell me-2"></i>Skicka manuell påminnelse</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Skickar en påminnelse till alla användare som har en tillgänglig lektion de inte har slutfört.</p>
                    <div class="mb-3">
                        <label for="customMessage" class="form-label">Valfritt meddelande (visas i e-posten)</label>
                        <textarea class="form-control" id="customMessage" rows="3" placeholder="Skriv ett personligt meddelande..."></textarea>
                    </div>
                    <div id="reminderResult" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-warning" id="sendReminderBtn">
                        <i class="bi bi-send me-1"></i>Skicka påminnelse
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('sendReminderBtn').addEventListener('click', function() {
        const btn = this;
        const resultDiv = document.getElementById('reminderResult');
        const customMessage = document.getElementById('customMessage').value;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Skickar...';
        resultDiv.style.display = 'none';

        const formData = new FormData();
        formData.append('course_id', <?= $selectedCourseId ?>);
        formData.append('custom_message', customMessage);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('ajax/send_manual_sequential_reminder.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            resultDiv.style.display = 'block';
            if (data.success) {
                resultDiv.className = 'alert alert-success';
                resultDiv.textContent = data.message;
            } else {
                resultDiv.className = 'alert alert-danger';
                resultDiv.textContent = data.message || 'Ett fel uppstod.';
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Skicka påminnelse';
        })
        .catch(error => {
            resultDiv.style.display = 'block';
            resultDiv.className = 'alert alert-danger';
            resultDiv.textContent = 'Nätverksfel. Försök igen.';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Skicka påminnelse';
        });
    });
    </script>
    <?php endif; ?>

    <?php if ($isRollingCourse): ?>
    <!-- Modal för inskrivning -->
    <div class="modal fade" id="enrollUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Skriv in användare</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Sök användare</label>
                        <input type="text" class="form-control" id="statsEnrollSearch" placeholder="Sök namn eller e-post..." autocomplete="off">
                        <div id="statsEnrollResults" class="list-group position-absolute mt-1" style="z-index: 1060; display: none;"></div>
                        <input type="hidden" id="statsEnrollEmail" value="">
                    </div>
                    <?php
                    // Org-taggar från alla orgens domäner
                    $statsOrgTags = [];
                    $seenStatsOrgTags = [];
                    foreach ($orgScopeDomains as $scopeDomain) {
                        foreach (getOrgTagsForDomain($scopeDomain) as $tagRow) {
                            $tagName = $tagRow['tag'] ?? null;
                            if ($tagName !== null && !isset($seenStatsOrgTags[$tagName])) {
                                $statsOrgTags[] = $tagRow;
                                $seenStatsOrgTags[$tagName] = true;
                            }
                        }
                    }
                    if (!empty($statsOrgTags)): ?>
                    <div class="mb-3">
                        <label class="form-label">Eller hela org-taggen</label>
                        <select class="form-select" id="statsEnrollOrgTag">
                            <option value="">— Välj —</option>
                            <?php foreach ($statsOrgTags as $ot): ?>
                            <option value="<?= htmlspecialchars($ot['tag']) ?>"><?= htmlspecialchars($ot['tag']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Startdatum</label>
                        <input type="date" class="form-control" id="statsEnrollDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div id="statsEnrollResult" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary" id="statsEnrollBtn" disabled>
                        <i class="bi bi-person-plus me-1"></i>Skriv in
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var search = document.getElementById('statsEnrollSearch');
        var results = document.getElementById('statsEnrollResults');
        var emailInput = document.getElementById('statsEnrollEmail');
        var orgTag = document.getElementById('statsEnrollOrgTag');
        var enrollBtn = document.getElementById('statsEnrollBtn');
        var searchTimeout = null;

        function updateBtn() {
            enrollBtn.disabled = !(emailInput.value || (orgTag && orgTag.value));
        }

        search.addEventListener('input', function() {
            var q = this.value.trim();
            emailInput.value = '';
            updateBtn();
            if (q.length < 2) { results.style.display = 'none'; return; }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                fetch('ajax/search_users.php?search=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        results.innerHTML = '';
                        if (data.success && data.users && data.users.length > 0) {
                            data.users.forEach(function(u) {
                                var item = document.createElement('a');
                                item.href = '#';
                                item.className = 'list-group-item list-group-item-action py-1 small';
                                item.textContent = (u.name || '') + ' (' + u.email + ')';
                                item.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    search.value = (u.name || '') + ' (' + u.email + ')';
                                    emailInput.value = u.email;
                                    results.style.display = 'none';
                                    if (orgTag) orgTag.value = '';
                                    updateBtn();
                                });
                                results.appendChild(item);
                            });
                            results.style.display = 'block';
                        } else {
                            results.style.display = 'none';
                        }
                    });
            }, 300);
        });

        if (orgTag) {
            orgTag.addEventListener('change', function() {
                if (this.value) { search.value = ''; emailInput.value = ''; }
                updateBtn();
            });
        }

        document.addEventListener('click', function(e) {
            if (!results.contains(e.target) && e.target !== search) results.style.display = 'none';
        });

        enrollBtn.addEventListener('click', function() {
            var btn = this;
            var resultDiv = document.getElementById('statsEnrollResult');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Skriver in...';
            resultDiv.style.display = 'none';

            var formData = new FormData();
            formData.append('course_id', '<?= $selectedCourseId ?>');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('start_date', document.getElementById('statsEnrollDate').value);
            if (emailInput.value) formData.append('email', emailInput.value);
            if (orgTag && orgTag.value) formData.append('org_tag', orgTag.value);

            fetch('ajax/enroll_user_sequential.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
                    resultDiv.textContent = data.message || 'Ett fel uppstod.';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-person-plus me-1"></i>Skriv in';
                    if (data.success && data.enrolled > 0) {
                        setTimeout(function() { location.reload(); }, 2000);
                    }
                })
                .catch(function() {
                    resultDiv.style.display = 'block';
                    resultDiv.className = 'alert alert-danger';
                    resultDiv.textContent = 'Nätverksfel.';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-person-plus me-1"></i>Skriv in';
                });
        });
    })();
    </script>
    <?php endif; ?>

<?php endif; ?>

</div>

<?php require_once 'include/footer.php'; ?>
