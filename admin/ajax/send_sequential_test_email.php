<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * AJAX endpoint: Skicka testmail för stegvisa kursers e-postmallar
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

require_once '../include/ajax_auth_check.php';

header('Content-Type: application/json');

// Verifiera CSRF
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

// Kontrollera behörighet
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;

if (!$isAdmin && !$isEditor) {
    echo json_encode(['success' => false, 'message' => 'Ingen behörighet.']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$testEmail = trim($_POST['test_email'] ?? '');

if (!$courseId) {
    echo json_encode(['success' => false, 'message' => 'Kurs-ID saknas.']);
    exit;
}

if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Ange en giltig e-postadress.']);
    exit;
}

// Hämta kurs
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte.']);
    exit;
}

// Redaktörskontroll
if (!$isAdmin) {
    $hasAccess = queryOne(
        "SELECT c.id FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
         WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
        [$courseId, $currentUser['id'], $_SESSION['user_email']]
    );
    if (!$hasAccess) {
        echo json_encode(['success' => false, 'message' => 'Du har inte behörighet till denna kurs.']);
        exit;
    }
}

$systemUrl = rtrim(getenv('SYSTEM_URL') ?: 'https://stimma.sambruk.se', '/');
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
$mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: 'Stimma';

// Exempeldata
$deadlineFormatted = '';
$daysRemaining = '';
if ($course['deadline']) {
    $months = ['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'];
    $dt = new DateTime($course['deadline']);
    $deadlineFormatted = $dt->format('j') . ' ' . $months[$dt->format('n') - 1] . ' ' . $dt->format('Y');
    $daysRemaining = (string)max(0, (int)floor(($dt->getTimestamp() - time()) / 86400));
}

$vars = [
    'user_name' => $currentUser['name'] ?: 'Testanvändare',
    'user_email' => $testEmail,
    'course_title' => $course['title'],
    'lesson_title' => 'Exempellektion - Introduktion',
    'lesson_url' => $systemUrl . '/lesson.php?id=test',
    'lesson_number' => '1',
    'total_lessons' => '5',
    'course_url' => $systemUrl . '/course.php?id=' . $courseId,
    'deadline' => $deadlineFormatted ?: '(inget slutdatum)',
    'days_remaining' => $daysRemaining ?: '-',
    'system_name' => $systemName,
];

$sentCount = 0;
$errors = [];

// 1. Skicka testmail: Ny lektion
$newSubject = $course['seq_new_lesson_subject'];
$newBody = $course['seq_new_lesson_body'];

if ($newSubject && $newBody) {
    $subject = '[TEST] ' . renderSequentialEmailTemplate($newSubject, $vars);
    $bodyText = renderSequentialEmailTemplate($newBody, $vars);
    $html = buildSequentialEmailHtml($bodyText, $systemName);
} else {
    $subject = '[TEST] Ny lektion tillgänglig: Exempellektion - Introduktion';
    $fakeItem = [
        'email_type' => 'new_lesson',
        'lesson_id' => 'test',
        'course_id' => $courseId,
        'user_name' => $currentUser['name'] ?: 'Testanvändare',
        'course_title' => $course['title'],
        'lesson_title' => 'Exempellektion - Introduktion',
    ];
    $html = buildDefaultSequentialEmailHtml($fakeItem, $vars, $systemName, $systemUrl);
}

if (sendSmtpMail($testEmail, $subject, $html, $mailFrom, $mailFromName)) {
    $sentCount++;
} else {
    $errors[] = 'Ny lektion-mail kunde inte skickas';
}

// 2. Skicka testmail: Påminnelse
$remSubject = $course['seq_reminder_subject'];
$remBody = $course['seq_reminder_body'];

if ($remSubject && $remBody) {
    $subject = '[TEST] ' . renderSequentialEmailTemplate($remSubject, $vars);
    $bodyText = renderSequentialEmailTemplate($remBody, $vars);
    $html = buildSequentialEmailHtml($bodyText, $systemName);
} else {
    $subject = '[TEST] Påminnelse: Exempellektion - Introduktion väntar på dig';
    $fakeItem = [
        'email_type' => 'reminder',
        'lesson_id' => 'test',
        'course_id' => $courseId,
        'user_name' => $currentUser['name'] ?: 'Testanvändare',
        'course_title' => $course['title'],
        'lesson_title' => 'Exempellektion - Introduktion',
    ];
    $html = buildDefaultSequentialEmailHtml($fakeItem, $vars, $systemName, $systemUrl);
}

if (sendSmtpMail($testEmail, $subject, $html, $mailFrom, $mailFromName)) {
    $sentCount++;
} else {
    $errors[] = 'Påminnelse-mail kunde inte skickas';
}

// Logga
logActivity($_SESSION['user_email'], 'Skickade testmail för stegvis kurs', [
    'action' => 'send_sequential_test_email',
    'course_id' => $courseId,
    'to_email' => $testEmail,
    'sent_count' => $sentCount,
]);

if ($sentCount === 2) {
    echo json_encode(['success' => true, 'message' => "2 testmail skickade till $testEmail (ny lektion + påminnelse). Kontrollera din inkorg."]);
} elseif ($sentCount > 0) {
    echo json_encode(['success' => true, 'message' => "$sentCount av 2 testmail skickade. Problem: " . implode(', ', $errors)]);
} else {
    echo json_encode(['success' => false, 'message' => 'Kunde inte skicka testmail. Kontrollera SMTP-inställningarna.']);
}
