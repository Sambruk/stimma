<?php
/**
 * Stimma — Quiz render/grade-modul
 *
 * Centraliserar all logik för att rendera en fråga som HTML i lesson.php och
 * bedöma inkommande svar. Stödjer alla 11 frågetyper.
 *
 * quiz_data (JSON) per typ:
 *
 *   single_choice:   { "answers": [...], "correct": 0 }
 *   multiple_choice: { "answers": [...], "correct": [0,2] }
 *   true_false:      { "correct": true }
 *   fill_blank:      { "template": "EU år {{0}}", "blanks": [ { "answers": ["1995"] } ] }
 *   image_choice:    { "multiple": false, "options": [ { "image": "x.jpg", "label": "A", "correct": true } ] }
 *   order:           { "items": ["Steg A", "Steg B"] }  // arrayens ordning = korrekt ordning
 *   match_pairs:     { "pairs": [ { "left": "Land", "right": "Huvudstad" } ] }
 *   categorize:      { "categories": [...], "items": [ { "text": "...", "category": 0 } ] }
 *   numeric:         { "correct": 3.14, "tolerance": 0.01, "unit": "cm" }
 *   hotspot:         { "image": "x.jpg", "targets": [ { "x": 0.45, "y": 0.3, "radius": 0.05 } ] }
 *   short_text:      { "answers": ["Paris"], "case_sensitive": false }
 */

/**
 * Returnera array med alla quiz-frågor för en lektion, sorterade.
 */
function getQuizQuestionsForLesson($lessonId) {
    $rows = query(
        "SELECT id, sort_order, question_type, question_text, question_image, quiz_data, points
         FROM " . DB_DATABASE . ".quiz_questions
         WHERE lesson_id = ? ORDER BY sort_order ASC, id ASC",
        [(int)$lessonId]
    );
    foreach ($rows as &$r) {
        $r['data'] = !empty($r['quiz_data']) ? json_decode($r['quiz_data'], true) : [];
        if (!is_array($r['data'])) $r['data'] = [];
    }
    return $rows;
}

/**
 * Mänskligt läsbar etikett för en frågetyp.
 */
function quizTypeLabel($type) {
    return [
        'single_choice'   => 'Enkelval',
        'multiple_choice' => 'Flerval',
        'true_false'      => 'Sant/falskt',
        'fill_blank'      => 'Lucktext',
        'image_choice'    => 'Bildval',
        'order'           => 'Ordna i rätt ordning',
        'match_pairs'     => 'Matcha par',
        'categorize'      => 'Kategorisera',
        'numeric'         => 'Siffersvar',
        'hotspot'         => 'Klicka i bild',
        'short_text'      => 'Kort textsvar',
    ][$type] ?? $type;
}

/**
 * Alla typer i en array {value=>label}
 */
function quizTypeOptions() {
    return [
        'single_choice'   => 'Enkelval (ett rätt svar)',
        'multiple_choice' => 'Flerval (flera rätta svar)',
        'true_false'      => 'Sant/falskt',
        'fill_blank'      => 'Lucktext (fyll i)',
        'image_choice'    => 'Bildval',
        'order'           => 'Ordna i rätt ordning',
        'match_pairs'     => 'Matcha par',
        'categorize'      => 'Kategorisera (drag till grupp)',
        'numeric'         => 'Siffersvar med tolerans',
        'hotspot'         => 'Klicka i bild (hotspot)',
        'short_text'      => 'Kort textsvar',
    ];
}

/**
 * Rendera en fråga som HTML. Returnerar en sträng med form-kontroller.
 * Svarsfält prefixas alltid med "q{$id}_" så flera frågor på samma sida
 * inte krockar.
 *
 * @param array $q Rad från getQuizQuestionsForLesson
 * @param int   $idx 1-baserat löpnummer (för rubrik)
 */
