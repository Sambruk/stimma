<?php
/**
 * Stimma - Learn in small steps
 * Copyright (C) 2025 Christian Alfredsson
 * 
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 * 
 * The name "Stimma" is a trademark and subject to restrictions.
 */

/**
 * Lesson page handler
 * 
 * This file handles the display and interaction with individual lessons in the system.
 * It includes:
 * - Lesson content display
 * - Quiz functionality
 * - Progress tracking
 * - Navigation between lessons
 * - Admin controls
 */

// Include required configuration and function files
require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/gamification.php';

// Get system name from environment variable with fallback
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'AI-kurser';

// Check if user is logged in, redirect if not (save intended URL for post-login redirect)
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    redirect('index.php');
    exit;
}

/**
 * Helper function to check if the current request is an AJAX request
 * @return bool True if the request is AJAX, false otherwise
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

// Get lesson ID from URL parameter
$lessonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if preview mode is enabled
$isPreviewMode = isset($_GET['preview']) && $_GET['preview'] == '1';

// Get current user's ID from session
$userId = $_SESSION['user_id'];

// If preview mode, verify user has admin or editor privileges
if ($isPreviewMode) {
    $currentUser = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);
    if (!$currentUser || (!$currentUser['is_admin'] && !$currentUser['is_editor'])) {
        // Not authorized for preview - redirect to normal lesson view
        redirect("lesson.php?id=$lessonId");
        exit;
    }
}

// Fetch lesson information including course details
$lesson = queryOne("
    SELECT l.*, c.title as course_title, c.status as course_status, c.sequential_mode
    FROM " . DB_DATABASE . ".lessons l
    JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
    WHERE l.id = ?
", [$lessonId]);

// Redirect if lesson doesn't exist
if (!$lesson) {
    $_SESSION['flash_message'] = 'Lektionen kunde inte hittas.';
    $_SESSION['flash_type'] = 'danger';
    redirect('index.php');
    exit;
}

// Åtkomstkontroll mot direkt URL-åtkomst (/lesson.php?id=X):
// - public_only-användare: måste ha public_course_access för kursen
// - domain-användare: måste ha kursen i sin orgscope ELLER public_course_access
// - Icke-admin/-editor får inte öppna lektioner i inaktiva kurser
if (!$isPreviewMode) {
    $currentUserInfo = queryOne(
        "SELECT access_mode, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?",
        [$userId]
    );
    $accessMode = $currentUserInfo['access_mode'] ?? 'domain';
    $isAdminOrEditor = !empty($currentUserInfo['is_admin']) || !empty($currentUserInfo['is_editor']);
    $hasPublic = hasPublicCourseAccess($userId, $lesson['course_id']);

    if ($accessMode === 'public_only') {
        $allowed = $hasPublic;
    } else {
        $courseRow = queryOne(
            "SELECT organization_domain, is_global FROM " . DB_DATABASE . ".courses WHERE id = ?",
            [$lesson['course_id']]
        );
        $courseOrg = $courseRow['organization_domain'] ?? '';
        $isGlobalCourse = !empty($courseRow['is_global']);
        $orgScope = getOrgScopeDomains($_SESSION['user_email'] ?? '');
        $inOwnOrg = $courseOrg !== '' && in_array($courseOrg, $orgScope, true);

        // Respektera eventuella shared_domains: om kursen är begränsad till
        // utvalda domäner måste användarens domän finnas i listan.
        if ($inOwnOrg) {
            $sharedDoms = getCourseSharedDomains((int)$lesson['course_id']);
            if (!empty($sharedDoms)) {
                $userDom = getUserDomain($_SESSION['user_email'] ?? '');
                if (!in_array($userDom, $sharedDoms, true)) {
                    $inOwnOrg = false; // begränsad — hens domän är inte med
                }
            }
        }
        // Globala kurser (is_global=1) ger access till alla domain-användare.
        $allowed = $inOwnOrg || $hasPublic || $isGlobalCourse;
    }

    if (!$allowed) {
        $_SESSION['flash_message'] = 'Du har inte tillgång till den här lektionen.';
        $_SESSION['flash_type'] = 'warning';
        redirect('index.php');
        exit;
    }

    // Blockera inaktiva kurser för vanliga användare (admins/editors i orgen
    // får öppna för att testa)
    if ($lesson['course_status'] !== 'active' && !$isAdminOrEditor) {
        $_SESSION['flash_message'] = 'Den här kursen är inte aktiv just nu.';
        $_SESSION['flash_type'] = 'warning';
        redirect('index.php');
        exit;
    }
}

// Auto-enroll när användaren öppnar en lektion (skip in preview mode).
// Detta är vad som markerar en kurs som "pågående" i Mina kurser-fliken.
if (!$isPreviewMode) {
    if (!empty($lesson['sequential_mode'])) {
        // Stegvisa kurser: kompletta schedule-rader behövs, hanteras av helpern.
        // Funktionen är idempotent (returnerar direkt om schedule redan finns).
        enrollUserInSequentialCourse($userId, $lesson['course_id']);

        // Check if the lesson is available
        if (!isLessonAvailableForUser($userId, $lessonId, $lesson['course_id'])) {
            $_SESSION['flash_message'] = 'Denna lektion är inte tillgänglig ännu.';
            $_SESSION['flash_type'] = 'warning';
            redirect('index.php');
            exit;
        }
    } else {
        // Icke-stegvisa kurser: en course_enrollments-rad räcker. Idempotent
        // via en kontroll först — utan denna dyker kursen aldrig upp som
        // pågående förrän användaren markerat en lektion som klar.
        $existingEnrollment = queryOne(
            "SELECT id FROM " . DB_DATABASE . ".course_enrollments
              WHERE user_id = ? AND course_id = ? LIMIT 1",
            [$userId, (int)$lesson['course_id']]
        );
        if (!$existingEnrollment) {
            execute(
                "INSERT INTO " . DB_DATABASE . ".course_enrollments
                    (user_id, course_id, status, started_at)
                 VALUES (?, ?, 'active', NOW())",
                [$userId, (int)$lesson['course_id']]
            );
        }
    }
}

// Get user's progress for this lesson (skip in preview mode)
$progress = null;
$isCompleted = false;
if (!$isPreviewMode) {
    $progress = queryOne("
        SELECT * FROM " . DB_DATABASE . ".progress
        WHERE user_id = ? AND lesson_id = ?
    ", [$userId, $lessonId]);

    // Check if lesson is completed
    $isCompleted = $progress && $progress['status'] === 'completed';
}

/**
 * Hittar nästa sida i sort_order i samma kurs (oavsett typ: lektion
 * eller informationssida). Används för att låta användaren stega
 * igenom infosidor mellan lektioner.
 */
function findNextPageInCourse($courseId, $currentSortOrder) {
    return queryOne(
        "SELECT id, title, lesson_type FROM " . DB_DATABASE . ".lessons
         WHERE course_id = ? AND sort_order > ? AND status = 'active'
         ORDER BY sort_order ASC LIMIT 1",
        [$courseId, $currentSortOrder]
    );
}

// Infosida-completion: enkel POST-handler. Registrerar progress som
// 'completed' för infosidan och returnerar URL till nästa sida.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_info_page_complete'])) {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
        exit;
    }
    if (($lesson['lesson_type'] ?? 'lesson') !== 'info_page') {
        echo json_encode(['success' => false, 'message' => 'Sidan är ingen informationssida.']);
        exit;
    }
    if (!$isPreviewMode) {
        $existing = queryOne(
            "SELECT id FROM " . DB_DATABASE . ".progress WHERE user_id = ? AND lesson_id = ?",
            [$userId, $lessonId]
        );
        if ($existing) {
            execute(
                "UPDATE " . DB_DATABASE . ".progress SET status = 'completed', score = 1 WHERE user_id = ? AND lesson_id = ?",
                [$userId, $lessonId]
            );
        } else {
            execute(
                "INSERT INTO " . DB_DATABASE . ".progress (user_id, lesson_id, status, score) VALUES (?, ?, 'completed', 1)",
                [$userId, $lessonId]
            );
        }
    }

    $nextPage = findNextPageInCourse($lesson['course_id'], $lesson['sort_order']);
    echo json_encode([
        'success' => true,
        'nextPageUrl' => $nextPage ? ('lesson.php?id=' . (int)$nextPage['id']) : ('course.php?id=' . (int)$lesson['course_id']),
        'nextPageTitle' => $nextPage ? $nextPage['title'] : null,
    ]);
    exit;
}

