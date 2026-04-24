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

// Include centralized authentication and authorization check
require_once 'include/auth_check.php';

// Sätt sidtitel
$page_title = 'Kurshantering';

// Hämta användarens e-post och domän
$userEmail = $_SESSION['user_email'];
$userDomain = substr(strrchr($userEmail, "@"), 1);

// Hämta användarens rättigheter
$user = queryOne("SELECT id, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?", [$userEmail]);
$isAdmin = $user && $user['is_admin'] == 1;
$userId = $user['id'] ?? 0;

// Org-scope: alla domäner i användarens organisation
$orgScopeDomains = getOrgScopeDomains($userEmail);
$courseDomClause = buildDomainInClause($orgScopeDomains, 'c.organization_domain');

// Hämta kurser baserat på användarens behörighet
if ($isAdmin) {
    // Administratörer ser kurser från hela sin organisation (alla orgens domäner)
    $courses = queryAll("
        SELECT c.*, COUNT(l.id) as lesson_count
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id
        WHERE {$courseDomClause['fragment']}
        GROUP BY c.id
        ORDER BY c.id ASC
    ", $courseDomClause['params']);
} else {
    // Redaktörer ser kurser de skapat (author_id) ELLER tilldelats redaktörskap för
    $courses = queryAll("
        SELECT DISTINCT c.*, COUNT(l.id) as lesson_count
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id
        LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
        WHERE {$courseDomClause['fragment']}
          AND (c.author_id = ? OR ce.email = ?)
        GROUP BY c.id
        ORDER BY c.id ASC
    ", array_merge($courseDomClause['params'], [$userId, $userEmail]));
}

// Hämta organisationstaggar per kurs
$courseOrgTagsMap = [];
if (!empty($courses)) {
    $allCourseIds = array_column($courses, 'id');
    $placeholders = implode(',', array_fill(0, count($allCourseIds), '?'));
    $allCourseOrgTags = query(
        "SELECT course_id, tag FROM " . DB_DATABASE . ".course_org_tags WHERE course_id IN ($placeholders) ORDER BY tag",
        $allCourseIds
    );
    foreach ($allCourseOrgTags as $cot) {
        $courseOrgTagsMap[$cot['course_id']][] = $cot['tag'];
    }
}

// Hämta max antal lektioner från AI-inställningar
$maxLessonSetting = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'max_lesson_count'");
$maxLessonCount = (int)($maxLessonSetting['setting_value'] ?? 20);
if ($maxLessonCount < 1) $maxLessonCount = 20;

// Inkludera header
require_once 'include/header.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-muted">Kurser</h6>
        <div class="d-flex gap-2 align-items-center">
            <!-- AI Progress Indicator -->
            <div id="aiProgressIndicator" class="d-none align-items-center me-2">
                <div class="spinner-border spinner-border-sm text-success me-2" role="status">
                    <span class="visually-hidden">Genererar...</span>
                </div>
                <span class="text-muted me-2" id="aiProgressText" style="min-width: 350px; font-size: 0.9rem;">AI genererar kurs...</span>
                <div class="progress" style="width: 150px; height: 10px;">
                    <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="aiProgressBarSmall" style="width: 0%"></div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-success" id="aiGenerateBtn" data-bs-toggle="modal" data-bs-target="#aiGenerateModal">
                <i class="bi bi-robot"></i> AI Generera kurs
            </button>
            <a href="import.php" class="btn btn-sm btn-secondary">
                <i class="bi bi-upload"></i> Importera kurs
            </a>
            <a href="edit_course.php" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> Ny kurs
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 50px;"></th>
                        <th style="width: 60px;">ID</th>
                        <th>Titel</th>
                        <th>Status</th>
                        <th>Antal lektioner</th>
                        <th style="width: 120px;">Åtgärder</th>
                    </tr>
                </thead>
                <tbody id="sortable-courses">
                    <?php foreach ($courses as $course): 
                        $lessons = query("SELECT * FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order, title", [$course['id']]);
                        $lessonCount = count($lessons);
                    ?>
                    <tr data-id="<?= $course['id'] ?>">
                        <td>
                            <i class="bi bi-grip-vertical grip-handle text-muted"></i>
                        </td>
                        <td><span class="text-muted"><?= $course['id'] ?></span></td>
                        <td>
                            <div class="d-flex align-items-center flex-wrap gap-1">
                                <a href="lessons.php?course_id=<?= $course['id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($course['title']) ?>
                                </a>
                                <?php if (!empty($course['sequential_mode'])): ?>
                                <span class="badge bg-warning text-dark" style="font-size: 0.7rem;"
                                      title="Lektioner låses upp stegvis med tidsintervall">
                                    <i class="bi bi-list-ol me-1"></i>Stegvis
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($course['sequential_mode']) && ($course['enrollment_type'] ?? '') === 'rolling'): ?>
                                <span class="badge bg-secondary" style="font-size: 0.7rem;"
                                      title="Varje deltagare startar från individuellt datum (rolling enrollment)">
                                    <i class="bi bi-calendar-event me-1"></i>Dynamiskt startdatum
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($courseOrgTagsMap[$course['id']])): ?>
                                    <?php foreach ($courseOrgTagsMap[$course['id']] as $orgTag): ?>
                                    <span class="badge bg-success" style="font-size: 0.7rem;"><?= htmlspecialchars($orgTag) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php
                                $origDomain = $course['original_organization_domain'] ?? null;
                                if ($origDomain && $origDomain !== $course['organization_domain']):
                                    $origLabel = getOriginalOrganizationLabel($origDomain);
                                ?>
                                <span class="badge bg-info text-dark" style="font-size: 0.7rem;"
                                      title="Permanent etikett — kursen kopierades ursprungligen från denna organisation">
                                    <i class="bi bi-diagram-3 me-1"></i>Ursprung: <?= htmlspecialchars($origLabel) ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($course['is_public'])): ?>
                                <a href="public_participants.php?course_id=<?= (int)$course['id'] ?>" class="badge bg-primary text-decoration-none"
                                   style="font-size: 0.7rem;" title="Hantera publika deltagare">
                                    <i class="bi bi-globe me-1"></i>Publik
                                </a>
                                <?php endif; ?>
                                <?php
                                $courseSharedDoms = getCourseSharedDomains($course['id']);
                                if (!empty($courseSharedDoms)):
                                ?>
                                <span class="badge bg-warning text-dark" style="font-size: 0.7rem;"
                                      title="Kursen delas endast med: <?= htmlspecialchars(implode(', ', $courseSharedDoms)) ?>">
                                    <i class="bi bi-share me-1"></i>Delad: <?= count($courseSharedDoms) ?> domän<?= count($courseSharedDoms) === 1 ? '' : 'er' ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-<?= $course['status'] === 'active' ? 'success' : 'secondary' ?>">
                                <?= $course['status'] === 'active' ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td><?= $lessonCount ?></td>
                        <td>
                            <div class="d-flex gap-2">
                                <a href="lessons.php?course_id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-primary" title="Hantera lektioner">
                                    <i class="bi bi-list-ul"></i>
                                </a>
                                <a href="edit_course.php?id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Redigera">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-secondary copy-course-link" title="Kopiera direktlänk till kursens landningssida"
                                        data-course-id="<?= (int)$course['id'] ?>">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                                <a href="export.php?id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Exportera kurs">
                                    <i class="bi bi-box-arrow-up"></i>
                                </a>
                                <button type="button" onclick="deleteCourse(<?= $course['id'] ?>)"
                                   class="btn btn-sm btn-outline-danger" title="Radera kurs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- AI Generate Course Modal -->
