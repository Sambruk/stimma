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

// Kontrollera om användaren är inloggad
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

// Hämta aktuell användares information
$currentUser = queryOne("SELECT id, email, role, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$currentUserDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isSuperAdmin = $currentUser['role'] === 'super_admin';
$isCurrentUserAdmin = $currentUser['is_admin'] == 1;

// Kontrollera behörighet
if (!$isSuperAdmin && !$isCurrentUserAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att exportera användare.';
    $_SESSION['message_type'] = 'danger';
    header('Location: users.php');
    exit;
}

// Synk-filter
$syncFilter = $_GET['sync'] ?? '';
$syncWhere = '';
$syncParams = [];
if ($syncFilter === 'synced') {
    $syncWhere = ' AND u.is_synced = 1';
} elseif ($syncFilter === 'inactive') {
    $syncWhere = ' AND u.is_synced = 1 AND u.sync_status = \'inactive\'';
}

// Bestäm domänfilter
$selectedDomain = '';
if ($isSuperAdmin && isset($_GET['domain']) && $_GET['domain'] !== '') {
    $selectedDomain = $_GET['domain'];
}

// Hämta användare
if ($isSuperAdmin && empty($selectedDomain)) {
    $users = queryAll("
        SELECT u.email, u.name, u.role, u.is_admin, u.is_editor, u.is_synced, u.sync_status,
               u.verified_at, u.created_at,
               COUNT(p.id) as completed_lessons,
               SUBSTRING_INDEX(u.email, '@', -1) as user_domain,
               (SELECT GROUP_CONCAT(uot.tag ORDER BY uot.tag SEPARATOR ', ')
                FROM " . DB_DATABASE . ".user_org_tags uot WHERE uot.user_id = u.id) as org_tags
        FROM " . DB_DATABASE . ".users u
        LEFT JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id AND p.status = 'completed'
        WHERE 1=1 $syncWhere
        GROUP BY u.id
        ORDER BY user_domain ASC, u.email ASC
    ");
    $filenameDomain = 'alla-organisationer';
} else {
    $filterDomain = $isSuperAdmin ? $selectedDomain : $currentUserDomain;
    $users = queryAll("
        SELECT u.email, u.name, u.role, u.is_admin, u.is_editor, u.is_synced, u.sync_status,
               u.verified_at, u.created_at,
               COUNT(p.id) as completed_lessons,
               SUBSTRING_INDEX(u.email, '@', -1) as user_domain,
               (SELECT GROUP_CONCAT(uot.tag ORDER BY uot.tag SEPARATOR ', ')
                FROM " . DB_DATABASE . ".user_org_tags uot WHERE uot.user_id = u.id) as org_tags
        FROM " . DB_DATABASE . ".users u
        LEFT JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id AND p.status = 'completed'
        WHERE u.email LIKE ? $syncWhere
        GROUP BY u.id
        ORDER BY u.email ASC
    ", ['%@' . $filterDomain]);
    $filenameDomain = $filterDomain;
}

// Logga exporten
logActivity($_SESSION['user_email'], "Exporterade användarlista" . ($filenameDomain !== 'alla-organisationer' ? " för " . $filenameDomain : " (alla organisationer)"));

// Generera CSV
$filename = 'stimma-anvandare-' . $filenameDomain . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// BOM för korrekt UTF-8 i Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Rubrikrad
fputcsv($output, [
    'E-post',
    'Namn',
    'Domän',
    'Roll',
    'Admin',
    'Redaktör',
    'Org-taggar',
    'Verifierad',
    'Synkad',
    'Synk-status',
    'Avklarade lektioner',
    'Skapad'
], ';');

// Rolletiketter
$roleLabels = [
    'super_admin' => 'Superadmin',
    'admin' => 'Admin',
    'teacher' => 'Lärare',
    'student' => 'Användare'
];

foreach ($users as $user) {
    $role = $user['role'] ?? 'student';
    fputcsv($output, [
        $user['email'],
        $user['name'] ?? '',
        $user['user_domain'],
        $roleLabels[$role] ?? $role,
        $user['is_admin'] ? 'Ja' : 'Nej',
        $user['is_editor'] ? 'Ja' : 'Nej',
        $user['org_tags'] ?? '',
        $user['verified_at'] ? 'Ja' : 'Nej',
        $user['is_synced'] ? 'Ja' : 'Nej',
        $user['is_synced'] ? (($user['sync_status'] ?? 'active') === 'active' ? 'Aktiv' : 'Inaktiv') : '',
        $user['completed_lessons'],
        $user['created_at'] ? date('Y-m-d', strtotime($user['created_at'])) : ''
    ], ';');
}

fclose($output);