/**
 * Samlar alla quiz-frågor för lektionen och bedömer dem via include/quiz.php.
 * Returnerar array med 'all_correct', 'results', 'correct_count', 'total'.
 */
require_once 'include/quiz.php';

function gradeLessonQuiz($lessonId, $post) {
    return gradeAllQuizQuestions((int)$lessonId, $post);
}

/**
 * Snabbkoll: finns det minst ett quiz-svar i POST (qNN_answer, qNN_blank_0, …)?
 */
function hasQuizAnswer($post) {
    foreach ($post as $key => $_) {
        if (preg_match('/^q\d+_/', $key)) return true;
    }
    return false;
}

// Handle PER-QUESTION AJAX answer (ny UX: varje fråga bedöms individuellt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer_question_id'])) {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
        exit;
    }
    $qid = (int)$_POST['answer_question_id'];
    // Hämta frågan + säkerställ att den tillhör DENNA lektion
    $questionRow = queryOne(
        "SELECT id, lesson_id, sort_order, question_type, question_text, question_image, quiz_data, points
         FROM " . DB_DATABASE . ".quiz_questions WHERE id = ? AND lesson_id = ?",
        [$qid, $lessonId]
    );
    if (!$questionRow) {
        echo json_encode(['success' => false, 'message' => 'Frågan finns inte i denna lektion.']);
        exit;
    }
    $questionRow['data'] = !empty($questionRow['quiz_data']) ? (json_decode($questionRow['quiz_data'], true) ?: []) : [];
    $g = gradeQuizQuestion($questionRow, $_POST);

    // I förhandsgranskningsläge: bara returnera resultat, spara inget
    if ($isPreviewMode) {
        echo json_encode(['success' => true, 'correct' => $g['correct'], 'preview' => true]);
        exit;
    }

    // Lektionens pass-läge: 'require_all_correct' (default) eller 'any_result'
    $lessonPassMode = $lesson['quiz_pass_mode'] ?? 'require_all_correct';

    // Håll reda på svars-state per fråga: 'answered' + 'correct'.
    if (!isset($_SESSION['lesson_quiz_state'][$lessonId])) {
        $_SESSION['lesson_quiz_state'][$lessonId] = ['correct' => [], 'wrong' => []];
    }
    // Migrera äldre state-struktur (innan migration 030) om den finns
    if (!isset($_SESSION['lesson_quiz_state'][$lessonId]['correct'])) {
        $old = $_SESSION['lesson_quiz_state'][$lessonId];
        $_SESSION['lesson_quiz_state'][$lessonId] = ['correct' => is_array($old) ? $old : [], 'wrong' => []];
    }

    if ($g['correct']) {
        $_SESSION['lesson_quiz_state'][$lessonId]['correct'][$qid] = true;
        unset($_SESSION['lesson_quiz_state'][$lessonId]['wrong'][$qid]);
    } else {
        // I strikt läge är det ok att försöka igen: spåra bara att det var fel
        // I any_result-läget låser vi frågan vid första svaret oavsett
        $_SESSION['lesson_quiz_state'][$lessonId]['wrong'][$qid] = true;
    }

    $allQuestions = query(
        "SELECT id FROM " . DB_DATABASE . ".quiz_questions WHERE lesson_id = ?",
        [$lessonId]
    );
    $totalQ = count($allQuestions);
    $correctCount = count($_SESSION['lesson_quiz_state'][$lessonId]['correct']);
    $wrongCount = count(array_diff_key(
        $_SESSION['lesson_quiz_state'][$lessonId]['wrong'],
        $_SESSION['lesson_quiz_state'][$lessonId]['correct']
    ));
    $answeredCount = $correctCount + $wrongCount;

    // All done-villkoret beror på pass-läget
    if ($lessonPassMode === 'any_result') {
        $allDone = $totalQ > 0 && $answeredCount >= $totalQ;
        $lockQuestion = true; // lås frågan vid första svar, oavsett rätt/fel
    } else {
        $allDone = $totalQ > 0 && $correctCount >= $totalQ;
        $lockQuestion = $g['correct']; // lås endast vid rätt svar
    }

    $result = [
        'success' => true,
        'correct' => $g['correct'],
        'lock_question' => $lockQuestion,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'answered_count' => $answeredCount,
        'total' => $totalQ,
        'pass_mode' => $lessonPassMode,
        'all_done' => $allDone,
    ];

    // Behåll legacy-namn för eventuell gammal JS
    $result['answered_ok'] = $correctCount;

    // Om alla frågor är klara: markera lektion som avklarad + lås upp nästa sekventiellt
    if ($allDone) {
        $allPreviousCompleted = true;
        $previousLessons = query(
            "SELECT id FROM " . DB_DATABASE . ".lessons
             WHERE course_id = ?
               AND sort_order < (SELECT sort_order FROM " . DB_DATABASE . ".lessons WHERE id = ?)",
            [$lesson['course_id'], $lessonId]
        );
        foreach ($previousLessons as $pl) {
            $prev = queryOne("SELECT status FROM " . DB_DATABASE . ".progress WHERE user_id = ? AND lesson_id = ?", [$userId, $pl['id']]);
            if (!$prev || $prev['status'] !== 'completed') { $allPreviousCompleted = false; break; }
        }

        if ($allPreviousCompleted) {
            if (!$progress) {
                execute(
                    "INSERT INTO " . DB_DATABASE . ".progress (user_id, lesson_id, status, score) VALUES (?, ?, 'completed', 1)",
                    [$userId, $lessonId]
                );
            } else {
                execute(
                    "UPDATE " . DB_DATABASE . ".progress SET status = 'completed', score = 1 WHERE user_id = ? AND lesson_id = ?",
                    [$userId, $lessonId]
                );
            }
            if (!empty($lesson['sequential_mode'])) {
                unlockNextSequentialLesson($userId, $lesson['course_id'], $lessonId);
            }

            // Hitta nästa sida — kan vara en lektion eller en infosida.
            // Användaren ska kunna stega genom infosidor som ligger mellan
            // lektioner i sort_order (ex. outro-sida efter en lektion).
            $nextRow = queryOne(
                "SELECT id, title, lesson_type FROM " . DB_DATABASE . ".lessons
                 WHERE course_id = ? AND sort_order > ? AND status = 'active'
                 ORDER BY sort_order ASC LIMIT 1",
                [$lesson['course_id'], $lesson['sort_order']]
            );
            if ($nextRow) {
                $available = true;
                if (!empty($lesson['sequential_mode'])) {
                    $available = isLessonAvailableForUser($userId, $nextRow['id'], $lesson['course_id']);
                }
                $result['nextLesson'] = ['id' => (int)$nextRow['id'], 'title' => $nextRow['title'], 'available' => $available];
            } else {
                // Ingen nästa lektion — kolla om hela kursen nu är klar
                $completion = checkAndCompleteCourse($userId, $lesson['course_id']);
                if (!empty($completion['is_complete'])) {
                    $result['courseComplete'] = true;
                    $result['completeUrl'] = $completion['complete_url'];
                }
            }
            // Nollställ session-state för lektionen så omgång kan göras om senare om admin återställer
            unset($_SESSION['lesson_quiz_state'][$lessonId]);
        } else {
            $result['message'] = 'Du måste klara tidigare lektioner först.';
        }
    }

    echo json_encode($result);
    exit;
}

// Handle preview mode quiz submission (no saving)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasQuizAnswer($_POST) && $isPreviewMode) {
    // Validate CSRF token for security
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ogiltig förfrågan.']);
            exit;
        }
    }

    $grade = gradeLessonQuiz($lessonId, $_POST);
    $isCorrect = $grade['all_correct'];

    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $isCorrect,
            'preview_mode' => true,
            'message' => $isCorrect
                ? 'Alla rätt! (Förhandsgranskningsläge — framsteg sparas inte)'
                : ('Rätta: ' . $grade['correct_count'] . ' av ' . $grade['total'] . '. Försök igen.'),
            'results' => $grade['results'],
        ]);
        exit;
    }
}

