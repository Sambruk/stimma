<?php
/**
 * Lägg till en granskare för en kurs (migration 052, 2026-10-14).
 *
 * En granskare får läsa kursen i förhandsgranskning för att lämna synpunkter,
 * men inte ändra den – se isCourseReviewer() i functions.php. Samma behörighet
 * som för att lägga till redaktörer: den som får ändra kursen, utom en extern
 * redaktör (vilka som når kursen bestämmer kursens organisation).
 */
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

require_once '../include/ajax_auth_check.php';

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig CSRF-token.']);
    exit;
}

$userEmail = $_SESSION['user_email'];
$courseId = (int)($_POST['course_id'] ?? 0);
$email = strtolower(trim($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig e-postadress.']);
    exit;
}

$course = queryOne(
    "SELECT id, title, status, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte.']);
    exit;
}
if (!userCanModifyCourse($course)) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att lägga till granskare för denna kurs.']);
    exit;
}
if (isExternalCourseEditor($course)) {
    echo json_encode(['success' => false, 'message' => 'Kursen tillhör en annan organisation. Kontakta den för att lägga till granskare.']);
    exit;
}

if (isCourseReviewer($courseId, $email)) {
    echo json_encode(['success' => false, 'message' => 'Personen är redan granskare för kursen.']);
    exit;
}
if (isAssignedCourseEditor($courseId, $email)) {
    echo json_encode(['success' => false, 'message' => 'Personen är redaktör för kursen och kan redan läsa den.']);
    exit;
}

// Saknas kontot skapas det om domänen är ansluten till Stimma – samma regel som
// för redaktörer (add_course_editor.php). Kontot får ingen roll.
$nyttKonto = false;
if (!queryOne("SELECT 1 FROM " . DB_DATABASE . ".users WHERE email = ?", [$email])) {
    $doman = substr(strrchr($email, '@'), 1);
    if (!isDomainAllowed($doman)) {
        echo json_encode(['success' => false, 'message' => 'Domänen ' . $doman . ' är inte ansluten till Stimma.']);
        exit;
    }
    execute("INSERT INTO " . DB_DATABASE . ".users (email, created_at) VALUES (?, NOW())", [$email]);
    $nyttKonto = true;
}

if (!execute(
    "INSERT INTO " . DB_DATABASE . ".course_reviewers (course_id, email, created_by) VALUES (?, ?, ?)",
    [$courseId, $email, $userEmail]
)) {
    echo json_encode(['success' => false, 'message' => 'Kunde inte lägga till granskaren.']);
    exit;
}

$extern = !in_array($course['organization_domain'], getOrgScopeDomains($email), true);

$addedBy = queryOne("SELECT name FROM " . DB_DATABASE . ".users WHERE email = ?", [$userEmail]);
$addedByName = htmlspecialchars(!empty($addedBy['name']) ? $addedBy['name'] : $userEmail);
$courseTitle = htmlspecialchars($course['title']);
$systemName = getenv('SYSTEM_NAME') ?: 'Stimma';
$siteUrl = defined('SITE_URL') ? SITE_URL : 'https://stimma.sambruk.se';
$kursLank = $siteUrl . '/course.php?id=' . $courseId;
$inaktiv = $course['status'] !== 'active'
    ? '<p>Kursen är inte publicerad ännu – du ser den innan deltagarna gör det.</p>' : '';
$avsandare = htmlspecialchars($userEmail);

$emailBody = "
<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
    <div style='background: #0F3B5F; color: #fff; padding: 20px; text-align: center;'>
        <h2 style='margin: 0;'>$systemName</h2>
    </div>
    <div style='padding: 30px; background: #f9f9f9;'>
        <h3 style='color: #333;'>Du är inbjuden att granska en kurs</h3>
        <p>Hej!</p>
        <p><strong>$addedByName</strong> vill ha dina synpunkter på kursen:</p>
        <div style='background: #fff; border-left: 4px solid #0F3B5F; padding: 15px; margin: 20px 0;'>
            <strong style='font-size: 1.1em;'>$courseTitle</strong>
        </div>
        $inaktiv
        <p>Du kan läsa hela kursen och prova quizen. Inga framsteg eller svar sparas, och du kan inte ändra något.
           Synpunkter skickar du till $avsandare.</p>
        <p>Logga in med din e-postadress på $siteUrl – du får en inloggningslänk till den här adressen.
           Kursen finns sedan under Kurser att granska på startsidan.</p>
        <p style='text-align: center; margin-top: 25px;'>
            <a href='$kursLank' style='background: #0F3B5F; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 5px;'>Öppna kursen</a>
        </p>
    </div>
    <div style='padding: 15px; text-align: center; color: #999; font-size: 12px;'>
        Detta meddelande skickades från $systemName
    </div>
</div>";

sendSmtpMail($email, "Granska kursen - " . $course['title'], $emailBody);

logActivity($userEmail, "Lade till granskare {$email} för kurs {$courseId}"
    . ($extern ? ' (annan organisation)' : '') . ($nyttKonto ? ', konto skapat' : ''));

echo json_encode(['success' => true, 'extern' => $extern, 'nytt_konto' => $nyttKonto]);
