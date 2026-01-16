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
 * Cron-skript för att skicka påminnelser till användare som påbörjat kurser men inte slutfört dem.
 *
 * Kör detta skript dagligen via cron:
 * 0 9 * * * /usr/bin/php /var/www/html/cron/send_reminders.php >> /var/log/stimma_reminders.log 2>&1
 */

// Sätt CLI-läge
define('CLI_MODE', php_sapi_name() === 'cli');

// Säkerställ att vi kör i rätt katalog
chdir(dirname(__DIR__));

// Inkludera konfiguration
require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/mail.php';

// Loggfunktion för CLI
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
}

// Funktion för att konvertera URLs till klickbara länkar
function makeLinksClickable($text) {
    $pattern = '/(https?:\/\/[^\s<>\[\]]+)/i';
    return preg_replace_callback($pattern, function($matches) {
        $url = rtrim($matches[1], '.,;:!?)');
        return '<a href="' . $url . '" style="color: #007bff; text-decoration: underline;">' . $url . '</a>';
    }, $text);
}

logMessage("Startar påminnelsejobb...");

// Hämta alla domäner med aktiverade påminnelser
$activeSettings = query("SELECT * FROM " . DB_DATABASE . ".reminder_settings WHERE enabled = 1");

if (empty($activeSettings)) {
    logMessage("Inga aktiva påminnelseinställningar hittades. Avslutar.");
    exit(0);
}

logMessage("Hittade " . count($activeSettings) . " domäner med aktiva påminnelser.");

$totalSent = 0;
$totalFailed = 0;

