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

// Kontrollera att course_id finns
if (!isset($_GET['course_id']) || !is_numeric($_GET['course_id'])) {
    $_SESSION['message'] = "Ingen kurs vald.";
    $_SESSION['message_type'] = "danger";
    header('Location: courses.php');
    exit;
}

$courseId = (int)$_GET['course_id'];

// Hämta kursinformation
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);

// Ärende 770: bara kurser användaren får ändra, eller se i den egna organisationen.
// Samma svar som för en kurs som inte finns.
if (!$course || !canViewCourseAdmin($courseId)) {
    denyCourseAccess();
}

// Sätt sidtitel med kursnamn
$page_title = 'Lektionshantering - ' . htmlspecialchars($course['title']);

// Extra CSS för sorterbar tabell
$extra_css = '.grip-handle { cursor: move; color: #adb5bd; } .grip-handle:hover { color: #6c757d; }';

// Hämta alla lektioner för kursen.
// quiz_question_count räknar rader i nya quiz_questions-tabellen — den gamla
// inline-kolumnen lessons.quiz_question är legacy och fångar inte AI-genererade
// frågor (som sparas direkt i quiz_questions). Listan nedan visar "Quiz"-badge
// om antingen legacy-kolumnen eller nya tabellen har innehåll.
$lessons = query(
    "SELECT l.*,
            (SELECT COUNT(*) FROM " . DB_DATABASE . ".quiz_questions qq WHERE qq.lesson_id = l.id) AS quiz_question_count
       FROM " . DB_DATABASE . ".lessons l
      WHERE l.course_id = ?
      ORDER BY l.sort_order, l.title",
    [$courseId]
);

// Bygg uppslag av lektionstitlar för "Tillhör"-kolumnen (endast lektioner,
// inte infosidor, kan fungera som parent).
$lessonTitleById = [];
foreach ($lessons as $__l) {
    if (($__l['lesson_type'] ?? 'lesson') === 'lesson') {
        $lessonTitleById[(int)$__l['id']] = $__l['title'];
    }
}

// Definiera extra JavaScript för drag-and-drop sortering
$extra_scripts = '<script>
    $(document).ready(function() {
        $("#sortable-lessons").sortable({
            items: "tr.sortable-row",
            handle: ".grip-handle",
            axis: "y",
            cursor: "grabbing",
            opacity: 0.8,
            placeholder: "ui-sortable-placeholder",
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            start: function(e, ui) {
                // Gör placeholder lika hög som raden
                ui.placeholder.height(ui.helper.outerHeight());
            },
            update: function(event, ui) {
                // Samla in den nya ordningen
                var lessonIds = [];
                $("#sortable-lessons tr.sortable-row").each(function(index) {
                    lessonIds.push({
                        id: $(this).data("id"),
                        order: index
                    });
                });

                // Skicka den nya ordningen till servern
                $.ajax({
                    url: "update_lesson_order.php",
                    method: "POST",
                    headers: {
                        "X-CSRF-Token": CSRF_TOKEN
                    },
                    data: {
                        lessons: JSON.stringify(lessonIds),
                        course_id: ' . $courseId . '
                    },
                    success: function(response) {
                        // Uppdatera ordningsnummer i tabellen
                        $("#sortable-lessons tr.sortable-row").each(function(index) {
                            $(this).find(".sort-order-display").text(index);
                        });
                    },
                    error: function(error) {
                        alert("Kunde inte spara den nya ordningen. Ladda om sidan och försök igen.");
                        location.reload();
                    }
                });
            }
        });
    });
</script>';