// Handle form submission for quiz answers (skip in preview mode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasQuizAnswer($_POST) && !$isPreviewMode) {
    // Validate CSRF token for security
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ogiltig förfrågan. Vänligen försök igen.']);
            exit;
        }
        $_SESSION['flash_message'] = 'Ogiltig förfrågan. Vänligen försök igen.';
        $_SESSION['flash_type'] = 'danger';
        redirect("lesson.php?id=$lessonId");
        exit;
    }

    // Check if ALL quiz answers are correct (använder nya multi-question-modulen)
    $grade = gradeLessonQuiz($lessonId, $_POST);
    $isCorrect = $grade['all_correct'];
    
    // Check if all previous lessons in the course are completed
    $previousLessons = query("SELECT id FROM " . DB_DATABASE . ".lessons 
                            WHERE course_id = ? 
                            AND sort_order < (SELECT sort_order FROM " . DB_DATABASE . ".lessons WHERE id = ?)
                            ORDER BY sort_order", 
                            [$lesson['course_id'], $lessonId]);
    
    $allPreviousCompleted = true;
    foreach ($previousLessons as $prevLesson) {
        $prevProgress = queryOne("SELECT status FROM " . DB_DATABASE . ".progress 
                                WHERE user_id = ? AND lesson_id = ?", 
                                [$userId, $prevLesson['id']]);
        if (!$prevProgress || $prevProgress['status'] !== 'completed') {
            $allPreviousCompleted = false;
            break;
        }
    }
    
    // Update user's progress if answer is correct and previous lessons are completed
    if ($isCorrect && $allPreviousCompleted) {
        if (!$progress) {
            // Create new progress record if it doesn't exist
            execute("
                INSERT INTO " . DB_DATABASE . ".progress 
                (user_id, lesson_id, status, score)
                VALUES (?, ?, 'completed', 1)
            ", [$userId, $lessonId]);
        } else {
            // Update existing progress record
            execute("
                UPDATE " . DB_DATABASE . ".progress 
                SET status = 'completed', 
                    score = 1
                WHERE user_id = ? AND lesson_id = ?
            ", [$userId, $lessonId]);
        }
        
        $isCompleted = true;

        // Unlock next sequential lesson if applicable
        if (!empty($lesson['sequential_mode'])) {
            unlockNextSequentialLesson($userId, $lesson['course_id'], $lessonId);
        }

        // Get next lesson in the course
        $nextLesson = queryOne("
            SELECT l.*, c.title as course_title
            FROM " . DB_DATABASE . ".lessons l
            JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
            WHERE l.course_id = ? AND l.sort_order > ?
            ORDER BY l.sort_order ASC
            LIMIT 1
        ", [$lesson['course_id'], $lesson['sort_order']]);
        
        // Kolla kursavslutning om ingen nästa lektion
        $courseCompletion = null;
        if (!$nextLesson) {
            $courseCompletion = checkAndCompleteCourse($userId, $lesson['course_id']);
        }

        // Handle AJAX response
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            $nextLessonData = null;
            if ($nextLesson) {
                $nextLessonData = [
                    'id' => $nextLesson['id'],
                    'title' => $nextLesson['title'],
                    'available' => true
                ];
                // Check if next lesson is available for sequential courses
                if (!empty($lesson['sequential_mode'])) {
                    $available = isLessonAvailableForUser($userId, $nextLesson['id'], $lesson['course_id']);
                    $nextLessonData['available'] = $available;
                    if (!$available) {
                        $course = queryOne("SELECT sequential_interval_days FROM " . DB_DATABASE . ".courses WHERE id = ?", [$lesson['course_id']]);
                        $nextLessonData['available_in_days'] = $course ? (int)$course['sequential_interval_days'] : 7;
                    }
                }
            }
            $response = [
                'success' => true,
                'nextLesson' => $nextLessonData
            ];
            if (!empty($courseCompletion['is_complete'])) {
                $response['courseComplete'] = true;
                $response['completeUrl'] = $courseCompletion['complete_url'];
            }
            echo json_encode($response);
            exit;
        }

        // Non-AJAX: om kursen är klar, skicka till avslutssidan istället för samma lektion
        if (!empty($courseCompletion['is_complete'])) {
            redirect($courseCompletion['complete_url']);
            exit;
        }
        
        $_SESSION['show_confetti'] = true;
        redirect("lesson.php?id=$lessonId");
        exit;
    } else if (!$allPreviousCompleted) {
        // Handle case where previous lessons aren't completed
        $message = 'Du måste klara alla tidigare lektioner innan du kan gå vidare.';
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'warning';
    } else if (!$isCorrect) {
        // Handle incorrect answer — meddela antal rätt per-fråga för feedback
        $message = 'Rätta: ' . $grade['correct_count'] . ' av ' . $grade['total'] . '. Gå igenom frågorna och försök igen.';
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $message,
                'results' => $grade['results'],
            ]);
            exit;
        }
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'danger';
    }
}

// Prepare quiz options from JSON data
$quizOptions = [];
if (!empty($lesson['quiz_options'])) {
    $quizOptions = json_decode($lesson['quiz_options'], true);
    if (!is_array($quizOptions)) {
        $quizOptions = [];
    }
}

