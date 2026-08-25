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

// SECURITY FIX: Include centralized authentication and authorization check
require_once 'include/auth_check.php';

// Sätt sidtitel
$page_title = 'Admin';

// Chart.js laddas via admin-headern (cdnjs); ingen extra script-tag behövs här.

// Hämta aktuell användares domän och roll
$currentUser = queryOne("SELECT email, role, is_admin FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$currentUserDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isSuperAdmin = $currentUser['role'] === 'super_admin';

// Org-scope: alla domäner i adminens organisation (eller bara $currentUserDomain
// om domänen inte tillhör någon org)
$orgScopeDomains = getOrgScopeDomains($currentUser['email']);
$courseDomClause = buildDomainInClause($orgScopeDomains, 'c.organization_domain');
$emailDomClause = buildEmailDomainInClause($orgScopeDomains, 'u.email');

// Hämta statistik för dashboard - filtrera på organisation om inte superadmin
if ($isSuperAdmin) {
    $totalUsers = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".users")['count'] ?? 0;
    $totalCourses = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".courses")['count'] ?? 0;
    $totalLessons = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".lessons")['count'] ?? 0;
    $totalCompletions = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".progress WHERE status = 'completed'")['count'] ?? 0;
} else {
    // Filtrera på adminens organisation (alla orgens domäner)
    $userEmailClause = buildEmailDomainInClause($orgScopeDomains, 'email');
    $totalUsers = queryOne(
        "SELECT COUNT(*) as count FROM " . DB_DATABASE . ".users WHERE {$userEmailClause['fragment']}",
        $userEmailClause['params']
    )['count'] ?? 0;
    $totalCourses = queryOne(
        "SELECT COUNT(*) as count FROM " . DB_DATABASE . ".courses c WHERE {$courseDomClause['fragment']}",
        $courseDomClause['params']
    )['count'] ?? 0;
    $totalLessons = queryOne(
        "SELECT COUNT(*) as count FROM " . DB_DATABASE . ".lessons l
         JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
         WHERE {$courseDomClause['fragment']}",
        $courseDomClause['params']
    )['count'] ?? 0;
    $totalCompletions = queryOne(
        "SELECT COUNT(*) as count FROM " . DB_DATABASE . ".progress p
         JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
         WHERE p.status = 'completed' AND {$emailDomClause['fragment']}",
        $emailDomClause['params']
    )['count'] ?? 0;
}