// Inkludera header
require_once 'include/header.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-muted">Lektioner</h6>
        <div class="d-flex gap-2">
            <?php if (userCanModifyCourse($course)): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#kursBilderModal">
                <i class="bi bi-images"></i> Generera bilder
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#aiLessonModal">
                <i class="bi bi-stars"></i> AI-generera lektion
            </button>
            <a href="edit_lesson.php?course_id=<?= $courseId ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> Ny lektion
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (count($lessons) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="50"></th>
                            <th>Titel</th>
                            <th>Typ</th>
                            <th>Tillhör</th>
                            <th>Status</th>
                            <th>Quiz</th>
                            <th>AI</th>
                            <th>Ordning</th>
                            <th>Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody id="sortable-lessons">
                        <?php foreach ($lessons as $lesson):
                            $isInfo = ($lesson['lesson_type'] ?? 'lesson') === 'info_page';
                            $parentTitle = $isInfo && !empty($lesson['belongs_to_lesson_id'])
                                ? ($lessonTitleById[(int)$lesson['belongs_to_lesson_id']] ?? null)
                                : null;
                        ?>
                            <tr class="sortable-row<?= $isInfo ? ' table-info-row' : '' ?>" data-id="<?= $lesson['id'] ?>"<?= $isInfo ? ' style="background-color:#e7f5ff;"' : '' ?>>
                                <td>
                                    <i class="bi bi-grip-vertical grip-handle"></i>
                                </td>
                                <td>
                                    <a href="edit_lesson.php?id=<?= $lesson['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($lesson['title']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($isInfo): ?>
                                        <span class="badge bg-info text-dark"><i class="bi bi-info-circle"></i> Infosida</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><i class="bi bi-journal-text"></i> Lektion</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isInfo): ?>
                                        <?php if ($parentTitle !== null): ?>
                                            <span class="small text-muted" title="Låses upp samtidigt som denna lektion">
                                                <i class="bi bi-link-45deg"></i> <?= htmlspecialchars($parentTitle) ?>
                                            </span>
                                            <button type="button" class="btn btn-sm btn-link p-0 ms-1 info-owner-toggle"
                                                    data-lesson-id="<?= $lesson['id'] ?>" data-direction="swap"
                                                    title="Byt ägarskap till andra angränsande lektionen">
                                                <i class="bi bi-arrow-left-right"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="small text-muted"><em>Fristående</em></span>
                                            <button type="button" class="btn btn-sm btn-link p-0 ms-1 info-owner-toggle"
                                                    data-lesson-id="<?= $lesson['id'] ?>" data-direction="swap"
                                                    title="Koppla till angränsande lektion">
                                                <i class="bi bi-arrow-left-right"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $lesson['status'] === 'active' ? 'success' : 'secondary' ?>">
                                        <?= $lesson['status'] === 'active' ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                        $hasLegacyQuiz = !empty($lesson['quiz_question']);
                                        $newQuizCount = (int)($lesson['quiz_question_count'] ?? 0);
                                        $hasQuiz = $hasLegacyQuiz || $newQuizCount > 0;
                                        $totalQuestions = $newQuizCount + ($hasLegacyQuiz ? 1 : 0);
                                    ?>
                                    <?php if ($isInfo): ?>
                                        <span class="text-muted small">—</span>
                                    <?php elseif ($hasQuiz): ?>
                                        <a href="edit_quiz.php?lesson_id=<?= $lesson['id'] ?>" class="badge bg-primary text-decoration-none" title="Hantera quizfrågor">
                                            <i class="bi bi-check-circle-fill"></i> Quiz<?= $totalQuestions > 1 ? ' (' . $totalQuestions . ')' : '' ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="edit_quiz.php?lesson_id=<?= $lesson['id'] ?>" class="badge bg-secondary text-decoration-none" title="Lägg till quizfrågor">
                                            <i class="bi bi-plus-circle"></i> Lägg till quiz
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($lesson['ai_instruction']) || !empty($lesson['ai_prompt'])): ?>
                                        <span class="badge bg-info">
                                            <i class="bi bi-robot"></i> AI-stöd
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-dash-circle"></i> Inget AI-stöd
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="sort-order-display"><?= $lesson['sort_order'] ?></span></td>
                                <td>
                                    <a href="../lesson.php?id=<?= $lesson['id'] ?>&preview=1" target="_blank"
                                       class="btn btn-sm btn-outline-info" title="Förhandsgranska">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit_lesson.php?id=<?= $lesson['id'] ?>" class="btn btn-sm btn-primary" title="Redigera lektion">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!$isInfo): ?>
                                    <a href="edit_quiz.php?lesson_id=<?= $lesson['id'] ?>" class="btn btn-sm btn-outline-success" title="Hantera quizfrågor">
                                        <i class="bi bi-patch-question"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" onclick="deleteLesson(<?= $lesson['id'] ?>)"
                                       class="btn btn-sm btn-outline-danger" title="Radera lektion">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                Inga lektioner har skapats för denna kurs än. Klicka på "Ny lektion" för att komma igång.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Info-sidor: inline-swap av parent-lesson (byter mellan föregående och
// nästa angränsande lektion i sort_order, eller clear-to-null om det inte
// finns nå'n på andra sidan).
document.querySelectorAll('.info-owner-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.getAttribute('data-lesson-id');
        var fd = new FormData();
        fd.append('lesson_id', id);
        fd.append('action', 'swap');
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('ajax/update_info_page_owner.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data && data.success) {
                    location.reload();
                } else {
                    alert((data && data.message) ? data.message : 'Kunde inte uppdatera tillhörighet.');
                }
            })
            .catch(function(){ alert('Nätverksfel. Försök igen.'); });
    });
});