// Get next lesson in the course for navigation
$nextLesson = queryOne("
    SELECT l.*, c.title as course_title
    FROM " . DB_DATABASE . ".lessons l
    JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
    WHERE l.course_id = ? AND l.sort_order > ?
    ORDER BY l.sort_order ASC
    LIMIT 1
", [$lesson['course_id'], $lesson['sort_order']]);

// Check if current user is an admin
$user = queryOne("SELECT is_admin FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);

// Set page title with course name
$page_title = $systemName . ' - ' . sanitize($lesson['course_title']) . ' - ' . sanitize($lesson['title']);

// Include header template
include 'include/header.php';

/**
 * Convert YouTube watch URL to embed URL with privacy enhancements
 * @param string $url YouTube URL
 * @return string Embed URL with privacy parameters
 */
function convertYoutubeUrl($url) {
    if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return 'https://www.youtube-nocookie.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
    }
    return str_replace('youtube.com', 'youtube-nocookie.com', $url);
}
?>

<style>
.lesson-intro {
    font-size: 1.1em;
    color: #555;
    border-bottom: 1px solid #eee;
    padding-bottom: 12px;
    margin-bottom: 16px;
}
.lesson-tip {
    background: #e8f5e9;
    border-left: 4px solid #4caf50;
    padding: 12px 16px;
    margin: 16px 0;
    border-radius: 0 8px 8px 0;
}
.lesson-info {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 12px 16px;
    margin: 16px 0;
    border-radius: 0 8px 8px 0;
}
.lesson-example {
    background: #fff3e0;
    border-left: 4px solid #ff9800;
    padding: 12px 16px;
    margin: 16px 0;
    border-radius: 0 8px 8px 0;
}
.lesson-warning {
    background: #fce4ec;
    border-left: 4px solid #f44336;
    padding: 12px 16px;
    margin: 16px 0;
    border-radius: 0 8px 8px 0;
}
.lesson-summary {
    background: #f3e5f5;
    border: 1px solid #ce93d8;
    padding: 16px;
    margin: 20px 0;
    border-radius: 8px;
}
.lesson-summary h4 {
    margin-top: 0;
    color: #7b1fa2;
}

/* Tabeller i lektionsinnehåll */
.content table {
    border-collapse: collapse;
    width: 100%;
    margin: 16px 0;
}
.content table td,
.content table th {
    border: 1px solid #dee2e6;
    padding: 8px 12px;
}
.content table th {
    background: #f8f9fa;
    font-weight: 600;
}

/* Länkar i lektionsinnehåll */
.content a {
    color: #0F3B5F;
    text-decoration: underline;
}
.content a:hover {
    color: #1a5a8a;
}
</style>

<!-- Preview mode banner -->
<?php if ($isPreviewMode): ?>
<div class="alert alert-warning alert-dismissible mb-0 rounded-0 text-center" role="alert">
    <i class="bi bi-eye-fill me-2"></i>
    <strong>Förhandsgranskningsläge</strong> - Dina framsteg sparas inte.
    <a href="admin/edit_lesson.php?id=<?= $lessonId ?>" class="alert-link ms-2">
        <i class="bi bi-pencil"></i> Redigera lektion
    </a>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Stäng"></button>
</div>
<?php endif; ?>

<!-- Override globala rd-main max-width (1100px) för lesson-sidan — vi vill
     att lektionsinnehållet får använda hela bredden mellan app-sidebaren
     och en liten marginal i högerkant. -->
<style>
    #rdMain { max-width: none; }
    @media (min-width: 992px) {
        /* Lite extra padding-right så texten inte klistras i högerkanten */
        #rdMain { padding-right: 48px; }
    }
</style>

<!-- Main content container -->
<div class="container-fluid py-4 px-0">
    <div class="row g-4">
        <!-- Left sidebar: lektionsmeny -->
        <div class="col-lg-3 d-none d-lg-block">
            <?php
            $navLessons = query(
                "SELECT id, title, sort_order, lesson_type, belongs_to_lesson_id FROM " . DB_DATABASE . ".lessons
                 WHERE course_id = ? AND status = 'active' ORDER BY sort_order ASC, id ASC",
                [$lesson['course_id']]
            );
            $navProgressRows = query(
                "SELECT p.lesson_id, p.status FROM " . DB_DATABASE . ".progress p
                 JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
                 WHERE p.user_id = ? AND l.course_id = ?",
                [$userId, $lesson['course_id']]
            );
            $navCompletedIds = [];
            foreach ($navProgressRows as $pr) {
                if ($pr['status'] === 'completed') $navCompletedIds[(int)$pr['lesson_id']] = true;
            }
            // Räkna bara riktiga lektioner i "X av Y klara"-summan — infosidor
            // räknas inte som lektioner.
            $realLessons = array_values(array_filter($navLessons, function($nl) { return ($nl['lesson_type'] ?? 'lesson') === 'lesson'; }));
            $realLessonCount = count($realLessons);
            $realDoneCount = 0;
            foreach ($realLessons as $rl) {
                if (isset($navCompletedIds[(int)$rl['id']])) $realDoneCount++;
            }
            // Numrering: bara riktiga lektioner får ett nummer i sidomenyn.
            $lessonNumberByIdx = [];
            $num = 0;
            foreach ($navLessons as $i => $nl) {
                if (($nl['lesson_type'] ?? 'lesson') === 'lesson') {
                    $num++;
                    $lessonNumberByIdx[$i] = $num;
                }
            }
            ?>
            <div class="card shadow-sm lesson-nav" style="position: sticky; top: 1rem;">
                <div class="card-header py-2 px-3 small">
                    <i class="bi bi-list-ol me-1 text-primary"></i><strong>Innehåll</strong>
                </div>
                <ol class="list-group list-group-flush m-0" style="list-style: none; padding-left: 0;">
                    <?php foreach ($navLessons as $idx => $nl):
                        $isCurrent = (int)$nl['id'] === (int)$lessonId;
                        $isDone = isset($navCompletedIds[(int)$nl['id']]);
                        $isInfo = ($nl['lesson_type'] ?? 'lesson') === 'info_page';
                        // Regel: klickbar om den är tillgänglig via schedule-regler
                        // (isLessonAvailableForUser hanterar infosidor via rekursion).
                        // Nuvarande sida är självklart inte klickbar.
                        $isAvailable = isLessonAvailableForUser($userId, (int)$nl['id'], $lesson['course_id']);
                        $isClickable = !$isCurrent && $isAvailable;
                        $numLabel = $isInfo ? '' : ($lessonNumberByIdx[$idx] . '. ');
                    ?>
                    <li class="list-group-item py-2 px-2 small <?= $isCurrent ? 'bg-primary bg-opacity-10 border-start border-primary border-3' : ($isInfo ? 'bg-info bg-opacity-10' : '') ?>">
                        <?php if ($isClickable): ?>
                            <a href="lesson.php?id=<?= (int)$nl['id'] ?>" class="text-decoration-none d-flex align-items-start gap-1 text-dark">
                                <?php if ($isDone): ?>
                                    <i class="bi bi-check-circle-fill text-success flex-shrink-0" style="line-height: 1.3;"></i>
                                <?php elseif ($isInfo): ?>
                                    <i class="bi bi-info-circle text-info flex-shrink-0" style="line-height: 1.3;" title="Informationssida"></i>
                                <?php else: ?>
                                    <i class="bi bi-circle flex-shrink-0" style="line-height: 1.3;"></i>
                                <?php endif; ?>
                                <span><?= $numLabel ?><?= htmlspecialchars($nl['title']) ?></span>
                            </a>
                        <?php elseif ($isCurrent): ?>
                            <div class="d-flex align-items-start gap-1 fw-semibold">
                                <i class="bi <?= $isInfo ? 'bi-info-circle-fill text-info' : 'bi-play-circle-fill text-primary' ?> flex-shrink-0" style="line-height: 1.3;"></i>
                                <span><?= $numLabel ?><?= htmlspecialchars($nl['title']) ?></span>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-start gap-1 text-muted" title="Låst — låses upp när föregående lektion är klar">
                                <i class="bi bi-lock-fill flex-shrink-0" style="line-height: 1.3;"></i>
                                <span><?= $numLabel ?><?= htmlspecialchars($nl['title']) ?></span>
                            </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <div class="card-footer py-2 px-3 small text-muted text-center">
                    <?= $realDoneCount ?> av <?= $realLessonCount ?> <?= $realLessonCount === 1 ? 'lektion' : 'lektioner' ?> klara
                </div>
            </div>
        </div>

        <!-- Main content area -->
        <div class="col-12 col-lg-9">
            <main>
                <?php
                // Multi-page-stöd: räkna sidbrytningar i lektionsinnehållet redan här
                // så vi vet om vi ska wrappa kort i en lesson-paginator. Splitten görs
                // INNAN cleanHtml senare så HTML-kommentaren <!-- pagebreak --> överlever.
                $rdRawContent = (string)($lesson['content'] ?? '');
                $rdPages = preg_split('/<!--\s*pagebreak\s*-->/i', $rdRawContent);
                $rdContentPageCount = is_array($rdPages) ? count($rdPages) : 1;
                $rdHasVideo = !empty($lesson['video_url']);
                // AI-chat visas bara om författaren explicit aktiverat den
                // ai_chat_enabled OCH minst ett av styrfälten har innehåll.
                $rdHasAiChat = !empty($lesson['ai_chat_enabled'])
                    && (!empty($lesson['ai_instruction']) || !empty($lesson['ai_prompt']));
                // Ladda quiz tidigt så vi kan räkna in det när vi avgör multipage.
                $rdIsInfoPage = ($lesson['lesson_type'] ?? 'lesson') === 'info_page';
                $lessonQuestions = $rdIsInfoPage ? [] : getQuizQuestionsForLesson($lessonId);
                $rdHasQuizCard = $rdIsInfoPage || !empty($lessonQuestions);
                // Multipage aktiveras om totalt antal sidor > 1. Quiz/AI-chat/video
                // räknas som egna sidor även när content bara har en sida — annars
                // skulle quiz visas direkt under innehållet istället för på egen
                // bläddringsbar sida.
                $rdExtraPages = ($rdHasVideo ? 1 : 0)
                              + ($rdHasAiChat ? 1 : 0)
                              + ($rdHasQuizCard ? 1 : 0);
                $rdMultiPage = ($rdContentPageCount + $rdExtraPages) > 1;
                ?>
                <?php if ($rdMultiPage):
                    // Skicka över nästa-lektion-status och completed-flag till paginator-JS
                    // så Next-knappen på sista sidan kan bli "Nästa lektion →" när
                    // lektionen är klar.
                    $rdNextLessonId = $nextLesson ? (int)$nextLesson['id'] : 0;
                    $rdNextLessonTitle = $nextLesson ? (string)$nextLesson['title'] : '';
                    $rdNextLessonAvailable = 0;
                    if ($nextLesson) {
                        $rdNextLessonAvailable = isLessonAvailableForUser(
                            (int)$userId, (int)$nextLesson['id'], (int)$lesson['course_id']
                        ) ? 1 : 0;
                    }
                    // Läs pass-mode direkt från lektionen — $lessonPassMode
                    // definieras bara inom POST-handlern och är odefinierad vid
                    // normal GET. Default är 'require_all_correct' enligt
                    // schemat.
                    $rdPassMode = ($lesson['quiz_pass_mode'] ?? 'require_all_correct') === 'any_result'
                        ? 'any_result' : 'require_all_correct';
                ?>
                <div class="lesson-paginator"
                     id="lessonPaginator"
                     aria-label="Lektionssidor"
                     data-lesson-completed="<?= $isCompleted ? '1' : '0' ?>"
                     data-pass-mode="<?= htmlspecialchars($rdPassMode) ?>"
                     data-preview-mode="<?= $isPreviewMode ? '1' : '0' ?>"
                     data-next-id="<?= (int)$rdNextLessonId ?>"
                     data-next-title="<?= htmlspecialchars($rdNextLessonTitle) ?>"
                     data-next-available="<?= (int)$rdNextLessonAvailable ?>">
                <?php endif; ?>

                <!-- Lesson content card. I multipage-läge gömmer paginator-JS
                     hela kortet när active page inte är "content" — annars
                     skulle lektionens titel + bild visas ovanför quiz/AI-chat/video. -->
                <div class="card mb-4 lesson-content-card"<?php if (!empty($lesson['background_color'])): ?> style="background-color: <?= htmlspecialchars($lesson['background_color']) ?>"<?php endif; ?>>
                    <div class="card-body">
                        <!-- Flash message display -->
                        <?php if (isset($_SESSION['flash_message']) && $_SESSION['flash_type'] !== 'danger'): ?>
                        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>" role="alert">
                            <?= htmlspecialchars($_SESSION['flash_message']) ?>
                        </div>
                        <?php 
                            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
                        endif; ?>
                        
                        <!-- Completion status badge (visas inte på infosidor) -->
                        <?php if ($isCompleted && ($lesson['lesson_type'] ?? 'lesson') !== 'info_page'): ?>
                        <div class="text-end mb-3">
                            <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i> Avklarad</span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Lesson title with admin edit link -->
                        <h1 class="h2 mb-3"><?= sanitize($lesson['title']) ?>
                    
                        <?php if ($user['is_admin']): ?>
                            <a href="admin/edit_lesson.php?id=<?= $lesson['id'] ?>"> <i class="bi bi-pencil-square small"></i></a>
                        <?php endif; ?>
                        </h1>
                        
                        <!-- Lesson description -->
                        <?php if (!empty($lesson['description'])): ?>
                            <div class="lead mb-4"><?= nl2br(sanitize($lesson['description'])) ?></div>
                        <?php endif; ?>
                        
                        <!-- Lesson content with optional image -->
                        <div class="row">
                            <?php if (!empty($lesson['image_url'])): ?>
                            <div class="col-md-4">
                                <div class="mb-3 mb-md-0">
                                    <img src="upload/<?= sanitize($lesson['image_url']) ?>" alt="<?= sanitize($lesson['title']) ?>" class="img-fluid rounded">
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="<?= !empty($lesson['image_url']) ? 'col-md-8' : 'col-12' ?>">
                                <?php if ($rdMultiPage): ?>
                                    <?php foreach ($rdPages as $idx => $rdPageHtml): ?>
                                    <section class="lesson-page <?= $idx === 0 ? 'active' : '' ?>"
                                             data-page-kind="content"
                                             role="region"
                                             aria-label="Innehållssida <?= $idx + 1 ?>"
                                             <?= $idx === 0 ? '' : 'hidden' ?>>
                                        <div class="content clearfix">
                                            <?= cleanHtml($rdPageHtml) ?>
                                        </div>
                                    </section>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <div class="content clearfix">
                                    <?= cleanHtml($rdRawContent) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Video Section -->
                <?php if (!empty($lesson['video_url'])): ?>
                <div class="card mb-4 <?= $rdMultiPage ? 'lesson-page' : '' ?>" <?php if ($rdMultiPage): ?>data-page-kind="video" hidden role="region" aria-label="Video"<?php endif; ?>>
                    <div class="card-header">
                        <?php if (($lesson['video_type'] ?? '') === 'local'): ?>
                            <i class="bi bi-camera-video me-2"></i> Video
                        <?php else: ?>
                            <i class="bi bi-youtube me-2"></i> Video
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (($lesson['video_type'] ?? '') === 'local'): ?>
                        <?php
                            $videoFile = basename($lesson['video_url']);
                            $videoExt = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));
                            $videoMime = $videoExt === 'webm' ? 'video/webm' : 'video/mp4';
                        ?>
                        <div class="ratio ratio-16x9">
                            <video controls preload="metadata">
                                <source src="upload/videos/<?= htmlspecialchars($videoFile) ?>" type="<?= $videoMime ?>">
                                Din webbläsare stöder inte videouppspelning.
                            </video>
                        </div>
                        <?php else: ?>
                        <div class="ratio ratio-16x9">
                            <iframe
                                src="<?= htmlspecialchars(convertYoutubeUrl($lesson['video_url'])) ?>"
                                title="Lesson video"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen
                                loading="lazy">
                            </iframe>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Audio card (tillgänglighetsalternativ / pedagogiskt ljud) -->
                <?php if (!empty($lesson['audio_url'])): ?>
                <?php
                    $audioFile = basename($lesson['audio_url']);
                    $audioExt = strtolower(pathinfo($audioFile, PATHINFO_EXTENSION));
                    $audioMimeMap = [
                        'mp3' => 'audio/mpeg',
                        'ogg' => 'audio/ogg',
                        'wav' => 'audio/wav',
                        'm4a' => 'audio/mp4',
                    ];
                    $audioMime = $audioMimeMap[$audioExt] ?? 'audio/mpeg';
                ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h3 class="h6 mb-3"><i class="bi bi-volume-up me-2 text-primary" aria-hidden="true"></i>Lyssna på lektionen</h3>
                        <audio controls preload="metadata" style="width: 100%;" aria-label="Ljudversion av lektionen <?= htmlspecialchars($lesson['title']) ?>">
                            <source src="upload/audio/<?= htmlspecialchars($audioFile) ?>" type="<?= $audioMime ?>">
                            Din webbläsare stöder inte uppspelning av ljud. <a href="upload/audio/<?= htmlspecialchars($audioFile) ?>" download>Ladda ner ljudfilen</a>.
                        </audio>
                    </div>
                </div>
                <?php endif; ?>

                <!-- AI chat interface card -->
                <?php if ($rdHasAiChat): ?>
                <div class="card mb-4 <?= $rdMultiPage ? 'lesson-page' : '' ?>" <?php if ($rdMultiPage): ?>data-page-kind="ai-chat" hidden role="region" aria-label="Fråga AI"<?php endif; ?>>
                    <div class="card-header d-flex align-items-center" id="aiChatToggle">
                        <i class="bi bi-robot me-2"></i> Fråga AI om detta ämne
                    </div>
                    <div class="card-body" id="aiChatBody">
                        <div class="mb-3" id="aiMessages">
                            <?php
                                $aiInstructionClean = trim(strip_tags($lesson['ai_instruction'] ?? ''));
                                if ($aiInstructionClean !== ''):
                            ?>
                            <div class="alert alert-info">
                                <div>
                                    <?= cleanHtml($lesson['ai_instruction']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="input-group">
                            <textarea id="aiInput" class="form-control"
                                    placeholder="Skriv här för att chatta med AI..."
                                    rows="1"
                                    style="resize: none; overflow-y: hidden;"
                            ></textarea>
                            <button id="aiSendBtn" class="btn btn-primary">
                                <i class="bi bi-send"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (($lesson['lesson_type'] ?? 'lesson') === 'info_page'): ?>
                <!-- Informationssida: "Fortsätt"-knapp istället för quiz -->
                <div class="card <?= $rdMultiPage ? 'lesson-page' : '' ?>" <?php if ($rdMultiPage): ?>data-page-kind="finish" hidden role="region" aria-label="Avsluta lektion"<?php endif; ?>>
                    <div class="card-body text-center">
                        <p class="text-muted mb-3"><i class="bi bi-info-circle me-1 text-info"></i>Detta är en informationssida. När du har läst klart — klicka för att fortsätta.</p>
                        <button type="button" class="btn btn-primary" id="infoPageContinueBtn">
                            <i class="bi bi-arrow-right-circle me-1"></i>Fortsätt
                        </button>
                        <div id="infoPageContinueResult" class="mt-3" style="display: none;"></div>
                    </div>
                </div>
                <?php elseif (!empty($lessonQuestions)): ?>
                <!-- Quiz-sektion. I multipage-läge renderas varje fråga på egen
                     sida (data-page-kind="quiz") och submit-knappen på sista
                     sidan submittar hela formen. I single-page-läge listas alla
                     frågor i ett kort. -->
                <?php if (isset($_SESSION['flash_message']) && $_SESSION['flash_type'] === 'danger'): ?>
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= htmlspecialchars($_SESSION['flash_message']) ?>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); endif; ?>

                <form method="post" class="quiz-form" id="quizForm" onsubmit="return false;">
                    <input type="hidden" name="csrf_token" id="quizCsrfToken" value="<?= generateCsrfToken() ?>">

                    <?php if ($rdMultiPage): ?>
                        <?php
                        $rdQuizCount = count($lessonQuestions);
                        foreach ($lessonQuestions as $idx => $q):
                            $rdIsLastQuestion = ($idx === $rdQuizCount - 1);
                        ?>
                        <div class="card mb-4 lesson-page"
                             data-page-kind="quiz"
                             data-question-number="<?= $idx + 1 ?>"
                             data-total-questions="<?= $rdQuizCount ?>"
                             hidden
                             role="region"
                             aria-label="Quiz – fråga <?= $idx + 1 ?> av <?= $rdQuizCount ?>">
                            <div class="card-header">
                                <i class="bi bi-question-circle me-2"></i>
                                Quiz <span class="badge bg-secondary ms-2">Fråga <?= $idx + 1 ?> av <?= $rdQuizCount ?></span>
                            </div>
                            <div class="card-body">
                                <?= renderQuizQuestion($q, $idx + 1) ?>
                                <?php if ($rdIsLastQuestion): ?>
                                <div id="quizProgress" class="mt-3 small text-muted"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-question-circle me-2"></i> Quiz
                                <span class="badge bg-secondary ms-2"><?= count($lessonQuestions) ?> <?= count($lessonQuestions) === 1 ? 'fråga' : 'frågor' ?></span>
                            </div>
                            <div class="card-body">
                                <?php foreach ($lessonQuestions as $idx => $q): ?>
                                    <?= renderQuizQuestion($q, $idx + 1) ?>
                                <?php endforeach; ?>
                                <div id="quizProgress" class="mt-3 small text-muted"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
                <?php endif; /* info_page vs quiz */ ?>

                <?php if ($rdMultiPage): ?>
                <nav class="lesson-page-nav" aria-label="Bläddra mellan sidor">
                    <button type="button" class="lesson-page-prev" disabled aria-label="Föregående sida">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        <span>Föregående</span>
                    </button>
                    <span class="lesson-page-indicator" aria-live="polite">
                        Sida <span data-page-current>1</span> av <span data-page-total>1</span>
                    </span>
                    <button type="button" class="lesson-page-next" aria-label="Nästa sida">
                        <span>Nästa</span>
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </button>
                </nav>
                </div><!-- /.lesson-paginator -->
                <?php endif; ?>

                <!-- Interaktions-JS för drag-drop, hotspot etc -->
                <script src="include/js/quiz-client.js" defer></script>
                
                <!-- Progress bar for course completion -->
                <?php
                // Räkna bara riktiga lektioner — infosidor är informativa steg
                // men ingår inte i "antal klara lektioner"-mätaren.
                $allLessons = query("
                    SELECT id FROM " . DB_DATABASE . ".lessons
                    WHERE course_id = ? AND lesson_type = 'lesson'
                    ORDER BY sort_order",
                    [$lesson['course_id']]);

                $totalLessons = count($allLessons);

                $completedLessons = 0;
                foreach ($allLessons as $courseLesson) {
                    $lessonProgress = queryOne("
                        SELECT status FROM " . DB_DATABASE . ".progress
                        WHERE user_id = ? AND lesson_id = ?",
                        [$userId, $courseLesson['id']]);
                    if ($lessonProgress && $lessonProgress['status'] === 'completed') {
                        $completedLessons++;
                    }
                }
                
                // Calculate progress percentage
                $progressPercent = ($totalLessons > 0) ? round(($completedLessons / $totalLessons) * 100) : 0;
                ?>
                
                <div class="progress mt-3 mb-4" style="height: 8px; background-color: white; border: 1px solid #dee2e6;" title="<?= $completedLessons ?> av <?= $totalLessons ?> lektioner avklarade">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $progressPercent ?>%" 
                         aria-valuenow="<?= $progressPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                
                <!-- Extra utrymme under quizrutan -->
                <div class="py-5"></div>
            </main>
        </div>
    </div>
