<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

// Sidtitel
$page_title = 'Påminnelseinställningar';

// Kontrollera att användaren är inloggad
require_once 'include/auth_check.php';

// Hämta användarinformation
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userEmail = $_SESSION['user_email'];
$userDomain = substr(strrchr($userEmail, "@"), 1);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

// Kontrollera behörighet - måste vara admin
if (!$isAdmin && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att hantera påminnelseinställningar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Hämta eller skapa inställningar för domänen
$settings = queryOne("SELECT * FROM " . DB_DATABASE . ".reminder_settings WHERE domain = ?", [$userDomain]);

if (!$settings) {
    // Skapa standardinställningar för domänen
    execute("INSERT INTO " . DB_DATABASE . ".reminder_settings (domain, created_by) VALUES (?, ?)",
            [$userDomain, $userEmail]);
    $settings = queryOne("SELECT * FROM " . DB_DATABASE . ".reminder_settings WHERE domain = ?", [$userDomain]);
}

// Hantera formulärdata
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifiera CSRF-token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: reminders.php');
        exit;
    }

    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $daysAfterStart = max(1, min(365, (int)$_POST['days_after_start']));
    $maxReminders = max(1, min(10, (int)$_POST['max_reminders']));
    $daysBetweenReminders = max(1, min(90, (int)$_POST['days_between_reminders']));
    $emailSubject = trim($_POST['email_subject']);
    $emailBody = trim($_POST['email_body']);

    // Validera
    if (empty($emailSubject)) {
        $emailSubject = 'Påminnelse: Du har en påbörjad kurs i Stimma';
    }

    if (empty($emailBody)) {
        $emailBody = "Hej!\n\nDu har påbörjat kursen \"{{course_title}}\" i Stimma men har ännu inte slutfört den.\n\nDu har slutfört {{completed_lessons}} av {{total_lessons}} lektioner.\n\nKlicka här för att fortsätta: {{course_url}}\n\nOm du inte längre vill gå kursen kan du avsluta den genom att klicka här: {{abandon_url}}\n\nMed vänliga hälsningar,\nStimma";
    }

    // Uppdatera inställningarna
    execute("UPDATE " . DB_DATABASE . ".reminder_settings SET
             enabled = ?,
             days_after_start = ?,
             max_reminders = ?,
             days_between_reminders = ?,
             email_subject = ?,
             email_body = ?,
             updated_at = NOW()
             WHERE domain = ?",
            [$enabled, $daysAfterStart, $maxReminders, $daysBetweenReminders, $emailSubject, $emailBody, $userDomain]);

    $_SESSION['message'] = 'Påminnelseinställningarna har sparats.';
    $_SESSION['message_type'] = 'success';

    logActivity($userEmail, 'Uppdaterade påminnelseinställningar', [
        'action' => 'update_reminder_settings',
        'domain' => $userDomain
    ]);

    header('Location: reminders.php');
    exit;
}