<div class="modal fade" id="aiGenerateModal" tabindex="-1" aria-labelledby="aiGenerateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="aiGenerateModalLabel">
                    <i class="bi bi-robot me-2"></i>AI Generera kurs
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Stäng"></button>
            </div>
            <form id="aiGenerateForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_job">

                <!-- STEP 1: Basic settings -->
                <div id="aiStep1">
                    <div class="modal-body">
                        <!-- Step indicator -->
                        <div class="d-flex align-items-center mb-3">
                            <span class="badge bg-success me-2">Steg 1</span>
                            <small class="text-muted">Grundinställningar</small>
                        </div>

                        <!-- Rå kursidé — användaren beskriver fritt, AI formulerar -->
                        <div class="card mb-3 border-success">
                            <div class="card-body">
                                <label for="raw_idea" class="form-label fw-semibold mb-2">
                                    <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                                    Beskriv din kursidé
                                </label>
                                <div class="form-text mb-2">
                                    Vilken kurs vill du utveckla? Beskriv med dina egna ord — du behöver inte tänka på att formulera dig väl. Din text används bara för att skapa en beskrivning för kursen och ligga till grund för AI-genereringen.
                                </div>
                                <textarea class="form-control" id="raw_idea" rows="4"
                                          placeholder="T.ex: Jag vill lära våra nyanställda om GDPR — framför allt vad de får och inte får göra med kunduppgifter, med några konkreta exempel från vår bransch..."></textarea>
                                <div class="d-flex gap-2 align-items-center mt-2">
                                    <button type="button" class="btn btn-success btn-sm" id="draftDescriptionBtn">
                                        <i class="bi bi-magic me-1"></i>Skapa förslag på namn och beskrivning
                                    </button>
                                    <span id="draftDescriptionStatus" class="small text-muted"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Kursnamn (fylls i av AI-förslag eller manuellt) -->
                        <div class="mb-3">
                            <label for="course_name" class="form-label">Kursnamn <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="course_name" name="course_name" required maxlength="255"
                                   placeholder="Fyll i manuellt eller tryck på knappen ovan">
                        </div>

                        <!-- Beskrivning -->
                        <div class="mb-3">
                            <label for="course_description" class="form-label">Beskrivning av kursen <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="course_description" name="course_description" rows="4" required
                                      placeholder="Fyll i manuellt eller tryck på knappen ovan"></textarea>
                            <div class="form-text">Redigera fritt — AI använder den här beskrivningen som grund för genereringen.</div>
                        </div>

                        <div class="row">
                            <!-- Antal lektioner -->
                            <div class="col-md-4 mb-3">
                                <label for="lesson_count" class="form-label">Antal lektioner <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="lesson_count" name="lesson_count"
                                       min="1" max="<?= $maxLessonCount ?>" value="5" required>
                                <div class="form-text">Minst 1, max <?= $maxLessonCount ?> lektioner.</div>
                            </div>

                            <!-- Svårighetsnivå -->
                            <div class="col-md-4 mb-3">
                                <label for="difficulty_level" class="form-label">Svårighetsnivå</label>
                                <select class="form-select" id="difficulty_level" name="difficulty_level">
                                    <option value="beginner">Nybörjare</option>
                                    <option value="intermediate">Mellannivå</option>
                                    <option value="advanced">Avancerad</option>
                                </select>
                            </div>

                            <!-- Textlängd -->
                            <div class="col-md-4 mb-3">
                                <label for="text_length" class="form-label">Textlängd per lektion</label>
                                <select class="form-select" id="text_length" name="text_length">
                                    <option value="short">Kort (~5-8 meningar)</option>
                                    <option value="medium" selected>Mellan (~12-18 meningar)</option>
                                    <option value="long">Lång (~25-35 meningar)</option>
                                </select>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-muted mb-3"><i class="bi bi-sliders me-1"></i> Kursinställningar</h6>

                        <div class="row">
                            <!-- Tonalitet -->
                            <div class="col-md-6 mb-3">
                                <label for="tone" class="form-label">Tonalitet</label>
                                <select class="form-select" id="tone" name="tone">
                                    <option value="pedagogical" selected>Pedagogisk</option>
                                    <option value="formal">Formell</option>
                                    <option value="casual">Avslappnad</option>
                                    <option value="inspiring">Inspirerande</option>
                                </select>
                            </div>

                            <!-- Språkstil -->
                            <div class="col-md-6 mb-3">
                                <label for="language_style" class="form-label">Språkstil</label>
                                <select class="form-select" id="language_style" name="language_style">
                                    <option value="formal" selected>Formell</option>
                                    <option value="informal">Informell</option>
                                    <option value="academic">Akademisk</option>
                                    <option value="conversational">Vardaglig</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Målgrupp -->
                            <div class="col-md-8 mb-3">
                                <label for="target_audience" class="form-label">Målgrupp</label>
                                <input type="text" class="form-control" id="target_audience" name="target_audience"
                                       placeholder="T.ex. nyanställda inom vården">
                                <div class="form-text">Valfritt. Hjälper AI att anpassa innehållet.</div>
                            </div>

                            <!-- Färgtema -->
                            <div class="col-md-4 mb-3">
                                <label for="color_theme" class="form-label">Färgtema för bilder</label>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="color" class="form-control form-control-color" id="color_theme" name="color_theme" value="#007bff">
                                    <small class="text-muted">Bildernas palette</small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- Checkboxar -->
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="include_quiz" name="include_quiz" value="1">
                                    <label class="form-check-label" for="include_quiz">
                                        <strong>Quiz per lektion</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="include_ai_tutor" name="include_ai_tutor" value="1">
                                    <label class="form-check-label" for="include_ai_tutor">
                                        <strong>AI-handledare</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="generate_images" name="generate_images" value="1" checked>
                                    <label class="form-check-label" for="generate_images">
                                        <strong>Generera bilder</strong>
                                    </label>
                                    <div class="form-text">Kurs + lektioner + diplom</div>
                                </div>
                            </div>
                        </div>

                        <!-- Info -->
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Kursen skapas som inaktiv så du kan granska den innan publicering.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                        <button type="button" class="btn btn-success" id="submitStep1Btn" onclick="submitStep1()">
                            <i class="bi bi-arrow-right me-2"></i>Nästa - Låt AI ställa frågor
                        </button>
                    </div>
                </div>

                <!-- STEP 2: AI Questions -->
                <div id="aiStep2" style="display: none;">
                    <div class="modal-body">
                        <!-- Step indicator -->
                        <div class="d-flex align-items-center mb-3">
                            <span class="badge bg-secondary me-2">Steg 1</span>
                            <span class="badge bg-success me-2">Steg 2</span>
                            <small class="text-muted">AI-frågor</small>
                        </div>

                        <div class="alert alert-success">
                            <i class="bi bi-robot me-2"></i>
                            <strong>AI har analyserat din kursbeskrivning</strong> och har några frågor som hjälper till att skapa en bättre kurs.
                            Svaren är valfria men rekommenderade.
                        </div>

                        <div id="aiQuestionsContainer">
                            <!-- Dynamic questions will be inserted here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" onclick="goBackToStep1()">
                            <i class="bi bi-arrow-left me-2"></i>Tillbaka
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="skipQuestionsAndGenerate()">
                            Hoppa över
                        </button>
                        <button type="button" class="btn btn-success" id="submitStep2Btn" onclick="submitStep2()">
                            <i class="bi bi-robot me-2"></i>Starta generering
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- AI Job Status Modal -->
<div class="modal fade" id="aiStatusModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-robot me-2"></i>AI-generering pågår
                </h5>
            </div>
            <div class="modal-body text-center">
                <div class="spinner-border text-success mb-3" role="status" id="aiSpinner">
                    <span class="visually-hidden">Laddar...</span>
                </div>
                <div class="progress mb-3" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                         role="progressbar" style="width: 0%" id="aiProgressBar"></div>
                </div>
                <p id="aiStatusMessage" class="mb-0">Startar...</p>
            </div>
            <div class="modal-footer" id="aiStatusFooter" style="display: none;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Stäng</button>
                <a href="#" class="btn btn-primary" id="aiViewCourseBtn">Visa kurs</a>
            </div>
        </div>
    </div>
