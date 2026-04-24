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

/**
 * Spara en ny version av en prompt om innehållet ändrats. Returnerar det nya
 * versionsnumret eller null om innehållet är oförändrat.
 */
function saveAiPromptVersionIfChanged($settingKey, $newContent, $createdBy) {
    $latest = queryOne(
        "SELECT version, content FROM " . DB_DATABASE . ".ai_prompt_versions
         WHERE setting_key = ? ORDER BY version DESC LIMIT 1",
        [$settingKey]
    );
    if ($latest && $latest['content'] === $newContent) {
        return null; // oförändrat — spara inget
    }
    if ($newContent === '' && !$latest) {
        return null; // tomt och ingen historik — meningslöst att versionera
    }
    $nextVersion = $latest ? ((int)$latest['version'] + 1) : 1;
    execute(
        "INSERT INTO " . DB_DATABASE . ".ai_prompt_versions
         (setting_key, version, content, created_by) VALUES (?, ?, ?, ?)",
        [$settingKey, $nextVersion, $newContent, $createdBy]
    );
    return $nextVersion;
}

// Hantera restore av tidigare version (eget flöde innan standard-POST-hanteringen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_prompt_version') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ai_settings.php');
        exit;
    }

    $versionId = (int)($_POST['version_id'] ?? 0);
    $versionRow = queryOne(
        "SELECT setting_key, version, content FROM " . DB_DATABASE . ".ai_prompt_versions WHERE id = ?",
        [$versionId]
    );
    if (!$versionRow || $versionRow['setting_key'] !== 'course_generation_prompt') {
        $_SESSION['message'] = 'Ogiltig version.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ai_settings.php');
        exit;
    }

    $newVersion = saveAiPromptVersionIfChanged('course_generation_prompt', $versionRow['content'], $_SESSION['user_email']);
    execute(
        "UPDATE " . DB_DATABASE . ".ai_settings SET setting_value = ?, updated_by = ? WHERE setting_key = 'course_generation_prompt'",
        [$versionRow['content'], $_SESSION['user_email']]
    );
    logActivity($_SESSION['user_email'], 'Återställde AI-kursgenereringsprompt', [
        'action' => 'ai_prompt_restore',
        'restored_from_version' => $versionRow['version'],
        'new_version' => $newVersion
    ]);

    $_SESSION['message'] = $newVersion
        ? "Version {$versionRow['version']} återställd som ny version $newVersion."
        : "Version {$versionRow['version']} är redan aktuell.";
    $_SESSION['message_type'] = 'success';
    header('Location: ai_settings.php');
    exit;
}

// Hantera formulärinlämning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ai_settings.php');
        exit;
    }

    // Innan vi skriver över: spara ny version av kursgenereringsprompten om den ändrats.
    if (isset($_POST['course_generation_prompt'])) {
        saveAiPromptVersionIfChanged(
            'course_generation_prompt',
            trim($_POST['course_generation_prompt']),
            $_SESSION['user_email']
        );
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
    ];
    // course_generation_prompt uppdateras bara om fältet faktiskt skickades med
    // (annars skulle submission av andra formulär på sidan nolla ut prompten).
    if (array_key_exists('course_generation_prompt', $_POST)) {
        $settings['course_generation_prompt'] = trim($_POST['course_generation_prompt']);
    }

    // Handle max_lesson_count if submitted
    if (isset($_POST['max_lesson_count'])) {
        $maxLessons = max(1, min(100, (int)$_POST['max_lesson_count']));
        $settings['max_lesson_count'] = (string)$maxLessons;
    }

    // Handle sequential course settings if submitted
    if (isset($_POST['sequential_cron_hour'])) {
        $settings['sequential_cron_hour'] = (string)max(0, min(23, (int)$_POST['sequential_cron_hour']));
    }
    if (isset($_POST['sequential_batch_size'])) {
        $settings['sequential_batch_size'] = (string)max(1, min(500, (int)$_POST['sequential_batch_size']));
    }
    if (isset($_POST['sequential_batch_delay_seconds'])) {
        $settings['sequential_batch_delay_seconds'] = (string)max(0, min(300, (int)$_POST['sequential_batch_delay_seconds']));
    }

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

