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
require_once '../include/xlsx.php';

// Kontrollera att användaren är inloggad
require_once 'include/auth_check.php';

// Hämta användarinformation
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userEmail = $_SESSION['user_email'];
$userDomain = substr(strrchr($userEmail, "@"), 1);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;
$isViewer = $currentUser && $currentUser['is_viewer'] == 1;

// Kontrollera behörighet - måste vara admin eller redaktör
if (!$isAdmin && !$isEditor && !$isViewer) {
    $_SESSION['message'] = 'Du har inte behörighet att exportera statistik.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Scope + valfritt domänfilter (samma som statistik-sidorna). Skärs mot
// användarens behörighet i getStatsDomainScope().
$domainScope = getStatsDomainScope($userEmail);
$activeDomains = $domainScope['active'];
$orgScopeDomains = $domainScope['scope'];

// Kursurvalet: samma klausul som statistics.php använder, så exporten släpper
// igenom exakt de kurser sidan visar.
$statsCourseScope = buildOrgCourseScopeClause($orgScopeDomains, 'c');

// Taggfiltret följer med i export-länken från statistics.php och måste läsas
// här också — annars innehåller filen fler rader än listan den utgick från.
$orgTagFilter = getOrgTagFilter($currentUser['id']);
$orgTagClauseU = buildOrgTagFilterClause($orgTagFilter['selected'], 'u.id');

// Hämta vald kurs
$selectedCourseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

if (!$selectedCourseId) {
    $_SESSION['message'] = 'Ingen kurs vald för export.';
    $_SESSION['message_type'] = 'warning';
    header('Location: statistics.php');
    exit;
}

// Kontrollera behörighet för kursen. Läsbehörig prövades tidigare mot
// ägarskap/redaktörskap precis som en redaktör, och eftersom en läsande roll
// varken äger eller redigerar kurser blev exporten alltid nekad. Rollen prövas
// nu mot organisationens kursscope, samma regel som statistics.php.
if (!$isAdmin) {
    if ($isViewer) {
        $hasAccess = queryOne(
            "SELECT c.id FROM " . DB_DATABASE . ".courses c
             WHERE c.id = ? AND {$statsCourseScope['fragment']}",
            array_merge([$selectedCourseId], $statsCourseScope['params'])
        );
    } else {
        $hasAccess = queryOne("SELECT c.id FROM " . DB_DATABASE . ".courses c
            LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
            WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
            [$selectedCourseId, $currentUser['id'], $userEmail]);
    }

    if (!$hasAccess) {
        $_SESSION['message'] = 'Du har inte behörighet att exportera statistik för denna kurs.';
        $_SESSION['message_type'] = 'danger';
        header('Location: statistics.php');
        exit;
    }
}

// Hämta kursinformation
$courseDetails = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$selectedCourseId]);

if (!$courseDetails) {
    $_SESSION['message'] = 'Kursen kunde inte hittas.';
    $_SESSION['message_type'] = 'danger';
    header('Location: statistics.php');
    exit;
}

