<?php
/**
 * Stimma — Quiz-hanterare för en lektion.
 *
 * Lista alla frågor i en lektion med möjlighet att lägga till, redigera,
 * ta bort och sortera frågor. Varje frågas specifika fält renderas
 * kontextuellt beroende på typ.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/quiz.php';
require_once 'include/auth_check.php';

$lessonId = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;
$lesson = queryOne("SELECT l.*, c.organization_domain, c.author_id AS course_author_id, c.title AS course_title FROM " . DB_DATABASE . ".lessons l JOIN " . DB_DATABASE . ".courses c ON c.id = l.course_id WHERE l.id = ?", [$lessonId]);
if (!$lesson) {
    $_SESSION['message'] = 'Lektionen hittades inte.';
    $_SESSION['message_type'] = 'danger';
    redirect('courses.php');
    exit;
}

$currentUser = queryOne("SELECT id, email, is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$orgScope = getOrgScopeDomains($currentUser['email']);
$isSuper = ($currentUser['role'] ?? '') === 'super_admin';

// Behörighet: admin inom orgscope, eller editor som är författare/course_editor
if (!$isSuper && !in_array($lesson['organization_domain'], $orgScope, true)) {
    $_SESSION['message'] = 'Du har inte behörighet till den här lektionens organisation.';
    $_SESSION['message_type'] = 'danger';
    redirect('courses.php');
    exit;
}
if (!$isSuper && empty($currentUser['is_admin'])) {
    $isCourseEditor = queryOne("SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?", [$lesson['course_id'], $currentUser['email']]);
    $isAuthor = ((int)$lesson['course_author_id'] === (int)$currentUser['id']);
    if (!$isCourseEditor && !$isAuthor) {
        $_SESSION['message'] = 'Du har inte behörighet till lektionens kurs.';
        $_SESSION['message_type'] = 'danger';
        redirect('courses.php');
        exit;
    }
}

// ================= POST: skapa/uppdatera/radera fråga =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig CSRF-token.';
        $_SESSION['message_type'] = 'danger';
        redirect('admin/edit_quiz.php?lesson_id=' . $lessonId);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $qid = (int)($_POST['question_id'] ?? 0);
        execute("DELETE FROM " . DB_DATABASE . ".quiz_questions WHERE id = ? AND lesson_id = ?", [$qid, $lessonId]);
        $_SESSION['message'] = 'Frågan borttagen.';
        $_SESSION['message_type'] = 'success';
        redirect('admin/edit_quiz.php?lesson_id=' . $lessonId);
        exit;
    }

    if ($action === 'reorder') {
        $orderCsv = $_POST['order'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $orderCsv)));
        foreach ($ids as $i => $qid) {
            execute("UPDATE " . DB_DATABASE . ".quiz_questions SET sort_order = ? WHERE id = ? AND lesson_id = ?", [$i, $qid, $lessonId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $type = $_POST['question_type'] ?? 'single_choice';
        $allowedTypes = array_keys(quizTypeOptions());
        if (!in_array($type, $allowedTypes, true)) $type = 'single_choice';
        $text = trim($_POST['question_text'] ?? '');
        $image = trim($_POST['question_image'] ?? '');
        $points = max(1, (int)($_POST['points'] ?? 1));

        // Bygg quiz_data per typ från formulärfälten
        $data = buildQuizDataFromPost($type, $_POST);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($qid > 0) {
            execute(
                "UPDATE " . DB_DATABASE . ".quiz_questions
                 SET question_type = ?, question_text = ?, question_image = ?, quiz_data = ?, points = ?
                 WHERE id = ? AND lesson_id = ?",
                [$type, $text, $image ?: null, $json, $points, $qid, $lessonId]
            );
            $_SESSION['message'] = 'Frågan uppdaterad.';
        } else {
            $maxOrder = (int)(queryOne("SELECT COALESCE(MAX(sort_order), -1) AS mo FROM " . DB_DATABASE . ".quiz_questions WHERE lesson_id = ?", [$lessonId])['mo'] ?? -1);
            execute(
                "INSERT INTO " . DB_DATABASE . ".quiz_questions
                 (lesson_id, sort_order, question_type, question_text, question_image, quiz_data, points)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$lessonId, $maxOrder + 1, $type, $text, $image ?: null, $json, $points]
            );
            $_SESSION['message'] = 'Fråga skapad.';
        }
        $_SESSION['message_type'] = 'success';
        redirect('admin/edit_quiz.php?lesson_id=' . $lessonId);
        exit;
    }
}

/**
 * Bygg quiz_data-struktur från POST-fält baserat på typ.
 */
