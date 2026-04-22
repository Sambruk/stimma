<?php
/**
 * Flyttar befintliga quiz-frågor från lessons-tabellen till quiz_questions.
 *
 * Körning:
 *   docker exec stimma-web-1 php /var/www/html/migrations/029_backfill_quiz_questions.php
 *     — dry-run (visar vad som skulle flyttas)
 *   docker exec stimma-web-1 php /var/www/html/migrations/029_backfill_quiz_questions.php --apply
 *     — skapar rader i quiz_questions
 *
 * Idempotent: hoppar över lektioner som redan har rader i quiz_questions.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/connect.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';

$apply = in_array('--apply', $argv, true);
echo "=== Backfill av quiz_questions ===\n";
echo ($apply ? "LÄGE: --apply\n" : "LÄGE: dry-run (--apply för att skriva)\n\n");

$lessons = query("
    SELECT id, quiz_type, quiz_question,
           quiz_answer1, quiz_answer2, quiz_answer3, quiz_answer4, quiz_answer5,
           quiz_correct_answer, quiz_correct_answers
    FROM " . DB_DATABASE . ".lessons
    WHERE quiz_question IS NOT NULL AND quiz_question != ''
");

$converted = 0;
$skipped = 0;

foreach ($lessons as $l) {
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".quiz_questions WHERE lesson_id = ? LIMIT 1",
        [$l['id']]
    );
    if ($existing) {
        $skipped++;
        continue;
    }

    // Plocka bara icke-tomma svar
    $answers = array_values(array_filter([
        $l['quiz_answer1'], $l['quiz_answer2'], $l['quiz_answer3'],
        $l['quiz_answer4'], $l['quiz_answer5'],
    ], fn($v) => $v !== null && $v !== ''));
    if (empty($answers)) {
        $skipped++;
        continue;
    }

    $type = $l['quiz_type'] ?? 'single_choice';
    if ($type === 'multiple_choice') {
        // quiz_correct_answers är CSV "1,3" där 1 = svar index 1 (1-baserad)
        $correctCsv = $l['quiz_correct_answers'] ?? '';
        $correct = [];
        foreach (explode(',', $correctCsv) as $c) {
            $c = (int)trim($c);
            if ($c >= 1 && $c <= count($answers)) $correct[] = $c - 1; // 0-baserad
        }
        $data = ['answers' => $answers, 'correct' => $correct];
    } else {
        // single_choice, fallback
        $type = 'single_choice';
        $correctIdx = (int)$l['quiz_correct_answer']; // 1-baserad
        $data = [
            'answers' => $answers,
            'correct' => ($correctIdx >= 1 && $correctIdx <= count($answers)) ? $correctIdx - 1 : 0,
        ];
    }

    echo sprintf(
        "  lektion #%d, typ=%s, %d svar → quiz_questions.%s\n",
        $l['id'], $type, count($answers), $apply ? 'skapad' : 'skulle skapats'
    );

    if ($apply) {
        execute(
            "INSERT INTO " . DB_DATABASE . ".quiz_questions
             (lesson_id, sort_order, question_type, question_text, quiz_data)
             VALUES (?, 0, ?, ?, ?)",
            [$l['id'], $type, $l['quiz_question'], json_encode($data, JSON_UNESCAPED_UNICODE)]
        );
    }
    $converted++;
}

echo "\n=== Summering ===\n";
echo "  Flyttade: $converted\n";
echo "  Överhoppade (redan finns/tomma): $skipped\n";
if (!$apply) echo "\nKör med --apply för att skriva.\n";