// Hämta alla lektioner i kursen
$lessonsInCourse = query("SELECT id, title, sort_order FROM " . DB_DATABASE . ".lessons
                          WHERE course_id = ? AND status = 'active'
                          ORDER BY sort_order ASC", [$selectedCourseId]);

// Hämta progress för användare
if ($isAdmin || $isViewer) {
    // Admin och läsbehörig: hela org-scopet (eller den valda delmängden via
    // domains[]). Tidigare användes bara admins egen domän, vilket gjorde att en
    // huvuddomän-admins export saknade övriga domäner i organisationen.
    //
    // Läsbehörig måste hit och inte till redaktörsgrenen nedan: den listar ALLA
    // som rört kursen oavsett domän, vilket för en läsande roll hade inneburit
    // att andra organisationers användare hamnade i filen.
    $emailClause = combineSqlClauses(
        buildEmailDomainInClause($activeDomains, 'u.email'),
        $orgTagClauseU
    );
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
    WHERE {$emailClause['fragment']}
    AND l.course_id = ?
    AND l.status = 'active'
    ORDER BY u.email ASC, l.sort_order ASC", array_merge($emailClause['params'], [$selectedCourseId]));
} else {
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
$userProgressGrouped = [];
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

if (empty($userProgressGrouped)) {
    $_SESSION['message'] = 'Inga användare har påbörjat denna kurs ännu.';
    $_SESSION['message_type'] = 'info';
    $returnPage = ($_GET['return'] ?? '') === 'course_stats' ? 'course_stats.php' : 'statistics.php';
    header('Location: ' . $returnPage . '?course_id=' . $selectedCourseId);
    exit;
}

// Kontrollera om kursen är rolling enrollment
$isRollingExport = $courseDetails['sequential_mode'] && ($courseDetails['enrollment_type'] ?? 'bulk_start') === 'rolling';
$enrollmentMap = [];
if ($isRollingExport) {
    $enrollRows = query("SELECT * FROM " . DB_DATABASE . ".course_enrollments WHERE course_id = ?", [$selectedCourseId]);
    foreach ($enrollRows as $er) {
        $enrollmentMap[$er['user_id']] = $er;
    }
}

// Bygg ett äkta xlsx-paket. Tidigare skrevs en HTML-tabell med filändelsen
// .xls, vilket fick Excel att varna för att format och filändelse inte stämmer
// överens innan filen ens gick att öppna. Se include/xlsx.php.
$extraCols = $isRollingExport ? 3 : 0;
$totalCols = count($lessonsInCourse) + 4 + $extraCols;

$rows = [];
$rows[] = [['v' => 'Användarframsteg: ' . $courseDetails['title'], 's' => XLSX_STYLE_TITLE, 'merge' => $totalCols]];
$rows[] = [['v' => 'Exporterad: ' . date('Y-m-d H:i:s'), 'merge' => $totalCols]];
if (!empty($orgTagFilter['selected'])) {
    $rows[] = [['v' => 'Filtrerat på org-tagg: ' . implode(', ', $orgTagFilter['selected']), 'merge' => $totalCols]];
}
$rows[] = [];

// Rubrikrad
$header = [
    ['v' => 'E-post', 's' => XLSX_STYLE_HEADER],
    ['v' => 'Namn',   's' => XLSX_STYLE_HEADER],
];
if ($isRollingExport) {
    $header[] = ['v' => 'Startdatum', 's' => XLSX_STYLE_HEADER];
    $header[] = ['v' => 'Senaste lektion', 's' => XLSX_STYLE_HEADER];
    $header[] = ['v' => 'Beräknat slutdatum', 's' => XLSX_STYLE_HEADER];
}
$header[] = ['v' => 'Slutförda', 's' => XLSX_STYLE_HEADER];
$header[] = ['v' => 'Procent', 's' => XLSX_STYLE_HEADER];
foreach ($lessonsInCourse as $lesson) {
    $header[] = ['v' => $lesson['title'], 's' => XLSX_STYLE_HEADER];
}
$rows[] = $header;

// Datarad för varje användare
foreach ($userProgressGrouped as $userId => $userData) {
    $percentage = $userData['total'] > 0 ? round(($userData['completed'] / $userData['total']) * 100) : 0;

    $row = [
        ['v' => $userData['email'], 's' => XLSX_STYLE_CELL],
        ['v' => $userData['name'] ?? '', 's' => XLSX_STYLE_CELL],
    ];

    if ($isRollingExport) {
        $enroll = $enrollmentMap[$userId] ?? null;
        $startedAt = $enroll ? $enroll['started_at'] : null;
        $latestAvail = null;
        if ($startedAt) {
            $latestRow = queryOne(
                "SELECT MAX(available_at) AS latest FROM " . DB_DATABASE . ".sequential_lesson_schedule
                 WHERE user_id = ? AND course_id = ? AND available_at <= NOW()",
                [$userId, $selectedCourseId]
            );
            $latestAvail = $latestRow ? $latestRow['latest'] : null;
        }
        $projEnd = getProjectedEndDate($startedAt, count($lessonsInCourse), (int)$courseDetails['sequential_interval_days']);
        $row[] = ['v' => $startedAt ? date('Y-m-d', strtotime($startedAt)) : '-', 's' => XLSX_STYLE_CELL];
        $row[] = ['v' => $latestAvail ? date('Y-m-d', strtotime($latestAvail)) : '-', 's' => XLSX_STYLE_CELL];
        $row[] = ['v' => $projEnd ?: '-', 's' => XLSX_STYLE_CELL];
    }

    $row[] = ['v' => $userData['completed'] . '/' . $userData['total'], 's' => XLSX_STYLE_CELL];
    // Procenten skrivs som tal, så att kolumnen går att sortera och summera i Excel.
    $row[] = ['v' => (int)$percentage, 's' => XLSX_STYLE_CELL];

    foreach ($userData['lessons'] as $lesson) {
        if ($lesson['status'] === 'completed') {
            $row[] = ['v' => 'Klar', 's' => XLSX_STYLE_DONE];
        } else {
            $row[] = ['v' => '-', 's' => XLSX_STYLE_TODO];
        }
    }
    $rows[] = $row;
}

// Summering
$rows[] = [];
$rows[] = [
    ['v' => 'Totalt antal användare:', 's' => XLSX_STYLE_BOLD, 'merge' => 2],
    ['v' => count($userProgressGrouped), 's' => XLSX_STYLE_BOLD],
];

$colWidths = [1 => 34, 2 => 24];
$firstLessonCol = 3 + $extraCols + 2;
for ($i = 0; $i < count($lessonsInCourse); $i++) {
    $colWidths[$firstLessonCol + $i] = 18;
}

$filename = 'stimma_framsteg_' . preg_replace('/[^a-z0-9]/i', '_', $courseDetails['title']) . '_' . date('Y-m-d');

try {
    $xlsxPath = xlsxWrite($rows, 'Framsteg', $colWidths);
} catch (Exception $e) {
    error_log('export_statistics: ' . $e->getMessage());
    $_SESSION['message'] = 'Exporten kunde inte skapas. Försök igen eller kontakta support.';
    $_SESSION['message_type'] = 'danger';
    $returnPage = ($_GET['return'] ?? '') === 'course_stats' ? 'course_stats.php' : 'statistics.php';
    header('Location: ' . $returnPage . '?course_id=' . $selectedCourseId);
    exit;
}

xlsxSend($xlsxPath, $filename);

// Logga exporten
logActivity($_SESSION['user_email'], 'Exporterade statistik', [
    'action' => 'statistics_export',
    'course_id' => $selectedCourseId,
    'course_title' => $courseDetails['title'],
    'users_exported' => count($userProgressGrouped)
]);

exit;
