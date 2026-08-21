<?php
/**
 * Deltagarens självradering från en publik kurs.
 *
 * GET: visar en bekräftelsesida med tvåspärrsmodal (kryssruta + skriv RADERA).
 * POST: raderar all deltagardata för kursen, skickar bekräftelsemail, om
 * användaren är public_only och detta var sista publika kursen → konto raderas
 * + session destroy. Annars → tillbaka till index.php.
 */
require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/mail.php';

if (!isLoggedIn()) {
    redirect('index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : (int)($_POST['course_id'] ?? 0);

$course = queryOne(
    "SELECT id, title FROM " . DB_DATABASE . ".courses WHERE id = ?",
    [$courseId]
);
if (!$course) {
    $_SESSION['flash_message'] = 'Kursen hittades inte.';
    $_SESSION['flash_type'] = 'danger';
    redirect('index.php');
    exit;
}

// Måste ha publik access till den angivna kursen
if (!hasPublicCourseAccess($userId, $courseId)) {
    $_SESSION['flash_message'] = 'Du är inte registrerad för den kursen.';
    $_SESSION['flash_type'] = 'warning';
    redirect('index.php');
    exit;
}

$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errorMsg = 'Ogiltig säkerhetstoken. Försök igen.';
    } elseif (($_POST['confirm_understand'] ?? '') !== '1' || ($_POST['confirm_text'] ?? '') !== 'RADERA') {
        $errorMsg = 'Båda bekräftelserna krävs för att radera.';
    } else {
        // Hämta info innan vi raderar
        $userRow = queryOne(
            "SELECT id, email, name, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
            [$userId]
        );

        try {
            purgePublicCourseUserData($userId, $courseId);

            // Bekräftelsemail
            $systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
            $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
            $mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: $systemName;
            $name = trim($userRow['name'] ?? '');
            $greeting = $name !== '' ? ('Hej ' . htmlspecialchars($name) . ',') : 'Hej,';
            $subject = 'Din anmälan till ' . $course['title'] . ' har avslutats';
            $body = '<html><body style="font-family: Arial, sans-serif;">'
                  . '<p>' . $greeting . '</p>'
                  . '<p>Du har avregistrerats från kursen <strong>' . htmlspecialchars($course['title']) . '</strong> och all din data för den här kursen har raderats permanent enligt din begäran.</p>'
                  . '<p>Om detta var ett misstag kan du registrera dig igen via samma länk från kursens administratör.</p>'
                  . '<p>Hälsningar,<br>' . htmlspecialchars($systemName) . '</p>'
                  . '</body></html>';
            @sendSmtpMail($userRow['email'], $subject, $body, $mailFrom, $mailFromName);

            $wasOrphanRemoved = maybeDeleteOrphanPublicUser($userId);

            if ($wasOrphanRemoved) {
                // Inget konto kvar — förstör sessionen och visa bekräftelse
                session_destroy();
                ?>
                <!DOCTYPE html><html lang="sv"><head>
                <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Avregistrering klar</title>
                <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
                <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
                </head><body>
                <div class="container py-5"><div class="row justify-content-center"><div class="col-12 col-md-6">
                    <div class="card shadow-sm"><div class="card-body text-center p-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        <h2 class="h4 mt-3">Avregistrering klar</h2>
                        <p class="text-muted">Du har avregistrerats från kursen <strong><?= htmlspecialchars($course['title']) ?></strong> och all din data har raderats. Ett bekräftelsemail har skickats.</p>
                    </div></div>
                </div></div></div>
                </body></html>
                <?php
                exit;
            } else {
                $_SESSION['flash_message'] = 'Du har avregistrerats från "' . $course['title'] . '". All din kursdata är borta.';
                $_SESSION['flash_type'] = 'success';
                redirect('index.php');
                exit;
            }
        } catch (Exception $e) {
            error_log('[leave_public_course] Fel: ' . $e->getMessage());
            $errorMsg = 'Ett fel uppstod. Försök igen eller kontakta support.';
        }
    }
}

$page_title = 'Lämna kursen';
require_once 'include/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Lämna kursen och radera all data</h5>
                </div>
                <div class="card-body">
                    <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
                    <?php endif; ?>

                    <p>Du kommer att avregistreras från <strong><?= htmlspecialchars($course['title']) ?></strong> och all din data raderas permanent:</p>
                    <ul class="small text-muted">
                        <li>Ditt framsteg i kursens lektioner</li>
                        <li>Din anmälan och lektionsschema</li>
                        <li>Eventuella påminnelsemail i systemet</li>
                    </ul>

                    <form method="post" action="leave_public_course.php" id="leaveForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">

                        <div class="form-check mb-3">
                            <input class="form-check-input confirm-understand" type="checkbox" id="confirmUnderstand" name="confirm_understand" value="1">
                            <label class="form-check-label" for="confirmUnderstand">
                                Jag förstår att detta inte kan ångras
                            </label>
                        </div>

                        <label class="form-label small">Skriv <code>RADERA</code> för att bekräfta:</label>
                        <input type="text" class="form-control confirm-type-radera" name="confirm_text" placeholder="RADERA" autocomplete="off">

                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="index.php" class="btn btn-secondary">Avbryt</a>
                            <button type="submit" class="btn btn-danger confirm-destructive-btn" disabled>
                                <i class="bi bi-trash me-1"></i>RADERA
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="admin/include/confirm_destructive.js"></script>
<script>
(function() {
    const form = document.getElementById('leaveForm');
    const check = form.querySelector('.confirm-understand');
    const input = form.querySelector('.confirm-type-radera');
    const btn = form.querySelector('.confirm-destructive-btn');
    function update() { btn.disabled = !(check.checked && input.value.trim() === 'RADERA'); }
    check.addEventListener('change', update);
    input.addEventListener('input', update);
    update();
})();
</script>

<?php require_once 'include/footer.php'; ?>
