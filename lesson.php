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
            "SELECT organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ?",
            [$lesson['course_id']]
        );
        $courseOrg = $courseRow['organization_domain'] ?? '';
        $orgScope = getOrgScopeDomains($_SESSION['user_email'] ?? '');
        $inOwnOrg = $courseOrg !== '' && in_array($courseOrg, $orgScope, true);
        $allowed = $inOwnOrg || $hasPublic;
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

// Handle sequential course enrollment and access control (skip in preview mode)
if (!$isPreviewMode && !empty($lesson['sequential_mode'])) {
    // Check if this is the first lesson in the course (for enrollment)
    $firstLesson = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1",
        [$lesson['course_id']]
    );

    if ($firstLesson && $firstLesson['id'] == $lessonId) {
        // First lesson - enroll user if not already enrolled
        enrollUserInSequentialCourse($userId, $lesson['course_id']);
    }

    // Check if the lesson is available
    if (!isLessonAvailableForUser($userId, $lessonId, $lesson['course_id'])) {
        $_SESSION['flash_message'] = 'Denna lektion är inte tillgänglig ännu.';
        $_SESSION['flash_type'] = 'warning';
        redirect('index.php');
        exit;
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
 * Check if quiz answer is correct based on quiz type
 */
function checkQuizAnswer($lesson, $post) {
    $quizType = $lesson['quiz_type'] ?? 'single_choice';

    switch ($quizType) {
        case 'multiple_choice':
            // Flerval - jämför valda svar med quiz_correct_answers
            $userAnswers = $post['answers'] ?? [];
            if (!is_array($userAnswers)) {
                $userAnswers = [];
            }
            sort($userAnswers);
            $correctAnswers = array_map('trim', explode(',', $lesson['quiz_correct_answers'] ?? ''));
            sort($correctAnswers);
            return $userAnswers == $correctAnswers;

        case 'single_choice':
        default:
            // Enkelval - jämför med quiz_correct_answer
            $userAnswer = (int)($post['answer'] ?? 0);
            $correctAnswer = (int)$lesson['quiz_correct_answer'];
            return $userAnswer === $correctAnswer;
    }
}

/**
 * Check if a quiz answer was submitted
 */
function hasQuizAnswer($post) {
    return isset($post['answer']) || isset($post['text_answer']) || isset($post['answers']);
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

    $isCorrect = checkQuizAnswer($lesson, $_POST);

    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        if ($isCorrect) {
            echo json_encode([
                'success' => true,
                'preview_mode' => true,
                'message' => 'Rätt svar! (Förhandsgranskningsläge - framsteg sparas inte)'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Fel svar. Försök igen! (Förhandsgranskningsläge)'
            ]);
        }
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

    // Check if answer is correct based on quiz type
    $isCorrect = checkQuizAnswer($lesson, $_POST);
    
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
            echo json_encode([
                'success' => true,
                'nextLesson' => $nextLessonData
            ]);
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
        // Handle incorrect answer
        $message = 'Fel svar. Försök igen!';
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
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

<!-- Main content container -->
<div class="container-sm py-4">
    <div class="row">
        <!-- Left sidebar (empty on desktop) -->
        <div class="col-lg-2 d-none d-lg-block"></div>
        
        <!-- Main content area -->
        <div class="col-12 col-lg-8">
            <main>
                <!-- Lesson content card -->
                <div class="card mb-4"<?php if (!empty($lesson['background_color'])): ?> style="background-color: <?= htmlspecialchars($lesson['background_color']) ?>"<?php endif; ?>>
                    <div class="card-body">
                        <!-- Flash message display -->
                        <?php if (isset($_SESSION['flash_message']) && $_SESSION['flash_type'] !== 'danger'): ?>
                        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>" role="alert">
                            <?= htmlspecialchars($_SESSION['flash_message']) ?>
                        </div>
                        <?php 
                            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
                        endif; ?>
                        
                        <!-- Completion status badge -->
                        <?php if ($isCompleted): ?>
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
                                <div class="content clearfix">
                                    <?= cleanHtml($lesson['content']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Video Section -->
                <?php if (!empty($lesson['video_url'])): ?>
                <div class="card mb-4">
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
                <?php if (!empty($lesson['ai_instruction']) || !empty($lesson['ai_prompt'])): ?>
                <div class="card mb-4">
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
                
                <!-- Quiz-sektion -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-question-circle me-2"></i> Quiz
                    </div>
                    <div class="card-body">
                        <?php if (!empty($lesson['quiz_question'])): ?>
                            <div class="quiz-section mb-4">
                                <?php if (isset($_SESSION['flash_message']) && $_SESSION['flash_type'] === 'danger'): ?>
                                <div class="alert alert-danger mb-3">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <?= htmlspecialchars($_SESSION['flash_message']) ?>
                                </div>
                                <?php 
                                    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
                                endif; ?>
                                <div class="quiz-question mb-3">
                                    <?= cleanHtml($lesson['quiz_question']) ?>
                                </div>
                                <form method="post" class="quiz-form" id="quizForm">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="quiz_type" value="<?= htmlspecialchars($lesson['quiz_type'] ?? 'single_choice') ?>">
                                    <div class="quiz-options">
                                        <?php
                                        $quizType = $lesson['quiz_type'] ?? 'single_choice';

                                        if ($quizType === 'multiple_choice'):
                                            // Flerval - visa checkboxar
                                            $answers = [
                                                1 => $lesson['quiz_answer1'],
                                                2 => $lesson['quiz_answer2'],
                                                3 => $lesson['quiz_answer3'],
                                                4 => $lesson['quiz_answer4'] ?? null,
                                                5 => $lesson['quiz_answer5'] ?? null
                                            ];
                                            foreach ($answers as $key => $answer):
                                                if (!empty($answer)):
                                        ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="answers[]" id="answer<?= $key ?>" value="<?= $key ?>">
                                            <label class="form-check-label" for="answer<?= $key ?>">
                                                <?= cleanHtml($answer) ?>
                                            </label>
                                        </div>
                                        <?php
                                                endif;
                                            endforeach;
                                        ?>
                                        <small class="text-muted">Välj ett eller flera alternativ</small>
                                        <?php
                                        else:
                                            // Enkelval (single_choice) - visa radioknappar
                                            $answers = [
                                                1 => $lesson['quiz_answer1'],
                                                2 => $lesson['quiz_answer2'],
                                                3 => $lesson['quiz_answer3'],
                                                4 => $lesson['quiz_answer4'] ?? null,
                                                5 => $lesson['quiz_answer5'] ?? null
                                            ];
                                            foreach ($answers as $key => $answer):
                                                if (!empty($answer)):
                                        ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="answer" id="answer<?= $key ?>" value="<?= $key ?>" required>
                                            <label class="form-check-label" for="answer<?= $key ?>">
                                                <?= cleanHtml($answer) ?>
                                            </label>
                                        </div>
                                        <?php
                                                endif;
                                            endforeach;
                                        endif;
                                        ?>
                                    </div>
                                    <button type="submit" class="btn btn-primary mt-3">Skicka svar</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Inget quiz tillgängligt för denna lektion.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Progress bar for course completion -->
                <?php
                // Get all lessons in this course
                $allLessons = query("
                    SELECT id FROM " . DB_DATABASE . ".lessons 
                    WHERE course_id = ? 
                    ORDER BY sort_order", 
                    [$lesson['course_id']]);
                
                // Count total lessons in the course
                $totalLessons = count($allLessons);
                
                // Count completed lessons
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
        <div class="col-lg-2 d-none d-lg-block"></div>
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
		if (!empty($lesson['ai_instruction']) || !empty($lesson['ai_prompt'])):
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
		if (!empty($lesson['ai_instruction']) || !empty($lesson['ai_prompt'])):
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
});
</script>



<?php include 'include/footer.php'; ?>