<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * AJAX endpoint: Skicka manuell påminnelse för stegvisa kurser
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

// Hämta användarinfo
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;

if (!$isAdmin && !$isEditor) {
    echo json_encode(['success' => false, 'message' => 'Ingen behörighet.']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$customMessage = trim($_POST['custom_message'] ?? '');

if (!$courseId) {
    echo json_encode(['success' => false, 'message' => 'Kurs-ID saknas.']);
    exit;
}

// Verifiera kurs och behörighet
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$course || !$course['sequential_mode']) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte eller är inte stegvis.']);
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

// Hitta alla med available_at <= NOW() AND completed_at IS NULL
// Dedupliera per användare (första ej avklarade lektionen)
$pendingLessons = query("
    SELECT sls.id AS schedule_id, sls.user_id, sls.course_id, sls.lesson_id,
           u.email, u.name AS user_name,
           l.title AS lesson_title,
           COALESCE(ce.opt_out_reminders, 0) AS opt_out_reminders
    FROM " . DB_DATABASE . ".sequential_lesson_schedule sls
    JOIN " . DB_DATABASE . ".users u ON sls.user_id = u.id
    JOIN " . DB_DATABASE . ".lessons l ON sls.lesson_id = l.id
    LEFT JOIN " . DB_DATABASE . ".course_enrollments ce ON ce.user_id = sls.user_id AND ce.course_id = sls.course_id
    WHERE sls.course_id = ?
      AND sls.available_at <= NOW()
      AND sls.completed_at IS NULL
    ORDER BY l.sort_order ASC
", [$courseId]);

// Dedupliera per användare - behåll bara den första ej avklarade
$usersToRemind = [];
foreach ($pendingLessons as $item) {
    if (!isset($usersToRemind[$item['user_id']]) && !$item['opt_out_reminders']) {
        $usersToRemind[$item['user_id']] = $item;
    }
}

if (empty($usersToRemind)) {
    echo json_encode(['success' => true, 'message' => 'Inga användare att påminna.']);
    exit;
}

$systemUrl = rtrim(getenv('SYSTEM_URL') ?: 'https://stimma.sambruk.se', '/');
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
$mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: 'Stimma';

$sentCount = 0;
$failCount = 0;

foreach ($usersToRemind as $item) {
    $lessonUrl = $systemUrl . '/lesson.php?id=' . $item['lesson_id'];
    $userName = $item['user_name'] ?: 'användare';

    $subject = "Påminnelse: " . $item['lesson_title'] . " i " . $course['title'];

    $customHtml = '';
    if (!empty($customMessage)) {
        $customHtml = '
        <div style="background-color: #e3f2fd; border: 1px solid #90caf9; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <p style="margin: 0; color: #1565c0;">' . nl2br(htmlspecialchars($customMessage)) . '</p>
        </div>';
    }

    $htmlMessage = "
    <!DOCTYPE html>
    <html lang='sv'>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
            <h1 style='color: #007bff; margin: 0 0 10px 0; font-size: 24px;'>$systemName</h1>
            <p style='margin: 0; color: #6c757d;'>Påminnelse</p>
        </div>
        <div style='padding: 20px 0;'>
            <p>Hej " . htmlspecialchars($userName) . "!</p>
            $customHtml
            <p>Du har en lektion i kursen <strong>" . htmlspecialchars($course['title']) . "</strong> som väntar på dig:</p>
            <div style='background-color: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <strong style='color: #856404;'>" . htmlspecialchars($item['lesson_title']) . "</strong>
            </div>
            <p>
                <a href='" . htmlspecialchars($lessonUrl) . "' style='display: inline-block; background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold;'>Gå till lektionen</a>
            </p>
        </div>
        <div style='border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 20px; color: #6c757d; font-size: 12px;'>
            <p>Detta är ett automatiskt meddelande från $systemName.</p>
        </div>
    </body>
    </html>";

    $mailSent = sendSmtpMail($item['email'], $subject, $htmlMessage, $mailFrom, $mailFromName);

    // Logga
    $emailStatus = $mailSent ? 'sent' : 'failed';
    execute(
        "INSERT INTO " . DB_DATABASE . ".sequential_reminder_log
         (user_id, course_id, lesson_id, type, custom_message, email_status, error_message, sent_by)
         VALUES (?, ?, ?, 'manual_reminder', ?, ?, ?, ?)",
        [
            $item['user_id'],
            $courseId,
            $item['lesson_id'],
            $customMessage ?: null,
            $emailStatus,
            $mailSent ? null : 'E-post kunde inte skickas',
            $currentUser['id']
        ]
    );

    if ($mailSent) {
        $sentCount++;
    } else {
        $failCount++;
    }

    usleep(100000); // 100ms delay
}

$message = "Påminnelse skickad till $sentCount användare.";
if ($failCount > 0) {
    $message .= " $failCount misslyckade.";
}

echo json_encode(['success' => true, 'message' => $message]);