function renderQuizQuestion(array $q, $idx = 1) {
    $id = (int)$q['id'];
    $type = $q['question_type'];
    $data = $q['data'];
    $pre = 'q' . $id . '_';

    ob_start();
    echo '<div class="quiz-question mb-4 p-3 border rounded" data-question-id="' . $id . '" data-question-type="' . htmlspecialchars($type) . '">';
    echo '<div class="d-flex justify-content-between align-items-start mb-2">';
    echo '<h5 class="mb-0">Fråga ' . (int)$idx . '</h5>';
    echo '<span class="badge bg-light text-dark border">' . htmlspecialchars(quizTypeLabel($type)) . '</span>';
    echo '</div>';

    // Fråge-text (rich HTML från editorn)
    if (!empty($q['question_text'])) {
        echo '<div class="mb-3 question-text">' . $q['question_text'] . '</div>';
    }
    if (!empty($q['question_image']) && $type !== 'hotspot' && $type !== 'image_choice') {
        echo '<div class="mb-3"><img src="upload/' . htmlspecialchars($q['question_image']) . '" alt="" class="img-fluid rounded" style="max-height: 320px;"></div>';
    }

    switch ($type) {
        case 'single_choice':
            $answers = $data['answers'] ?? [];
            foreach ($answers as $i => $ans) {
                echo '<div class="form-check mb-2">';
                echo '<input class="form-check-input" type="radio" name="' . $pre . 'answer" value="' . (int)$i . '" id="' . $pre . 'a' . $i . '">';
                echo '<label class="form-check-label" for="' . $pre . 'a' . $i . '">' . htmlspecialchars($ans) . '</label>';
                echo '</div>';
            }
            break;

        case 'multiple_choice':
            $answers = $data['answers'] ?? [];
            foreach ($answers as $i => $ans) {
                echo '<div class="form-check mb-2">';
                echo '<input class="form-check-input" type="checkbox" name="' . $pre . 'answer[]" value="' . (int)$i . '" id="' . $pre . 'a' . $i . '">';
                echo '<label class="form-check-label" for="' . $pre . 'a' . $i . '">' . htmlspecialchars($ans) . '</label>';
                echo '</div>';
            }
            break;

        case 'true_false':
            echo '<div class="d-flex gap-3">';
            foreach ([1 => 'Sant', 0 => 'Falskt'] as $v => $label) {
                echo '<div class="form-check">';
                echo '<input class="form-check-input" type="radio" name="' . $pre . 'answer" value="' . $v . '" id="' . $pre . 'tf' . $v . '">';
                echo '<label class="form-check-label" for="' . $pre . 'tf' . $v . '">' . $label . '</label>';
                echo '</div>';
            }
            echo '</div>';
            break;

        case 'fill_blank':
            $template = $data['template'] ?? '';
            $blanks = $data['blanks'] ?? [];
            // Ersätt {{0}} {{1}} osv med input-fält
            $rendered = preg_replace_callback('/\{\{(\d+)\}\}/', function($m) use ($pre) {
                $i = (int)$m[1];
                return '<input type="text" class="form-control d-inline-block mx-1" style="width: 180px;" name="' . $pre . 'blank_' . $i . '" autocomplete="off">';
            }, htmlspecialchars($template));
            echo '<div class="fill-blank-content" style="line-height: 2.2;">' . $rendered . '</div>';
            break;

        case 'image_choice':
            $multiple = !empty($data['multiple']);
            $options = $data['options'] ?? [];
            $inputType = $multiple ? 'checkbox' : 'radio';
            $inputName = $multiple ? ($pre . 'answer[]') : ($pre . 'answer');
            echo '<div class="row g-3">';
            foreach ($options as $i => $opt) {
                $imgPath = !empty($opt['image']) ? 'upload/' . htmlspecialchars($opt['image']) : '';
                echo '<div class="col-6 col-md-4 col-lg-3">';
                echo '<label class="image-choice-option d-block border rounded p-2 text-center" style="cursor:pointer;">';
                echo '<input type="' . $inputType . '" name="' . $inputName . '" value="' . (int)$i . '" class="form-check-input image-choice-input mb-1">';
                if ($imgPath) {
                    echo '<img src="' . $imgPath . '" alt="' . htmlspecialchars($opt['label'] ?? '') . '" class="img-fluid rounded" style="max-height: 140px;">';
                }
                if (!empty($opt['label'])) echo '<div class="small mt-1">' . htmlspecialchars($opt['label']) . '</div>';
                echo '</label>';
                echo '</div>';
            }
            echo '</div>';
            break;

        case 'order':
            // Presentera items i shufflad ordning; användaren drar för att ordna rätt
            $items = $data['items'] ?? [];
            $shuffled = [];
            foreach ($items as $i => $it) $shuffled[] = ['idx' => $i, 'text' => $it];
            shuffle($shuffled);
            echo '<p class="small text-muted mb-2">Dra raderna till rätt ordning (den översta ska vara först).</p>';
            echo '<ul class="list-group order-list" data-name="' . $pre . 'order">';
            foreach ($shuffled as $item) {
                echo '<li class="list-group-item d-flex align-items-center" draggable="true" data-idx="' . (int)$item['idx'] . '">';
                echo '<i class="bi bi-grip-vertical me-2 text-muted"></i>';
                echo '<span>' . htmlspecialchars($item['text']) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
            echo '<input type="hidden" name="' . $pre . 'order" value="">';
            break;

        case 'match_pairs':
            $pairs = $data['pairs'] ?? [];
            $lefts = [];
            $rights = [];
            foreach ($pairs as $i => $p) {
                $lefts[] = ['idx' => $i, 'text' => $p['left'] ?? ''];
                $rights[] = ['idx' => $i, 'text' => $p['right'] ?? ''];
            }
            shuffle($rights);
            echo '<p class="small text-muted mb-2">Dra varje ruta från höger till rätt plats bredvid motsvarande ruta till vänster.</p>';
            echo '<div class="match-pairs" data-name="' . $pre . 'match">';
            echo '<div class="row g-2">';
            foreach ($lefts as $l) {
                echo '<div class="col-12 d-flex align-items-center gap-2 match-row" data-left-idx="' . (int)$l['idx'] . '">';
                echo '<div class="flex-grow-1 p-2 border rounded bg-light">' . htmlspecialchars($l['text']) . '</div>';
                echo '<i class="bi bi-arrow-right"></i>';
                echo '<div class="flex-grow-1 p-2 border rounded match-dropzone" style="min-height: 44px;" data-dropzone="1"></div>';
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="mt-3"><strong class="small">Högerspalt — dra härifrån:</strong></div>';
            echo '<div class="match-pool d-flex flex-wrap gap-2 p-2 border rounded mt-1" style="min-height: 50px;">';
            foreach ($rights as $r) {
                echo '<div class="p-2 border rounded bg-white match-draggable" draggable="true" data-right-idx="' . (int)$r['idx'] . '">' . htmlspecialchars($r['text']) . '</div>';
            }
            echo '</div>';
            echo '</div>';
            echo '<input type="hidden" name="' . $pre . 'match" value="">';
            break;

        case 'categorize':
            $categories = $data['categories'] ?? [];
            $items = $data['items'] ?? [];
            $pool = [];
            foreach ($items as $i => $it) $pool[] = ['idx' => $i, 'text' => $it['text'] ?? ''];
            shuffle($pool);
            echo '<p class="small text-muted mb-2">Dra varje objekt till rätt kategori.</p>';
            echo '<div class="categorize-widget" data-name="' . $pre . 'categorize">';
            echo '<div class="categorize-pool d-flex flex-wrap gap-2 p-2 border rounded mb-3" style="min-height: 50px;">';
            foreach ($pool as $it) {
                echo '<div class="p-2 border rounded bg-white cat-item" draggable="true" data-item-idx="' . (int)$it['idx'] . '">' . htmlspecialchars($it['text']) . '</div>';
            }
            echo '</div>';
            echo '<div class="row g-2">';
            foreach ($categories as $ci => $cat) {
                echo '<div class="col">';
                echo '<div class="border rounded p-2 cat-bucket" data-cat-idx="' . (int)$ci . '" style="min-height: 100px;">';
                echo '<div class="fw-semibold small text-center border-bottom pb-1 mb-2">' . htmlspecialchars($cat) . '</div>';
                echo '<div class="cat-bucket-items d-flex flex-column gap-1"></div>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
            echo '<input type="hidden" name="' . $pre . 'categorize" value="">';
            break;

        case 'numeric':
            $unit = $data['unit'] ?? '';
            echo '<div class="input-group" style="max-width: 320px;">';
            echo '<input type="number" step="any" class="form-control" name="' . $pre . 'answer" autocomplete="off">';
            if ($unit !== '') echo '<span class="input-group-text">' . htmlspecialchars($unit) . '</span>';
            echo '</div>';
            break;

        case 'hotspot':
            $img = $data['image'] ?? '';
            if (!$img) { echo '<div class="text-muted">Ingen bild konfigurerad.</div>'; break; }
            echo '<p class="small text-muted mb-2">Klicka på rätt plats i bilden.</p>';
            echo '<div class="hotspot-widget position-relative d-inline-block" data-name="' . $pre . 'hotspot">';
            echo '<img src="upload/' . htmlspecialchars($img) . '" alt="" class="img-fluid rounded" style="max-width: 100%; cursor: crosshair;" draggable="false">';
            echo '<span class="hotspot-marker" style="display:none;position:absolute;width:20px;height:20px;margin:-10px 0 0 -10px;border-radius:50%;background:rgba(13,110,253,.7);border:2px solid white;pointer-events:none;"></span>';
            echo '</div>';
            echo '<input type="hidden" name="' . $pre . 'hotspot_x" value="">';
            echo '<input type="hidden" name="' . $pre . 'hotspot_y" value="">';
            break;

        case 'short_text':
            echo '<input type="text" class="form-control" name="' . $pre . 'answer" style="max-width: 400px;" autocomplete="off">';
            break;

        default:
            echo '<div class="text-danger">Okänd frågetyp: ' . htmlspecialchars($type) . '</div>';
    }

    echo '<div class="mt-3 d-flex align-items-center gap-2">';
    echo '  <button type="button" class="btn btn-primary btn-sm quiz-answer-btn" data-question-id="' . $id . '">Svara</button>';
    echo '  <div class="quiz-feedback" data-feedback></div>';
    echo '</div>';
    echo '</div>';
    return ob_get_clean();
}

/**
 * Utvärdera svar på en fråga. Returnerar ['correct' => bool, 'message' => string].
 */
function gradeQuizQuestion(array $q, array $post) {
    $id = (int)$q['id'];
    $type = $q['question_type'];
    $data = $q['data'];
    $pre = 'q' . $id . '_';

    switch ($type) {
        case 'single_choice': {
            $ans = isset($post[$pre . 'answer']) ? (int)$post[$pre . 'answer'] : -1;
            $correct = (int)($data['correct'] ?? 0);
            return ['correct' => $ans === $correct, 'message' => ''];
        }
        case 'multiple_choice': {
            $ans = $post[$pre . 'answer'] ?? [];
            if (!is_array($ans)) $ans = [];
            $ans = array_values(array_unique(array_map('intval', $ans)));
            sort($ans);
            $correct = array_values(array_unique(array_map('intval', $data['correct'] ?? [])));
            sort($correct);
            return ['correct' => $ans === $correct, 'message' => ''];
        }
        case 'true_false': {
            $ans = isset($post[$pre . 'answer']) ? (int)$post[$pre . 'answer'] : -1;
            $correct = !empty($data['correct']) ? 1 : 0;
            return ['correct' => $ans === $correct, 'message' => ''];
        }
        case 'fill_blank': {
            $blanks = $data['blanks'] ?? [];
            foreach ($blanks as $i => $b) {
                $user = trim((string)($post[$pre . 'blank_' . $i] ?? ''));
                $accepted = $b['answers'] ?? [];
                $ok = false;
                foreach ($accepted as $a) {
                    if (!empty($b['case_sensitive'])) {
                        if ($user === $a) { $ok = true; break; }
                    } else {
                        if (mb_strtolower($user) === mb_strtolower($a)) { $ok = true; break; }
                    }
                }
                if (!$ok) return ['correct' => false, 'message' => ''];
            }
            return ['correct' => true, 'message' => ''];
        }
        case 'image_choice': {
            $multiple = !empty($data['multiple']);
            $options = $data['options'] ?? [];
            $correctIdxs = [];
            foreach ($options as $i => $o) if (!empty($o['correct'])) $correctIdxs[] = (int)$i;
            sort($correctIdxs);
            if ($multiple) {
                $ans = $post[$pre . 'answer'] ?? [];
                if (!is_array($ans)) $ans = [];
                $ans = array_values(array_unique(array_map('intval', $ans)));
                sort($ans);
                return ['correct' => $ans === $correctIdxs, 'message' => ''];
            } else {
                $ans = isset($post[$pre . 'answer']) ? (int)$post[$pre . 'answer'] : -1;
                return ['correct' => in_array($ans, $correctIdxs, true), 'message' => ''];
            }
        }
        case 'order': {
            $submitted = json_decode((string)($post[$pre . 'order'] ?? ''), true);
            if (!is_array($submitted)) return ['correct' => false, 'message' => ''];
            $items = $data['items'] ?? [];
            // Rätt ordning = [0,1,2,...] index
            $correctSeq = range(0, count($items) - 1);
            $submitted = array_map('intval', $submitted);
            return ['correct' => $submitted === $correctSeq, 'message' => ''];
        }
        case 'match_pairs': {
            $submitted = json_decode((string)($post[$pre . 'match'] ?? ''), true);
            if (!is_array($submitted)) return ['correct' => false, 'message' => ''];
            // submitted är {left_idx: right_idx}; rätt är att left_idx === right_idx
            foreach ($submitted as $l => $r) {
                if ((int)$l !== (int)$r) return ['correct' => false, 'message' => ''];
            }
            // Och att ALLA pairs är matchade
            $pairs = $data['pairs'] ?? [];
            if (count($submitted) !== count($pairs)) return ['correct' => false, 'message' => ''];
            return ['correct' => true, 'message' => ''];
        }
        case 'categorize': {
            $submitted = json_decode((string)($post[$pre . 'categorize'] ?? ''), true);
            if (!is_array($submitted)) return ['correct' => false, 'message' => ''];
            $items = $data['items'] ?? [];
            if (count($submitted) !== count($items)) return ['correct' => false, 'message' => ''];
            foreach ($submitted as $itemIdx => $catIdx) {
                $expected = (int)($items[$itemIdx]['category'] ?? -1);
                if ((int)$catIdx !== $expected) return ['correct' => false, 'message' => ''];
            }
            return ['correct' => true, 'message' => ''];
        }
        case 'numeric': {
            $raw = $post[$pre . 'answer'] ?? '';
            if ($raw === '' || !is_numeric($raw)) return ['correct' => false, 'message' => ''];
            $user = (float)$raw;
            $correct = (float)($data['correct'] ?? 0);
            $tol = (float)($data['tolerance'] ?? 0);
            return ['correct' => abs($user - $correct) <= $tol + 1e-9, 'message' => ''];
        }
        case 'hotspot': {
            $x = isset($post[$pre . 'hotspot_x']) && $post[$pre . 'hotspot_x'] !== '' ? (float)$post[$pre . 'hotspot_x'] : null;
            $y = isset($post[$pre . 'hotspot_y']) && $post[$pre . 'hotspot_y'] !== '' ? (float)$post[$pre . 'hotspot_y'] : null;
            if ($x === null || $y === null) return ['correct' => false, 'message' => ''];
            $targets = $data['targets'] ?? [];
            foreach ($targets as $t) {
                $dx = $x - (float)$t['x'];
                $dy = $y - (float)$t['y'];
                $r = (float)($t['radius'] ?? 0.05);
                if (($dx * $dx + $dy * $dy) <= ($r * $r)) return ['correct' => true, 'message' => ''];
            }
            return ['correct' => false, 'message' => ''];
        }
        case 'short_text': {
            $user = trim((string)($post[$pre . 'answer'] ?? ''));
            $accepted = $data['answers'] ?? [];
            $cs = !empty($data['case_sensitive']);
            foreach ($accepted as $a) {
                if ($cs) {
                    if ($user === $a) return ['correct' => true, 'message' => ''];
                } else {
                    if (mb_strtolower($user) === mb_strtolower($a)) return ['correct' => true, 'message' => ''];
                }
            }
            return ['correct' => false, 'message' => ''];
        }
    }
    return ['correct' => false, 'message' => 'Okänd frågetyp'];
}

/**
 * Bedöm ALLA frågor för en lektion. Returnerar ['all_correct' => bool,
 * 'results' => [question_id => bool], 'correct_count' => int, 'total' => int].
 */
function gradeAllQuizQuestions($lessonId, array $post) {
    $questions = getQuizQuestionsForLesson($lessonId);
    $results = [];
    $correct = 0;
    foreach ($questions as $q) {
        $g = gradeQuizQuestion($q, $post);
        $results[$q['id']] = $g['correct'];
        if ($g['correct']) $correct++;
    }
    $total = count($questions);
    return [
        'all_correct' => $total > 0 && $correct === $total,
        'results' => $results,
        'correct_count' => $correct,
        'total' => $total,
    ];
}
