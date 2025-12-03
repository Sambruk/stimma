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

// Inkludera header
require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="bi bi-bar-chart me-2"></i><?= htmlspecialchars($statsTitle) ?>
                </h6>
                <div>
                    <?php if ($isAdmin): ?>
                    <span class="badge bg-primary me-2">Admin</span>
                    <?php else: ?>
                    <span class="badge bg-info me-2">Redaktör</span>
                    <?php endif; ?>
                    <span class="badge bg-secondary"><?= count($usersInDomain) ?> användare</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($courses)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Du har inga kurser att visa statistik för. Skapa en kurs eller bli tilldelad som redaktör för en befintlig kurs.
        </div>
    </div>
</div>
<?php else: ?>

<!-- Kursfilter -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="bi bi-funnel me-2"></i>Välj kurs för detaljerad statistik
                </h6>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="course_id" class="form-label">Kurs</label>
                        <select name="course_id" id="course_id" class="form-select">
                            <option value="">-- Välj en kurs --</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= $course['id'] ?>" <?= $selectedCourseId == $course['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($course['title']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-2"></i>Visa statistik
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Översikt per kurs -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="bi bi-journal-text me-2"></i>Kursöversikt
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Kurs</th>
                                <th>Lektioner</th>
                                <th>Användare påbörjat</th>
                                <th>Slutförda lektioner</th>
                                <th>Åtgärd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courseStats as $stat): ?>
                            <tr>
                                <td><?= htmlspecialchars($stat['course_title']) ?></td>
                                <td><?= $stat['total_lessons'] ?></td>
                                <td><?= $stat['users_started'] ?></td>
                                <td><?= $stat['completed_count'] ?></td>
                                <td>
                                    <a href="?course_id=<?= $stat['course_id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Detaljer
                                    </a>
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

<?php if ($courseDetails && !empty($userProgressGrouped)): ?>
<!-- Detaljerad kursstatistik -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-person-lines-fill me-2"></i>Användarframsteg: <?= htmlspecialchars($courseDetails['title']) ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 200px;">Användare</th>
                                <th style="min-width: 120px;">Framsteg</th>
                                <?php
                                $firstUser = reset($userProgressGrouped);
                                if ($firstUser):
                                    foreach ($firstUser['lessons'] as $lesson):
                                ?>
                                <th class="text-center" style="min-width: 40px;" title="<?= htmlspecialchars($lesson['title']) ?>">
                                    <small><?= mb_substr($lesson['title'], 0, 15) ?><?= mb_strlen($lesson['title']) > 15 ? '...' : '' ?></small>
                                </th>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userProgressGrouped as $userId => $userData): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($userData['email']) ?></strong>
                                    <?php if ($userData['name']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($userData['name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $percentage = $userData['total'] > 0 ? round(($userData['completed'] / $userData['total']) * 100) : 0;
                                    $progressClass = $percentage == 100 ? 'bg-success' : ($percentage > 50 ? 'bg-info' : ($percentage > 0 ? 'bg-warning' : 'bg-secondary'));
                                    ?>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar <?= $progressClass ?>" role="progressbar"
                                             style="width: <?= $percentage ?>%;"
                                             aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                            <?= $userData['completed'] ?>/<?= $userData['total'] ?>
                                        </div>
                                    </div>
                                    <small class="text-muted"><?= $percentage ?>%</small>
                                </td>
                                <?php foreach ($userData['lessons'] as $lesson): ?>
                                <td class="text-center">
                                    <?php if ($lesson['status'] === 'completed'): ?>
                                    <i class="bi bi-check-circle-fill text-success" title="Slutförd <?= $lesson['completed_at'] ?>"></i>
                                    <?php else: ?>
                                    <i class="bi bi-circle text-muted" title="Ej påbörjad"></i>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php elseif ($selectedCourseId && empty($userProgressGrouped)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>Inga användare har påbörjat denna kurs ännu.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Användaröversikt -->
<?php if (!empty($usersInDomain)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="bi bi-people me-2"></i><?= htmlspecialchars($userListTitle) ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>E-post</th>
                                <th>Namn</th>
                                <th>Registrerad</th>
                                <th>Senaste inloggning</th>
                                <th>Slutförda lektioner</th>
                                <th>Kurser påbörjade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersInDomain as $user): ?>
                            <tr>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['name'] ?? '-') ?></td>
                                <td><?= $user['created_at'] ? date('Y-m-d', strtotime($user['created_at'])) : '-' ?></td>
                                <td><?= $user['last_login_at'] ? date('Y-m-d H:i', strtotime($user['last_login_at'])) : 'Aldrig' ?></td>
                                <td>
                                    <span class="badge bg-<?= $user['completed_lessons'] > 0 ? 'success' : 'secondary' ?>">
                                        <?= $user['completed_lessons'] ?>
                                    </span>
                                </td>
                                <td>
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