</div>

<?php
// Definiera extra JavaScript
$extra_scripts = '<script>
    // CSRF_TOKEN is already defined in header.php
    let currentJobId = null;
    let statusCheckInterval = null;
    const MAX_LESSON_COUNT = ' . $maxLessonCount . ';

    // Check localStorage for active job on page load
    (function checkSavedJob() {
        var savedJobId = localStorage.getItem("stimma_ai_job_id");
        if (savedJobId) {
            currentJobId = parseInt(savedJobId);
            showProgressIndicator();
            checkJobStatus();
            statusCheckInterval = setInterval(checkJobStatus, 2000);
        }
    })();

    function showProgressIndicator() {
        var indicator = document.getElementById("aiProgressIndicator");
        var btn = document.getElementById("aiGenerateBtn");
        if (indicator) {
            indicator.classList.remove("d-none");
            indicator.classList.add("d-flex");
        }
        if (btn) {
            btn.disabled = true;
        }
    }

    function hideProgressIndicator() {
        var indicator = document.getElementById("aiProgressIndicator");
        var btn = document.getElementById("aiGenerateBtn");
        if (indicator) {
            indicator.classList.add("d-none");
            indicator.classList.remove("d-flex");
        }
        if (btn) {
            btn.disabled = false;
        }
    }

    function updateProgressIndicator(percent, message) {
        var progressBar = document.getElementById("aiProgressBarSmall");
        var progressText = document.getElementById("aiProgressText");
        if (progressBar) {
            progressBar.style.width = percent + "%";
        }
        if (progressText && message) {
            // Visa hela meddelandet - ingen begränsning
            progressText.textContent = message;
        }
    }

    function startBackgroundProcess() {
        fetch("ajax/trigger_ai_processor.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: "csrf_token=" + encodeURIComponent(CSRF_TOKEN)
        }).catch(err => console.log("Background process triggered"));
    }

    function checkJobStatus() {
        if (!currentJobId) return;

        fetch("ajax/ai_generate_course.php?action=get_status&job_id=" + currentJobId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.job) {
                const job = data.job;
                updateProgressIndicator(job.progress_percent, job.progress_message);

                if (job.status === "completed") {
                    clearInterval(statusCheckInterval);
                    localStorage.removeItem("stimma_ai_job_id");
                    hideProgressIndicator();
                    alert("Kursen \\"" + job.course_name + "\\" har skapats!");
                    window.location.reload();
                } else if (job.status === "failed") {
                    clearInterval(statusCheckInterval);
                    localStorage.removeItem("stimma_ai_job_id");
                    hideProgressIndicator();
                    alert("Ett fel uppstod: " + (job.error_message || "Okänt fel"));
                }
            } else if (!data.success) {
                // Job not found, clear localStorage
                clearInterval(statusCheckInterval);
                localStorage.removeItem("stimma_ai_job_id");
                hideProgressIndicator();
            }
        })
        .catch(error => {
            console.error("Error checking status:", error);
        });
    }

    // "Skapa förslag"-knappen: skickar fri idétext → AI returnerar
    // kursnamn + beskrivning som fyller fälten.
    document.addEventListener("click", function(ev) {
        var btn = ev.target.closest("#draftDescriptionBtn");
        if (!btn) return;
        var rawIdea = document.getElementById("raw_idea").value.trim();
        var status = document.getElementById("draftDescriptionStatus");
        if (rawIdea.length < 10) {
            status.innerHTML = "<span class=\"text-danger\"><i class=\"bi bi-exclamation-circle me-1\"></i>Skriv minst 10 tecken först.</span>";
            return;
        }
        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = "<span class=\"spinner-border spinner-border-sm me-1\"></span>AI funderar...";
        status.innerHTML = "";

        var fd = new FormData();
        fd.append("csrf_token", CSRF_TOKEN);
        fd.append("action", "draft_description");
        fd.append("raw_idea", rawIdea);

        fetch("ajax/ai_generate_course.php", { method: "POST", body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById("course_name").value = data.name || "";
                    document.getElementById("course_description").value = data.description || "";
                    status.innerHTML = "<span class=\"text-success\"><i class=\"bi bi-check-circle-fill me-1\"></i>Förslag skapat — justera gärna texterna.</span>";
                } else {
                    status.innerHTML = "<span class=\"text-danger\"><i class=\"bi bi-exclamation-circle me-1\"></i>" + (data.message || "Något gick fel.") + "</span>";
                }
            })
            .catch(function() {
                status.innerHTML = "<span class=\"text-danger\"><i class=\"bi bi-exclamation-circle me-1\"></i>Nätverksfel.</span>";
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    });

    // Store AI questions for step 2
    var aiGeneratedQuestions = [];

    // Step 1: Send course info to AI for follow-up questions
    function submitStep1() {
        var form = document.getElementById("aiGenerateForm");
        var submitBtn = document.getElementById("submitStep1Btn");

        var courseName = form.querySelector("[name=course_name]").value.trim();
        var courseDesc = form.querySelector("[name=course_description]").value.trim();
        var lessonCount = parseInt(form.querySelector("[name=lesson_count]").value) || 0;

        if (!courseName) {
            alert("Ange ett kursnamn");
            return;
        }
        if (!courseDesc) {
            alert("Ange en kursbeskrivning");
            return;
        }
        if (lessonCount < 1 || lessonCount > MAX_LESSON_COUNT) {
            alert("Antal lektioner måste vara mellan 1 och " + MAX_LESSON_COUNT + ".");
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = \'<span class="spinner-border spinner-border-sm me-2"></span>AI analyserar...\';

        var formData = new FormData();
        formData.append("action", "ask_questions");
        formData.append("csrf_token", form.querySelector("[name=csrf_token]").value);
        formData.append("course_name", courseName);
        formData.append("course_description", courseDesc);
        formData.append("tone", form.querySelector("[name=tone]").value);
        formData.append("target_audience", form.querySelector("[name=target_audience]").value);
        formData.append("difficulty_level", form.querySelector("[name=difficulty_level]").value);

        fetch("ajax/ai_generate_course.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = \'<i class="bi bi-arrow-right me-2"></i>Nästa - Låt AI ställa frågor\';

            if (data.success && data.questions) {
                aiGeneratedQuestions = data.questions;
                renderAiQuestions(data.questions);
                document.getElementById("aiStep1").style.display = "none";
                document.getElementById("aiStep2").style.display = "block";
            } else {
                alert(data.message || "Kunde inte generera frågor. Försöker starta generering direkt.");
                // Fallback: start generation directly
                startGeneration();
            }
        })
        .catch(error => {
            console.error("Error:", error);
            submitBtn.disabled = false;
            submitBtn.innerHTML = \'<i class="bi bi-arrow-right me-2"></i>Nästa - Låt AI ställa frågor\';
            alert("Ett fel uppstod. Försöker starta generering direkt.");
            startGeneration();
        });
    }

    function renderAiQuestions(questions) {
        var container = document.getElementById("aiQuestionsContainer");
        container.innerHTML = "";
        questions.forEach(function(q, index) {
            var div = document.createElement("div");
            div.className = "mb-3";
            var label = document.createElement("label");
            label.className = "form-label";
            label.setAttribute("for", "ai_q_" + index);
            label.textContent = q.question;
            var input = document.createElement("input");
            input.type = "text";
            input.className = "form-control";
            input.id = "ai_q_" + index;
            input.name = "ai_answer_" + index;
            input.placeholder = q.placeholder || "";
            div.appendChild(label);
            div.appendChild(input);
            container.appendChild(div);
        });
    }

    function goBackToStep1() {
        document.getElementById("aiStep2").style.display = "none";
        document.getElementById("aiStep1").style.display = "block";
    }

    function skipQuestionsAndGenerate() {
        startGeneration();
    }

    // Step 2: Collect answers and start generation
    function submitStep2() {
        startGeneration();
    }

    function startGeneration() {
        var form = document.getElementById("aiGenerateForm");
        var submitBtn = document.getElementById("submitStep2Btn");
        var step1Btn = document.getElementById("submitStep1Btn");

        // Disable all submit buttons
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = \'<span class="spinner-border spinner-border-sm me-2"></span>Startar...\';
        }
        if (step1Btn) {
            step1Btn.disabled = true;
        }

        var formData = new FormData(form);
        formData.set("action", "create_job");

        // Collect AI questions and answers
        if (aiGeneratedQuestions.length > 0) {
            var questionsJson = JSON.stringify(aiGeneratedQuestions);
            formData.append("ai_questions", questionsJson);

            var answers = [];
            aiGeneratedQuestions.forEach(function(q, index) {
                var input = document.getElementById("ai_q_" + index);
                answers.push({
                    question: q.question,
                    answer: input ? input.value.trim() : ""
                });
            });
            formData.append("ai_answers", JSON.stringify(answers));
        }

        fetch("ajax/ai_generate_course.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentJobId = data.job_id;
                localStorage.setItem("stimma_ai_job_id", data.job_id);

                var modalEl = document.getElementById("aiGenerateModal");
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                showProgressIndicator();
                startBackgroundProcess();
                checkJobStatus();
                statusCheckInterval = setInterval(checkJobStatus, 2000);
            } else {
                alert(data.message || "Ett fel uppstod");
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = \'<i class="bi bi-robot me-2"></i>Starta generering\';
                }
                if (step1Btn) {
                    step1Btn.disabled = false;
                }
            }
        })
        .catch(error => {
            console.error("Error:", error);
            alert("Ett fel uppstod vid start av generering: " + error);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = \'<i class="bi bi-robot me-2"></i>Starta generering\';
            }
            if (step1Btn) {
                step1Btn.disabled = false;
            }
        });
    }

    $(document).ready(function() {
        // Reset generate form when modal is closed
        $("#aiGenerateModal").on("hidden.bs.modal", function() {
            $("#aiGenerateForm")[0].reset();
            // Reset to step 1
            document.getElementById("aiStep1").style.display = "block";
            document.getElementById("aiStep2").style.display = "none";
            document.getElementById("aiQuestionsContainer").innerHTML = "";
            aiGeneratedQuestions = [];
            var step1Btn = document.getElementById("submitStep1Btn");
            if (step1Btn) {
                step1Btn.disabled = false;
                step1Btn.innerHTML = \'<i class="bi bi-arrow-right me-2"></i>Nästa - Låt AI ställa frågor\';
            }
            var step2Btn = document.getElementById("submitStep2Btn");
            if (step2Btn) {
                step2Btn.disabled = false;
                step2Btn.innerHTML = \'<i class="bi bi-robot me-2"></i>Starta generering\';
            }
            // Re-check the generate_images checkbox after reset
            document.getElementById("generate_images").checked = true;
        });

        // Hantera expandering av lektionslistan
        $(".expand-button").click(function() {
            const courseId = $(this).data("course-id");
            const lessonContainer = $("#lessons-" + courseId);
            const icon = $(this).find("i");

            if (lessonContainer.is(":visible")) {
                lessonContainer.hide();
                icon.removeClass("bi-chevron-down").addClass("bi-chevron-right");
            } else {
                lessonContainer.show();
                icon.removeClass("bi-chevron-right").addClass("bi-chevron-down");
            }
        });

        // Sorterbar funktionalitet för kurser
        $("#sortable-courses").sortable({
            items: "tr:not(.lesson-container)",
            handle: ".grip-handle",
            axis: "y",
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            update: function(event, ui) {
                // Samla in den nya ordningen
                const courseIds = [];
                $("#sortable-courses tr:not(.lesson-container)").each(function(index) {
                    const id = $(this).data("id");
                    if (id) { // Kontrollera att vi bara inkluderar kurser med ID
                        courseIds.push({
                            id: id,
                            order: index
                        });
                    }
                });

                // Skicka den nya ordningen till servern
                $.ajax({
                    url: "update_course_order.php",
                    method: "POST",
                    headers: {
                        "X-CSRF-Token": CSRF_TOKEN
                    },
                    data: {
                        courses: JSON.stringify(courseIds)
                    },
                    success: function(response) {
                        console.log("Kursordning uppdaterad");
                    },
                    error: function(error) {
                        console.error("Fel vid uppdatering av kursordning", error);
                    }
                });
            }
        });
    });
