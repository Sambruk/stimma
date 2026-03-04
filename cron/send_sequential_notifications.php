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
 * Cron-skript för stegvisa kurser: köar och skickar e-post vid ny tillgänglig lektion och påminnelser.
 * Använder sequential_email_queue med throttling för att undvika svartlistning.
 *
 * Kör detta skript dagligen via cron:
 * 0 8 * * * /usr/bin/php /var/www/html/cron/send_sequential_notifications.php >> /var/log/stimma_sequential.log 2>&1
 */

// Blockera webbåtkomst
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

define('CLI_MODE', true);

chdir(dirname(__DIR__));

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/mail.php';

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
}

logMessage("Startar stegvisa lektioner - notifieringsjobb...");

// Hämta inställningar
$batchSize = 10;
$batchDelay = 30;
$settingBatch = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'sequential_batch_size'");
if ($settingBatch) $batchSize = max(1, (int)$settingBatch['setting_value']);
$settingDelay = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'sequential_batch_delay_seconds'");
if ($settingDelay) $batchDelay = max(0, (int)$settingDelay['setting_value']);

// ===== FAS 1: Kö nya lektion-mail =====
logMessage("--- Fas 1: Kö nya tillgängliga lektioner ---");

$newLessons = query("
    SELECT sls.id AS schedule_id, sls.user_id, sls.course_id, sls.lesson_id, sls.available_at,
           u.email, u.name AS user_name,
           l.title AS lesson_title, l.sort_order,
           c.title AS course_title
    FROM " . DB_DATABASE . ".sequential_lesson_schedule sls
    JOIN " . DB_DATABASE . ".users u ON sls.user_id = u.id
    JOIN " . DB_DATABASE . ".lessons l ON sls.lesson_id = l.id
    JOIN " . DB_DATABASE . ".courses c ON sls.course_id = c.id
    WHERE sls.available_at <= NOW()
      AND sls.notified_at IS NULL
      AND sls.completed_at IS NULL
      AND c.sequential_mode = 1
      AND c.status = 'active'
    ORDER BY sls.available_at ASC
");

logMessage("Hittade " . count($newLessons) . " nya tillgängliga lektioner att notifiera om.");

// Gruppera per kurs
$newByCourse = [];
foreach ($newLessons as $item) {
    $newByCourse[$item['course_id']][] = ['user_id' => $item['user_id'], 'lesson_id' => $item['lesson_id']];
}
foreach ($newByCourse as $cId => $pairs) {
    $queued = queueSequentialEmails($cId, $pairs, 'new_lesson');
    logMessage("Kurs $cId: $queued nya lektion-mail köade.");
}

// ===== FAS 2: Kö påminnelser =====
logMessage("--- Fas 2: Kö påminnelser ---");

$reminders = query("
    SELECT sls.id AS schedule_id, sls.user_id, sls.course_id, sls.lesson_id, sls.available_at,
           u.email, u.name AS user_name,
           l.title AS lesson_title,
           c.title AS course_title, c.sequential_reminder_delay_days,
           COALESCE(ce.opt_out_reminders, 0) AS opt_out_reminders
    FROM " . DB_DATABASE . ".sequential_lesson_schedule sls
    JOIN " . DB_DATABASE . ".users u ON sls.user_id = u.id
    JOIN " . DB_DATABASE . ".lessons l ON sls.lesson_id = l.id
    JOIN " . DB_DATABASE . ".courses c ON sls.course_id = c.id
    LEFT JOIN " . DB_DATABASE . ".course_enrollments ce ON ce.user_id = sls.user_id AND ce.course_id = sls.course_id
    WHERE sls.completed_at IS NULL
      AND sls.notified_at IS NOT NULL
      AND sls.reminded_at IS NULL
      AND DATEDIFF(NOW(), sls.available_at) >= c.sequential_reminder_delay_days
      AND c.sequential_mode = 1
      AND c.status = 'active'
    ORDER BY sls.available_at ASC
");

logMessage("Hittade " . count($reminders) . " lektioner att påminna om.");

$reminderByCourse = [];
foreach ($reminders as $item) {
    if ($item['opt_out_reminders']) {
        logMessage("Hoppar över {$item['email']} - har valt bort påminnelser.");
        continue;
    }
    $reminderByCourse[$item['course_id']][] = ['user_id' => $item['user_id'], 'lesson_id' => $item['lesson_id']];
}
foreach ($reminderByCourse as $cId => $pairs) {
    $queued = queueSequentialEmails($cId, $pairs, 'reminder');
    logMessage("Kurs $cId: $queued påminnelse-mail köade.");
}

// ===== FAS 3: Bearbeta hela kön med throttling =====
logMessage("--- Fas 3: Bearbeta e-postkö (batch=$batchSize, delay={$batchDelay}s) ---");

$result = processEmailQueue(null, $batchSize, $batchDelay, 'logMessage');

logMessage("Stegvisa lektioner - notifieringsjobb slutfört. Skickade: {$result['sent']}, Misslyckade: {$result['failed']}");