// Versioner av kursgenereringsprompten (nyaste först)
$promptVersions = query(
    "SELECT id, version, content, created_by, created_at
     FROM " . DB_DATABASE . ".ai_prompt_versions
     WHERE setting_key = 'course_generation_prompt'
     ORDER BY version DESC"
);
$currentPromptVersion = $promptVersions[0] ?? null;

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
                    <strong>Information:</strong> AI-kursgenereringen använder två inbyggda grundprompter (Fas 1 för struktur, Fas 2 för lektionsinnehåll). Du kan se dem i read-only-vyn nedan och lägga till egna kompletterande instruktioner som appenderas vid varje generering — t.ex. organisationsspecifika krav, terminologi eller tonfall.
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

                    <!-- Max antal lektioner -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="m-0"><i class="bi bi-sliders me-2"></i>Begränsningar</h6>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-end">
                                <div class="col-md-4">
                                    <label for="max_lesson_count" class="form-label">
                                        Maximalt antal lektioner per AI-genererad kurs
                                    </label>
                                    <input type="number" class="form-control" id="max_lesson_count" name="max_lesson_count"
                                        min="1" max="100" value="<?= htmlspecialchars($settings['max_lesson_count']['setting_value'] ?? '20') ?>">
                                </div>
                                <div class="col-md-8">
                                    <small class="text-muted">
                                        Denna gräns visas i AI-genereringsmodalen och förhindrar att användare anger fler lektioner än tillåtet.
                                        Standardvärde: 20. Högre värden ger längre genereringstid och högre API-kostnader.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Hårdkodade referensprompter som visas för admin. Dessa speglar exakt
                    // texten som byggs i process_ai_jobs.php (placeholders i {...} ersätts
                    // där med värden från jobbet).
                    $phase1PromptReference = <<<'TXT'
Du är en expert på att skapa utbildningsmaterial. Du ska generera en kursstruktur i JSON-format.

VIKTIGT: Svara ENDAST med giltig JSON, ingen annan text före eller efter.

Du ska skapa EXAKT {lesson_count} lektioner. Detta är ett ABSOLUT krav - varken fler eller färre.

Kursen ska:
- Ha EXAKT {lesson_count} lektioner (detta är obligatoriskt)
- Vara på {difficulty_level}-nivå  (nybörjare / mellannivå / avancerad)
- Vara på svenska
- Ha {tone} ton  (pedagogisk / formell / avslappnad / inspirerande)
- Använda {language_style}  (formellt / informellt / akademiskt / vardagligt språk)
- [om angivet] Målgrupp: {target_audience}

QUIZ (om aktiverat): Varje lektion ska ha 2-4 frågor i en "questions"-array. VÄLJ frågetyp
som passar bäst för innehållet — variera över lektionerna.

FRÅGETYPER: single_choice, multiple_choice, true_false, fill_blank, order,
match_pairs, categorize, numeric, short_text.
(INTE image_choice eller hotspot — kräver bildfiler.)

REGLER:
- Välj EN typ per fråga som verkligen matchar innehållet
- Minst 3 olika typer totalt över kursen (variera!)
- Sprid korrekta svar jämnt (inte alltid position 0)

AI-HANDLEDARE (om aktiverat): Varje lektion ska ha
- "ai_instruction": Kort instruktion för AI-handledaren om lektionens ämne
- "ai_prompt": Startprompt för dialogen med användaren

JSON-strukturen ska vara:
{
  "course": { "title", "description", "difficulty_level", "duration_minutes",
              "prerequisites": null, "tags": null, "status": "inactive",
              "sort_order": 0, "featured": 0 },
  "lessons": [
    { "title", "estimated_duration", "description", "video_url": null,
      "resource_links": null, "tags": null, "status": "active", "sort_order",
      "ai_instruction", "ai_prompt",
      "questions": [ { "question_type", "question_text", "quiz_data": { ... } } ]
    }
  ]
}

VIKTIGT: Du MÅSTE generera EXAKT {lesson_count} lektioner i lessons-arrayen. Räkna noga!
TXT;

                    $phase2PromptReference = <<<'TXT'
Du är en expert på att skapa utbildningsmaterial. Du ska generera innehåll för EN lektion i HTML-format.

VIKTIGT: Svara ENDAST med lektionens HTML-innehåll. Ingen JSON, ingen markdown, bara ren HTML.

TONALITET: {tone}
SPRÅKSTIL: {language_style}
TEXTLÄNGD: {text_length}  (kort ~150-250 ord / medium ~400-600 ord / lång ~800-1200 ord)
[om angivet] MÅLGRUPP: {target_audience} - anpassa innehåll, exempel och terminologi.

FORMATERING - Använd dessa HTML-element:

<div class="lesson-intro"><p>Kort introduktion som sammanfattar lektionen...</p></div>

<h3>Huvudrubrik 1</h3>
<p>Stycketext med förklaringar...</p>

<div class="lesson-tip"><strong>Tips:</strong> Praktiskt råd...</div>
<div class="lesson-info"><strong>Visste du att...</strong> Intressant fakta...</div>
<div class="lesson-example"><strong>Exempel:</strong> Konkret scenario...</div>
<div class="lesson-warning"><strong>Obs!</strong> Viktig varning...</div>