function buildQuizDataFromPost($type, $post) {
    switch ($type) {
        case 'single_choice': {
            $answers = array_values(array_filter(array_map('trim', $post['answers'] ?? []), fn($v) => $v !== ''));
            $correct = max(0, min(count($answers) - 1, (int)($post['correct'] ?? 0)));
            return ['answers' => $answers, 'correct' => $correct];
        }
        case 'multiple_choice': {
            $answers = array_values(array_filter(array_map('trim', $post['answers'] ?? []), fn($v) => $v !== ''));
            $correct = array_values(array_unique(array_map('intval', $post['correct_multi'] ?? [])));
            sort($correct);
            return ['answers' => $answers, 'correct' => $correct];
        }
        case 'true_false':
            return ['correct' => !empty($post['tf_correct'])];
        case 'fill_blank': {
            $template = trim($post['template'] ?? '');
            $blanksRaw = $post['blank_answers'] ?? [];
            $blanks = [];
            foreach ($blanksRaw as $csv) {
                $list = array_values(array_filter(array_map('trim', explode('|', $csv))));
                $blanks[] = ['answers' => $list, 'case_sensitive' => false];
            }
            return ['template' => $template, 'blanks' => $blanks];
        }
        case 'image_choice': {
            $multiple = !empty($post['img_multiple']);
            $images = $post['img_files'] ?? [];
            $labels = $post['img_labels'] ?? [];
            $correctFlags = $post['img_correct'] ?? [];
            $options = [];
            foreach ($images as $i => $img) {
                $img = trim($img);
                if ($img === '') continue;
                $options[] = [
                    'image' => basename($img),
                    'label' => trim($labels[$i] ?? ''),
                    'correct' => !empty($correctFlags[$i]),
                ];
            }
            return ['multiple' => $multiple, 'options' => $options];
        }
        case 'order': {
            $items = array_values(array_filter(array_map('trim', $post['order_items'] ?? []), fn($v) => $v !== ''));
            return ['items' => $items];
        }
        case 'match_pairs': {
            $lefts = $post['pair_left'] ?? [];
            $rights = $post['pair_right'] ?? [];
            $pairs = [];
            foreach ($lefts as $i => $l) {
                $l = trim($l); $r = trim($rights[$i] ?? '');
                if ($l === '' && $r === '') continue;
                $pairs[] = ['left' => $l, 'right' => $r];
            }
            return ['pairs' => $pairs];
        }
        case 'categorize': {
            $categories = array_values(array_filter(array_map('trim', $post['categories'] ?? []), fn($v) => $v !== ''));
            $itemTexts = $post['cat_item_text'] ?? [];
            $itemCats = $post['cat_item_cat'] ?? [];
            $items = [];
            foreach ($itemTexts as $i => $t) {
                $t = trim($t);
                if ($t === '') continue;
                $items[] = ['text' => $t, 'category' => (int)($itemCats[$i] ?? 0)];
            }
            return ['categories' => $categories, 'items' => $items];
        }
        case 'numeric':
            return [
                'correct' => (float)($post['num_correct'] ?? 0),
                'tolerance' => (float)($post['num_tolerance'] ?? 0),
                'unit' => trim($post['num_unit'] ?? ''),
            ];
        case 'hotspot': {
            $img = trim($post['hotspot_image'] ?? '');
            $x = (float)($post['hotspot_x'] ?? 0);
            $y = (float)($post['hotspot_y'] ?? 0);
            $r = (float)($post['hotspot_radius'] ?? 0.08);
            return ['image' => basename($img), 'targets' => [['x' => $x, 'y' => $y, 'radius' => $r]]];
        }
        case 'short_text':
            return [
                'answers' => array_values(array_filter(array_map('trim', explode('|', $post['short_answers'] ?? '')))),
                'case_sensitive' => !empty($post['short_case']),
            ];
    }
    return [];
}

// ================= Hämta frågor =================
$questions = getQuizQuestionsForLesson($lessonId);
$editQ = null;
if (isset($_GET['edit_id'])) {
    foreach ($questions as $q) if ($q['id'] == (int)$_GET['edit_id']) { $editQ = $q; break; }
}
$isNew = isset($_GET['new']) || (!$editQ && !empty($_GET['new_type']));
if ($isNew && !$editQ) {
    $editQ = [
        'id' => 0,
        'question_type' => $_GET['new_type'] ?? 'single_choice',
        'question_text' => '',
        'question_image' => '',
        'quiz_data' => '',
        'data' => [],
        'points' => 1,
    ];
}