</script>';

echo '<script>
function deleteCourse(id) {
    if (!confirm("Är du säker på att du vill radera denna kurs? Alla lektioner i kursen kommer också att raderas.")) return;
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "delete_course.php";
    var idInput = document.createElement("input");
    idInput.type = "hidden"; idInput.name = "id"; idInput.value = id;
    var csrfInput = document.createElement("input");
    csrfInput.type = "hidden"; csrfInput.name = "csrf_token"; csrfInput.value = CSRF_TOKEN;
    form.appendChild(idInput);
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
}

// Kopiera direktlänk till en kurs landningssida
document.addEventListener("click", function(ev) {
    var btn = ev.target.closest(".copy-course-link");
    if (!btn) return;
    var id = btn.dataset.courseId;
    var url = window.location.protocol + "//" + window.location.host + "/course.php?id=" + id;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            var original = btn.innerHTML;
            btn.innerHTML = "<i class=\"bi bi-check\"></i>";
            btn.classList.add("btn-success");
            btn.classList.remove("btn-outline-secondary");
            setTimeout(function() {
                btn.innerHTML = original;
                btn.classList.remove("btn-success");
                btn.classList.add("btn-outline-secondary");
            }, 1500);
        }).catch(function() { prompt("Kopiera länken:", url); });
    } else {
        prompt("Kopiera länken:", url);
    }
});
</script>';

// Inkludera footer
require_once 'include/footer.php';
