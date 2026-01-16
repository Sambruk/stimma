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
 * AJAX-endpoint för att skicka testmail för påminnelser
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

// Kontrollera autentisering
require_once '../include/ajax_auth_check.php';

// Verifiera CSRF-token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken. Ladda om sidan och försök igen.']);
    exit;
}

// Kontrollera att användaren är admin
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

if (!$isAdmin && !$isSuperAdmin) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att skicka testmail.']);
    exit;
}

// Hämta och validera indata
$testEmail = trim($_POST['test_email'] ?? '');
$useTemplate = isset($_POST['use_template']) && $_POST['use_template'] === '1';

if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Ange en giltig e-postadress.']);
    exit;
}

// Hämta användarens domän och påminnelseinställningar
$userEmail = $_SESSION['user_email'];
$userDomain = substr(strrchr($userEmail, "@"), 1);
$settings = queryOne("SELECT * FROM " . DB_DATABASE . ".reminder_settings WHERE domain = ?", [$userDomain]);

// Förbered e-postinnehåll
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$systemUrl = rtrim(getenv('SYSTEM_URL') ?: 'https://stimma.sambruk.se', '/');

if ($useTemplate && $settings) {
    // Använd sparad mall med exempeldata
    $emailSubject = $settings['email_subject'] ?: 'Påminnelse: Du har en påbörjad kurs i Stimma';
    $emailBody = $settings['email_body'] ?: "Hej!\n\nDu har påbörjat kursen \"{{course_title}}\" i Stimma men har ännu inte slutfört den.\n\nDu har slutfört {{completed_lessons}} av {{total_lessons}} lektioner.\n\nKlicka här för att fortsätta: {{course_url}}\n\nOm du inte längre vill gå kursen kan du avsluta den genom att klicka här: {{abandon_url}}\n\nMed vänliga hälsningar,\nStimma";

    // Skapa exempelvärden för deadline (14 dagar framåt)
    $exampleDeadline = new DateTime('+14 days');
    $months = ['januari', 'februari', 'mars', 'april', 'maj', 'juni',
               'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
    $deadlineFormatted = $exampleDeadline->format('j') . ' ' . $months[$exampleDeadline->format('n') - 1] . ' ' . $exampleDeadline->format('Y');
    $daysRemaining = '14';
    $deadlineInfo = "Kursen ska vara genomförd senast $deadlineFormatted (14 dagar kvar).";

    // Ersätt variabler med exempeldata
    $emailBody = str_replace(
        ['{{course_title}}', '{{completed_lessons}}', '{{total_lessons}}', '{{course_url}}', '{{abandon_url}}', '{{user_name}}', '{{user_email}}', '{{deadline}}', '{{days_remaining}}', '{{deadline_info}}'],
        [
            'Exempelkurs - Testmail',
            '2',
            '5',
            $systemUrl . '/lesson.php?id=test',
            $systemUrl . '/abandon_course.php?course_id=test',
            $currentUser['name'] ?: 'Testanvändare',
            $testEmail,
            $deadlineFormatted,
            $daysRemaining,
            $deadlineInfo
        ],
        $emailBody
    );
} else {
    // Använd enkel testmall
    $emailSubject = "[TESTMAIL] Påminnelse från $systemName";
    $emailBody = "Hej!\n\nDetta är ett testmail från $systemName för att verifiera att e-postinställningarna fungerar korrekt.\n\nOm du får detta mail fungerar SMTP-konfigurationen som den ska.\n\nSkickat: " . date('Y-m-d H:i:s') . "\nMottagare: $testEmail\nAvsändare: " . (getenv('MAIL_FROM_ADDRESS') ?: 'noreply@stimma.se') . "\n\nMed vänliga hälsningar,\n$systemName";
}

// Funktion för att konvertera URLs till klickbara länkar
function makeLinksClickable($text) {
    // Matcha URLs (http, https)
    $pattern = '/(https?:\/\/[^\s<>\[\]]+)/i';
    return preg_replace_callback($pattern, function($matches) {
        $url = $matches[1];
        // Ta bort eventuella avslutande skiljetecken som kan ha fångats
        $url = rtrim($url, '.,;:!?)');
        return '<a href="' . $url . '" style="color: #007bff; text-decoration: underline;">' . $url . '</a>';
    }, $text);
}

// Konvertera till HTML - först escape, sedan gör länkar klickbara, sedan newlines
$emailBodyHtml = htmlspecialchars($emailBody);
$emailBodyHtml = makeLinksClickable($emailBodyHtml);
$emailBodyHtml = nl2br($emailBodyHtml);

// Skapa HTML-mail
$htmlMessage = "
<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
<html xmlns=\"http://www.w3.org/1999/xhtml\">
<head>
    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />
    <title>$emailSubject</title>
</head>
<body style=\"font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4;\">
    <table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin: 0; padding: 0;\">
        <tr>
            <td style=\"padding: 20px;\">
                <table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
                    <tr>
                        <td style=\"padding: 30px; background-color: #007bff; border-radius: 8px 8px 0 0;\">
                            <h1 style=\"margin: 0; color: #ffffff; font-size: 24px;\">$systemName</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style=\"padding: 30px;\">
                            <p style=\"font-family: Arial, sans-serif; color: #333333; margin: 0;\">$emailBodyHtml</p>
                        </td>
                    </tr>
                    <tr>
                        <td style=\"padding: 20px 30px; background-color: #f8f9fa; border-radius: 0 0 8px 8px; text-align: center;\">
                            <p style=\"margin: 0; color: #6c757d; font-size: 12px;\">Detta är ett automatiskt genererat testmail från $systemName</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
";

// Skicka e-post
$mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@stimma.se';
$mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: 'Stimma';

$mailSent = sendSmtpMail($testEmail, $emailSubject, $htmlMessage, $mailFrom, $mailFromName);

// Logga testmail-försöket
logActivity($userEmail, 'Skickade testmail för påminnelser', [
    'action' => 'send_test_reminder',
    'to_email' => $testEmail,
    'use_template' => $useTemplate,
    'success' => $mailSent
]);

if ($mailSent) {
    echo json_encode([
        'success' => true,
        'message' => "Testmail skickat till $testEmail. Kontrollera din inkorg (och skräppost)."
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Kunde inte skicka testmail. Kontrollera SMTP-inställningarna i .env-filen.'
    ]);
}