<h3>Huvudrubrik 2</h3>
<p>Mer innehåll...</p>
<ul><li>Punktlista...</li></ul>

<div class="lesson-summary"><h4>Sammanfattning</h4><ul><li>Nyckelinsikt 1</li><li>Nyckelinsikt 2</li><li>Nyckelinsikt 3</li></ul></div>

KRAV på varje lektion:
1. Börja med en lesson-intro
2. Minst 2-3 huvudsektioner med h3-rubriker
3. Minst en lesson-tip ELLER lesson-info ruta
4. Minst ett lesson-example
5. Avsluta med lesson-summary
6. Använd <strong> för nyckelord, <ul>/<li> för listor
TXT;
                    ?>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="m-0"><i class="bi bi-file-earmark-code me-2"></i>Kursgenereringsprompter</h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Så här fungerar kursgenereringen:</strong> Generering sker i två faser. Fas 1 skapar kursens struktur (titlar, quizfrågor), Fas 2 skriver detaljerat HTML-innehåll för varje lektion. Grundprompterna är inbyggda i koden (<code>process_ai_jobs.php</code>) för att säkerställa stabil output. Dina egna kompletteringar nedan appenderas till båda faserna vid varje generering.
                            </div>

                            <div class="accordion mb-3" id="phasePromptAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#phase1Collapse">
                                            <i class="bi bi-1-circle me-2"></i>Fas 1 – Kursstruktur (read-only)
                                        </button>
                                    </h2>
                                    <div id="phase1Collapse" class="accordion-collapse collapse" data-bs-parent="#phasePromptAccordion">
                                        <div class="accordion-body">
                                            <small class="text-muted d-block mb-2">Denna prompt byggs dynamiskt vid varje generering. Platshållare i <code>{…}</code> ersätts med värden från jobbet (antal lektioner, svårighetsgrad, ton, målgrupp osv).</small>
                                            <pre class="bg-light p-3 rounded small mb-0" style="max-height: 500px; overflow: auto; white-space: pre-wrap;"><?= htmlspecialchars($phase1PromptReference) ?></pre>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#phase2Collapse">
                                            <i class="bi bi-2-circle me-2"></i>Fas 2 – Lektionsinnehåll (read-only)
                                        </button>
                                    </h2>
                                    <div id="phase2Collapse" class="accordion-collapse collapse" data-bs-parent="#phasePromptAccordion">
                                        <div class="accordion-body">
                                            <small class="text-muted d-block mb-2">Kör en gång per lektion och genererar HTML-innehållet för den lektionen.</small>
                                            <pre class="bg-light p-3 rounded small mb-0" style="max-height: 500px; overflow: auto; white-space: pre-wrap;"><?= htmlspecialchars($phase2PromptReference) ?></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                    <label for="course_generation_prompt" class="form-label mb-0">
                                        <strong>Egna kompletteringar till AI-kursgenereringen</strong>
                                        <?php if ($currentPromptVersion): ?>
                                        <span class="badge bg-primary ms-2">Version <?= (int)$currentPromptVersion['version'] ?></span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary ms-2">Ingen version sparad</span>
                                        <?php endif; ?>
                                    </label>
                                    <?php if (!empty($promptVersions)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="collapse" data-bs-target="#promptVersionHistory">
                                        <i class="bi bi-clock-history me-1"></i>Visa historik (<?= count($promptVersions) ?>)
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php if ($currentPromptVersion): ?>
                                <div class="small text-muted mb-2">
                                    Senast uppdaterad <?= htmlspecialchars(date('Y-m-d H:i', strtotime($currentPromptVersion['created_at']))) ?>
                                    av <?= htmlspecialchars($currentPromptVersion['created_by'] ?: 'okänd') ?>
                                </div>
                                <?php endif; ?>
                                <textarea class="form-control font-monospace" id="course_generation_prompt" name="course_generation_prompt"
                                    rows="12" placeholder="T.ex.: Använd alltid konkreta exempel från offentlig sektor. Referera till aktuella svenska regelverk där det är relevant..."><?= htmlspecialchars($settings['course_generation_prompt']['setting_value'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    Texten här appenderas under rubriken <em>"EGNA INSTRUKTIONER FRÅN ADMINISTRATÖR"</em> i båda faser vid varje AI-generering.
                                    Lämna tomt för att inte lägga till något. Passar för organisationsspecifika krav, terminologi, tonfall eller källhänvisningar som ska gälla alla genererade kurser.
                                </small>
                            </div>

                            <?php if (!empty($promptVersions)): ?>
                            <div class="collapse" id="promptVersionHistory">
                                <div class="card card-body bg-light mb-3">
                                    <h6 class="mb-2"><i class="bi bi-clock-history me-1"></i>Tidigare versioner</h6>
                                    <small class="text-muted d-block mb-3">En ny version sparas varje gång du sparar prompten med ändrat innehåll. Återställ skriver aktuell version som en ny version av den valda.</small>
                                    <div class="list-group">
                                        <?php foreach ($promptVersions as $idx => $v): ?>
                                        <div class="list-group-item <?= $idx === 0 ? 'list-group-item-primary' : '' ?>">
                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                <div class="flex-grow-1">
                                                    <strong>Version <?= (int)$v['version'] ?></strong>
                                                    <?php if ($idx === 0): ?><span class="badge bg-primary ms-1">Aktuell</span><?php endif; ?>
                                                    <div class="small text-muted">
                                                        <?= htmlspecialchars(date('Y-m-d H:i', strtotime($v['created_at']))) ?>
                                                        · <?= htmlspecialchars($v['created_by'] ?: 'okänd') ?>
                                                        · <?= (int)mb_strlen($v['content']) ?> tecken
                                                    </div>
                                                    <pre class="mt-2 mb-0 small text-muted" style="max-height: 120px; overflow: auto; white-space: pre-wrap;"><?= htmlspecialchars(mb_substr($v['content'], 0, 400)) . (mb_strlen($v['content']) > 400 ? '…' : '') ?></pre>
                                                </div>
                                                <?php if ($idx !== 0): ?>
                                                <form method="POST" action="ai_settings.php"
                                                      onsubmit="return confirm('Återställ version <?= (int)$v['version'] ?>? En ny version skapas med samma innehåll.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="restore_prompt_version">
                                                    <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Återställ
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Frågetyper som stöds:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><code>single_choice</code> - Enkelval (ett rätt svar)</li>
                                    <li><code>multiple_choice</code> - Flerval (flera rätta svar, ange i quiz_correct_answers)</li>
                                </ul>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-outline-secondary" onclick="clearCourseSupplement()">
                                    <i class="bi bi-eraser me-2"></i>Rensa kompletteringar
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save me-2"></i>Spara kompletteringar
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stegvisa kurser - inställningar -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-info text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-list-ol me-2"></i>Stegvisa kurser
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Information:</strong> Dessa inställningar styr hur stegvisa kursers e-postutskick hanteras.
                    Batchar används för att sprida utskick över tid och undvika svartlistning hos e-postleverantörer.
                </div>

                <form method="POST" action="ai_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <!-- Behåll övriga inställningar -->
                    <input type="hidden" name="guardrails_enabled" value="<?= ($settings['guardrails_enabled']['setting_value'] ?? '1') === '1' ? '1' : '' ?>">
                    <input type="hidden" name="system_prompt_prefix" value="<?= htmlspecialchars($settings['system_prompt_prefix']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="blocked_topics" value="<?= htmlspecialchars($settings['blocked_topics']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="response_guidelines" value="<?= htmlspecialchars($settings['response_guidelines']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="topic_restrictions" value="<?= htmlspecialchars($settings['topic_restrictions']['setting_value'] ?? '') ?>">
                    <input type="hidden" name="custom_instructions" value="<?= htmlspecialchars($settings['custom_instructions']['setting_value'] ?? '') ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="sequential_cron_hour" class="form-label">Cron-timme (0-23)</label>
                            <input type="number" class="form-control" id="sequential_cron_hour" name="sequential_cron_hour"
                                   min="0" max="23" value="<?= htmlspecialchars($settings['sequential_cron_hour']['setting_value'] ?? '8') ?>">
                            <small class="text-muted">Vilken timme på dygnet det nattliga utskicket körs.</small>
                        </div>
                        <div class="col-md-4">
                            <label for="sequential_batch_size" class="form-label">Batch-storlek</label>
                            <input type="number" class="form-control" id="sequential_batch_size" name="sequential_batch_size"
                                   min="1" max="500" value="<?= htmlspecialchars($settings['sequential_batch_size']['setting_value'] ?? '10') ?>">
                            <small class="text-muted">Antal e-post som skickas per batch.</small>
                        </div>
                        <div class="col-md-4">
                            <label for="sequential_batch_delay_seconds" class="form-label">Batchfördröjning (sekunder)</label>
                            <input type="number" class="form-control" id="sequential_batch_delay_seconds" name="sequential_batch_delay_seconds"
                                   min="0" max="300" value="<?= htmlspecialchars($settings['sequential_batch_delay_seconds']['setting_value'] ?? '30') ?>">
                            <small class="text-muted">Paus mellan varje batch för att undvika svartlistning.</small>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-info text-white">
                            <i class="bi bi-save me-2"></i>Spara stegvisa inställningar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function clearCourseSupplement() {
    if (confirm('Vill du rensa dina kompletteringar? En ny version skapas vid spara.')) {
        document.getElementById('course_generation_prompt').value = '';
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
