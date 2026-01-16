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
 * Sida för att avsluta/överge en påbörjad kurs.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';

$error = '';
$success = '';
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$token = $_GET['token'] ?? '';

// Hämta kursinformation
$course = null;
$user = null;

if ($courseId > 0) {
    $course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
}

// Kontrollera om användaren är inloggad
$isLoggedIn = isLoggedIn();

if ($isLoggedIn) {
    $user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
}

// Validera token för icke-inloggade användare
$tokenValid = false;
if (!$isLoggedIn && !empty($token) && $user) {
    // Token valideras mot user_id + course_id + månad
    $expectedToken = hash('sha256', $user['id'] . $courseId . date('Y-m'));
    $tokenValid = hash_equals($expectedToken, $token);
}

// Hantera formulär för att avsluta kurs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $course) {
    // Validera CSRF-token om inloggad
    if ($isLoggedIn) {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            $error = 'Ogiltig förfrågan. Vänligen försök igen.';
        } else {
            $userId = $_SESSION['user_id'];
            $reason = trim($_POST['reason'] ?? '');

            // Kontrollera om det redan finns en enrollment-rad
            $enrollment = queryOne("SELECT * FROM " . DB_DATABASE . ".course_enrollments
                                    WHERE user_id = ? AND course_id = ?",
                                   [$userId, $courseId]);

            if ($enrollment) {
                // Uppdatera befintlig rad
                execute("UPDATE " . DB_DATABASE . ".course_enrollments
                         SET status = 'abandoned',
                             abandoned_at = NOW(),
                             abandon_reason = ?,
                             opt_out_reminders = 1
                         WHERE user_id = ? AND course_id = ?",
                        [$reason, $userId, $courseId]);
            } else {
                // Skapa ny rad
                execute("INSERT INTO " . DB_DATABASE . ".course_enrollments
                         (user_id, course_id, status, abandoned_at, abandon_reason, opt_out_reminders)
                         VALUES (?, ?, 'abandoned', NOW(), ?, 1)",
                        [$userId, $courseId, $reason]);
            }

            logActivity($_SESSION['user_email'], "Avslutade kurs", [
                'action' => 'course_abandoned',
                'course_id' => $courseId,
                'course_title' => $course['title'],
                'reason' => $reason
            ]);

            $success = 'Du har avslutat kursen "' . htmlspecialchars($course['title']) . '". Du kommer inte längre få påminnelser om denna kurs.';
        }
    } else {
        $error = 'Du måste vara inloggad för att avsluta en kurs.';
    }
}

// Sätt sidtitel
$page_title = 'Avsluta kurs - ' . $systemName;
require_once 'include/header.php';
?>

<div class="container-sm min-vh-100 d-flex align-items-center px-3">
    <div class="row justify-content-center w-100">
        <div class="col-12 col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-4 text-center">
                        <i class="bi bi-x-circle text-warning me-2"></i>
                        Avsluta kurs
                    </h1>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?= $success ?>
                        </div>
                        <div class="text-center mt-4">
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-house me-1"></i>Tillbaka till startsidan
                            </a>
                        </div>
                    <?php elseif ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?= $error ?>
                        </div>
                        <div class="text-center mt-4">
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-house me-1"></i>Tillbaka till startsidan
                            </a>
                        </div>
                    <?php elseif (!$course): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            Kursen kunde inte hittas.
                        </div>
                        <div class="text-center mt-4">
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-house me-1"></i>Tillbaka till startsidan
                            </a>
                        </div>
                    <?php elseif (!$isLoggedIn): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            Du måste logga in för att avsluta kursen "<?= htmlspecialchars($course['title']) ?>".
                        </div>
                        <div class="text-center mt-4">
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Logga in
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="mb-4">
                            <p class="text-muted">
                                Vill du verkligen avsluta kursen <strong>"<?= htmlspecialchars($course['title']) ?>"</strong>?
                            </p>
                            <p class="text-muted small">
                                Om du avslutar kursen kommer du inte längre få påminnelser om den.
                                Din framsteg i kursen kommer att sparas och du kan alltid återvända till kursen senare om du ändrar dig.
                            </p>
                        </div>

                        <form method="POST" action="abandon_course.php?course_id=<?= $courseId ?>">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                            <div class="mb-3">
                                <label for="reason" class="form-label">Varför vill du avsluta kursen? (valfritt)</label>
                                <select class="form-select" id="reason" name="reason">
                                    <option value="">Välj en anledning...</option>
                                    <option value="Startade kursen av misstag">Startade kursen av misstag</option>
                                    <option value="Kursen är inte relevant för mig">Kursen är inte relevant för mig</option>
                                    <option value="Har inte tid just nu">Har inte tid just nu</option>
                                    <option value="Kursen var för svår">Kursen var för svår</option>
                                    <option value="Kursen var för enkel">Kursen var för enkel</option>
                                    <option value="Annat">Annat</option>
                                </select>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-x-circle me-1"></i>Ja, avsluta kursen
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>Nej, gå tillbaka
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