foreach ($activeSettings as $settings) {
    $domain = $settings['domain'];
    logMessage("Behandlar domän: $domain");

    $daysAfterStart = $settings['days_after_start'];
    $maxReminders = $settings['max_reminders'];
    $daysBetweenReminders = $settings['days_between_reminders'];
    $emailSubject = $settings['email_subject'];
    $emailBody = $settings['email_body'];

    // Hitta användare som behöver påminnelse
    // Villkor:
    // 1. Användare från denna domän
    // 2. Har påbörjat en kurs (har progress på minst en lektion)
    // 3. Har inte slutfört alla lektioner i kursen
    // 4. Har inte nått max antal påminnelser
    // 5. Det har gått tillräckligt många dagar sedan kursstart eller senaste påminnelse
    // 6. Har inte valt att avsluta kursen

    $usersToRemind = query("
        SELECT DISTINCT
            u.id AS user_id,
            u.email,
            u.name,
            c.id AS course_id,
            c.title AS course_title,
            c.deadline AS course_deadline,
            (SELECT MIN(p2.created_at) FROM " . DB_DATABASE . ".progress p2
             JOIN " . DB_DATABASE . ".lessons l2 ON p2.lesson_id = l2.id
             WHERE p2.user_id = u.id AND l2.course_id = c.id) AS course_started_at,
            (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = c.id AND status = 'active') AS total_lessons,
            (SELECT COUNT(*) FROM " . DB_DATABASE . ".progress p3
             JOIN " . DB_DATABASE . ".lessons l3 ON p3.lesson_id = l3.id
             WHERE p3.user_id = u.id AND l3.course_id = c.id AND p3.status = 'completed') AS completed_lessons,
            COALESCE(
                (SELECT MAX(rl.sent_at) FROM " . DB_DATABASE . ".reminder_log rl
                 WHERE rl.user_id = u.id AND rl.course_id = c.id),
                '1970-01-01'
            ) AS last_reminder_at,
            COALESCE(
                (SELECT MAX(rl.reminder_number) FROM " . DB_DATABASE . ".reminder_log rl
                 WHERE rl.user_id = u.id AND rl.course_id = c.id),
                0
            ) AS reminder_count,
            COALESCE(
                (SELECT ce.opt_out_reminders FROM " . DB_DATABASE . ".course_enrollments ce
                 WHERE ce.user_id = u.id AND ce.course_id = c.id),
                0
            ) AS opt_out_reminders,
            COALESCE(
                (SELECT ce.status FROM " . DB_DATABASE . ".course_enrollments ce
                 WHERE ce.user_id = u.id AND ce.course_id = c.id),
                'active'
            ) AS enrollment_status
        FROM " . DB_DATABASE . ".users u
        JOIN " . DB_DATABASE . ".progress p ON u.id = p.user_id
        JOIN " . DB_DATABASE . ".lessons l ON p.lesson_id = l.id
        JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
        WHERE u.email LIKE ?
        AND c.status = 'active'
        GROUP BY u.id, c.id
        HAVING
            completed_lessons < total_lessons
            AND reminder_count < ?
            AND opt_out_reminders = 0
            AND enrollment_status = 'active'
            AND (
                (reminder_count = 0 AND DATEDIFF(NOW(), course_started_at) >= ?)
                OR
                (reminder_count > 0 AND DATEDIFF(NOW(), last_reminder_at) >= ?)
            )
    ", ['%@' . $domain, $maxReminders, $daysAfterStart, $daysBetweenReminders]);

    logMessage("Hittade " . count($usersToRemind) . " användare att påminna i domän $domain");

    foreach ($usersToRemind as $user) {
        logMessage("Skickar påminnelse till {$user['email']} för kurs: {$user['course_title']}");

        // Skapa kurs-URL
        $systemUrl = rtrim(getenv('SYSTEM_URL') ?: 'https://stimma.sambruk.se', '/');

        // Hitta nästa lektion att göra
        $nextLesson = queryOne("
            SELECT l.id FROM " . DB_DATABASE . ".lessons l
            LEFT JOIN " . DB_DATABASE . ".progress p ON l.id = p.lesson_id AND p.user_id = ?
            WHERE l.course_id = ? AND l.status = 'active'
            AND (p.status IS NULL OR p.status != 'completed')
            ORDER BY l.sort_order ASC
            LIMIT 1
        ", [$user['user_id'], $user['course_id']]);

        $courseUrl = $systemUrl . '/lesson.php?id=' . ($nextLesson['id'] ?? '');
        $abandonUrl = $systemUrl . '/abandon_course.php?course_id=' . $user['course_id'] . '&token=' . hash('sha256', $user['user_id'] . $user['course_id'] . date('Y-m'));

        // Hantera deadline-variabler
        $deadline = '';
        $daysRemaining = '';
        $deadlineInfo = '';

        if (!empty($user['course_deadline'])) {
            $deadlineDate = new DateTime($user['course_deadline']);
            $today = new DateTime('today');
            $diff = $today->diff($deadlineDate);
            $daysLeft = $diff->invert ? -$diff->days : $diff->days;

            // Formatera deadline på svenska
            $months = ['januari', 'februari', 'mars', 'april', 'maj', 'juni',
                       'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
            $deadline = $deadlineDate->format('j') . ' ' . $months[$deadlineDate->format('n') - 1] . ' ' . $deadlineDate->format('Y');

            if ($daysLeft > 0) {
                $daysRemaining = $daysLeft;
                $deadlineInfo = "Kursen ska vara genomförd senast $deadline ($daysLeft dagar kvar).";
            } elseif ($daysLeft == 0) {
                $daysRemaining = '0';
                $deadlineInfo = "Kursen ska vara genomförd senast idag ($deadline)!";
            } else {
                $daysRemaining = '0';
                $deadlineInfo = "Kursen skulle ha varit genomförd senast $deadline (" . abs($daysLeft) . " dagar sedan).";
            }
        }

        // Ersätt variabler i e-postmallen
        $personalizedBody = str_replace(
            ['{{course_title}}', '{{completed_lessons}}', '{{total_lessons}}', '{{course_url}}', '{{abandon_url}}', '{{user_name}}', '{{user_email}}', '{{deadline}}', '{{days_remaining}}', '{{deadline_info}}'],
            [
                $user['course_title'],
                $user['completed_lessons'],
                $user['total_lessons'],
                $courseUrl,
                $abandonUrl,
                $user['name'] ?: 'användare',
                $user['email'],
                $deadline,
                $daysRemaining,
                $deadlineInfo
            ],
            $emailBody
        );

        // Konvertera till HTML - först escape, sedan gör länkar klickbara, sedan newlines
        $personalizedBodyHtml = htmlspecialchars($personalizedBody);
        $personalizedBodyHtml = makeLinksClickable($personalizedBodyHtml);
        $personalizedBodyHtml = nl2br($personalizedBodyHtml);

        // Skapa HTML-mail
        $htmlMessage = "
        <!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
        <html xmlns=\"http://www.w3.org/1999/xhtml\">
        <head>
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />
            <title>Påminnelse</title>
        </head>
        <body style=\"font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0;\">
            <table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin: 0; padding: 0;\">
                <tr>
                    <td style=\"padding: 20px;\">
                        <p style=\"font-family: Arial, sans-serif; color: #000000;\">$personalizedBodyHtml</p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        // Skicka e-post
        $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
        $mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: 'Stimma';

        $mailSent = sendSmtpMail($user['email'], $emailSubject, $htmlMessage, $mailFrom, $mailFromName);

        // Logga påminnelsen i databasen
        $reminderNumber = $user['reminder_count'] + 1;
        $emailStatus = $mailSent ? 'sent' : 'failed';
        $errorMessage = $mailSent ? null : 'E-post kunde inte skickas';

        execute("INSERT INTO " . DB_DATABASE . ".reminder_log
                 (user_id, course_id, reminder_number, email_status, error_message)
                 VALUES (?, ?, ?, ?, ?)",
                [$user['user_id'], $user['course_id'], $reminderNumber, $emailStatus, $errorMessage]);

        if ($mailSent) {
            $totalSent++;
            logMessage("Påminnelse #{$reminderNumber} skickad till {$user['email']}");
        } else {
            $totalFailed++;
            logMessage("FEL: Kunde inte skicka påminnelse till {$user['email']}");
        }

        // Undvik att överbelasta mailservern
        usleep(100000); // 100ms delay mellan mail
    }
}

logMessage("Påminnelsejobb slutfört. Skickade: $totalSent, Misslyckade: $totalFailed");