$page_title = 'Quizfrågor — ' . $lesson['title'];
require_once 'include/header.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-patch-question me-2 text-primary"></i>Quizfrågor</h4>
            <div class="text-muted">
                <a href="edit_lesson.php?id=<?= $lessonId ?>" class="text-decoration-none"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($lesson['title']) ?></a>
                — <?= htmlspecialchars($lesson['course_title']) ?>
            </div>
        </div>
        <?php if (!$editQ): ?>
        <div class="btn-group">
            <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-plus-lg me-1"></i>Lägg till fråga
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach (quizTypeOptions() as $tv => $tl): ?>
                <li><a class="dropdown-item" href="?lesson_id=<?= $lessonId ?>&amp;new=1&amp;new_type=<?= htmlspecialchars($tv) ?>"><?= htmlspecialchars($tl) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type'] ?? 'info') ?> alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message'], $_SESSION['message_type']); endif; ?>

    <?php if ($editQ): ?>
    <!-- ======== REDIGERA/NY FRÅGA ======== -->
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><strong><?= $editQ['id'] ? 'Redigera fråga' : 'Ny fråga' ?></strong></span>
            <a href="edit_quiz.php?lesson_id=<?= $lessonId ?>" class="btn btn-sm btn-outline-secondary">Avbryt</a>
        </div>
        <div class="card-body">
            <form method="post" id="editQuestionForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="question_id" value="<?= (int)$editQ['id'] ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Frågetyp</label>
                        <select class="form-select" name="question_type" id="type_selector" <?= $editQ['id'] ? '' : '' ?>>
                            <?php foreach (quizTypeOptions() as $tv => $tl): ?>
                            <option value="<?= htmlspecialchars($tv) ?>" <?= $editQ['question_type'] === $tv ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editQ['id']): ?>
                        <div class="form-text">Om du byter typ: spara först, all typ-specifik data anpassas.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Poäng</label>
                        <input type="number" class="form-control" name="points" min="1" max="100" value="<?= (int)($editQ['points'] ?? 1) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Frågetext</label>
                    <textarea class="form-control" name="question_text" rows="3"><?= htmlspecialchars($editQ['question_text'] ?? '') ?></textarea>
                </div>

                <!-- Typ-specifika fält -->
                <?php $data = $editQ['data'] ?? []; ?>
                <div class="type-specific" data-type="single_choice" style="display: <?= $editQ['question_type'] === 'single_choice' ? 'block' : 'none' ?>;">
                    <?= renderChoiceFields($data, false) ?>
                </div>
                <div class="type-specific" data-type="multiple_choice" style="display: <?= $editQ['question_type'] === 'multiple_choice' ? 'block' : 'none' ?>;">
                    <?= renderChoiceFields($data, true) ?>
                </div>
                <div class="type-specific" data-type="true_false" style="display: <?= $editQ['question_type'] === 'true_false' ? 'block' : 'none' ?>;">
                    <div class="mb-3">
                        <label class="form-label">Korrekt svar</label>
                        <div class="d-flex gap-3">
                            <div class="form-check"><input class="form-check-input" type="radio" name="tf_correct" value="1" <?= !empty($data['correct']) ? 'checked' : '' ?> id="tf_t"><label class="form-check-label" for="tf_t">Sant</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="tf_correct" value="0" <?= empty($data['correct']) ? 'checked' : '' ?> id="tf_f"><label class="form-check-label" for="tf_f">Falskt</label></div>
                        </div>
                    </div>
                </div>
                <div class="type-specific" data-type="fill_blank" style="display: <?= $editQ['question_type'] === 'fill_blank' ? 'block' : 'none' ?>;">
                    <?= renderFillBlankFields($data) ?>
                </div>
                <div class="type-specific" data-type="image_choice" style="display: <?= $editQ['question_type'] === 'image_choice' ? 'block' : 'none' ?>;">
                    <?= renderImageChoiceFields($data) ?>
                </div>
                <div class="type-specific" data-type="order" style="display: <?= $editQ['question_type'] === 'order' ? 'block' : 'none' ?>;">
                    <?= renderOrderFields($data) ?>
                </div>
                <div class="type-specific" data-type="match_pairs" style="display: <?= $editQ['question_type'] === 'match_pairs' ? 'block' : 'none' ?>;">
                    <?= renderMatchFields($data) ?>
                </div>
                <div class="type-specific" data-type="categorize" style="display: <?= $editQ['question_type'] === 'categorize' ? 'block' : 'none' ?>;">
                    <?= renderCategorizeFields($data) ?>
                </div>
                <div class="type-specific" data-type="numeric" style="display: <?= $editQ['question_type'] === 'numeric' ? 'block' : 'none' ?>;">
                    <?= renderNumericFields($data) ?>
                </div>
                <div class="type-specific" data-type="hotspot" style="display: <?= $editQ['question_type'] === 'hotspot' ? 'block' : 'none' ?>;">
                    <?= renderHotspotFields($data) ?>
                </div>
                <div class="type-specific" data-type="short_text" style="display: <?= $editQ['question_type'] === 'short_text' ? 'block' : 'none' ?>;">
                    <div class="mb-3">
                        <label class="form-label">Godkända svar (separera med <code>|</code>)</label>
                        <input type="text" class="form-control" name="short_answers" value="<?= htmlspecialchars(implode('|', $data['answers'] ?? [])) ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="short_case" id="short_case" <?= !empty($data['case_sensitive']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="short_case">Känsligt för stora/små bokstäver</label>
                    </div>
                </div>

                <?php
                // "Frågebild" är en generell illustration som visas ovanför själva frågetexten.
                // Den är INTE relevant för hotspot (har egen "Bildfil") eller image_choice
                // (har egna bildalternativ), så dölj fältet för de typerna.
                $hideQuestionImage = in_array($editQ['question_type'] ?? '', ['hotspot', 'image_choice'], true);
                ?>
                <div class="mb-3" id="question_image_wrapper" style="<?= $hideQuestionImage ? 'display:none;' : '' ?>">
                    <label class="form-label">Frågebild (valfri) — illustration som visas <em>ovanför</em> frågetexten</label>
                    <div class="input-group">
                        <input type="text" class="form-control generic-image-input" id="question_image_input" name="question_image" value="<?= htmlspecialchars($editQ['question_image'] ?? '') ?>" placeholder="filnamn.jpg">
                        <button type="button" class="btn btn-outline-secondary btn-upload-generic" data-target="question_image_input" title="Ladda upp bild">
                            <i class="bi bi-cloud-upload"></i> Ladda upp
                        </button>
                    </div>
                    <div class="form-text">Används <strong>inte</strong> för "Klicka i bild" eller "Bildval" (de har egna bildfält nedan).</div>
                </div>

                <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Spara fråga</button>
                <a href="edit_quiz.php?lesson_id=<?= $lessonId ?>" class="btn btn-outline-secondary">Avbryt</a>
            </form>
        </div>
    </div>

    <script>
    (function() {
        // Typ-väljare
        var typeSel = document.getElementById('type_selector');
        if (typeSel) typeSel.addEventListener('change', function() {
            document.querySelectorAll('.type-specific').forEach(function(el) { el.style.display = 'none'; });
            var target = document.querySelector('.type-specific[data-type="' + this.value + '"]');
            if (target) target.style.display = 'block';
            // Dölj generell "Frågebild" för hotspot/image_choice
            var qImgWrap = document.getElementById('question_image_wrapper');
            if (qImgWrap) {
                qImgWrap.style.display = (this.value === 'hotspot' || this.value === 'image_choice') ? 'none' : '';
            }
        });

        // Vid submit: disable formfält i dolda type-specific-divar så de inte
        // skickas med. Båda single_choice och multiple_choice renderar t.ex.
        // <input name="answers[]"> — utan denna fix slås listorna ihop till
        // dubbletter när admin sparar och öppnar igen.
        if (typeSel) {
            var quizForm = typeSel.closest('form');
            if (quizForm) quizForm.addEventListener('submit', function() {
                var current = typeSel.value;
                document.querySelectorAll('.type-specific').forEach(function(el) {
                    if (el.dataset.type !== current) {
                        el.querySelectorAll('input, select, textarea').forEach(function(f) { f.disabled = true; });
                    }
                });
            });
        }

        // Gemensam "ta bort rad"-hanterare (event delegation)
        document.addEventListener('click', function(ev) {
            var btn = ev.target.closest('.btn-remove-row');
            if (btn) {
                var row = btn.closest('.choice-row, .img-row, .order-row, .pair-row, .cat-item-row');
                if (row) row.remove();
            }
        });

        // Order-rad
        var addOrder = document.getElementById('add-order-row-btn');
        if (addOrder) addOrder.addEventListener('click', function() {
            var c = document.getElementById('order-rows');
            var n = c.querySelectorAll('.order-row').length + 1;
            var d = document.createElement('div');
            d.className = 'input-group mb-2 order-row';
            d.innerHTML = '<span class="input-group-text">' + n + '</span>'
                + '<input type="text" class="form-control" name="order_items[]">'
                + '<button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button>';
            c.appendChild(d);
        });

        // Pair-rad
        var addPair = document.getElementById('add-pair-row-btn');
        if (addPair) addPair.addEventListener('click', function() {
            var c = document.getElementById('pair-rows');
            var d = document.createElement('div');
            d.className = 'row g-2 mb-2 pair-row';
            d.innerHTML = '<div class="col"><input type="text" class="form-control" name="pair_left[]" placeholder="Vänster"></div>'
                + '<div class="col-auto align-self-center"><i class="bi bi-arrow-left-right"></i></div>'
                + '<div class="col"><input type="text" class="form-control" name="pair_right[]" placeholder="Höger"></div>'
                + '<div class="col-auto"><button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button></div>';
            c.appendChild(d);
        });

        // Kategori-rad
        var addCat = document.getElementById('add-cat-row-btn');
        if (addCat) addCat.addEventListener('click', function() {
            var c = document.getElementById('cat-cats');
            var n = c.querySelectorAll('.cat-cat-row').length + 1;
            var d = document.createElement('div');
            d.className = 'input-group mb-2 cat-cat-row';
            d.innerHTML = '<span class="input-group-text">' + n + '</span>'
                + '<input type="text" class="form-control" name="categories[]">';
            c.appendChild(d);
            refreshCatSelects();
        });

        // Kategori-objekt-rad
        var addCatItem = document.getElementById('add-cat-item-btn');
        if (addCatItem) addCatItem.addEventListener('click', function() {
            var c = document.getElementById('cat-items');
            var catsCount = document.querySelectorAll('#cat-cats .cat-cat-row').length;
            var opts = '';
            for (var i = 0; i < catsCount; i++) opts += '<option value="' + i + '">Kategori ' + (i+1) + '</option>';
            var d = document.createElement('div');
            d.className = 'row g-2 mb-2 cat-item-row';
            d.innerHTML = '<div class="col"><input type="text" class="form-control" name="cat_item_text[]" placeholder="Objekt"></div>'
                + '<div class="col-auto"><select class="form-select cat-item-select" name="cat_item_cat[]">' + opts + '</select></div>'
                + '<div class="col-auto"><button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button></div>';
            c.appendChild(d);
        });

        // Uppdatera alla cat-item-select när kategorilistan ändras
        function refreshCatSelects() {
            var count = document.querySelectorAll('#cat-cats .cat-cat-row').length;
            document.querySelectorAll('.cat-item-select').forEach(function(sel) {
                var current = sel.value;
                sel.innerHTML = '';
                for (var i = 0; i < count; i++) {
                    var opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = 'Kategori ' + (i + 1);
                    if (String(i) === current) opt.selected = true;
                    sel.appendChild(opt);
                }
            });
        }

        // Image-choice: lägg till rad
        var addImg = document.getElementById('add-img-row-btn');
        if (addImg) addImg.addEventListener('click', function() {
            var c = document.getElementById('img-rows');
            var n = c.querySelectorAll('.img-row').length;
            var d = document.createElement('div');
            d.className = 'input-group mb-2 img-row';
            d.innerHTML = '<span class="input-group-text" title="Markera som korrekt"><input type="checkbox" name="img_correct[' + n + ']" value="1"></span>'
                + '<input type="text" class="form-control img-filename-input" name="img_files[]" placeholder="filnamn.jpg">'
                + '<input type="text" class="form-control" name="img_labels[]" placeholder="Etikett (valfri)">'
                + '<button type="button" class="btn btn-outline-secondary btn-upload-to-row" title="Ladda upp bild"><i class="bi bi-cloud-upload"></i></button>'
                + '<button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button>';
            c.appendChild(d);
        });

        // Gemensam AJAX-uppladdare. Skickar CSRF både i body och X-CSRF-Token-
        // header. onFilled(filename) kallas när uppladningen lyckats.
        var csrfToken = (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : <?= json_encode($_SESSION['csrf_token']) ?>;
        function triggerUpload(btn, onFilled) {
            var originalHtml = btn.innerHTML;
            var fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/jpeg,image/png,image/gif';
            fileInput.style.display = 'none';
            document.body.appendChild(fileInput);
            fileInput.addEventListener('change', function() {
                var f = fileInput.files[0];
                if (!f) { fileInput.remove(); return; }
                var fd = new FormData();
                fd.append('image', f);
                fd.append('csrf_token', csrfToken);
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                fetch('upload_image.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-CSRF-Token': csrfToken }
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.url) {
                            onFilled(data.url);
                        } else {
                            var msg = data.error || 'okänt fel';
                            if (data.debug) msg += '\n(debug: ' + JSON.stringify(data.debug) + ')';
                            alert('Uppladdning misslyckades: ' + msg);
                        }
                    })
                    .catch(() => alert('Nätverksfel vid uppladdning.'))
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                        fileInput.remove();
                    });
            });
            fileInput.click();
        }

        // Image-choice: en uppladdningsknapp per bildrad — skriv till img-filename-input
        document.addEventListener('click', function(ev) {
            var btn = ev.target.closest('.btn-upload-to-row');
            if (!btn) return;
            var row = btn.closest('.img-row');
            if (!row) return;
            triggerUpload(btn, function(url) {
                var inp = row.querySelector('.img-filename-input');
                if (inp) inp.value = url;
            });
        });

        // Generic uppladdning: knappar med data-target="<input-id>"
        document.addEventListener('click', function(ev) {
            var btn = ev.target.closest('.btn-upload-generic');
            if (!btn) return;
            var targetId = btn.dataset.target;
            if (!targetId) return;
            var inp = document.getElementById(targetId);
            if (!inp) return;
            triggerUpload(btn, function(url) {
                inp.value = url;
                // Hotspot: trigga preview-uppdatering istället för reload (annars
                // skulle filnamnet tappas eftersom det bara fanns client-side).
                if (targetId === 'hotspot_image_input' && typeof window.__hotspotSetImage === 'function') {
                    window.__hotspotSetImage(url);
                }
            });
        });
    })();
    </script>
    <?php else: ?>
    <!-- ======== FRÅGELISTA ======== -->
    <?php if (empty($questions)): ?>
    <div class="alert alert-info">Inga frågor ännu. Klicka på <strong>Lägg till fråga</strong> ovan för att skapa den första.</div>
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="p-2 small text-muted border-bottom"><i class="bi bi-info-circle me-1"></i>Dra i grepp-ikonen till vänster för att ändra ordning på frågorna.</div>
            <ul class="list-group list-group-flush" id="question-list">
                <?php foreach ($questions as $idx => $q): ?>
                <li class="list-group-item d-flex align-items-center gap-3" data-qid="<?= $q['id'] ?>">
                    <i class="bi bi-grip-vertical text-muted handle" style="cursor: grab; font-size: 1.2rem;"></i>
                    <span class="badge bg-secondary question-order-badge"><?= $idx + 1 ?></span>
                    <div class="flex-grow-1">
                        <?php
                        $previewText = trim(strip_tags(html_entity_decode($q['question_text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                        ?>
                        <div><strong><?= htmlspecialchars($previewText !== '' ? $previewText : '(ingen frågetext)') ?></strong></div>
                        <small class="text-muted"><?= htmlspecialchars(quizTypeLabel($q['question_type'])) ?> · <?= (int)$q['points'] ?> poäng</small>
                    </div>
                    <a href="?lesson_id=<?= $lessonId ?>&amp;edit_id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Ta bort frågan?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <div id="reorder-status" class="p-2 small text-muted" style="display:none;"></div>
        </div>
    </div>
    <script>
    // Sortera frågor via jquery-ui sortable. jQuery + jQuery UI laddas i
    // admin-footern (efter denna script-tag), så initieringen måste vänta
    // tills dokumentet är färdigt — annars är jQuery.fn.sortable undefined.
    function initQuestionListSortable() {
        if (typeof jQuery === 'undefined' || !jQuery.fn.sortable) return;
        var $ = jQuery;
        var $list = $('#question-list');
        if (!$list.length || $list.data('ui-sortable')) return;
        $list.sortable({
            handle: '.handle',
            axis: 'y',
            cursor: 'grabbing',
            placeholder: 'list-group-item bg-warning-subtle',
            forcePlaceholderSize: true,
            update: function() {
                var ids = [];
                $('#question-list li').each(function() {
                    ids.push($(this).data('qid'));
                });
                $('#question-list .question-order-badge').each(function(i) { $(this).text(i + 1); });

                var fd = new FormData();
                fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token']) ?>);
                fd.append('action', 'reorder');
                fd.append('order', ids.join(','));
                var status = document.getElementById('reorder-status');
                status.style.display = 'block';
                status.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sparar ny ordning...';
                fetch('edit_quiz.php?lesson_id=<?= (int)$lessonId ?>', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            status.innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>Ordning sparad.';
                            setTimeout(function() { status.style.display = 'none'; }, 2000);
                        } else {
                            status.innerHTML = '<i class="bi bi-x-circle text-danger me-1"></i>Kunde inte spara ordning.';
                        }
                    })
                    .catch(function() {
                        status.innerHTML = '<i class="bi bi-x-circle text-danger me-1"></i>Nätverksfel vid sparande.';
                    });
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQuestionListSortable);
    } else {
        initQuestionListSortable();
    }
    // Poll-fallback om footer-scripten ännu inte har hunnit köra
    // (DOMContentLoaded kan racea mot sen script-execution på vissa browsrar)
    var __sortableTries = 0;
    var __sortableInterval = setInterval(function() {
        if (typeof jQuery !== 'undefined' && jQuery.fn.sortable) {
            initQuestionListSortable();
            clearInterval(__sortableInterval);
        }
        if (++__sortableTries > 40) clearInterval(__sortableInterval);
    }, 100);
    </script>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
require_once 'include/footer.php';

// =========================================================
// Fältrenderings-helpers (keep nära botten av fil)
// =========================================================
function renderChoiceFields($data, $multiple) {
    $answers = $data['answers'] ?? [''];
    if (empty($answers)) $answers = [''];
    $correctSingle = (int)($data['correct'] ?? 0);
    $correctMulti = $data['correct'] ?? [];
    if (!is_array($correctMulti)) $correctMulti = [];
    ob_start();
    ?>
    <label class="form-label">Svarsalternativ</label>
    <div id="choice-rows">
        <?php foreach ($answers as $i => $a): ?>
        <div class="input-group mb-2 choice-row">
            <?php if ($multiple): ?>
            <span class="input-group-text"><input type="checkbox" name="correct_multi[]" value="<?= $i ?>" <?= in_array($i, $correctMulti) ? 'checked' : '' ?>></span>
            <?php else: ?>
            <span class="input-group-text"><input type="radio" name="correct" value="<?= $i ?>" <?= $i === $correctSingle ? 'checked' : '' ?>></span>
            <?php endif; ?>
            <input type="text" class="form-control" name="answers[]" value="<?= htmlspecialchars($a) ?>">
            <button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary btn-add-choice" data-multi="<?= $multiple ? '1' : '0' ?>"><i class="bi bi-plus"></i> Lägg till alternativ</button>
    <script>
    document.querySelectorAll('.btn-add-choice').forEach(function(btn) {
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', function() {
            var multi = btn.dataset.multi === '1';
            var container = document.getElementById('choice-rows');
            var n = container.querySelectorAll('.choice-row').length;
            var div = document.createElement('div');
            div.className = 'input-group mb-2 choice-row';
            var ctrlName = multi ? 'correct_multi[]' : 'correct';
            var ctrlType = multi ? 'checkbox' : 'radio';
            div.innerHTML = '<span class="input-group-text"><input type="' + ctrlType + '" name="' + ctrlName + '" value="' + n + '"></span>'
                + '<input type="text" class="form-control" name="answers[]">'
                + '<button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button>';
            container.appendChild(div);
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

function renderFillBlankFields($data) {
    $template = $data['template'] ?? '';
    $blanks = $data['blanks'] ?? [];
    $csvs = [];
    foreach ($blanks as $b) $csvs[] = implode('|', $b['answers'] ?? []);
    ob_start();
    ?>
    <div class="mb-3">
        <label class="form-label">Text med luckor (använd <code>{{0}}</code>, <code>{{1}}</code>, ... där användaren ska fylla i)</label>
        <textarea class="form-control" name="template" rows="3"><?= htmlspecialchars($template) ?></textarea>
        <div class="form-text">Exempel: "Sverige gick med i EU år {{0}} och Frankrike år {{1}}."</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Godkända svar per lucka (en rad per lucka, <code>|</code> för alternativ)</label>
        <?php for ($i = 0; $i < max(5, count($csvs)); $i++): ?>
        <div class="input-group mb-1">
            <span class="input-group-text" style="min-width: 90px;">Lucka <?= $i ?></span>
            <input type="text" class="form-control" name="blank_answers[]" value="<?= htmlspecialchars($csvs[$i] ?? '') ?>">
        </div>
        <?php endfor; ?>
        <div class="form-text">Exempel: <code>1995</code> eller <code>1995|nittiofem</code>. Lämna tomt för luckor som inte finns i mallen.</div>
    </div>
    <?php
    return ob_get_clean();
}

function renderImageChoiceFields($data) {
    $multi = !empty($data['multiple']);
    $options = $data['options'] ?? [];
    if (empty($options)) $options = [['image' => '', 'label' => '', 'correct' => false]];
    ob_start();
    ?>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="img_multiple" id="img_multiple" <?= $multi ? 'checked' : '' ?>>
        <label class="form-check-label" for="img_multiple">Tillåt flera korrekta svar (flerval)</label>
    </div>
    <label class="form-label">Bildalternativ</label>
    <div id="img-rows">
        <?php foreach ($options as $i => $o): ?>
        <div class="input-group mb-2 img-row">
            <span class="input-group-text" title="Markera som korrekt"><input type="checkbox" name="img_correct[<?= $i ?>]" value="1" <?= !empty($o['correct']) ? 'checked' : '' ?>></span>
            <input type="text" class="form-control img-filename-input" name="img_files[]" placeholder="filnamn.jpg" value="<?= htmlspecialchars($o['image'] ?? '') ?>">
            <input type="text" class="form-control" name="img_labels[]" placeholder="Etikett (valfri)" value="<?= htmlspecialchars($o['label'] ?? '') ?>">
            <button type="button" class="btn btn-outline-secondary btn-upload-to-row" title="Ladda upp bild till denna rad"><i class="bi bi-cloud-upload"></i></button>
            <button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="add-img-row-btn"><i class="bi bi-plus"></i> Lägg till bild</button>
    <div class="form-text mt-2">Klicka på <i class="bi bi-cloud-upload"></i>-knappen vid raden för att ladda upp en bild direkt.</div>
    <?php
    return ob_get_clean();
}

function renderOrderFields($data) {
    $items = $data['items'] ?? [''];
    if (empty($items)) $items = [''];
    ob_start();
    ?>
    <label class="form-label">Objekt (ange dem i <strong>rätt ordning</strong> — de visas slumpat för deltagaren)</label>
    <div id="order-rows">
        <?php foreach ($items as $i => $it): ?>
        <div class="input-group mb-2 order-row">
            <span class="input-group-text"><?= $i + 1 ?></span>
            <input type="text" class="form-control" name="order_items[]" value="<?= htmlspecialchars($it) ?>">
            <button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="add-order-row-btn"><i class="bi bi-plus"></i> Lägg till</button>
    <?php
    return ob_get_clean();
}

function renderMatchFields($data) {
    $pairs = $data['pairs'] ?? [['left' => '', 'right' => '']];
    if (empty($pairs)) $pairs = [['left' => '', 'right' => '']];
    ob_start();
    ?>
    <label class="form-label">Par (vänster matchar höger)</label>
    <div id="pair-rows">
        <?php foreach ($pairs as $i => $p): ?>
        <div class="row g-2 mb-2 pair-row">
            <div class="col"><input type="text" class="form-control" name="pair_left[]" placeholder="Vänster" value="<?= htmlspecialchars($p['left'] ?? '') ?>"></div>
            <div class="col-auto align-self-center"><i class="bi bi-arrow-left-right"></i></div>
            <div class="col"><input type="text" class="form-control" name="pair_right[]" placeholder="Höger" value="<?= htmlspecialchars($p['right'] ?? '') ?>"></div>
            <div class="col-auto"><button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button></div>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="add-pair-row-btn"><i class="bi bi-plus"></i> Lägg till par</button>
    <?php
    return ob_get_clean();
}

function renderCategorizeFields($data) {
    $categories = $data['categories'] ?? ['', ''];
    if (count($categories) < 2) $categories = ['', ''];
    $items = $data['items'] ?? [];
    if (empty($items)) $items = [['text' => '', 'category' => 0]];
    ob_start();
    ?>
    <label class="form-label">Kategorier</label>
    <div id="cat-cats">
        <?php foreach ($categories as $i => $c): ?>
        <div class="input-group mb-2 cat-cat-row">
            <span class="input-group-text"><?= $i + 1 ?></span>
            <input type="text" class="form-control" name="categories[]" value="<?= htmlspecialchars($c) ?>">
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="add-cat-row-btn"><i class="bi bi-plus"></i> Lägg till kategori</button>

    <label class="form-label">Objekt och deras kategori</label>
    <div id="cat-items">
        <?php foreach ($items as $i => $it): ?>
        <div class="row g-2 mb-2 cat-item-row">
            <div class="col"><input type="text" class="form-control" name="cat_item_text[]" placeholder="Objekt" value="<?= htmlspecialchars($it['text'] ?? '') ?>"></div>
            <div class="col-auto">
                <select class="form-select cat-item-select" name="cat_item_cat[]">
                    <?php for ($ci = 0; $ci < count($categories); $ci++): ?>
                    <option value="<?= $ci ?>" <?= (int)($it['category'] ?? 0) === $ci ? 'selected' : '' ?>>Kategori <?= $ci + 1 ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto"><button type="button" class="btn btn-outline-danger btn-remove-row"><i class="bi bi-x"></i></button></div>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="add-cat-item-btn"><i class="bi bi-plus"></i> Lägg till objekt</button>
    <?php
    return ob_get_clean();
}

function renderNumericFields($data) {
    ob_start();
    ?>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Korrekt värde</label><input type="number" step="any" class="form-control" name="num_correct" value="<?= htmlspecialchars((string)($data['correct'] ?? '')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Tolerans ±</label><input type="number" step="any" class="form-control" name="num_tolerance" value="<?= htmlspecialchars((string)($data['tolerance'] ?? 0)) ?>"></div>
        <div class="col-md-4"><label class="form-label">Enhet (valfri)</label><input type="text" class="form-control" name="num_unit" value="<?= htmlspecialchars($data['unit'] ?? '') ?>" placeholder="kr, cm, ..."></div>
    </div>
    <?php
    return ob_get_clean();
}

function renderHotspotFields($data) {
    $img = $data['image'] ?? '';
    $t = $data['targets'][0] ?? ['x' => 0.5, 'y' => 0.5, 'radius' => 0.08];
    ob_start();
    ?>
    <div class="mb-3">
        <label class="form-label">Bildfil</label>
        <div class="input-group">
            <input type="text" class="form-control generic-image-input" name="hotspot_image" value="<?= htmlspecialchars($img) ?>" placeholder="filnamn.jpg" id="hotspot_image_input">
            <button type="button" class="btn btn-outline-secondary btn-upload-generic" data-target="hotspot_image_input" title="Ladda upp bild">
                <i class="bi bi-cloud-upload"></i> Ladda upp
            </button>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Mål X (0–1)</label><input type="number" step="any" min="0" max="1" class="form-control" name="hotspot_x" id="hotspot_x_field" value="<?= htmlspecialchars((string)$t['x']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Mål Y (0–1)</label><input type="number" step="any" min="0" max="1" class="form-control" name="hotspot_y" id="hotspot_y_field" value="<?= htmlspecialchars((string)$t['y']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Radie (0–1)</label><input type="number" step="any" min="0.01" max="0.5" class="form-control" name="hotspot_radius" id="hotspot_r_field" value="<?= htmlspecialchars((string)($t['radius'] ?? 0.08)) ?>"></div>
    </div>
    <div class="form-check mt-2 mb-2">
        <input class="form-check-input" type="checkbox" id="hotspot_grid_toggle" checked>
        <label class="form-check-label small" for="hotspot_grid_toggle">Visa rutnät (10 × 10)</label>
    </div>
    <div class="form-text">Klicka i bilden för att sätta mål-X/Y automatiskt. Den blå cirkeln visar hur stor klickzonen blir.</div>
    <div id="hotspot-preview" class="mt-3 position-relative d-inline-block" style="max-width: 100%;<?= $img ? '' : 'display:none;' ?>">
        <img id="hotspot_img" src="<?= $img ? '../upload/' . htmlspecialchars($img) : '' ?>" style="max-width: 100%; cursor: crosshair; display: block;" draggable="false">
        <!-- Rutnätet ritas via JS som absolut-positionerade divs över bilden -->
        <div id="hotspot_grid" style="position:absolute;inset:0;pointer-events:none;"></div>
        <!-- Hitzonscirkel — visar den faktiska klickzonen (radie) -->
        <span id="hotspot_circle" style="position:absolute;border-radius:50%;border:2px dashed rgba(13,110,253,.9);background:rgba(13,110,253,.12);pointer-events:none;transform:translate(-50%,-50%);"></span>
        <!-- Mitt-markören -->
        <span id="hotspot_marker" style="position:absolute;width:12px;height:12px;border-radius:50%;background:rgba(13,110,253,.95);border:2px solid white;box-shadow:0 0 0 1px rgba(0,0,0,.35);pointer-events:none;transform:translate(-50%,-50%);"></span>
        <!-- Hover-koordinatbubbla -->
        <span id="hotspot_hover_label" style="position:absolute;display:none;padding:2px 6px;background:rgba(0,0,0,.75);color:white;font-size:11px;border-radius:3px;pointer-events:none;transform:translate(8px,8px);"></span>
    </div>
    <script>
    (function() {
        var preview = document.getElementById('hotspot-preview');
        var img = document.getElementById('hotspot_img');
        var grid = document.getElementById('hotspot_grid');
        var marker = document.getElementById('hotspot_marker');
        var circle = document.getElementById('hotspot_circle');
        var hoverLabel = document.getElementById('hotspot_hover_label');
        var xField = document.getElementById('hotspot_x_field');
        var yField = document.getElementById('hotspot_y_field');
        var rField = document.getElementById('hotspot_r_field');
        var gridToggle = document.getElementById('hotspot_grid_toggle');
        var gridCols = 10, gridRows = 10;

        function buildGrid() {
            grid.innerHTML = '';
            if (!gridToggle.checked) { grid.style.display = 'none'; return; }
            grid.style.display = 'block';
            // Rita vertikala och horisontella linjer (1:10 av bildens storlek)
            for (var i = 1; i < gridCols; i++) {
                var v = document.createElement('div');
                v.style.cssText = 'position:absolute;top:0;bottom:0;left:' + (i*100/gridCols) + '%;width:1px;background:rgba(255,255,255,.5);box-shadow:0 0 0 0.5px rgba(0,0,0,.2);';
                grid.appendChild(v);
            }
            for (var j = 1; j < gridRows; j++) {
                var h = document.createElement('div');
                h.style.cssText = 'position:absolute;left:0;right:0;top:' + (j*100/gridRows) + '%;height:1px;background:rgba(255,255,255,.5);box-shadow:0 0 0 0.5px rgba(0,0,0,.2);';
                grid.appendChild(h);
            }
            // Axel-etiketter (0.1, 0.2, …) i övre/vänstra kanten
            for (var c = 1; c < gridCols; c++) {
                var lx = document.createElement('div');
                lx.textContent = (c/gridCols).toFixed(1);
                lx.style.cssText = 'position:absolute;left:' + (c*100/gridCols) + '%;top:2px;transform:translateX(-50%);font-size:10px;color:white;text-shadow:0 0 3px rgba(0,0,0,.9);';
                grid.appendChild(lx);
            }
            for (var r = 1; r < gridRows; r++) {
                var ly = document.createElement('div');
                ly.textContent = (r/gridRows).toFixed(1);
                ly.style.cssText = 'position:absolute;top:' + (r*100/gridRows) + '%;left:2px;transform:translateY(-50%);font-size:10px;color:white;text-shadow:0 0 3px rgba(0,0,0,.9);';
                grid.appendChild(ly);
            }
        }

        function updateMarker() {
            var x = parseFloat(xField.value) || 0.5;
            var y = parseFloat(yField.value) || 0.5;
            var r = Math.max(0.01, parseFloat(rField.value) || 0.08);
            marker.style.left = (x * 100) + '%';
            marker.style.top = (y * 100) + '%';
            // Radie-cirkel: storlek i pixlar relativt bildens bredd (normaliserad 0-1 → % av bredd)
            var rect = img.getBoundingClientRect();
            var diameterPx = r * 2 * rect.width;
            circle.style.left = (x * 100) + '%';
            circle.style.top = (y * 100) + '%';
            circle.style.width = diameterPx + 'px';
            circle.style.height = diameterPx + 'px';
        }

        // Load-listener alltid registrerad — fångar både initial ladd OCH
        // senare bildbyte via __hotspotSetImage (upload)
        img.addEventListener('load', function() { buildGrid(); updateMarker(); });
        // Om bilden redan är inläst vid DOM-ready (cachad)
        if (img.getAttribute('src') && img.complete && img.naturalWidth > 0) {
            buildGrid();
            updateMarker();
        }

        img.addEventListener('click', function(e) {
            if (!img.naturalWidth) return; // ingen bild inläst än
            var rect = img.getBoundingClientRect();
            if (rect.width === 0) return;
            var x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            var y = Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height));
            xField.value = x.toFixed(3);
            yField.value = y.toFixed(3);
            updateMarker();
        });

        img.addEventListener('mousemove', function(e) {
            var rect = img.getBoundingClientRect();
            var x = (e.clientX - rect.left) / rect.width;
            var y = (e.clientY - rect.top) / rect.height;
            hoverLabel.textContent = 'x=' + x.toFixed(2) + ', y=' + y.toFixed(2);
            hoverLabel.style.display = 'block';
            hoverLabel.style.left = (x * 100) + '%';
            hoverLabel.style.top = (y * 100) + '%';
        });
        img.addEventListener('mouseleave', function() { hoverLabel.style.display = 'none'; });

        xField.addEventListener('input', updateMarker);
        yField.addEventListener('input', updateMarker);
        rField.addEventListener('input', updateMarker);
        gridToggle.addEventListener('change', buildGrid);

        // Exponera API så upload-hanteraren i edit_quiz kan trigga refresh
        window.__hotspotSetImage = function(filename) {
            var input = document.getElementById('hotspot_image_input');
            if (input) input.value = filename;
            img.src = '../upload/' + filename;
            preview.style.removeProperty('display');
        };
    })();
    </script>
    <?php
    return ob_get_clean();
}
?>
