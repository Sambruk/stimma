<?php
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

// Include AJAX-compatible authentication check
require_once '../include/ajax_auth_check.php';

// Kontrollera CSRF-token
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig CSRF-token.']);
    exit;
}

// Hämta användarens behörigheter
$userEmail = $_SESSION['user_email'];
$isAdmin = isAdmin($userEmail);

// Validera input
if (!isset($_POST['course_id']) || !isset($_POST['email'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig förfrågan.']);
    exit;
}

$courseId = (int)$_POST['course_id'];
$newEditorEmail = trim($_POST['email']);

// Kontrollera om kursen finns (måste hämtas före behörighetscheck så att
// userCanModifyCourse kan kolla organization_domain).
$course = queryOne(
    "SELECT id, organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte.']);
    exit;
}

// IDOR-fixad behörighetskontroll: admins får bara modifiera kurser i sin
// egen org. Tidigare släpptes alla admins igenom oavsett kursens org.
if (!userCanModifyCourse($course)) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att lägga till redaktörer för denna kurs.']);
    exit;
}

// Kontrollera om e-postadressen redan är redaktör
$existingEditor = queryOne("SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?", [$courseId, $newEditorEmail]);
if ($existingEditor) {
    echo json_encode(['success' => false, 'message' => 'Denna e-postadress är redan redaktör för kursen.']);
    exit;
}

// Kontrollera om användaren finns
$userExists = queryOne("SELECT 1 FROM " . DB_DATABASE . ".users WHERE email = ?", [$newEditorEmail]);
if (!$userExists) {
    echo json_encode(['success' => false, 'message' => 'Användaren måste skapas först.']);
    exit;
}

// Lägg till redaktör
try {
    // Starta transaktion för att säkerställa att båda uppdateringarna genomförs eller ingen
    execute("START TRANSACTION");
    
    // Lägg till i course_editors-tabellen
    $sql1 = "INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by) VALUES (?, ?, ?)";
    $result1 = execute($sql1, [$courseId, $newEditorEmail, $userEmail]);
    
    // Uppdatera is_editor i users-tabellen om den inte redan är satt till 1
    $sql2 = "UPDATE " . DB_DATABASE . ".users SET is_editor = 1 WHERE email = ? AND (is_editor = 0 OR is_editor IS NULL)";
    $result2 = execute($sql2, [$newEditorEmail]);
    
    if ($result1) {
        // Bekräfta transaktion
        execute("COMMIT");

        // Skicka e-postnotifiering till den nya redaktören
        $courseInfo = queryOne("SELECT title FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
        $addedByUser = queryOne("SELECT name, email FROM " . DB_DATABASE . ".users WHERE email = ?", [$userEmail]);
        $addedByName = !empty($addedByUser['name']) ? $addedByUser['name'] : $addedByUser['email'];
        $courseTitle = $courseInfo ? htmlspecialchars($courseInfo['title']) : 'Okänd kurs';
        $systemName = getenv('SYSTEM_NAME') ?: 'Stimma';
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://stimma.sambruk.se';

        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #0F3B5F; color: #fff; padding: 20px; text-align: center;'>
                <h2 style='margin: 0;'>$systemName</h2>
            </div>
            <div style='padding: 30px; background: #f9f9f9;'>
                <h3 style='color: #333;'>Du har lagts till som redaktör</h3>
                <p>Hej!</p>
                <p><strong>$addedByName</strong> har lagt till dig som redaktör för kursen:</p>
                <div style='background: #fff; border-left: 4px solid #0F3B5F; padding: 15px; margin: 20px 0;'>
                    <strong style='font-size: 1.1em;'>$courseTitle</strong>
                </div>
                <p>Som redaktör kan du redigera kursens innehåll och lektioner.</p>
                <p style='text-align: center; margin-top: 25px;'>
                    <a href='$siteUrl/admin/courses.php' style='background: #0F3B5F; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 5px;'>Gå till kurser</a>
                </p>
            </div>
            <div style='padding: 15px; text-align: center; color: #999; font-size: 12px;'>
                Detta meddelande skickades från $systemName
            </div>
        </div>";

        sendSmtpMail($newEditorEmail, "Du har lagts till som redaktör - $courseTitle", $emailBody);

        echo json_encode(['success' => true]);
    } else {
        // Återställ transaktion vid fel
        execute("ROLLBACK");
        echo json_encode(['success' => false, 'message' => 'Kunde inte lägga till redaktören.']);
    }
} catch (Exception $e) {
    // Återställ transaktion vid fel och visa felmeddelande
    execute("ROLLBACK");
    echo json_encode(['success' => false, 'message' => 'Ett fel uppstod när redaktören skulle läggas till.']);
} 