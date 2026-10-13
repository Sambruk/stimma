<?php
/**
 * Framsteg – EN regel för hela appen (ärende 782, 2026-10-13).
 *
 * - Tabell: progress (unik på user_id + lesson_id efter migrations/050_progress_single_table.php).
 *   user_progress skrevs aldrig och är avvecklad.
 * - En lektion är klar när användaren klarat lektionens quiz. Quizläget läses ur
 *   quiz_answers (senaste svaret per fråga), inte ur sessionen – svar från en tidigare
 *   inloggning räknas. require_all_correct: alla frågor rätt. any_result: alla besvarade.
 * - En lektion utan quiz är klar när den öppnas (läses).
 * - Informationssidor krävs inte för att kursen ska bli klar (samma som lessonOrderBlocker()
 *   och kursvyns räknare). De registreras när användaren klickar "Fortsätt".
 * - Ordningen i kursen styr bara vilka lektioner som går att ÖPPNA (lessonOrderBlocker()),
 *   aldrig om framsteg sparas. Förr nekades sparandet tyst om en tidigare sida – även en
 *   inaktiv lektion eller en infosida – inte var klar.
 * - En kurs är klar när alla aktiva lektioner (lesson_type = 'lesson') är klara.
 *   checkAndCompleteCourse() körs efter VARJE registrerat framsteg, inte bara på sista
 *   sidan, och sätter course_enrollments.status = 'completed' samt utfärdar diplom om
 *   diplomkriterierna (x % rätt) är uppfyllda.
 */

require_once __DIR__ . '/gamification.php';

/** SQL-villkor för de lektioner som krävs för att en kurs ska vara klar. */
function requiredLessonSql(string $alias = 'l'): string {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'l';
    return "$a.status = 'active' AND $a.lesson_type = 'lesson'";
}

/** Har lektionen quizfrågor? */
function lessonHasQuiz(int $lessonId): bool {
    return (bool)queryOne("SELECT 1 FROM " . DB_DATABASE . ".quiz_questions WHERE lesson_id = ? LIMIT 1", [$lessonId]);
}

/**
 * Lektionens quizläge för en användare, ur quiz_answers.
 * @return array{total:int,correct:int,answered:int,passed:bool}
 */
function lessonQuizState(int $userId, int $lessonId, string $passMode = 'require_all_correct'): array {
    $row = queryOne(
        "SELECT COUNT(q.id) AS total,
                SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) AS correct,
                SUM(CASE WHEN qa.id IS NOT NULL THEN 1 ELSE 0 END) AS answered
           FROM " . DB_DATABASE . ".quiz_questions q
           LEFT JOIN " . DB_DATABASE . ".quiz_answers qa
                  ON qa.question_id = q.id AND qa.lesson_id = q.lesson_id AND qa.user_id = ?
          WHERE q.lesson_id = ?",
        [$userId, $lessonId]
    );
    $total = (int)($row['total'] ?? 0);
    $correct = (int)($row['correct'] ?? 0);
    $answered = (int)($row['answered'] ?? 0);
    $passed = $total > 0 && ($passMode === 'any_result' ? $answered >= $total : $correct >= $total);
    return ['total' => $total, 'correct' => $correct, 'answered' => $answered, 'passed' => $passed];
}

/**
 * Registrera en lektion eller infosida som klar och kontrollera om kursen därmed är klar.
 * Idempotent. Låser upp nästa lektion i stegvisa kurser.
 *
 * @param array $lesson Rad från lessons (id, course_id, lesson_type) + ev. sequential_mode
 * @return array Resultatet från checkAndCompleteCourse()
 */
function markLessonCompleted(int $userId, array $lesson): array {
    $lessonId = (int)$lesson['id'];
    $courseId = (int)$lesson['course_id'];
    $existing = queryOne(
        "SELECT id, status FROM " . DB_DATABASE . ".progress WHERE user_id = ? AND lesson_id = ? ORDER BY id LIMIT 1",
        [$userId, $lessonId]
    );
    if (!$existing) {
        execute(
            "INSERT INTO " . DB_DATABASE . ".progress (user_id, lesson_id, status, score) VALUES (?, ?, 'completed', 1)",
            [$userId, $lessonId]
        );
    } elseif ($existing['status'] !== 'completed') {
        execute(
            "UPDATE " . DB_DATABASE . ".progress SET status = 'completed', score = 1 WHERE user_id = ? AND lesson_id = ?",
            [$userId, $lessonId]
        );
    }

    $sequential = $lesson['sequential_mode'] ?? null;
    if ($sequential === null) {
        $sequential = queryOne("SELECT sequential_mode FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId])['sequential_mode'] ?? 0;
    }
    if (!empty($sequential) && ($lesson['lesson_type'] ?? 'lesson') === 'lesson') {
        unlockNextSequentialLesson($userId, $courseId, $lessonId);
    }

    return checkAndCompleteCourse($userId, $courseId);
}

/**
 * Är kursen klar för användaren enligt regeln ovan (utan sidoeffekter)?
 * @return array{total:int,done:int,complete:bool}
 */
function courseLessonCompletion(int $userId, int $courseId): array {
    $total = (int)(queryOne(
        "SELECT COUNT(*) AS n FROM " . DB_DATABASE . ".lessons l WHERE l.course_id = ? AND " . requiredLessonSql('l'),
        [$courseId]
    )['n'] ?? 0);
    $done = (int)(queryOne(
        "SELECT COUNT(DISTINCT p.lesson_id) AS n
           FROM " . DB_DATABASE . ".lessons l
           JOIN " . DB_DATABASE . ".progress p ON p.lesson_id = l.id
          WHERE l.course_id = ? AND " . requiredLessonSql('l') . " AND p.user_id = ? AND p.status = 'completed'",
        [$courseId, $userId]
    )['n'] ?? 0);
    return ['total' => $total, 'done' => $done, 'complete' => $total > 0 && $done >= $total];
}
