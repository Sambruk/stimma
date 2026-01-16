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

// Kontrollera att användaren är inloggad
require_once 'include/auth_check.php';

// Hämta användarinformation
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userEmail = $_SESSION['user_email'];
$userDomain = substr(strrchr($userEmail, "@"), 1);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;

// Kontrollera behörighet - måste vara admin eller redaktör
if (!$isAdmin && !$isEditor) {
    $_SESSION['message'] = 'Du har inte behörighet att se statistik.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Statistik';

// Hämta vald kurs om sådan finns
$selectedCourseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

// Hämta kurser baserat på behörighet
if ($isAdmin) {
    // Admin ser alla kurser
    $courses = query("SELECT id, title FROM " . DB_DATABASE . ".courses WHERE status = 'active' ORDER BY title ASC");

    // Hämta kurs-IDs för statistikfrågor (alla kurser)
    $courseIds = array_column($courses, 'id');
} else {
    // Redaktör ser endast kurser de är redaktör för eller har skapat
    $courses = query("SELECT DISTINCT c.id, c.title
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
        WHERE c.status = 'active'
        AND (c.author_id = ? OR ce.email = ?)
        ORDER BY c.title ASC",
        [$currentUser['id'], $userEmail]);

    $courseIds = array_column($courses, 'id');
}

// Om redaktör försöker se en kurs de inte har tillgång till
if ($selectedCourseId && !$isAdmin) {
    $hasAccess = queryOne("SELECT c.id FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
        WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
        [$selectedCourseId, $currentUser['id'], $userEmail]);

    if (!$hasAccess) {
        $_SESSION['message'] = 'Du har inte behörighet att se statistik för denna kurs.';
        $_SESSION['message_type'] = 'danger';
        header('Location: statistics.php');
        exit;
    }
}

// Bygg IN-klausul för kurs-IDs
$courseIdsPlaceholder = !empty($courseIds) ? implode(',', array_fill(0, count($courseIds), '?')) : '0';

// Hämta användare - för admin: alla i domänen, för redaktör: alla som använt deras kurser
if ($isAdmin) {
    $domainPattern = '%@' . $userDomain;
    $usersInDomain = query("SELECT
        u.id,
        u.email,
        u.name,
        u.created_at,
        u.last_login_at,
        COUNT(DISTINCT p.lesson_id) as completed_lessons,
        COUNT(DISTINCT l.course_id) as courses_started
    FROM " . DB_DATABASE . ".users u
    LEFT JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id AND p.status = 'completed'
    LEFT JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
    WHERE u.email LIKE ?
    GROUP BY u.id, u.email, u.name, u.created_at, u.last_login_at
    ORDER BY u.email ASC", [$domainPattern]);

    $statsTitle = "Statistik för domän: " . $userDomain;
    $userListTitle = "Alla användare i " . $userDomain;
} else {
    // För redaktörer: visa användare som har interagerat med deras kurser
    if (!empty($courseIds)) {
        $usersInDomain = query("SELECT
            u.id,
            u.email,
            u.name,
            u.created_at,
            u.last_login_at,
            COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.lesson_id END) as completed_lessons,
            COUNT(DISTINCT l.course_id) as courses_started
        FROM " . DB_DATABASE . ".users u
        JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id
        JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
        WHERE l.course_id IN ($courseIdsPlaceholder)
        GROUP BY u.id, u.email, u.name, u.created_at, u.last_login_at
        ORDER BY u.email ASC", $courseIds);
    } else {
        $usersInDomain = [];
    }

    $statsTitle = "Statistik för dina kurser";
    $userListTitle = "Användare som interagerat med dina kurser";
}

// Hämta kursstatistik
if (!empty($courseIds)) {
    if ($isAdmin) {
        $domainPattern = '%@' . $userDomain;
        $courseStatsQuery = "SELECT
            c.id as course_id,
            c.title as course_title,
            COUNT(DISTINCT l.id) as total_lessons,
            COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as completed_count,
            COUNT(DISTINCT p.user_id) as users_started
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id AND l.status = 'active'
        LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id
        LEFT JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id AND u.email LIKE ?
        WHERE c.status = 'active'
        GROUP BY c.id, c.title
        ORDER BY c.title ASC";
        $courseStats = query($courseStatsQuery, [$domainPattern]);
    } else {
        // För redaktörer: statistik för alla användare på deras kurser
        $courseStats = query("SELECT
            c.id as course_id,
            c.title as course_title,
            COUNT(DISTINCT l.id) as total_lessons,
            COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as completed_count,
            COUNT(DISTINCT p.user_id) as users_started
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id AND l.status = 'active'
        LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id
        WHERE c.id IN ($courseIdsPlaceholder) AND c.status = 'active'
        GROUP BY c.id, c.title
        ORDER BY c.title ASC", $courseIds);
    }
} else {
    $courseStats = [];
}

// Om en specifik kurs är vald, hämta detaljerad statistik
$courseDetails = null;
$userProgressGrouped = [];
if ($selectedCourseId) {
    // Hämta kursinformation
    $courseDetails = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$selectedCourseId]);

    if ($courseDetails) {
        // Hämta alla lektioner i kursen
        $lessonsInCourse = query("SELECT id, title, sort_order FROM " . DB_DATABASE . ".lessons
                                  WHERE course_id = ? AND status = 'active'
                                  ORDER BY sort_order ASC", [$selectedCourseId]);

        // Hämta progress för användare
        if ($isAdmin) {
            // Admin ser endast användare från sin domän
            $domainPattern = '%@' . $userDomain;
            $userProgressInCourse = query("SELECT
                u.id as user_id,
                u.email,
                u.name,
                l.id as lesson_id,
                l.title as lesson_title,
                l.sort_order,
                p.status as progress_status,
                p.updated_at as completed_at
            FROM " . DB_DATABASE . ".users u
            CROSS JOIN " . DB_DATABASE . ".lessons l
            LEFT JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id AND l.id = p.lesson_id
            WHERE u.email LIKE ?
            AND l.course_id = ?
            AND l.status = 'active'
            ORDER BY u.email ASC, l.sort_order ASC", [$domainPattern, $selectedCourseId]);
        } else {
            // Redaktör ser alla användare som har interagerat med kursen
            $userProgressInCourse = query("SELECT
                u.id as user_id,
                u.email,
                u.name,
                l.id as lesson_id,
                l.title as lesson_title,
                l.sort_order,
                p.status as progress_status,
                p.updated_at as completed_at
            FROM " . DB_DATABASE . ".users u
            CROSS JOIN " . DB_DATABASE . ".lessons l
            LEFT JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id AND l.id = p.lesson_id
            WHERE u.id IN (
                SELECT DISTINCT p2.user_id FROM " . DB_DATABASE . ".progress p2
                JOIN " . DB_DATABASE . ".lessons l2 ON p2.lesson_id = l2.id
                WHERE l2.course_id = ?
            )
            AND l.course_id = ?
            AND l.status = 'active'
            ORDER BY u.email ASC, l.sort_order ASC", [$selectedCourseId, $selectedCourseId]);
        }

        // Organisera data per användare
        foreach ($userProgressInCourse as $row) {
            $userId = $row['user_id'];
            if (!isset($userProgressGrouped[$userId])) {
                $userProgressGrouped[$userId] = [
                    'email' => $row['email'],
                    'name' => $row['name'],
                    'lessons' => [],
                    'completed' => 0,
                    'total' => 0
                ];
            }
            $userProgressGrouped[$userId]['lessons'][] = [
                'id' => $row['lesson_id'],
                'title' => $row['lesson_title'],
                'status' => $row['progress_status'],
                'completed_at' => $row['completed_at']
            ];
            $userProgressGrouped[$userId]['total']++;
            if ($row['progress_status'] === 'completed') {
                $userProgressGrouped[$userId]['completed']++;
            }
        }
    }
}

// Beräkna sammanfattande statistik
$totalUsersCount = count($usersInDomain);
$totalCompletedLessons = array_sum(array_column($usersInDomain, 'completed_lessons'));
$totalCoursesCount = count($courseStats);
$avgCompletionRate = 0;
if (!empty($courseStats)) {
    $totalPossibleCompletions = 0;
    $actualCompletions = 0;
    foreach ($courseStats as $stat) {
        $totalPossibleCompletions += $stat['total_lessons'] * $stat['users_started'];
        $actualCompletions += $stat['completed_count'];
    }
    $avgCompletionRate = $totalPossibleCompletions > 0 ? round(($actualCompletions / $totalPossibleCompletions) * 100) : 0;
}

// Beräkna antal fullt genomförda kurser (där användare slutfört ALLA lektioner)
$fullyCompletedCourses = 0;
$avgCoursesPerUser = 0;

if (!empty($courseIds)) {
    if ($isAdmin) {
        $domainPattern = '%@' . $userDomain;
        // Hämta antal användare som slutfört alla lektioner i varje kurs
        $fullyCompletedResult = queryOne("
            SELECT COUNT(*) as total_completions
            FROM (
                SELECT p.user_id, l.course_id,
                       COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) as completed_lessons,
                       (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active') as total_lessons
                FROM " . DB_DATABASE . ".progress p
                JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
                WHERE u.email LIKE ?
                AND l.course_id IN ($courseIdsPlaceholder)
                GROUP BY p.user_id, l.course_id
                HAVING completed_lessons = total_lessons AND total_lessons > 0
            ) as completed_courses
        ", array_merge([$domainPattern], $courseIds));
        $fullyCompletedCourses = $fullyCompletedResult['total_completions'] ?? 0;

        // Beräkna genomsnittligt antal slutförda kurser per användare
        $avgCoursesResult = queryOne("
            SELECT AVG(courses_completed) as avg_courses
            FROM (
                SELECT u.id, COUNT(DISTINCT completed_courses.course_id) as courses_completed
                FROM " . DB_DATABASE . ".users u
                LEFT JOIN (
                    SELECT p.user_id, l.course_id
                    FROM " . DB_DATABASE . ".progress p
                    JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                    WHERE l.course_id IN ($courseIdsPlaceholder)
                    GROUP BY p.user_id, l.course_id
                    HAVING COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) =
                           (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active')
                           AND (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active') > 0
                ) as completed_courses ON u.id = completed_courses.user_id
                WHERE u.email LIKE ?
                GROUP BY u.id
                HAVING courses_completed > 0
            ) as user_courses
        ", array_merge($courseIds, [$domainPattern]));
        $avgCoursesPerUser = round($avgCoursesResult['avg_courses'] ?? 0, 1);
    } else {
        // För redaktörer
        $fullyCompletedResult = queryOne("
            SELECT COUNT(*) as total_completions
            FROM (
                SELECT p.user_id, l.course_id,
                       COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) as completed_lessons,
                       (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active') as total_lessons
                FROM " . DB_DATABASE . ".progress p
                JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                WHERE l.course_id IN ($courseIdsPlaceholder)
                GROUP BY p.user_id, l.course_id
                HAVING completed_lessons = total_lessons AND total_lessons > 0
            ) as completed_courses
        ", $courseIds);
        $fullyCompletedCourses = $fullyCompletedResult['total_completions'] ?? 0;

        // Genomsnitt för redaktörer
        $avgCoursesResult = queryOne("
            SELECT AVG(courses_completed) as avg_courses
            FROM (
                SELECT p.user_id, COUNT(DISTINCT completed_courses.course_id) as courses_completed
                FROM " . DB_DATABASE . ".progress p
                JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                LEFT JOIN (
                    SELECT p2.user_id, l2.course_id
                    FROM " . DB_DATABASE . ".progress p2
                    JOIN " . DB_DATABASE . ".lessons l2 ON p2.lesson_id = l2.id
                    WHERE l2.course_id IN ($courseIdsPlaceholder)
                    GROUP BY p2.user_id, l2.course_id
                    HAVING COUNT(DISTINCT CASE WHEN p2.status = 'completed' THEN l2.id END) =
                           (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l2.course_id AND status = 'active')
                           AND (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l2.course_id AND status = 'active') > 0
                ) as completed_courses ON p.user_id = completed_courses.user_id
                WHERE l.course_id IN ($courseIdsPlaceholder)
                GROUP BY p.user_id
                HAVING courses_completed > 0
            ) as user_courses
        ", array_merge($courseIds, $courseIds));
        $avgCoursesPerUser = round($avgCoursesResult['avg_courses'] ?? 0, 1);
    }
}

// Inkludera header
require_once 'include/header.php';
?>

<?php if (empty($courses)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Du har inga kurser att visa statistik för. Skapa en kurs eller bli tilldelad som redaktör för en befintlig kurs.
</div>
<?php else: ?>

<!-- Kursval och användarframsteg -->
<div class="card shadow mb-4">
    <div class="card-header py-3 bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-bar-chart-fill me-2"></i>Kursstatistik
                </h6>
            </div>
            <div class="col-md-6">
                <form method="GET" class="d-flex align-items-center justify-content-end gap-2 mb-0">
                    <select name="course_id" id="course_id" class="form-select form-select-sm" style="max-width: 300px;" onchange="this.form.submit()">
                        <option value="">-- Välj kurs --</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>" <?= $selectedCourseId == $course['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['title']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selectedCourseId): ?>
                    <a href="export_statistics.php?course_id=<?= $selectedCourseId ?>" class="btn btn-light btn-sm" title="Exportera till Excel">
                        <i class="bi bi-file-earmark-excel"></i>
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (!$selectedCourseId): ?>
        <!-- Kursöversikt när ingen kurs är vald -->
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kurs</th>
                        <th class="text-center">Lektioner</th>
                        <th class="text-center">Aktiva användare</th>
                        <th class="text-center">Slutförda</th>
                        <th style="width: 200px;">Slutförandegrad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courseStats as $stat):
                        $completionRate = ($stat['total_lessons'] * $stat['users_started']) > 0
                            ? round(($stat['completed_count'] / ($stat['total_lessons'] * $stat['users_started'])) * 100)
                            : 0;
                        $progressClass = $completionRate >= 75 ? 'bg-success' : ($completionRate >= 50 ? 'bg-info' : ($completionRate >= 25 ? 'bg-warning' : 'bg-danger'));
                    ?>
                    <tr style="cursor: pointer;" onclick="window.location='?course_id=<?= $stat['course_id'] ?>'">
                        <td>
                            <strong><?= htmlspecialchars($stat['course_title']) ?></strong>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $stat['total_lessons'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary"><?= $stat['users_started'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-success"><?= $stat['completed_count'] ?></span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                    <div class="progress-bar <?= $progressClass ?>" style="width: <?= $completionRate ?>%;"></div>
                                </div>
                                <small class="text-muted"><?= $completionRate ?>%</small>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-center text-muted mt-3">
            <small><i class="bi bi-info-circle me-1"></i>Klicka på en kurs för att se detaljerad användarstatistik</small>
        </div>

        <?php elseif ($courseDetails && !empty($userProgressGrouped)): ?>
        <!-- Detaljerad användarframsteg för vald kurs -->
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1"><?= htmlspecialchars($courseDetails['title']) ?></h5>
                <small class="text-muted">
                    <?= count($userProgressGrouped) ?> användare ·
                    <?= count($firstUser['lessons'] ?? []) ?> lektioner
                </small>
            </div>
            <div class="d-flex gap-2">
                <a href="export_statistics.php?course_id=<?= $selectedCourseId ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exportera till Excel
                </a>
                <a href="?course_id=" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Tillbaka
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width: 220px; position: sticky; left: 0; background: #f8f9fa; z-index: 1;">Användare</th>
                        <th style="min-width: 150px;">Framsteg</th>
                        <?php
                        $firstUser = reset($userProgressGrouped);
                        if ($firstUser):
                            $lessonNum = 1;
                            foreach ($firstUser['lessons'] as $lesson):
                        ?>
                        <th class="text-center" style="min-width: 50px;" title="<?= htmlspecialchars($lesson['title']) ?>">
                            <span class="badge bg-light text-dark"><?= $lessonNum++ ?></span>
                        </th>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userProgressGrouped as $userId => $userData):
                        $percentage = $userData['total'] > 0 ? round(($userData['completed'] / $userData['total']) * 100) : 0;
                        $progressClass = $percentage == 100 ? 'bg-success' : ($percentage >= 50 ? 'bg-info' : ($percentage > 0 ? 'bg-warning' : 'bg-secondary'));
                    ?>
                    <tr>
                        <td style="position: sticky; left: 0; background: white; z-index: 1;">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-<?= $percentage == 100 ? 'success' : 'primary' ?> text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 12px;">
                                    <?= strtoupper(substr($userData['email'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="fw-bold" style="font-size: 0.85rem;"><?= htmlspecialchars(explode('@', $userData['email'])[0]) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars(explode('@', $userData['email'])[1] ?? '') ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                    <div class="progress-bar <?= $progressClass ?>" role="progressbar"
                                         style="width: <?= $percentage ?>%;">
                                        <?= $userData['completed'] ?>/<?= $userData['total'] ?>
                                    </div>
                                </div>
                                <span class="badge <?= $progressClass ?>"><?= $percentage ?>%</span>
                            </div>
                        </td>
                        <?php foreach ($userData['lessons'] as $lesson): ?>
                        <td class="text-center align-middle">
                            <?php if ($lesson['status'] === 'completed'): ?>
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 1.2rem;" title="Slutförd: <?= date('Y-m-d H:i', strtotime($lesson['completed_at'])) ?>"></i>
                            <?php else: ?>
                            <i class="bi bi-circle text-muted" style="font-size: 1.2rem;" title="Ej slutförd"></i>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Lektionsförklaring -->
        <div class="mt-3 p-3 bg-light rounded">
            <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>Lektioner</h6>
            <div class="row">
                <?php
                $lessonNum = 1;
                foreach ($firstUser['lessons'] as $lesson):
                ?>
                <div class="col-md-4 col-lg-3 mb-1">
                    <small><span class="badge bg-secondary me-1"><?= $lessonNum++ ?></span><?= htmlspecialchars($lesson['title']) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php elseif ($selectedCourseId): ?>
        <!-- Ingen data för vald kurs -->
        <div class="text-center py-5">
            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
            <h5 class="mt-3 text-muted">Inga användare har påbörjat denna kurs ännu</h5>
            <a href="?course_id=" class="btn btn-outline-primary mt-2">
                <i class="bi bi-arrow-left me-1"></i>Tillbaka till översikt
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Användaröversikt (dold bakom accordion för att spara plats) -->
<?php if (!empty($usersInDomain) && !$selectedCourseId): ?>
<div class="accordion mb-4" id="userAccordion">
    <div class="accordion-item shadow">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#userList">
                <i class="bi bi-people me-2"></i>
                Alla användare i <?= htmlspecialchars($userDomain) ?>
                <span class="badge bg-secondary ms-2"><?= count($usersInDomain) ?></span>
            </button>
        </h2>
        <div id="userList" class="accordion-collapse collapse" data-bs-parent="#userAccordion">
            <div class="accordion-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Användare</th>
                                <th class="text-center">Registrerad</th>
                                <th class="text-center">Senast aktiv</th>
                                <th class="text-center">Slutförda</th>
                                <th class="text-center">Kurser</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersInDomain as $user): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 12px;">
                                            <?= strtoupper(substr($user['email'], 0, 2)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars(explode('@', $user['email'])[0]) ?></div>
                                            <?php if ($user['name']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($user['name']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <small><?= $user['created_at'] ? date('Y-m-d', strtotime($user['created_at'])) : '-' ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($user['last_login_at']): ?>
                                    <small><?= date('Y-m-d', strtotime($user['last_login_at'])) ?></small>
                                    <?php else: ?>
                                    <span class="badge bg-light text-muted">Aldrig</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $user['completed_lessons'] > 0 ? 'success' : 'secondary' ?>">
                                        <?= $user['completed_lessons'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $user['courses_started'] > 0 ? 'info' : 'secondary' ?>">
                                        <?= $user['courses_started'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
// Inkludera footer
require_once 'include/footer.php';
?>
