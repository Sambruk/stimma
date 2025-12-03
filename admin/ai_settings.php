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

// Kontrollera att användaren är inloggad och är admin
require_once 'include/auth_check.php';

// Kontrollera att användaren är super_admin
$currentUser = queryOne("SELECT role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    $_SESSION['message'] = 'Du har inte behörighet att komma åt denna sida. Endast superadministratörer kan hantera AI-inställningar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Sätt sidtitel
$page_title = 'AI-inställningar';

// Hantera formulärinlämning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ai_settings.php');
        exit;
    }

    $updatedBy = $_SESSION['user_email'];
    $success = true;

    // Uppdatera varje inställning
    $settings = [
        'guardrails_enabled' => isset($_POST['guardrails_enabled']) ? '1' : '0',
        'system_prompt_prefix' => trim($_POST['system_prompt_prefix'] ?? ''),
        'blocked_topics' => trim($_POST['blocked_topics'] ?? ''),
        'response_guidelines' => trim($_POST['response_guidelines'] ?? ''),
        'topic_restrictions' => trim($_POST['topic_restrictions'] ?? ''),
        'custom_instructions' => trim($_POST['custom_instructions'] ?? '')
    ];

    foreach ($settings as $key => $value) {
        $result = execute(
            "UPDATE " . DB_DATABASE . ".ai_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?",
            [$value, $updatedBy, $key]
        );
        if ($result === false) {
            $success = false;
        }
    }

    if ($success) {
        $_SESSION['message'] = 'AI-inställningarna har sparats.';
        $_SESSION['message_type'] = 'success';

        // Logga aktiviteten
        logActivity($_SESSION['user_email'], 'Uppdaterade AI-inställningar', [
            'action' => 'ai_settings_update'
        ]);
    } else {
        $_SESSION['message'] = 'Ett fel uppstod när inställningarna skulle sparas.';
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: ai_settings.php');
    exit;
}

// Hämta alla AI-inställningar
$settingsResult = query("SELECT setting_key, setting_value, description, updated_by, updated_at FROM " . DB_DATABASE . ".ai_settings");
$settings = [];
foreach ($settingsResult as $row) {
    $settings[$row['setting_key']] = $row;
}