</div>



<script>
// HTML-escape helper to prevent XSS via innerHTML
function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Konfigurera marked om det finns tillgängligt
if (typeof marked !== 'undefined') {
    marked.setOptions({
        breaks: true,
        gfm: true,
        headerIds: false
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Hämta alla nödvändiga element
    const aiInput = document.getElementById('aiInput');
    const aiSendBtn = document.getElementById('aiSendBtn');
    const aiMessages = document.getElementById('aiMessages');
    const aiChatToggle = document.getElementById('aiChatToggle');
    const aiChatBody = document.getElementById('aiChatBody');

    // Funktion för att automatiskt justera höjden på textarean
    function autoResizeTextarea(element) {
        element.style.height = 'auto';
        element.style.height = (element.scrollHeight) + 'px';
    }

	<?php
		if ($rdHasAiChat):
	?>

    // Toggle AI chat
    aiChatToggle.addEventListener('click', function() {
        aiChatBody.classList.toggle('active');
    });

    // Lägg till event listeners för automatisk höjdjustering
    aiInput.addEventListener('input', function() {
        autoResizeTextarea(this);
    });

    // Återställ höjden när meddelandet skickas
    function resetTextareaHeight() {
        aiInput.style.height = 'auto';
        aiInput.rows = 1;
    }

	<?php endif; ?>

    // Add message to chat
    function addMessage(message, isUser = false) {
        const messageDiv = document.createElement('div');
        messageDiv.className = isUser ? 'alert alert-primary mb-3' : 'alert alert-info mb-3';

        if (isUser) {
            // User messages: use textContent (safe)
            messageDiv.textContent = message;
        } else {
            // AI responses: already sanitized by server-side parseMarkdown()
            messageDiv.innerHTML = message;
        }

        aiMessages.appendChild(messageDiv);
        aiMessages.scrollTop = aiMessages.scrollHeight;
    }

    // Uppdatera sendAIMessage funktionen
    function sendAIMessage() {
        const message = aiInput.value.trim();
        if (!message) return;

        // Add user message
        addMessage(message, true);
        aiInput.value = '';
        resetTextareaHeight();  // Återställ höjden

        // Show typing indicator
        const typingIndicator = document.createElement('div');
        typingIndicator.className = 'd-flex justify-content-center mb-3';
        typingIndicator.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Laddar...</span>
            </div>
        `;
        aiMessages.appendChild(typingIndicator);

        // Send to server
        fetch('ai_chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                lesson_id: <?= $lessonId ?>,
                message: message,
                ai_prompt: <?= json_encode(cleanHtml($lesson['ai_prompt'] ?? '')) ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            // Remove typing indicator
            typingIndicator.remove();
            // Add AI response
            addMessage(data.response, false);
        })
        .catch(error => {
            console.error('Error:', error);
            typingIndicator.remove();
            addMessage('Ett fel uppstod. Försök igen senare.', false);
        });
    }

	<?php
		if ($rdHasAiChat):
	?>

    // Event listeners för att skicka meddelande
    aiSendBtn.addEventListener('click', sendAIMessage);
    aiInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendAIMessage();
        }
    });

	<?php endif; ?>

    // Quiz form submission
    const quizForm = document.getElementById('quizForm');
    if (quizForm) {
        quizForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(quizForm);
            
            fetch('lesson.php?id=<?= $lessonId ?><?= $isPreviewMode ? '&preview=1' : '' ?>', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Server response:', data);
                if (data.success) {
                    // Visa framgångsmeddelande
                    const quizSection = document.querySelector('.quiz-section');

                    // Check if this is preview mode
                    if (data.preview_mode) {
                        quizSection.innerHTML = `
                            <div class="text-center">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                                <h3 class="mt-3">Rätt svar!</h3>
                                <p class="text-warning mb-3">
                                    <i class="bi bi-eye-fill"></i> Förhandsgranskningsläge - framsteg sparas inte
                                </p>
                                <a href="admin/edit_lesson.php?id=<?= $lessonId ?>" class="btn btn-primary">
                                    <i class="bi bi-pencil me-1"></i> Tillbaka till redigering
                                </a>
                            </div>
                        `;
                    } else if (data.courseComplete && data.completeUrl) {
                        // Kursen är slutförd — skicka till avslutssidan
                        window.location.href = data.completeUrl;
                        return;
                    } else {
                        let nextLessonHtml = '';
                        if (data.nextLesson) {
                            if (data.nextLesson.available) {
                                nextLessonHtml = `
                                    <div class="d-grid">
                                        <a href="lesson.php?id=${parseInt(data.nextLesson.id)}" class="btn btn-success btn-lg">
                                            <i class="bi bi-arrow-right-circle-fill me-2"></i> Fortsätt till nästa lektion: <strong>${escapeHtml(data.nextLesson.title)}</strong>
                                        </a>
                                    </div>`;
                            } else {
                                const days = data.nextLesson.available_in_days || '?';
                                nextLessonHtml = `
                                    <div class="alert alert-info mt-3">
                                        <i class="bi bi-clock me-2"></i>
                                        Nästa lektion <strong>${escapeHtml(data.nextLesson.title)}</strong> öppnas om <strong>${days} dagar</strong>.
                                        Du får ett mejl när den blir tillgänglig.
                                    </div>`;
                            }
                        } else {
                            nextLessonHtml = '<p class="text-muted">Detta var sista lektionen i denna kurs!</p>';
                        }
                        quizSection.innerHTML = `
                            <div class="text-center">
                                <i class="bi bi-trophy-fill text-success" style="font-size: 3rem;"></i>
                                <h3 class="mt-3">Bra jobbat!</h3>
                                <p class="text-muted mb-3">Du har klarat denna lektion!</p>
                                ${nextLessonHtml}
                            </div>
                        `;

                        // Multipage: signalera till paginatorn att lektionen är klar
                        // så Next-knappen längst ner blir "Nästa lektion →".
                        if (typeof window.lessonPaginatorMarkCompleted === 'function') {
                            window.lessonPaginatorMarkCompleted();
                        }

                        // Visa konfetti vid framgång (bara i normalt läge)
                        setTimeout(() => {
                            try {
                                stimmaConfetti.show({
                                    particleCount: 600,
                                    gravity: 0.6,
                                    spread: 180,
                                    startY: 0.8,
                                    direction: 'up',
                                    colors: [
                                        '#FFC700',
                                        '#FF5252',
                                        '#3377FF',
                                        '#4CAF50',
                                        '#9C27B0',
                                        '#FF9800'
                                    ]
                                });
                            } catch (e) {
                                // Tysta fel
                            }
                        }, 200);
                    }
                } else {
                    // Visa felmeddelande
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger d-flex align-items-center mb-3';
                    var icon = document.createElement('i');
                    icon.className = 'bi bi-exclamation-circle me-2';
                    var msgDiv = document.createElement('div');
                    msgDiv.textContent = data.message;
                    errorDiv.appendChild(icon);
                    errorDiv.appendChild(msgDiv);
                    
                    const quizSection = document.querySelector('.quiz-section');
                    const existingError = quizSection.querySelector('.alert-danger');
                    if (existingError) {
                        existingError.remove();
                    }
                    quizSection.insertBefore(errorDiv, quizSection.querySelector('.quiz-question'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Visa ett generiskt felmeddelande om något går fel
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger d-flex align-items-center mb-3';
                errorDiv.innerHTML = `
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <div>Ett fel uppstod. Försök igen senare.</div>
                `;
                
                const quizSection = document.querySelector('.quiz-section');
                const existingError = quizSection.querySelector('.alert-danger');
                if (existingError) {
                    existingError.remove();
                }
                quizSection.insertBefore(errorDiv, quizSection.querySelector('.quiz-question'));
            });
        });
    }

    // Informationssida: Fortsätt-knapp markerar sidan som visad och
    // navigerar till nästa sida i sort_order.
    var infoBtn = document.getElementById('infoPageContinueBtn');
    if (infoBtn) {
        infoBtn.addEventListener('click', function() {
            var result = document.getElementById('infoPageContinueResult');
            infoBtn.disabled = true;
            infoBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sparar...';
            var fd = new FormData();
            fd.append('mark_info_page_complete', '1');
            fd.append('csrf_token', '<?= generateCsrfToken() ?>');
            fetch('lesson.php?id=<?= (int)$lessonId ?>', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data && data.success) {
                        window.location.href = data.nextPageUrl || 'course.php?id=<?= (int)$lesson['course_id'] ?>';
                    } else {
                        if (result) {
                            result.style.display = 'block';
                            result.className = 'alert alert-danger mt-3';
                            result.textContent = (data && data.message) ? data.message : 'Något gick fel. Försök igen.';
                        }
                        infoBtn.disabled = false;
                        infoBtn.innerHTML = '<i class="bi bi-arrow-right-circle me-1"></i>Fortsätt';
                    }
                })
                .catch(function(){
                    if (result) {
                        result.style.display = 'block';
                        result.className = 'alert alert-danger mt-3';
                        result.textContent = 'Nätverksfel. Försök igen.';
                    }
                    infoBtn.disabled = false;
                    infoBtn.innerHTML = '<i class="bi bi-arrow-right-circle me-1"></i>Fortsätt';
                });
        });
    }
});
</script>

<!-- Multi-page lektion: sidnavigering -->
<script>
(function() {
    var paginator = document.getElementById('lessonPaginator');
    if (!paginator) return;

    // Alla pages: content-slices INSIDE content card + extra-cards (video, chat, quiz/finish)
    // Vi tar dem i dokumentordning så användaren bläddrar:
    // content slice 1 → ... → content slice N → video → AI-chat → quiz/avslut
    var pages = paginator.querySelectorAll('.lesson-page');
    var prevBtn = paginator.querySelector('.lesson-page-prev');
    var nextBtn = paginator.querySelector('.lesson-page-next');
    var indicator = paginator.querySelector('[data-page-current]');
    var totalIndicator = paginator.querySelector('[data-page-total]');
    var total = pages.length;
    var current = 0;

    if (total < 2) return;
    if (totalIndicator) totalIndicator.textContent = String(total);

    // State från servern. Uppdateras dynamiskt när quiz/info-page slutförs.
    var state = {
        lessonCompleted: paginator.dataset.lessonCompleted === '1',
        passMode: paginator.dataset.passMode || 'require_all_correct',
        previewMode: paginator.dataset.previewMode === '1',
        nextId: parseInt(paginator.dataset.nextId || '0', 10),
        nextTitle: paginator.dataset.nextTitle || '',
        nextAvailable: paginator.dataset.nextAvailable === '1'
    };

    // I preview-läget (admin/editor förhandsgranskar) ska alla gates släppas
    // så hela lektionen går att bläddra igenom utan att svara på quiz.
    if (state.previewMode) {
        state.lessonCompleted = true;
    }

    // Publik API: gör state-uppdaterare tillgänglig för quiz-success / info-page
    // continue så Next-knappen kan svänga om till "Nästa lektion" direkt utan reload.
    window.lessonPaginatorMarkCompleted = function() {
        state.lessonCompleted = true;
        refreshNav();
    };

    function pageKind(idx) {
        var p = pages[idx];
        return p ? (p.getAttribute('data-page-kind') || 'content') : 'content';
    }

    function refreshNav() {
        if (prevBtn) prevBtn.disabled = (current === 0);
        if (!nextBtn) return;

        var isLast = (current === total - 1);
        var kind = pageKind(current);
        var p = pages[current];
        var qNum = p ? parseInt(p.getAttribute('data-question-number') || '0', 10) : 0;
        var qTotal = p ? parseInt(p.getAttribute('data-total-questions') || '0', 10) : 0;
        var isQuizPage = (kind === 'quiz' && qNum > 0);
        var isLastQuizPage = (isQuizPage && qNum === qTotal);

        // Reset attributes
        nextBtn.classList.remove('is-finish', 'btn-go-next-lesson');
        nextBtn.removeAttribute('data-go-next-lesson');
        nextBtn.removeAttribute('data-submit-quiz');

        if (state.lessonCompleted && isLast) {
            // Lektionen är klar och vi är på sista sidan → "Nästa lektion"-flöde
            if (state.nextId && state.nextAvailable) {
                nextBtn.classList.add('btn-go-next-lesson');
                nextBtn.setAttribute('data-go-next-lesson', '1');
                nextBtn.disabled = false;
                var title = state.nextTitle ? (': ' + state.nextTitle) : '';
                nextBtn.innerHTML = '<span>Nästa lektion' + escapeHtmlSafe(title) + '</span> <i class="bi bi-arrow-right-circle-fill" aria-hidden="true"></i>';
                nextBtn.setAttribute('aria-label', 'Gå till nästa lektion');
            } else if (state.nextId && !state.nextAvailable) {
                nextBtn.classList.add('is-finish');
                nextBtn.disabled = true;
                nextBtn.innerHTML = '<i class="bi bi-clock-history" aria-hidden="true"></i> <span>Nästa lektion låses upp senare</span>';
                nextBtn.setAttribute('aria-label', 'Nästa lektion är inte upplåst ännu');
            } else {
                nextBtn.classList.add('is-finish');
                nextBtn.disabled = true;
                nextBtn.innerHTML = '<i class="bi bi-check-circle-fill" aria-hidden="true"></i> <span>Sista lektionen klar!</span>';
                nextBtn.setAttribute('aria-label', 'Du har slutfört kursens sista lektion');
            }
        } else if (isQuizPage) {
            // Quiz-fråga: Paginator-Next bläddrar bara — själva svaret tas
            // emot av "Svara"-knappen INNE i frågan (quiz-client.js).
            // Aktivera Next endast när frågan är godkänd (rätt vid
            // require_all_correct, eller besvarad vid any_result).
            var quizOk = isQuestionAnswerOk(p, state.passMode);
            if (state.previewMode) quizOk = true; // preview: bläddra fritt
            nextBtn.disabled = !quizOk;
            if (isLastQuizPage) {
                nextBtn.innerHTML = quizOk
                    ? '<i class="bi bi-check-circle" aria-hidden="true"></i> <span>Klart — gå vidare</span>'
                    : '<i class="bi bi-lock" aria-hidden="true"></i> <span>Svara på frågan för att gå vidare</span>';
                nextBtn.setAttribute('aria-label', quizOk ? 'Klart — gå vidare' : 'Svara på frågan för att gå vidare');
            } else {
                nextBtn.innerHTML = quizOk
                    ? '<span>Nästa fråga</span> <i class="bi bi-chevron-right" aria-hidden="true"></i>'
                    : '<i class="bi bi-lock" aria-hidden="true"></i> <span>Svara på frågan för att gå vidare</span>';
                nextBtn.setAttribute('aria-label', quizOk ? 'Nästa fråga' : 'Svara på frågan för att gå vidare');
            }
        } else if (kind === 'finish' && isLast) {
            // Info-page finish-kort på sista sidan: vänta på "Fortsätt"-klick i kortet
            nextBtn.classList.add('is-finish');
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<i class="bi bi-lock" aria-hidden="true"></i> <span>Markera som klar för att gå vidare</span>';
            nextBtn.setAttribute('aria-label', 'Markera sidan som klar');
        } else if (isLast) {
            // Sista sidan men lektionen är inte klar och ingen quiz/finish
            nextBtn.classList.add('is-finish');
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<i class="bi bi-check-circle" aria-hidden="true"></i> <span>Sista sidan</span>';
            nextBtn.setAttribute('aria-label', 'Du är på sista sidan');
        } else {
            // Vanlig "Nästa"-knapp
            nextBtn.disabled = false;
            nextBtn.innerHTML = '<span>Nästa</span> <i class="bi bi-chevron-right" aria-hidden="true"></i>';
            nextBtn.setAttribute('aria-label', 'Nästa sida');
        }
    }

    function escapeHtmlSafe(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    var contentCard = paginator.querySelector('.lesson-content-card');

    function show(idx) {
        if (idx < 0 || idx >= total) return;
        pages.forEach(function(p, i) {
            var isActive = (i === idx);
            p.classList.toggle('active', isActive);
            if (isActive) {
                p.removeAttribute('hidden');
            } else {
                p.setAttribute('hidden', '');
            }
        });
        current = idx;
        if (indicator) indicator.textContent = String(idx + 1);

        // Göm hela lesson content card (titel + bild + content) när active
        // page är något annat än content — annars skulle quiz/AI-chat/video
        // få lektionens stora bild ovanför sig.
        if (contentCard) {
            if (pageKind(idx) === 'content') {
                contentCard.removeAttribute('hidden');
            } else {
                contentCard.setAttribute('hidden', '');
            }
        }

        refreshNav();

        // Scrolla till toppen av paginatorn så ny sida börjar synligt
        try {
            paginator.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {}
    }

    if (prevBtn) prevBtn.addEventListener('click', function() {
        if (current > 0) show(current - 1);
    });

    // Spår av rätt-svarade frågor (per question-id). Fungerar oberoende av
    // DOM-state så vi inte tappar info om en klass av nån anledning inte
    // appliceras. Initieras från redan-satta border-success/danger-klasser
    // ifall sidan reloadats med svar i session.
    var quizAnswered = {};

    function refreshQuizAnsweredFromDom() {
        paginator.querySelectorAll('.lesson-page[data-page-kind="quiz"] .quiz-question').forEach(function(q) {
            var qid = q.getAttribute('data-question-id');
            if (!qid) return;
            if (q.classList.contains('border-success')) {
                quizAnswered[qid] = 'correct';
            } else if (q.classList.contains('border-danger')) {
                quizAnswered[qid] = 'wrong';
            }
        });
    }
    refreshQuizAnsweredFromDom();

    // Avgör om aktuell quiz-fråga "går vidare-OK".
    function isQuestionAnswerOk(card, passMode) {
        if (!card) return false;
        var q = card.querySelector('.quiz-question');
        if (!q) return false;
        // Direkt DOM-check (snabb path)
        if (q.classList.contains('border-success')) return true;
        if (passMode === 'any_result' && q.classList.contains('border-danger')) return true;
        // Fallback via spår
        var qid = q.getAttribute('data-question-id');
        if (qid) {
            if (quizAnswered[qid] === 'correct') return true;
            if (passMode === 'any_result' && quizAnswered[qid] === 'wrong') return true;
        }
        return false;
    }

    // Lyssna på quiz-svar för att uppdatera Next-knappen direkt när
    // användaren klickar "Svara" inom frågan. quiz-client.js skickar event
    // 'quiz-question-graded' efter varje server-bedömning.
    document.addEventListener('quiz-question-graded', function(ev) {
        var d = (ev && ev.detail) || {};
        if (d.questionId) {
            quizAnswered[d.questionId] = d.correct ? 'correct' : 'wrong';
        }
        if (d.allDone) {
            state.lessonCompleted = true;
            if (d.nextLesson) {
                state.nextId = parseInt(d.nextLesson.id, 10) || 0;
                state.nextTitle = d.nextLesson.title || '';
                state.nextAvailable = !!d.nextLesson.available;
            }
        }
        refreshNav();
    });

    // Backup: observera class-ändringar på quiz-questions (om quiz-client.js
    // skulle ändra klasser utan att dispatcha event av någon anledning).
    if (typeof MutationObserver !== 'undefined') {
        var quizObserver = new MutationObserver(function(mutations) {
            var changed = false;
            mutations.forEach(function(m) {
                if (m.type === 'attributes' && m.attributeName === 'class') {
                    var el = m.target;
                    var qid = el.getAttribute && el.getAttribute('data-question-id');
                    if (!qid) return;
                    if (el.classList.contains('border-success')) {
                        if (quizAnswered[qid] !== 'correct') { quizAnswered[qid] = 'correct'; changed = true; }
                    } else if (el.classList.contains('border-danger')) {
                        if (quizAnswered[qid] !== 'wrong') { quizAnswered[qid] = 'wrong'; changed = true; }
                    }
                }
            });
            if (changed) refreshNav();
        });
        paginator.querySelectorAll('.lesson-page[data-page-kind="quiz"] .quiz-question').forEach(function(q) {
            quizObserver.observe(q, { attributes: true, attributeFilter: ['class'] });
        });
    }

    if (nextBtn) nextBtn.addEventListener('click', function() {
        // "Nästa lektion" → navigera direkt. Preview behåller &preview=1.
        if (nextBtn.getAttribute('data-go-next-lesson') === '1' && state.nextId) {
            var url = 'lesson.php?id=' + encodeURIComponent(state.nextId);
            if (state.previewMode) url += '&preview=1';
            window.location.href = url;
            return;
        }
        if (current < total - 1) show(current + 1);
    });

    // Tangentbordsstöd: pil-vänster/höger när inte i input
    document.addEventListener('keydown', function(e) {
        var inField = ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(e.target.tagName) !== -1;
        if (inField || e.target.isContentEditable) return;
        if (e.key === 'ArrowLeft' && current > 0) {
            show(current - 1);
        } else if (e.key === 'ArrowRight' && !nextBtn.disabled && current < total - 1) {
            show(current + 1);
        }
    });

    show(0);
})();
</script>

<?php include 'include/footer.php'; ?>