function deleteLesson(id) {
    if (!confirm('Är du säker på att du vill radera denna lektion?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'delete_lesson.php';
    var idInput = document.createElement('input');
    idInput.type = 'hidden'; idInput.name = 'id'; idInput.value = id;
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden'; csrfInput.name = 'csrf_token'; csrfInput.value = CSRF_TOKEN;
    form.appendChild(idInput);
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php
// Befintliga lektioner i denna kurs — används som "Tillhör"-dropdown vid
// AI-generering av en informationssida.
$parentCandidates = query(
    "SELECT id, title FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND lesson_type = 'lesson'
     ORDER BY sort_order ASC, title ASC",
    [$courseId]
);
?>

<!-- AI-generera-lektion-modal -->
<div class="modal fade" id="aiLessonModal" tabindex="-1" aria-labelledby="aiLessonModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiLessonModalLabel">
                    <i class="bi bi-stars text-info me-1"></i>AI-generera lektion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Stäng"></button>
            </div>
            <div class="modal-body">
                <form id="aiLessonForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">

                    <div class="mb-3">
                        <label for="aiLessonIdea" class="form-label fw-semibold">Vad ska lektionen handla om?</label>
                        <textarea class="form-control" id="aiLessonIdea" name="lesson_idea" rows="6"
                                  minlength="20" maxlength="10000" required
                                  placeholder="T.ex.: Förklara skillnaden mellan vokaler och konsonanter med två exempel-quizfrågor om svenska bokstäver."></textarea>
                        <div class="form-text">Minst 20, max 10000 tecken. Ju tydligare beskrivning, desto bättre lektion.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold d-block">Typ</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="lesson_type" id="aiTypeLesson" value="lesson" checked>
                                <label class="form-check-label" for="aiTypeLesson">
                                    <i class="bi bi-journal-text me-1 text-primary"></i>Lektion (kan ha quiz)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="lesson_type" id="aiTypeInfo" value="info_page">
                                <label class="form-check-label" for="aiTypeInfo">
                                    <i class="bi bi-info-circle me-1 text-info"></i>Informationssida (utan quiz)
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6" id="aiBelongsToWrapper" style="display:none;">
                            <label for="aiBelongsTo" class="form-label fw-semibold">Tillhör lektion</label>
                            <select class="form-select" id="aiBelongsTo" name="belongs_to_lesson_id">
                                <option value="">— Fristående —</option>
                                <?php foreach ($parentCandidates as $pc): ?>
                                <option value="<?= (int)$pc['id'] ?>"><?= htmlspecialchars($pc['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Styr när infosidan låses upp i stegvisa kurser.</div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label for="aiTextLength" class="form-label fw-semibold">Textlängd</label>
                            <select class="form-select" id="aiTextLength" name="text_length">
                                <option value="short">Kort (~150-250 ord)</option>
                                <option value="medium" selected>Medium (~400-600 ord)</option>
                                <option value="long">Lång (~800-1200 ord)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="aiTone" class="form-label fw-semibold">Ton</label>
                            <select class="form-select" id="aiTone" name="tone">
                                <option value="pedagogical" selected>Pedagogisk</option>
                                <option value="formal">Formell</option>
                                <option value="casual">Avslappnad</option>
                                <option value="inspiring">Inspirerande</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-check mt-3" id="aiQuizWrapper">
                        <input class="form-check-input" type="checkbox" name="include_quiz" id="aiIncludeQuiz" value="1" checked>
                        <label class="form-check-label" for="aiIncludeQuiz">
                            Inkludera 2-4 quizfrågor
                        </label>
                    </div>

                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="generate_image" id="aiGenerateImage" value="1">
                        <label class="form-check-label" for="aiGenerateImage">
                            Generera även AI-bild till lektionen
                            <?php
                            require_once __DIR__ . '/../../include/ai_image_helper.php';
                            require_once __DIR__ . '/../../include/ai_quota.php';
                            $bildKostnadText = aiImageCostLabel(aiImageEstimatedCostCents(
                                getModelForFeature('image', 'gpt-image-1-mini'),
                                aiImageSizeFor('lektion'), aiImageQualityFor('lektion')
                            ));
                            ?>
                            <span class="text-muted small">(tar ungefär en minut extra, kostar ca
                                <?= htmlspecialchars($bildKostnadText ?: 'okänt belopp') ?> och drar mot AI-kvoten)</span>
                        </label>
                        <?php
                        $stilLista = aiImageStyles();
                        $kursBildStil = $stilLista[aiImageStyleKey($course['image_style'] ?? '')]['namn'] ?? '';
                        ?>
                        <div class="mt-2" id="aiImageWishWrapper">
                            <label for="aiImageWish" class="form-label small mb-1">Vad ska bilden visa? <span class="text-muted fw-normal">(valfritt)</span></label>
                            <input type="text" class="form-control form-control-sm" id="aiImageWish" name="image_wish" maxlength="300"
                                   placeholder="T.ex. en lärare som hjälper en elev vid en dator">
                            <div class="form-text small">
                                Tomt fält betyder att bilden utgår från lektionens titel.
                                <?php if ($kursBildStil): ?>Bilden skapas i kursens stil: <strong><?= htmlspecialchars($kursBildStil) ?></strong>.<?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="generate_multipage" id="aiGenerateMultipage" value="1">
                        <label class="form-check-label" for="aiGenerateMultipage">
                            Bygg som flera sidor
                            <span class="text-muted small">(eleven bläddrar mellan korta sidor istället för att scrolla)</span>
                        </label>
                    </div>

                    <div id="aiLessonStatus" class="mt-3" style="display:none;"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                <button type="button" class="btn btn-primary" id="aiLessonSubmitBtn">
                    <i class="bi bi-stars me-1"></i>Generera
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var typeRadios   = document.querySelectorAll('input[name="lesson_type"]');
    var belongsWrap  = document.getElementById('aiBelongsToWrapper');
    var quizWrap     = document.getElementById('aiQuizWrapper');
    var quizCheckbox = document.getElementById('aiIncludeQuiz');
    var submitBtn    = document.getElementById('aiLessonSubmitBtn');
    var statusDiv    = document.getElementById('aiLessonStatus');
    var form         = document.getElementById('aiLessonForm');

    function applyType() {
        var t = document.querySelector('input[name="lesson_type"]:checked').value;
        var isInfo = (t === 'info_page');
        belongsWrap.style.display = isInfo ? 'block' : 'none';
        quizWrap.style.display    = isInfo ? 'none'  : 'block';
        if (isInfo) quizCheckbox.checked = false;
    }
    typeRadios.forEach(function(r){ r.addEventListener('change', applyType); });
    applyType();

    submitBtn.addEventListener('click', function(){
        var idea = document.getElementById('aiLessonIdea').value.trim();
        if (idea.length < 20) {
            statusDiv.style.display = 'block';
            statusDiv.className = 'mt-3 alert alert-warning';
            statusDiv.textContent = 'Beskriv lektionens ämne med minst 20 tecken.';
            return;
        }

        var wantsImage = document.getElementById('aiGenerateImage').checked;
        statusDiv.style.display = 'block';
        statusDiv.className = 'mt-3 alert alert-info';
        statusDiv.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Genererar lektion'
            + (wantsImage ? ' och bild' : '')
            + '... det här tar ca '
            + (wantsImage ? '60-90' : '30-60')
            + ' sekunder.';
        submitBtn.disabled = true;

        var fd = new FormData(form);

        function escHtml(s) {
            return String(s).replace(/[<>&"']/g, function(c){
                return ({"<":"&lt;",">":"&gt;","&":"&amp;","\"":"&quot;","'":"&#39;"})[c];
            });
        }

        fetch('ajax/ai_generate_lesson.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json().catch(function(){ throw new Error('Servern returnerade ogiltig data.'); }); })
            .then(function(data){
                if (data && data.success) {
                    var titleHtml = '<strong>' + escHtml(data.title || '?') + '</strong>';
                    if (data.image_warning) {
                        // Lektionen sparades men bilden misslyckades — visa varning
                        // och vänta längre innan reload så användaren hinner läsa.
                        statusDiv.className = 'mt-3 alert alert-warning';
                        statusDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>'
                            + 'Lektion skapad: ' + titleHtml + ', men <strong>AI-bilden kunde inte genereras</strong>: '
                            + escHtml(data.image_warning)
                            + '. Du kan klicka "Generera AI-bild" i lektionsredigeraren senare. '
                            + '<br><small>Laddar om om 5 sekunder...</small>';
                        setTimeout(function(){ window.location.reload(); }, 5000);
                    } else {
                        statusDiv.className = 'mt-3 alert alert-success';
                        statusDiv.innerHTML = '<i class="bi bi-check-circle me-1"></i>Lektion skapad: '
                            + titleHtml + '. Laddar om...';
                        setTimeout(function(){ window.location.reload(); }, 1200);
                    }
                } else {
                    statusDiv.className = 'mt-3 alert alert-danger';
                    statusDiv.textContent = (data && data.error) ? data.error : 'Ett okänt fel uppstod.';
                    submitBtn.disabled = false;
                }
            })
            .catch(function(err){
                statusDiv.className = 'mt-3 alert alert-danger';
                statusDiv.textContent = 'Nätverksfel eller AI-tidsgräns: ' + err.message;
                submitBtn.disabled = false;
            });
    });
})();
</script>


<?php if (userCanModifyCourse($course)):
// Bilder till hela kursen: motivförslag (plan_course_images.php) → granska → generera
// en i taget via de vanliga bildändpunkterna (samma kvot, loggning och kursstil).
require_once __DIR__ . '/../../include/ai_image_helper.php';
require_once __DIR__ . '/../../include/ai_quota.php';
$kbLektioner = array_values(array_filter($lessons, fn($l) => ($l['lesson_type'] ?? 'lesson') === 'lesson'));
$kbSaknas = count(array_filter($kbLektioner, fn($l) => trim((string)$l['image_url']) === ''));
$kbStil = aiImageStyles()[aiImageStyleKey($course['image_style'] ?? '')]['namn'];
?>
<div class="modal fade" id="kursBilderModal" tabindex="-1" aria-labelledby="kursBilderRubrik" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kursBilderRubrik"><i class="bi bi-images me-1" aria-hidden="true"></i>Generera bilder till kursen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Stäng"></button>
            </div>
            <div class="modal-body">
                <div id="kbSteg1">
                    <p>Varje lektion får ett <strong>eget motiv</strong> utifrån sitt innehåll, så att bilderna skiljer sig åt.
                       Alla bilder görs i kursens bildstil: <strong><?= htmlspecialchars($kbStil) ?></strong>
                       <span class="text-muted small">(ändras vid kursbilden under Redigera kurs)</span>.</p>
                    <fieldset class="mb-3">
                        <legend class="fs-6 fw-semibold">Vilka lektioner?</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="kbLage" id="kbSaknas" value="saknas" <?= $kbSaknas > 0 ? 'checked' : 'disabled' ?>>
                            <label class="form-check-label" for="kbSaknas">Lektioner som saknar bild (<?= $kbSaknas ?>)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="kbLage" id="kbAlla" value="alla" <?= $kbSaknas > 0 ? '' : 'checked' ?>>
                            <label class="form-check-label" for="kbAlla">Alla lektioner (<?= count($kbLektioner) ?>) — befintliga bilder ersätts</label>
                        </div>
                    </fieldset>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="kbOmslag" <?= trim((string)$course['image_url']) === '' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="kbOmslag">Även kursomslaget<?= trim((string)$course['image_url']) !== '' ? ' (ersätter nuvarande)' : '' ?></label>
                    </div>
                    <p class="text-muted small mb-0">Infosidor får ingen bild. Först föreslås motiven — du kan ändra dem innan något kostar pengar.</p>
                </div>

                <div id="kbSteg2" hidden>
                    <p class="mb-2">Granska och ändra motiven. Bocka ur det du inte vill generera. Motivet beskriver bara <em>vad</em> bilden visar; stilen läggs till automatiskt.</p>
                    <div id="kbLista"></div>
                    <p class="small text-muted mt-2 mb-0" id="kbKostnad"></p>
                </div>

                <div id="kbStatus" class="mt-3" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="kbStang">Avbryt</button>
                <button type="button" class="btn btn-primary" id="kbForeslaBtn"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Föreslå motiv</button>
                <button type="button" class="btn btn-primary" id="kbGenereraBtn" hidden><i class="bi bi-stars me-1" aria-hidden="true"></i><span>Generera</span></button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var COURSE = <?= json_encode(['id' => (int)$courseId, 'title' => $course['title'], 'description' => (string)($course['description'] ?? ''), 'style' => aiImageStyleKey($course['image_style'] ?? '')], JSON_UNESCAPED_UNICODE) ?>;
    var modal = document.getElementById('kursBilderModal');
    var steg1 = document.getElementById('kbSteg1'), steg2 = document.getElementById('kbSteg2');
    var lista = document.getElementById('kbLista'), status = document.getElementById('kbStatus');
    var foreslaBtn = document.getElementById('kbForeslaBtn'), genBtn = document.getElementById('kbGenereraBtn');
    var stangBtn = document.getElementById('kbStang');
    var plan = null, igang = false, avbryt = false, nagotKlart = false;

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function kostnad(c) {
        if (c == null) return '';
        return c < 10 ? c.toFixed(1).replace('.', ',') + ' US-cent' : '$' + (c / 100).toFixed(2).replace('.', ',');
    }
    function post(url, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('csrf_token', CSRF_TOKEN);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function rad(nyckel, rubrik, motiv, harBild) {
        var id = 'kbM-' + nyckel;
        return '<div class="border rounded p-2 mb-2 kb-rad" data-key="' + esc(nyckel) + '" data-title="' + esc(rubrik) + '">'
            + '<div class="d-flex gap-2 align-items-start">'
            + '<input class="form-check-input mt-1 kb-med" type="checkbox" checked aria-label="Generera bild för ' + esc(rubrik) + '">'
            + '<div class="flex-grow-1"><label for="' + id + '" class="form-label small fw-semibold mb-1">' + esc(rubrik)
            + (harBild ? ' <span class="badge bg-warning text-dark">ersätter bild</span>' : '') + '</label>'
            + '<textarea class="form-control form-control-sm kb-motiv" id="' + id + '" rows="2" maxlength="300">' + esc(motiv) + '</textarea>'
            + '<div class="small mt-1 kb-resultat"></div></div>'
            + '<div class="kb-tumme flex-shrink-0" style="width:72px;height:72px;"></div>'
            + '</div></div>';
    }
    function uppdateraKostnad() {
        if (!plan) return;
        var rader = lista.querySelectorAll('.kb-rad'), n = 0, cent = 0, omslag = false;
        rader.forEach(function (r) {
            if (!r.querySelector('.kb-med').checked) return;
            n++;
            if (r.dataset.key === 'omslag') { omslag = true; cent += plan.cost_cover_cents || 0; } else { cent += plan.cost_lesson_cents || 0; }
        });
        genBtn.querySelector('span').textContent = 'Generera ' + n + ' bild' + (n === 1 ? '' : 'er');
        genBtn.disabled = n === 0;
        document.getElementById('kbKostnad').textContent = n ? ('Uppskattad kostnad: ca ' + kostnad(cent)
            + ' totalt. Varje bild tar 15–60 sekunder; två görs åt gången. Låt fönstret vara öppet tills det är klart.') : '';
    }

    foreslaBtn.addEventListener('click', function () {
        var lage = (modal.querySelector('input[name="kbLage"]:checked') || {}).value || 'saknas';
        var omslag = document.getElementById('kbOmslag').checked;
        foreslaBtn.disabled = true;
        status.className = 'mt-3 text-muted';
        status.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Läser lektionerna och föreslår motiv…';
        post('ajax/plan_course_images.php', { course_id: COURSE.id, mode: lage, include_cover: omslag ? '1' : '' })
            .then(function (d) {
                if (!d || !d.success) throw new Error(d && d.message ? d.message : 'Okänt fel');
                plan = d;
                var html = '';
                if (d.cover) html += rad('omslag', 'Kursomslag', d.cover.motif, d.cover.has_image);
                d.lessons.forEach(function (l) { html += rad(String(l.id), l.title, l.motif, l.has_image); });
                lista.innerHTML = html;
                steg1.hidden = true; steg2.hidden = false;
                foreslaBtn.hidden = true; genBtn.hidden = false;
                status.className = 'mt-3'; status.textContent = '';
                uppdateraKostnad();
            })
            .catch(function (e) {
                status.className = 'mt-3 alert alert-danger';
                status.textContent = e.message;
                foreslaBtn.disabled = false;
            });
    });
    lista.addEventListener('change', function (e) { if (e.target.classList.contains('kb-med')) uppdateraKostnad(); });

    function generera(r) {
        var nyckel = r.dataset.key, motiv = r.querySelector('.kb-motiv').value.trim();
        var res = r.querySelector('.kb-resultat'), tumme = r.querySelector('.kb-tumme');
        res.className = 'small mt-1 kb-resultat text-muted';
        res.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Genererar…';
        var anrop = nyckel === 'omslag'
            ? post('ajax/generate_course_image.php', { course_id: COURSE.id, course_title: COURSE.title, course_description: COURSE.description, image_style: COURSE.style, image_wish: motiv })
            : post('ajax/generate_lesson_image.php', { lesson_id: nyckel, lesson_title: r.dataset.title, course_name: COURSE.title, image_wish: motiv });
        return anrop.then(function (d) {
            if (!d || !d.success) throw new Error(d && d.message ? d.message : 'Okänt fel');
            nagotKlart = true;
            res.className = 'small mt-1 kb-resultat text-success';
            res.innerHTML = '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Klar' + (d.cost_text ? ' (' + esc(d.cost_text) + ')' : '');
            tumme.innerHTML = '<img src="../upload/' + encodeURIComponent(String(d.image_url).split('/').pop()) + '" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:6px;">';
        }).catch(function (e) {
            res.className = 'small mt-1 kb-resultat text-danger';
            // Ett avbrutet svar betyder ofta att bilden ändå blev klar (lång generering).
            res.textContent = (e instanceof TypeError)
                ? 'Svaret kom inte fram — bilden kan ändå ha skapats. Kontrollera lektionen innan du försöker igen.'
                : 'Misslyckades: ' + e.message;
        });
    }

    genBtn.addEventListener('click', function () {
        var ko = Array.prototype.filter.call(lista.querySelectorAll('.kb-rad'), function (r) { return r.querySelector('.kb-med').checked; });
        if (!ko.length) return;
        igang = true; avbryt = false;
        genBtn.disabled = true;
        lista.querySelectorAll('textarea, .kb-med').forEach(function (el) { el.disabled = true; });
        stangBtn.textContent = 'Stoppa';
        status.className = 'mt-3 text-muted';
        var klara = 0, totalt = ko.length;
        status.textContent = '0 av ' + totalt + ' klara…';
        function nasta() {
            if (avbryt || !ko.length) return Promise.resolve();
            var r = ko.shift();
            return generera(r).then(function () { klara++; status.textContent = klara + ' av ' + totalt + ' klara…'; return nasta(); });
        }
        Promise.all([nasta(), nasta()]).then(function () {
            igang = false;
            status.className = 'mt-3 alert ' + (avbryt ? 'alert-warning' : 'alert-success');
            status.textContent = avbryt ? 'Stoppad. Bilder som redan var påbörjade har gjorts klart.' : 'Klart. Stäng fönstret för att se bilderna i listan.';
            stangBtn.textContent = 'Stäng'; stangBtn.disabled = false;
        });
    });

    stangBtn.addEventListener('click', function (e) {
        if (igang) { e.preventDefault(); e.stopPropagation(); avbryt = true; stangBtn.disabled = true; stangBtn.textContent = 'Stoppar efter pågående…'; }
    }, true);
    modal.addEventListener('hide.bs.modal', function (e) { if (igang) e.preventDefault(); });
    modal.addEventListener('hidden.bs.modal', function () { if (nagotKlart) window.location.reload(); });
})();
</script>
<?php endif; ?>

<?php
// Inkludera footer
require_once 'include/footer.php';
