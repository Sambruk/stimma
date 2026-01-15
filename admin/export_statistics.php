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
    $_SESSION['message'] = 'Du har inte behörighet att exportera statistik.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Hämta vald kurs
$selectedCourseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

if (!$selectedCourseId) {
    $_SESSION['message'] = 'Ingen kurs vald för export.';
    $_SESSION['message_type'] = 'warning';
    header('Location: statistics.php');
    exit;
}

// Kontrollera behörighet för kursen
if (!$isAdmin) {
    $hasAccess = queryOne("SELECT c.id FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
        WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
        [$selectedCourseId, $currentUser['id'], $userEmail]);

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
if ($isAdmin) {
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
    header('Location: statistics.php?course_id=' . $selectedCourseId);
    exit;
}

// Skapa Excel-fil (använder XML Spreadsheet format som öppnas i Excel)
$filename = 'stimma_framsteg_' . preg_replace('/[^a-z0-9]/i', '_', $courseDetails['title']) . '_' . date('Y-m-d') . '.xls';

// Sätt headers för Excel-nedladdning
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

// BOM för UTF-8 i Excel
echo "\xEF\xBB\xBF";

// Skapa HTML-tabell som Excel kan läsa
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>';
echo '<body>';

// Titel
echo '<table border="1">';
echo '<tr><td colspan="' . (count($lessonsInCourse) + 4) . '" style="font-size:16pt;font-weight:bold;">Användarframsteg: ' . htmlspecialchars($courseDetails['title']) . '</td></tr>';
echo '<tr><td colspan="' . (count($lessonsInCourse) + 4) . '">Exporterad: ' . date('Y-m-d H:i:s') . '</td></tr>';
echo '<tr><td colspan="' . (count($lessonsInCourse) + 4) . '"></td></tr>';

// Rubrikrad
echo '<tr style="background-color:#0F3B5F;color:#FFFFFF;font-weight:bold;">';
echo '<td>E-post</td>';
echo '<td>Namn</td>';
echo '<td>Slutförda</td>';
echo '<td>Procent</td>';
foreach ($lessonsInCourse as $lesson) {
    echo '<td>' . htmlspecialchars($lesson['title']) . '</td>';
}
echo '</tr>';

// Datarad för varje användare
foreach ($userProgressGrouped as $userId => $userData) {
    $percentage = $userData['total'] > 0 ? round(($userData['completed'] / $userData['total']) * 100) : 0;

    echo '<tr>';
    echo '<td>' . htmlspecialchars($userData['email']) . '</td>';
    echo '<td>' . htmlspecialchars($userData['name'] ?? '') . '</td>';
    echo '<td>' . $userData['completed'] . '/' . $userData['total'] . '</td>';
    echo '<td>' . $percentage . '%</td>';

    foreach ($userData['lessons'] as $lesson) {
        if ($lesson['status'] === 'completed') {
            echo '<td style="background-color:#4CAF50;color:#FFFFFF;text-align:center;">✓</td>';
        } else {
            echo '<td style="background-color:#F5F5F5;text-align:center;">-</td>';
        }
    }
    echo '</tr>';
}

// Summering
echo '<tr><td colspan="' . (count($lessonsInCourse) + 4) . '"></td></tr>';
echo '<tr style="font-weight:bold;">';
echo '<td colspan="2">Totalt antal användare:</td>';
echo '<td colspan="' . (count($lessonsInCourse) + 2) . '">' . count($userProgressGrouped) . '</td>';
echo '</tr>';

echo '</table>';
echo '</body></html>';

// Logga exporten
logActivity($_SESSION['user_email'], 'Exporterade statistik', [
    'action' => 'statistics_export',
    'course_id' => $selectedCourseId,
    'course_title' => $courseDetails['title'],
    'users_exported' => count($userProgressGrouped)
]);

exit;
