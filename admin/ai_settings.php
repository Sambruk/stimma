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
        'custom_instructions' => trim($_POST['custom_instructions'] ?? ''),
        'course_generation_prompt' => trim($_POST['course_generation_prompt'] ?? '')
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

                </form>
            </div>
        </div>

        <!-- Kursgenerering (separat sektion) -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-success text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-magic me-2"></i>AI-kursgenerering
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Information:</strong> Denna prompt används när superadmin genererar nya kurser med AI.
                    Prompten styr hur kursen struktureras, vilka frågetyper som används i quiz, och hur lektionsinnehållet formateras.
                </div>

                <form method="POST" action="ai_settings.php" id="courseGenerationForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <!-- Behåll guardrails-värdet -->
                    <input type="hidden" name="guardrails_enabled" value="<?= ($settings['guardrails_enabled']['setting_value'] ?? '1') === '1' ? '1' : '' ?>">
                    <input type="hidden" name="system_prompt_prefix" value="<?= htmlspecialchars($settings['system_prompt_prefix']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="blocked_topics" value="<?= htmlspecialchars($settings['blocked_topics']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="response_guidelines" value="<?= htmlspecialchars($settings['response_guidelines']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="topic_restrictions" value="<?= htmlspecialchars($settings['topic_restrictions']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="custom_instructions" value="<?= htmlspecialchars($settings['custom_instructions']['setting_value'] ?? '') ?>">

                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="m-0"><i class="bi bi-file-earmark-code me-2"></i>Kursgeneringsprompt</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="course_generation_prompt" class="form-label">
                                    Systemprompt för AI-kursgenerering
                                </label>
                                <textarea class="form-control font-monospace" id="course_generation_prompt" name="course_generation_prompt"
                                    rows="20" placeholder="Ange prompten som används för att generera kurser..."><?= htmlspecialchars($settings['course_generation_prompt']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    <strong>Tillgängliga platshållare:</strong><br>
                                    <code>{{lesson_count}}</code> - Antal lektioner<br>
                                    <code>{{difficulty_level}}</code> - Svårighetsgrad på svenska (nybörjare/mellannivå/avancerad)<br>
                                    <code>{{difficulty}}</code> - Svårighetsgrad på engelska (beginner/intermediate/advanced)<br>
                                    <code>{{ai_instruction_value}}</code> - Ersätts med null eller "Instruktion..." beroende på inställning<br>
                                    <code>{{ai_prompt_value}}</code> - Ersätts med null eller "Prompt..." beroende på inställning
                                </small>
                            </div>

                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Frågetyper som stöds:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><code>single_choice</code> - Enkelval (ett rätt svar)</li>
                                    <li><code>multiple_choice</code> - Flerval (flera rätta svar, ange i quiz_correct_answers)</li>
                                </ul>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-outline-secondary" onclick="resetCoursePrompt()">
                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Återställ standardprompt
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save me-2"></i>Spara Kursgeneringsprompt
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Standardprompt för kursgenerering
const defaultCoursePrompt = `Du är en expert på att skapa utbildningsmaterial. Du ska generera en komplett kurs i JSON-format.

VIKTIGT: Svara ENDAST med giltig JSON, ingen annan text före eller efter.

Kursen ska:
- Ha exakt {{lesson_count}} lektioner
- Vara på {{difficulty_level}}-nivå
- Vara på svenska
- Ha pedagogiskt strukturerat innehåll med tydliga rubriker och stycken
- Innehållet ska vara i HTML-format med <h3>, <p>, <ul>, <li>, <strong> taggar

VIKTIGT FÖR LEKTIONSINNEHÅLL:
- Varje lektion ska ha MINST 400-600 ord med detaljerat och beskrivande innehåll
- Inkludera praktiska exempel, tips och förklaringar
- Använd underrubriker (<h3>) för att strukturera innehållet
- Inkludera punktlistor (<ul><li>) för att sammanfatta viktiga punkter
- Lägg till konkreta råd och steg-för-steg instruktioner där det passar
- Gör innehållet engagerande och lätt att förstå
- Avsluta varje lektion med en kort sammanfattning eller nyckelinsikter

VIKTIGT FÖR QUIZ:
För varje lektion ska du skapa ett quiz. VARIERA frågetyperna mellan lektionerna:
- single_choice: Enkelval med 3-5 svarsalternativ (ett rätt svar)
- multiple_choice: Flerval med 4-5 svarsalternativ (flera rätta svar, ange i quiz_correct_answers som "1,3" eller "2,4,5")

Riktlinjer för quiz:
- Sprid korrekta svar jämnt över positionerna (inte alltid samma position)
- Gör distraktorer (felaktiga svar) rimliga och lärorika
- Använd mestadels single_choice, men inkludera några multiple_choice för variation

JSON-strukturen ska vara:
{
  "course": {
    "title": "Kursnamn",
    "description": "Kursbeskrivning",
    "difficulty_level": "{{difficulty}}",
    "duration_minutes": <total tid i minuter>,
    "prerequisites": null,
    "tags": null,
    "status": "inactive",
    "sort_order": 0,
    "featured": 0
  },
  "lessons": [
    {
      "title": "Lektionsnamn",
      "estimated_duration": <minuter>,
      "content": "<h3>Rubrik</h3><p>Innehåll...</p>",
      "video_url": null,
      "resource_links": null,
      "tags": null,
      "status": "active",
      "sort_order": <nummer>,
      "ai_instruction": {{ai_instruction_value}},
      "ai_prompt": {{ai_prompt_value}},
      "quiz_type": "single_choice|multiple_choice",
      "quiz_question": "Fråga om lektionens innehåll?",
      "quiz_answer1": "Svarsalternativ 1",
      "quiz_answer2": "Svarsalternativ 2",
      "quiz_answer3": "Svarsalternativ 3",
      "quiz_answer4": "Svarsalternativ 4 (valfritt)",
      "quiz_answer5": "Svarsalternativ 5 (valfritt)",
      "quiz_correct_answer": 2,
      "quiz_correct_answers": null
    }
  ]
}`;

function resetCoursePrompt() {
    if (confirm('Vill du återställa kursgeneringsprompten till standardvärdet? Detta kommer att skriva över dina ändringar.')) {
        document.getElementById('course_generation_prompt').value = defaultCoursePrompt;
    }
}

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