// Hämta statistik
$reminderStats = queryOne("SELECT
    COUNT(*) as total_sent,
    SUM(CASE WHEN email_status = 'sent' THEN 1 ELSE 0 END) as successful,
    SUM(CASE WHEN email_status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM " . DB_DATABASE . ".reminder_log rl
    JOIN " . DB_DATABASE . ".users u ON rl.user_id = u.id
    WHERE u.email LIKE ?", ['%@' . $userDomain]);

// Hämta senaste påminnelser
$recentReminders = query("SELECT rl.*, u.email, u.name, c.title as course_title
    FROM " . DB_DATABASE . ".reminder_log rl
    JOIN " . DB_DATABASE . ".users u ON rl.user_id = u.id
    JOIN " . DB_DATABASE . ".courses c ON rl.course_id = c.id
    WHERE u.email LIKE ?
    ORDER BY rl.sent_at DESC
    LIMIT 10", ['%@' . $userDomain]);

// Inkludera header
require_once 'include/header.php';
?>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Inställningar för påminnelser</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="reminders.php">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enabled" name="enabled"
                                   <?= $settings['enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enabled">
                                <strong>Aktivera automatiska påminnelser</strong>
                            </label>
                        </div>
                        <small class="text-muted">När aktiverat skickas påminnelser automatiskt till användare som påbörjat men inte slutfört kurser.</small>
                    </div>

                    <hr>

                    <h6 class="mb-3">Tidsinställningar</h6>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="days_after_start" class="form-label">Dagar efter kursstart</label>
                            <input type="number" class="form-control" id="days_after_start" name="days_after_start"
                                   value="<?= $settings['days_after_start'] ?>" min="1" max="365">
                            <small class="text-muted">Antal dagar efter påbörjad kurs innan första påminnelsen skickas.</small>
                        </div>
                        <div class="col-md-4">
                            <label for="max_reminders" class="form-label">Max antal påminnelser</label>
                            <input type="number" class="form-control" id="max_reminders" name="max_reminders"
                                   value="<?= $settings['max_reminders'] ?>" min="1" max="10">
                            <small class="text-muted">Maximalt antal påminnelser som skickas per kurs.</small>
                        </div>
                        <div class="col-md-4">
                            <label for="days_between_reminders" class="form-label">Dagar mellan påminnelser</label>
                            <input type="number" class="form-control" id="days_between_reminders" name="days_between_reminders"
                                   value="<?= $settings['days_between_reminders'] ?>" min="1" max="90">
                            <small class="text-muted">Antal dagar mellan varje påminnelse.</small>
                        </div>
                    </div>

                    <hr>

                    <h6 class="mb-3">E-postinnehåll</h6>

                    <div class="mb-3">
                        <label for="email_subject" class="form-label">Ämnesrad</label>
                        <input type="text" class="form-control" id="email_subject" name="email_subject"
                               value="<?= htmlspecialchars($settings['email_subject']) ?>" maxlength="255">
                    </div>

                    <div class="mb-3">
                        <label for="email_body" class="form-label">Meddelandetext</label>
                        <textarea class="form-control" id="email_body" name="email_body" rows="12"><?= htmlspecialchars($settings['email_body']) ?></textarea>
                        <small class="text-muted">
                            Tillgängliga variabler:
                            <code>{{course_title}}</code>,
                            <code>{{completed_lessons}}</code>,
                            <code>{{total_lessons}}</code>,
                            <code>{{course_url}}</code>,
                            <code>{{abandon_url}}</code>,
                            <code>{{user_name}}</code>,
                            <code>{{user_email}}</code>,
                            <code>{{deadline}}</code> (slutdatum, t.ex. "15 februari 2026"),
                            <code>{{days_remaining}}</code> (antal dagar kvar),
                            <code>{{deadline_info}}</code> (komplett mening om deadline finns)
                        </small>
                    </div>

                    <div class="d-flex justify-content-between">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Spara inställningar
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetToDefaults()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Återställ till standard
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Statistik</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <h3 class="mb-0"><?= $reminderStats['total_sent'] ?? 0 ?></h3>
                        <small class="text-muted">Totalt skickade</small>
                    </div>
                    <div class="col-4">
                        <h3 class="mb-0 text-success"><?= $reminderStats['successful'] ?? 0 ?></h3>
                        <small class="text-muted">Lyckade</small>
                    </div>
                    <div class="col-4">
                        <h3 class="mb-0 text-danger"><?= $reminderStats['failed'] ?? 0 ?></h3>
                        <small class="text-muted">Misslyckade</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Skicka testmail</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Skicka ett testmail för att verifiera att e-postinställningarna fungerar korrekt.</p>
                <form id="testMailForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="mb-3">
                        <label for="test_email" class="form-label">Mottagare</label>
                        <input type="email" class="form-control" id="test_email" name="test_email"
                               value="<?= htmlspecialchars($userEmail) ?>" required>
                        <small class="text-muted">E-postadressen dit testmailet ska skickas.</small>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="use_template" name="use_template" checked>
                        <label class="form-check-label" for="use_template">
                            Använd sparad e-postmall
                        </label>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100" id="sendTestBtn">
                        <i class="bi bi-send me-1"></i>Skicka testmail
                    </button>
                </form>
                <div id="testMailResult" class="mt-3" style="display: none;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Senaste påminnelser</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentReminders)): ?>
                    <div class="p-3 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        Inga påminnelser har skickats ännu.
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recentReminders as $reminder): ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($reminder['name'] ?: $reminder['email']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($reminder['course_title']) ?></small>
                                        <br>
                                        <small class="text-muted">Påminnelse <?= $reminder['reminder_number'] ?></small>
                                    </div>
                                    <div class="text-end">
                                        <?php if ($reminder['email_status'] === 'sent'): ?>
                                            <span class="badge bg-success">Skickad</span>
                                        <?php elseif ($reminder['email_status'] === 'failed'): ?>
                                            <span class="badge bg-danger">Misslyckades</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Studsade</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted"><?= date('Y-m-d H:i', strtotime($reminder['sent_at'])) ?></small>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Testmail-funktionalitet
document.getElementById('testMailForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const btn = document.getElementById('sendTestBtn');
    const resultDiv = document.getElementById('testMailResult');
    const testEmail = document.getElementById('test_email').value;
    const useTemplate = document.getElementById('use_template').checked;
    const csrfToken = this.querySelector('[name="csrf_token"]').value;

    // Visa laddningsindikator
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Skickar...';
    resultDiv.style.display = 'none';

    // Skicka AJAX-förfrågan
    fetch('ajax/send_test_reminder.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'test_email=' + encodeURIComponent(testEmail) +
              '&use_template=' + (useTemplate ? '1' : '0') +
              '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        resultDiv.style.display = 'block';
        if (data.success) {
            resultDiv.className = 'alert alert-success mt-3';
            resultDiv.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + data.message;
        } else {
            resultDiv.className = 'alert alert-danger mt-3';
            resultDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + data.message;
        }
    })
    .catch(error => {
        resultDiv.style.display = 'block';
        resultDiv.className = 'alert alert-danger mt-3';
        resultDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Ett oväntat fel uppstod.';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i>Skicka testmail';
    });
});

function resetToDefaults() {
    if (confirm('Är du säker på att du vill återställa alla inställningar till standardvärden?')) {
        document.getElementById('enabled').checked = true;
        document.getElementById('days_after_start').value = 7;
        document.getElementById('max_reminders').value = 3;
        document.getElementById('days_between_reminders').value = 7;
        document.getElementById('email_subject').value = 'Påminnelse: Du har en påbörjad kurs i Stimma';
        document.getElementById('email_body').value = `Hej!

Du har påbörjat kursen "{{course_title}}" i Stimma men har ännu inte slutfört den.

Du har slutfört {{completed_lessons}} av {{total_lessons}} lektioner.

Klicka här för att fortsätta: {{course_url}}

Om du inte längre vill gå kursen kan du avsluta den genom att klicka här: {{abandon_url}}

Med vänliga hälsningar,
Stimma`;
    }
}
</script>

<?php require_once 'include/footer.php'; ?>
