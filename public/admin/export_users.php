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
$currentUser = queryOne("SELECT id, email, role, is_admin, is_editor, is_viewer FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$currentUserDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isSuperAdmin = $currentUser['role'] === 'super_admin';
$isCurrentUserAdmin = $currentUser['is_admin'] == 1;

// Kontrollera behörighet
$isViewer = $currentUser['is_viewer'] == 1;
if (!$isSuperAdmin && !$isCurrentUserAdmin && !$isViewer) {
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

// Bestäm domänfilter — MÅSTE följa samma scope som users.php, annars innehåller
// CSV:n färre rader än den lista exporten utgick från. En admin på orgens
// primärdomän ser alla orgens domäner i listan och ska få med dem i exporten.
$adminScopeDomains = getEffectiveOrgScopeDomains($currentUser['email']);

$selectedDomain = '';
if ($isSuperAdmin && isset($_GET['domain']) && $_GET['domain'] !== '') {
    $selectedDomain = $_GET['domain'];
    // Validera mot faktiskt förekommande domäner, precis som users.php gör,
    // så att en okänd domän faller tillbaka till "alla" i stället för tom fil.
    $domainResults = queryAll("
        SELECT DISTINCT SUBSTRING_INDEX(email, '@', -1) as domain
        FROM " . DB_DATABASE . ".users
    ");
    if (!in_array($selectedDomain, array_column($domainResults, 'domain'), true)) {
        $selectedDomain = '';
    }
}

// För superadmin styrs scopet av den valda domänen i dropdownen (per-domän).
// För vanlig admin och läsbehörig används orgens samtliga domäner.
if ($isSuperAdmin) {
    $filterDomains = !empty($selectedDomain) ? [$selectedDomain] : [];
} else {
    $filterDomains = $adminScopeDomains;
}

// Taggfiltret från users.php förs vidare hit, annars innehåller CSV:n andra rader
// än den lista exporten utgick från.
$orgTagFilter = getOrgTagFilter($currentUser['id']);
$orgTagClauseUsers = buildOrgTagFilterClause($orgTagFilter['selected'], 'u.id');

if ($isSuperAdmin && empty($selectedDomain)) {
    $userClause = $orgTagClauseUsers;
    $filenameDomain = 'alla-organisationer';
    $logScope = ' (alla organisationer)';
} else {
    $userClause = combineSqlClauses(
        buildEmailDomainInClause($filterDomains, 'u.email'),
        $orgTagClauseUsers
    );
    // Flerdomänsscope förekommer bara för admin på primärdomänen, så den egna
    // domänen är samtidigt orgens huvuddomän och duger som filnamn.
    $filenameDomain = count($filterDomains) === 1 ? $filterDomains[0] : $currentUserDomain;
    $logScope = ' för ' . implode(', ', $filterDomains);
}

// Hämta användare
$users = queryAll("
    SELECT u.email, u.name, u.role, u.is_admin, u.is_editor, u.is_synced, u.sync_status,
           u.verified_at, u.created_at,
           COUNT(p.id) as completed_lessons,
           SUBSTRING_INDEX(u.email, '@', -1) as user_domain,
           (SELECT GROUP_CONCAT(uot.tag ORDER BY uot.tag SEPARATOR ', ')
            FROM " . DB_DATABASE . ".user_org_tags uot WHERE uot.user_id = u.id) as org_tags
    FROM " . DB_DATABASE . ".users u
    LEFT JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id AND p.status = 'completed'
    WHERE {$userClause['fragment']} $syncWhere
    GROUP BY u.id
    ORDER BY user_domain ASC, u.email ASC
", $userClause['params']);

// Logga exporten
logActivity($_SESSION['user_email'], "Exporterade användarlista" . $logScope);

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
    'teacher' => 'Redaktör',
    'student' => 'Användare' // lagrat värde, visas som Användare
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
