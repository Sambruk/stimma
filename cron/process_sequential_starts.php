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
 * Cron-skript: Automatisk start av stegvisa kurser.
 * Hittar kurser med start_date = idag, registrerar användare, köar första-lektion-mail.
 *
 * Kör dagligen via cron:
 * 0 8 * * * /usr/bin/php /var/www/html/cron/process_sequential_starts.php >> /var/log/stimma_sequential_starts.log 2>&1
 */

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

logMessage("Startar process_sequential_starts...");

// Hämta inställningar
$batchSize = 10;
$batchDelay = 30;
$settingBatch = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'sequential_batch_size'");
if ($settingBatch) $batchSize = max(1, (int)$settingBatch['setting_value']);
$settingDelay = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'sequential_batch_delay_seconds'");
if ($settingDelay) $batchDelay = max(0, (int)$settingDelay['setting_value']);

// Hitta kurser som ska starta idag
$courses = query("
    SELECT c.*
    FROM " . DB_DATABASE . ".courses c
    WHERE c.sequential_mode = 1
      AND c.start_date = CURDATE()
      AND (c.sequential_status IS NULL OR c.sequential_status = 'pending')
      AND c.status = 'active'
");

logMessage("Hittade " . count($courses) . " kurser att starta.");

foreach ($courses as $course) {
    logMessage("Startar kurs: {$course['title']} (ID: {$course['id']})");

    // Sätt status till 'sending'
    execute(
        "UPDATE " . DB_DATABASE . ".courses SET sequential_status = 'sending' WHERE id = ?",
        [$course['id']]
    );

    // Hitta berörda användare baserat på org-taggar
    $courseOrgTags = query(
        "SELECT tag FROM " . DB_DATABASE . ".course_org_tags WHERE course_id = ?",
        [$course['id']]
    );

    $users = [];
    if (!empty($courseOrgTags)) {
        // Hämta användare som matchar org-taggarna
        $tags = array_column($courseOrgTags, 'tag');
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $users = query(
            "SELECT DISTINCT u.id FROM " . DB_DATABASE . ".users u
             JOIN " . DB_DATABASE . ".user_org_tags uot ON u.id = uot.user_id
             WHERE uot.tag IN ($placeholders)
               AND u.email LIKE ?",
            array_merge($tags, ['%@' . $course['organization_domain']])
        );
    } else {
        // Alla användare i domänen
        $users = query(
            "SELECT id FROM " . DB_DATABASE . ".users WHERE email LIKE ?",
            ['%@' . $course['organization_domain']]
        );
    }

    logMessage("Registrerar " . count($users) . " användare i kurs {$course['id']}...");

    $enrolledCount = 0;
    $firstLessonPairs = [];

    // Hämta första lektionen
    $firstLesson = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1",
        [$course['id']]
    );

    foreach ($users as $user) {
        $result = enrollUserInSequentialCourse($user['id'], $course['id']);
        if ($result) {
            $enrolledCount++;
            if ($firstLesson) {
                $firstLessonPairs[] = ['user_id' => $user['id'], 'lesson_id' => $firstLesson['id']];
            }
        }
    }

    logMessage("$enrolledCount användare registrerade.");

    // Kö första-lektion-mail
    if (!empty($firstLessonPairs)) {
        $queued = queueSequentialEmails($course['id'], $firstLessonPairs, 'new_lesson');
        logMessage("$queued första-lektion-mail köade.");
    }

    // Bearbeta kön för denna kurs
    $result = processEmailQueue($course['id'], $batchSize, $batchDelay, 'logMessage');
    logMessage("Kurs {$course['id']}: {$result['sent']} skickade, {$result['failed']} misslyckade.");

    // Sätt status till 'active'
    execute(
        "UPDATE " . DB_DATABASE . ".courses SET sequential_status = 'active' WHERE id = ?",
        [$course['id']]
    );

    logMessage("Kurs {$course['id']} markerad som 'active'.");
}

logMessage("process_sequential_starts slutfört.");