// Inkludera header
require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-robot me-2"></i>AI Guardrails & Promptinställningar
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Information:</strong> Dessa inställningar styr hur AI-assistenten beter sig och svarar på användarnas frågor.
                    Guardrails hjälper till att säkerställa att AI:n håller sig till ämnet och undviker olämpligt innehåll.
                </div>

                <form method="POST" action="ai_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <!-- Aktivera/Inaktivera Guardrails -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="m-0"><i class="bi bi-shield-check me-2"></i>Guardrails Status</h6>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="guardrails_enabled" name="guardrails_enabled"
                                    <?= ($settings['guardrails_enabled']['setting_value'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="guardrails_enabled">
                                    <strong>Aktivera AI Guardrails</strong>
                                </label>
                            </div>
                            <small class="text-muted">
                                När aktiverat kommer alla guardrails-inställningar att tillämpas på AI-svaren.
                            </small>
                        </div>
                    </div>

                    <!-- System Prompt Prefix -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="m-0"><i class="bi bi-chat-left-text me-2"></i>Grundläggande Systemprompt</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="system_prompt_prefix" class="form-label">
                                    Denna text läggs till i början av varje AI-konversation
                                </label>
                                <textarea class="form-control" id="system_prompt_prefix" name="system_prompt_prefix"
                                    rows="4" placeholder="Beskriv AI:ns grundläggande roll och beteende..."><?= htmlspecialchars($settings['system_prompt_prefix']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    Exempel: "Du är en hjälpsam AI-assistent för utbildningsplattformen Stimma."
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Blockerade ämnen -->
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h6 class="m-0"><i class="bi bi-x-octagon me-2"></i>Blockerade Ämnen</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="blocked_topics" class="form-label">
                                    Ämnen som AI:n ska vägra diskutera (kommaseparerade)
                                </label>
                                <textarea class="form-control" id="blocked_topics" name="blocked_topics"
                                    rows="3" placeholder="vapen, droger, olaglig aktivitet..."><?= htmlspecialchars($settings['blocked_topics']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    AI:n kommer att avböja att svara på frågor om dessa ämnen och istället hänvisa användaren till lektionens innehåll.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Ämnesbegränsningar -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning">
                            <h6 class="m-0"><i class="bi bi-signpost-split me-2"></i>Ämnesbegränsningar</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="topic_restrictions" class="form-label">
                                    Instruktioner för hur AI:n ska hantera off-topic frågor
                                </label>
                                <textarea class="form-control" id="topic_restrictions" name="topic_restrictions"
                                    rows="4" placeholder="Håll dig till ämnet för lektionen..."><?= htmlspecialchars($settings['topic_restrictions']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    Definiera hur AI:n ska reagera när användare försöker diskutera ämnen utanför lektionens omfattning.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Svarsriktlinjer -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h6 class="m-0"><i class="bi bi-chat-quote me-2"></i>Svarsriktlinjer</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="response_guidelines" class="form-label">
                                    Riktlinjer för hur AI:n ska formulera sina svar
                                </label>
                                <textarea class="form-control" id="response_guidelines" name="response_guidelines"
                                    rows="4" placeholder="Var pedagogisk och uppmuntrande..."><?= htmlspecialchars($settings['response_guidelines']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    Definiera ton, språk och stil för AI:ns svar (t.ex. pedagogisk, formell, koncis).
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Anpassade instruktioner -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h6 class="m-0"><i class="bi bi-gear me-2"></i>Anpassade Instruktioner</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="custom_instructions" class="form-label">
                                    Ytterligare anpassade instruktioner för AI:n
                                </label>
                                <textarea class="form-control" id="custom_instructions" name="custom_instructions"
                                    rows="5" placeholder="Lägg till specifika instruktioner här..."><?= htmlspecialchars($settings['custom_instructions']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    Valfria extra instruktioner som läggs till i systemprompten. Använd detta för organisationsspecifika krav.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Förhandsvisning -->
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="m-0"><i class="bi bi-eye me-2"></i>Förhandsvisning av Systemprompt</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-2">Så här kommer den kompletta systemprompten att se ut:</p>
                            <div id="prompt-preview" class="bg-light p-3 rounded border" style="white-space: pre-wrap; font-family: monospace; font-size: 0.9rem;">
                                Laddar förhandsvisning...
                            </div>
                        </div>
                    </div>

                    <!-- Senast uppdaterad -->
                    <?php
                    $lastUpdate = $settings['system_prompt_prefix']['updated_at'] ?? null;
                    $lastUpdatedBy = $settings['system_prompt_prefix']['updated_by'] ?? null;
                    if ($lastUpdate && $lastUpdatedBy):
                    ?>
                    <div class="alert alert-secondary">
                        <i class="bi bi-clock-history me-2"></i>
                        Senast uppdaterad: <?= date('Y-m-d H:i', strtotime($lastUpdate)) ?>
                        av <?= htmlspecialchars($lastUpdatedBy) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Spara-knapp -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-save me-2"></i>Spara Inställningar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Uppdatera förhandsvisning när fälten ändras
function updatePreview() {
    const guardrailsEnabled = document.getElementById('guardrails_enabled').checked;
    const systemPrompt = document.getElementById('system_prompt_prefix').value;
    const blockedTopics = document.getElementById('blocked_topics').value;
    const topicRestrictions = document.getElementById('topic_restrictions').value;
    const responseGuidelines = document.getElementById('response_guidelines').value;
    const customInstructions = document.getElementById('custom_instructions').value;

    let preview = systemPrompt || '[Ingen grundprompt angiven]';

    if (guardrailsEnabled) {
        if (responseGuidelines) {
            preview += '\n\n**Svarsriktlinjer:**\n' + responseGuidelines;
        }
        if (topicRestrictions) {
            preview += '\n\n**Ämnesbegränsningar:**\n' + topicRestrictions;
        }
        if (blockedTopics) {
            preview += '\n\n**Du får INTE diskutera följande ämnen:** ' + blockedTopics + '. Om användaren frågar om dessa ämnen, avböj vänligt och hänvisa till lektionens innehåll.';
        }
    } else {
        preview += '\n\n[Guardrails är inaktiverade]';
    }

    if (customInstructions) {
        preview += '\n\n**Ytterligare instruktioner:**\n' + customInstructions;
    }

    preview += '\n\n[+ Lektionsspecifik AI-prompt läggs till här]';

    document.getElementById('prompt-preview').textContent = preview;
}

// Lägg till event listeners
document.getElementById('guardrails_enabled').addEventListener('change', updatePreview);
document.getElementById('system_prompt_prefix').addEventListener('input', updatePreview);
document.getElementById('blocked_topics').addEventListener('input', updatePreview);
document.getElementById('topic_restrictions').addEventListener('input', updatePreview);
document.getElementById('response_guidelines').addEventListener('input', updatePreview);
document.getElementById('custom_instructions').addEventListener('input', updatePreview);

// Initiera förhandsvisning
updatePreview();
</script>

<?php
// Inkludera footer
require_once 'include/footer.php';
?>