// Hämta statistik per kurs - filtrera på domän om inte superadmin
if ($isSuperAdmin) {
    $courseStats = query("SELECT
        c.id,
        c.title,
        c.status,
        COUNT(DISTINCT l.id) as total_lessons,
        COUNT(DISTINCT p.id) as total_completions,
        COUNT(DISTINCT p.user_id) as unique_users
    FROM " . DB_DATABASE . ".courses c
    LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id
    LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id AND p.status = 'completed'
    GROUP BY c.id, c.title, c.status
    ORDER BY total_completions DESC");
} else {
    $courseStats = query("SELECT
        c.id,
        c.title,
        c.status,
        COUNT(DISTINCT l.id) as total_lessons,
        COUNT(DISTINCT p.id) as total_completions,
        COUNT(DISTINCT p.user_id) as unique_users
    FROM " . DB_DATABASE . ".courses c
    LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id
    LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id AND p.status = 'completed'
    LEFT JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
    WHERE {$courseDomClause['fragment']}
    GROUP BY c.id, c.title, c.status
    ORDER BY total_completions DESC", $courseDomClause['params']);
}

// Hämta de senaste aktiviteterna - filtrera på domän om inte superadmin
if ($isSuperAdmin) {
    $recentActivity = query("SELECT p.*, u.email, l.title as lesson_title, c.title as course_title
                            FROM " . DB_DATABASE . ".progress p
                            JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
                            JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                            JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
                            ORDER BY p.updated_at DESC LIMIT 5");
} else {
    $recentActivity = query("SELECT p.*, u.email, l.title as lesson_title, c.title as course_title
                            FROM " . DB_DATABASE . ".progress p
                            JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
                            JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                            JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
                            WHERE {$courseDomClause['fragment']} AND {$emailDomClause['fragment']}
                            ORDER BY p.updated_at DESC LIMIT 5",
                            array_merge($courseDomClause['params'], $emailDomClause['params']));
}

// Beräkna aktivitet per dag (senaste 7 dagarna) - filtrera på domän om inte superadmin
$dateActivity = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dateActivity[$date] = 0;
}

if ($isSuperAdmin) {
    $weekActivity = query("SELECT DATE(updated_at) as date, COUNT(*) as count
                          FROM " . DB_DATABASE . ".progress
                          WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          GROUP BY DATE(updated_at)");
} else {
    $weekActivity = query("SELECT DATE(p.updated_at) as date, COUNT(*) as count
                          FROM " . DB_DATABASE . ".progress p
                          JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
                          JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                          JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
                          WHERE p.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          AND {$courseDomClause['fragment']} AND {$emailDomClause['fragment']}
                          GROUP BY DATE(p.updated_at)",
                          array_merge($courseDomClause['params'], $emailDomClause['params']));
}

foreach ($weekActivity as $day) {
    if (isset($dateActivity[$day['date']])) {
        $dateActivity[$day['date']] = $day['count'];
    }
}

// Beräkna aktivitet per kurs (senaste 7 dagarna) - filtrera på domän om inte superadmin
if ($isSuperAdmin) {
    $courseActivity = query("SELECT
        c.title,
        COUNT(p.id) as activity_count
    FROM " . DB_DATABASE . ".courses c
    LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id
    LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id
        AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY c.id, c.title
    ORDER BY activity_count DESC");
} else {
    $courseActivity = query("SELECT
        c.title,
        COUNT(p.id) as activity_count
    FROM " . DB_DATABASE . ".courses c
    LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id
    LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id
        AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    LEFT JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
    WHERE {$courseDomClause['fragment']}
    GROUP BY c.id, c.title
    ORDER BY activity_count DESC", $courseDomClause['params']);
}


// Beräkna antal fullt genomförda kurser och genomsnitt per användare
$fullyCompletedCourses = 0;
$avgCoursesPerUser = 0;
$avgCompletionRate = 0;

if ($isSuperAdmin) {
    // Superadmin: alla kurser
    $fullyCompletedResult = queryOne("
        SELECT COUNT(*) as total_completions
        FROM (
            SELECT p.user_id, l.course_id,
                   COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) as completed_lessons,
                   (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active') as total_lessons
            FROM " . DB_DATABASE . ".progress p
            JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
            GROUP BY p.user_id, l.course_id
            HAVING completed_lessons = total_lessons AND total_lessons > 0
        ) as completed_courses
    ");
    $fullyCompletedCourses = $fullyCompletedResult['total_completions'] ?? 0;

    // Genomsnitt kurser per användare (för användare med minst en slutförd kurs)
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
                GROUP BY p2.user_id, l2.course_id
                HAVING COUNT(DISTINCT CASE WHEN p2.status = 'completed' THEN l2.id END) =
                       (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l2.course_id AND status = 'active')
                       AND (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l2.course_id AND status = 'active') > 0
            ) as completed_courses ON p.user_id = completed_courses.user_id
            GROUP BY p.user_id
            HAVING courses_completed > 0
        ) as user_courses
    ");
    $avgCoursesPerUser = round($avgCoursesResult['avg_courses'] ?? 0, 1);

    // Genomsnittlig slutförandegrad
    $completionRateResult = queryOne("
        SELECT
            COALESCE(SUM(completed_count), 0) as total_completed,
            COALESCE(SUM(total_possible), 0) as total_possible
        FROM (
            SELECT
                c.id,
                COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as completed_count,
                COUNT(DISTINCT l.id) * COUNT(DISTINCT p.user_id) as total_possible
            FROM " . DB_DATABASE . ".courses c
            LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id AND l.status = 'active'
            LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id
            WHERE c.status = 'active'
            GROUP BY c.id
        ) as course_stats
    ");
    $avgCompletionRate = ($completionRateResult['total_possible'] ?? 0) > 0
        ? round(($completionRateResult['total_completed'] / $completionRateResult['total_possible']) * 100)
        : 0;
} else {
    // Filtrerat på orgens samtliga domäner
    $userEmailClauseU = buildEmailDomainInClause($orgScopeDomains, 'u.email');

    $fullyCompletedResult = queryOne("
        SELECT COUNT(*) as total_completions
        FROM (
            SELECT p.user_id, l.course_id,
                   COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) as completed_lessons,
                   (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active') as total_lessons
            FROM " . DB_DATABASE . ".progress p
            JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
            JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
            JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
            WHERE {$userEmailClauseU['fragment']} AND {$courseDomClause['fragment']}
            GROUP BY p.user_id, l.course_id
            HAVING completed_lessons = total_lessons AND total_lessons > 0
        ) as completed_courses
    ", array_merge($userEmailClauseU['params'], $courseDomClause['params']));
    $fullyCompletedCourses = $fullyCompletedResult['total_completions'] ?? 0;

    // Genomsnitt kurser per användare
    $avgCoursesResult = queryOne("
        SELECT AVG(courses_completed) as avg_courses
        FROM (
            SELECT u.id, COUNT(DISTINCT completed_courses.course_id) as courses_completed
            FROM " . DB_DATABASE . ".users u
            LEFT JOIN (
                SELECT p.user_id, l.course_id
                FROM " . DB_DATABASE . ".progress p
                JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
                JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
                WHERE {$courseDomClause['fragment']}
                GROUP BY p.user_id, l.course_id
                HAVING COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) =
                       (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active')
                       AND (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = l.course_id AND status = 'active') > 0
            ) as completed_courses ON u.id = completed_courses.user_id
            WHERE {$userEmailClauseU['fragment']}
            GROUP BY u.id
            HAVING courses_completed > 0
        ) as user_courses
    ", array_merge($courseDomClause['params'], $userEmailClauseU['params']));
    $avgCoursesPerUser = round($avgCoursesResult['avg_courses'] ?? 0, 1);

    // Genomsnittlig slutförandegrad
    $completionRateResult = queryOne("
        SELECT
            COALESCE(SUM(completed_count), 0) as total_completed,
            COALESCE(SUM(total_possible), 0) as total_possible
        FROM (
            SELECT
                c.id,
                COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as completed_count,
                COUNT(DISTINCT l.id) * COUNT(DISTINCT p.user_id) as total_possible
            FROM " . DB_DATABASE . ".courses c
            LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id AND l.status = 'active'
            LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id
            LEFT JOIN " . DB_DATABASE . ".users u ON p.user_id = u.id
            WHERE c.status = 'active' AND {$courseDomClause['fragment']} AND ({$userEmailClauseU['fragment']} OR u.email IS NULL)
            GROUP BY c.id
        ) as course_stats
    ", array_merge($courseDomClause['params'], $userEmailClauseU['params']));
    $avgCompletionRate = ($completionRateResult['total_possible'] ?? 0) > 0
        ? round(($completionRateResult['total_completed'] / $completionRateResult['total_possible']) * 100)
        : 0;
}

// Inkludera header
require_once 'include/header.php';
?>



<!-- Statistik Dashboard -->
<div class="row mb-2">
    <div class="col-12">
        <h4 class="mb-3">Dashboard</h4>
    </div>

    <!-- Statistikkort - Rad 1 -->
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Användare</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalUsers ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-people-fill text-primary" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Kurser</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalCourses ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-journal-text text-success" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Slutförda lektioner</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalCompletions ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-check-circle-fill text-info" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Genomsnittlig slutförandegrad</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $avgCompletionRate ?>%</div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-graph-up text-warning" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AI-kvotwidget — visar aktuell användning vs kvot för admins egen org -->
<?php
require_once '../include/ai_quota.php';
$aiWidgetScope = getAiScopeForEmail($_SESSION['user_email']);
$aiWidgetUsage = getMonthlyAiUsage($aiWidgetScope['organization_id'], $aiWidgetScope['domain']);
$aiWidgetQuota = getAiQuota($aiWidgetScope['organization_id'], $aiWidgetScope['domain']);
$aiWidgetTokensQuota = max(1, (int)$aiWidgetQuota['monthly_token_quota']);
$aiWidgetTokensUsed = (int)$aiWidgetUsage['total_tokens'];
$aiWidgetPct = (int)floor(($aiWidgetTokensUsed / $aiWidgetTokensQuota) * 100);
$aiWidgetColor = $aiWidgetPct >= 100 ? 'bg-danger' : ($aiWidgetPct >= (int)$aiWidgetQuota['alert_threshold_pct'] ? 'bg-warning' : 'bg-success');
$aiWidgetLabel = $aiWidgetScope['label'] ?: ($_SESSION['user_email'] ?? 'din organisation');
?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-0 shadow">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h6 class="font-weight-bold text-primary mb-1">
                            <i class="bi bi-robot me-1"></i>AI-användning denna månad
                        </h6>
                        <small class="text-muted"><?= htmlspecialchars($aiWidgetLabel) ?> · <?= date('Y-m') ?></small>
                    </div>
                    <?php /* ai_usage.php kräver admin. Läsbehörig och redaktör fick
                            knappen men kastades ut av behörighetskontrollen. */ ?>
                    <?php if ($isAdmin): ?>
                    <a href="ai_usage.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-bar-chart me-1"></i>Se detaljer
                    </a>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="flex-grow-1">
                        <div class="progress" style="height: 14px;">
                            <div class="progress-bar <?= $aiWidgetColor ?>" role="progressbar"
                                 style="width: <?= min(100, $aiWidgetPct) ?>%;"
                                 aria-valuenow="<?= $aiWidgetPct ?>" aria-valuemin="0" aria-valuemax="100">
                                <?= $aiWidgetPct ?>%
                            </div>
                        </div>
                    </div>
                    <div class="text-end" style="min-width: 200px;">
                        <span class="font-weight-bold"><?= number_format($aiWidgetTokensUsed, 0, ',', ' ') ?></span>
                        <span class="text-muted">/ <?= number_format($aiWidgetTokensQuota, 0, ',', ' ') ?> tokens</span>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center text-muted small">
                    <span><?= number_format($aiWidgetUsage['requests'], 0, ',', ' ') ?> AI-anrop</span>
                    <?php if ($aiWidgetPct >= 100): ?>
                        <span class="text-danger fw-semibold">
                            <i class="bi bi-exclamation-octagon me-1"></i>Kvoten är förbrukad —
                            <a href="mailto:hjalp@sambruksupport.se">hjalp@sambruksupport.se</a>
                        </span>
                    <?php elseif ($aiWidgetPct >= (int)$aiWidgetQuota['alert_threshold_pct']): ?>
                        <span class="text-warning fw-semibold">
                            <i class="bi bi-exclamation-triangle me-1"></i>Närmar sig gränsen
                        </span>
                    <?php else: ?>
                        <span><?= number_format(max(0, $aiWidgetTokensQuota - $aiWidgetTokensUsed), 0, ',', ' ') ?> tokens kvar</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Statistikkort - Rad 2 -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Fullt genomförda kurser</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $fullyCompletedCourses ?></div>
                        <small class="text-muted">Totalt antal kursavslut</small>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-award-fill text-success" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Genomförda kurser/användare</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $avgCoursesPerUser ?></div>
                        <small class="text-muted">Genomsnitt per aktiv användare</small>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-person-check-fill text-primary" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Lektioner</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalLessons ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-book-fill text-secondary" style="font-size: 2rem; opacity: 0.7;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
            
            <!-- Grafer och tabeller -->            
            <div class="row mb-4">
                <!-- Aktivitetsgraf -->                
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Aktivitet senaste 7 dagarna</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-area">
                                <canvas id="activityChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kursstatistik -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Kursstatistik</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Kurs</th>
                                            <th>Status</th>
                                            <th>Lektioner</th>
                                            <th>Slutförda</th>
                                            <th>Unika användare</th>
                                            <th>Genomsnitt/Användare</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($courseStats as $course): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($course['title']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $course['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                    <?= $course['status'] === 'active' ? 'Aktiv' : 'Inaktiv' ?>
                                                </span>
                                            </td>
                                            <td><?= $course['total_lessons'] ?></td>
                                            <td><?= $course['total_completions'] ?></td>
                                            <td><?= $course['unique_users'] ?></td>
                                            <td>
                                                <?= $course['unique_users'] > 0 
                                                    ? round($course['total_completions'] / $course['unique_users'], 1) 
                                                    : 0 ?>
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

            <!-- Kursaktivitet -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Kursaktivitet (senaste 7 dagarna)</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-area">
                                <canvas id="courseActivityChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Senaste aktiviteter -->                
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Senaste aktiviteter</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Användare</th>
                                            <th>Kurs</th>
                                            <th>Lektion</th>
                                            <th>Status</th>
                                            <th>Datum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentActivity as $activity): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($activity['email']) ?></td>
                                            <td><?= htmlspecialchars($activity['course_title']) ?></td>
                                            <td><?= htmlspecialchars($activity['lesson_title']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $activity['status'] === 'completed' ? 'success' : 'warning' ?>">
                                                    <?= $activity['status'] === 'completed' ? 'Slutförd' : 'Påbörjad' ?>
                                                </span>
                                            </td>
                                            <td><?= date('Y-m-d H:i', strtotime($activity['updated_at'])) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message_type'] ?? 'info' ?>">
                <?= $_SESSION['message'] ?>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
        </div>
    </div>

<?php
// Förbered data för graferna
$labels_date_activity = "'" . implode("', '", array_map(function($date) { return date('d M', strtotime($date)); }, array_keys($dateActivity))) . "'";
$values_date_activity = implode(', ', array_values($dateActivity));

$labels_course_activity = "'" . implode("', '", array_column($courseActivity, 'title')) . "'";
$values_course_activity = implode(', ', array_column($courseActivity, 'activity_count'));

// Definiera extra JavaScript för Chart.js
$extra_scripts = <<<EOT
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Aktivitetsgraf
        var ctx = document.getElementById('activityChart').getContext('2d');
        var activityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [{$labels_date_activity}],
                datasets: [{
                    label: 'Aktiviteter',
                    data: [{$values_date_activity}],
                    backgroundColor: 'rgba(78, 115, 223, 0.05)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    pointRadius: 3,
                    pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                    pointBorderColor: 'rgba(78, 115, 223, 1)',
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: 'rgba(78, 115, 223, 1)',
                    pointHoverBorderColor: 'rgba(78, 115, 223, 1)',
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    },
                    x: {
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgb(255, 255, 255)',
                        bodyColor: '#858796',
                        titleMarginBottom: 10,
                        titleColor: '#6e707e',
                        titleFont: { size: 14 },
                        borderColor: '#dddfeb',
                        borderWidth: 1,
                        padding: 15,
                        displayColors: false,
                        intersect: false,
                        mode: 'index',
                        caretPadding: 10
                    }
                }
            }
        });

        // Kursaktivitetsgraf
        var courseCtx = document.getElementById('courseActivityChart').getContext('2d');
        var courseActivityChart = new Chart(courseCtx, {
            type: 'bar',
            data: {
                labels: [{$labels_course_activity}],
                datasets: [{
                    label: 'Aktiviteter',
                    data: [{$values_course_activity}],
                    backgroundColor: 'rgba(78, 115, 223, 0.5)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    },
                    x: {
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgb(255, 255, 255)',
                        bodyColor: '#858796',
                        titleMarginBottom: 10,
                        titleColor: '#6e707e',
                        titleFont: { size: 14 },
                        borderColor: '#dddfeb',
                        borderWidth: 1,
                        padding: 15,
                        displayColors: false,
                        intersect: false,
                        mode: 'index',
                        caretPadding: 10
                    }
                }
            }
        });
    });
</script>
EOT;

// Inkludera footer
require_once 'include/footer.php';
