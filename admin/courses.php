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

// Hantera radering av kurs
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $courseId = (int)$_GET['id'];
    
    // Kontrollera om användaren har behörighet att radera kursen
    if (!isAdmin($_SESSION['user_email'])) {
        // Kontrollera om användaren är redaktör för kursen
        $isEditor = queryOne("SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?", [$courseId, $_SESSION['user_email']]);
        if (!$isEditor) {
            $_SESSION['message'] = 'Du har inte behörighet att radera denna kurs.';
            $_SESSION['message_type'] = 'danger';
            header('Location: courses.php');
            exit;
        }
    }
    
    // Kontrollera om kursen har lektioner
    $lessons = query("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$courseId]);
    $lessonCount = $lessons[0]['count'];

    if ($lessonCount > 0) {
        $_SESSION['message'] = 'Kursen kan inte raderas eftersom den innehåller lektioner. Ta bort alla lektioner först.';
        $_SESSION['message_type'] = 'warning';
    } else {
        try {
            execute("DELETE FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
            $_SESSION['message'] = 'Kursen har raderats.';
            $_SESSION['message_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['message'] = 'Ett fel uppstod när kursen skulle raderas.';
            $_SESSION['message_type'] = 'danger';
        }
    }
    
    header('Location: courses.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Kurshantering';

// Hämta användarens e-post
$userEmail = $_SESSION['user_email'];

// Hämta användarens rättigheter
$user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?", [$userEmail]);
$isAdmin = $user && $user['is_admin'] == 1;

// Hämta kurser baserat på användarens behörighet
if ($isAdmin) {
    // Administratörer ser alla kurser
    $courses = queryAll("
        SELECT c.*, COUNT(l.id) as lesson_count 
        FROM " . DB_DATABASE . ".courses c 
        LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id 
        GROUP BY c.id 
        ORDER BY c.sort_order ASC
    ");
} else {
    // Redaktörer ser bara sina tilldelade kurser
    $courses = queryAll("
        SELECT c.*, COUNT(l.id) as lesson_count 
        FROM " . DB_DATABASE . ".courses c 
        LEFT JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id 
        INNER JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id 
        WHERE ce.email = ?
        GROUP BY c.id 
        ORDER BY c.sort_order ASC
    ", [$userEmail]);
}

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
                        <td>
                            <div class="d-flex align-items-center">
                                <a href="lessons.php?course_id=<?= $course['id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($course['title']) ?>
                                </a>
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
                                <a href="lessons.php?course_id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-list-ul"></i>
                                </a>
                                <a href="edit_course.php?id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="export.php?id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Exportera kurs">
                                    <i class="bi bi-box-arrow-up"></i>
                                </a>
                                <a href="delete_course.php?id=<?= $course['id'] ?>&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                   onclick="return confirm('Är du säker på att du vill radera denna kurs? Alla lektioner i kursen kommer också att raderas.')"
                                   class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </a>
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
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_job">

                    <!-- Kursnamn -->
                    <div class="mb-3">
                        <label for="course_name" class="form-label">Kursnamn <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="course_name" name="course_name" required maxlength="255"
                               placeholder="T.ex. Introduktion till projektledning">
                    </div>

                    <!-- Beskrivning -->
                    <div class="mb-3">
                        <label for="course_description" class="form-label">Beskrivning av kursen <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="course_description" name="course_description" rows="4" required
                                  placeholder="Beskriv vad kursen ska handla om, vilka ämnen som ska täckas, målgrupp etc."></textarea>
                        <div class="form-text">Ju mer detaljerad beskrivning, desto bättre resultat från AI.</div>
                    </div>

                    <!-- Antal lektioner -->
                    <div class="mb-3">
                        <label for="lesson_count" class="form-label">Antal lektioner <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="lesson_count" name="lesson_count"
                               min="1" max="20" value="5" required style="max-width: 150px;">
                        <div class="form-text">Minst 1, max 20 lektioner.</div>
                    </div>

                    <!-- Svårighetsnivå -->
                    <div class="mb-3">
                        <label for="difficulty_level" class="form-label">Svårighetsnivå</label>
                        <select class="form-select" id="difficulty_level" name="difficulty_level" style="max-width: 200px;">
                            <option value="beginner">Nybörjare</option>
                            <option value="intermediate">Mellannivå</option>
                            <option value="advanced">Avancerad</option>
                        </select>
                    </div>

                    <hr>

                    <!-- Quiz -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_quiz" name="include_quiz" value="1">
                            <label class="form-check-label" for="include_quiz">
                                <strong>Quiz ska skapas</strong>
                            </label>
                            <div class="form-text">AI genererar ett quiz per lektion baserat på innehållet.</div>
                        </div>
                    </div>

                    <!-- AI-handledare -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_ai_tutor" name="include_ai_tutor" value="1">
                            <label class="form-check-label" for="include_ai_tutor">
                                <strong>AI-handledare</strong>
                            </label>
                            <div class="form-text">AI genererar instruktioner för interaktiv AI-dialog per lektion.</div>
                        </div>
                    </div>

                    <hr>

                    <!-- Info om asynkron process -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>OBS:</strong> Genereringen sker i bakgrunden och kan ta flera minuter beroende på antal lektioner och valda alternativ.
                        Du kommer att meddelas när kursen är klar. Kursen skapas som inaktiv så du kan granska den innan publicering.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-success" id="submitAiGenerate" onclick="submitAiGenerateForm()">
                        <i class="bi bi-robot me-2"></i>Starta generering
                    </button>
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

    function deleteCourse(id) {
        if (confirm(\'Är du säker på att du vill ta bort denna kurs? Alla lektioner i kursen kommer också att tas bort.\')) {
            $.post(\'delete_course.php\', { id: id }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(\'Ett fel uppstod vid borttagning av kursen.\');
                }
            });
        }
    }

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

    // Standalone function called by button onclick
    function submitAiGenerateForm() {
        var form = document.getElementById("aiGenerateForm");
        var submitBtn = document.getElementById("submitAiGenerate");

        // Validera obligatoriska fält
        var courseName = form.querySelector("[name=course_name]").value.trim();
        var courseDesc = form.querySelector("[name=course_description]").value.trim();

        if (!courseName) {
            alert("Ange ett kursnamn");
            return;
        }
        if (!courseDesc) {
            alert("Ange en kursbeskrivning");
            return;
        }

        // Visa spinner på knappen
        submitBtn.disabled = true;
        submitBtn.innerHTML = \'<span class="spinner-border spinner-border-sm me-2"></span>Startar...\';

        // Skapa FormData
        var formData = new FormData(form);

        // Skicka AJAX request med fetch
        fetch("ajax/ai_generate_course.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentJobId = data.job_id;

                // Spara job_id till localStorage för att behålla vid sidbyte
                localStorage.setItem("stimma_ai_job_id", data.job_id);

                // Stäng modalen
                var modalEl = document.getElementById("aiGenerateModal");
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                // Visa progress-indikatorn i headern
                showProgressIndicator();

                // Starta bakgrundsprocessen
                startBackgroundProcess();

                // Börja polla status
                checkJobStatus();
                statusCheckInterval = setInterval(checkJobStatus, 2000);
            } else {
                alert(data.message || "Ett fel uppstod");
                submitBtn.disabled = false;
                submitBtn.innerHTML = \'<i class="bi bi-robot me-2"></i>Starta generering\';
            }
        })
        .catch(error => {
            console.error("Error:", error);
            alert("Ett fel uppstod vid start av generering: " + error);
            submitBtn.disabled = false;
            submitBtn.innerHTML = \'<i class="bi bi-robot me-2"></i>Starta generering\';
        });
    }

    $(document).ready(function() {
        // Reset generate form when modal is closed
        $("#aiGenerateModal").on("hidden.bs.modal", function() {
            $("#aiGenerateForm")[0].reset();
            $("#submitAiGenerate").prop("disabled", false).html(\'<i class="bi bi-robot me-2"></i>Starta generering\');
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

// Inkludera footer
require_once 'include/footer.php';
