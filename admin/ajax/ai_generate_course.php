<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * AI Course Generation API
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Kontrollera att användaren är inloggad
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Du måste vara inloggad.']);
    exit;
}

// Hämta användarinfo
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'Användare hittades inte.']);
    exit;
}

$isAdmin = $currentUser['is_admin'] == 1;
$isEditor = $currentUser['is_editor'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';

// Kontrollera behörighet
if (!$isAdmin && !$isEditor && !$isSuperAdmin) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att generera kurser.']);
    exit;
}

$userDomain = substr(strrchr($currentUser['email'], "@"), 1);

// Hantera olika actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create_job':
        createJob();
        break;
    case 'get_status':
        getJobStatus();
        break;
    case 'get_jobs':
        getUserJobs();
        break;
    case 'cancel_job':
        cancelJob();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Ogiltig åtgärd.']);
}

/**
 * Skapa ett nytt AI-genereringsjobb
 */
function createJob() {
    global $currentUser, $userDomain;

    // Validera CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
        exit;
    }

    // Hämta och validera input
    $courseName = trim($_POST['course_name'] ?? '');
    $courseDescription = trim($_POST['course_description'] ?? '');
    $lessonCount = (int)($_POST['lesson_count'] ?? 5);
    $includeQuiz = isset($_POST['include_quiz']) && $_POST['include_quiz'] === '1';
    $includeVideoLinks = isset($_POST['include_video_links']) && $_POST['include_video_links'] === '1';
    $imageOption = $_POST['image_option'] ?? 'none';
    $difficultyLevel = $_POST['difficulty_level'] ?? 'beginner';
    $includeAiTutor = isset($_POST['include_ai_tutor']) && $_POST['include_ai_tutor'] === '1';

    // Validering
    if (empty($courseName)) {
        echo json_encode(['success' => false, 'message' => 'Kursnamn är obligatoriskt.']);
        exit;
    }

    if (strlen($courseName) > 255) {
        echo json_encode(['success' => false, 'message' => 'Kursnamnet får max vara 255 tecken.']);
        exit;
    }

    if (empty($courseDescription)) {
        echo json_encode(['success' => false, 'message' => 'Kursbeskrivning är obligatorisk.']);
        exit;
    }

    if ($lessonCount < 1 || $lessonCount > 20) {
        echo json_encode(['success' => false, 'message' => 'Antal lektioner måste vara mellan 1 och 20.']);
        exit;
    }

    if (!in_array($imageOption, ['none', 'internet', 'ai'])) {
        $imageOption = 'none';
    }

    if (!in_array($difficultyLevel, ['beginner', 'intermediate', 'advanced'])) {
        $difficultyLevel = 'beginner';
    }

    // Kontrollera om användaren redan har ett pågående jobb
    $pendingJob = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".ai_course_jobs
         WHERE user_id = ? AND status IN ('pending', 'processing')",
        [$currentUser['id']]
    );

    if ($pendingJob) {
        echo json_encode(['success' => false, 'message' => 'Du har redan ett pågående genereringsjobb. Vänta tills det är klart.']);
        exit;
    }

    // Skapa jobbet
    $result = execute(
        "INSERT INTO " . DB_DATABASE . ".ai_course_jobs
         (user_id, organization_domain, course_name, course_description, lesson_count,
          include_quiz, include_video_links, image_option, difficulty_level, include_ai_tutor,
          status, progress_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'Väntar på bearbetning...')",
        [
            $currentUser['id'],
            $userDomain,
            $courseName,
            $courseDescription,
            $lessonCount,
            $includeQuiz ? 1 : 0,
            $includeVideoLinks ? 1 : 0,
            $imageOption,
            $difficultyLevel,
            $includeAiTutor ? 1 : 0
        ]
    );

    if ($result) {
        $jobId = queryOne("SELECT LAST_INSERT_ID() as id")['id'];

        // Logga aktiviteten
        logActivity($_SESSION['user_email'], 'Startade AI-kursgenerering', [
            'job_id' => $jobId,
            'course_name' => $courseName,
            'lesson_count' => $lessonCount
        ]);

        // Skicka svar till klienten FÖRST
        $response = json_encode([
            'success' => true,
            'message' => 'Genereringsjobb har skapats.',
            'job_id' => $jobId
        ]);

        // Skicka headers för att stänga anslutningen
        header('Content-Length: ' . strlen($response));
        header('Connection: close');
        echo $response;

        // Flush allt till klienten
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        // Stäng sessionen
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Kör processorn synkront efter att klienten fått svar
        set_time_limit(600);
        ignore_user_abort(true);

        $processorPath = __DIR__ . '/../cron/process_ai_jobs.php';
        if (file_exists($processorPath)) {
            $logFile = '/var/www/html/upload/ai_processor_' . date('Y-m-d_H-i-s') . '_job' . $jobId . '.log';
            ob_start();
            try {
                include $processorPath;
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage();
            }
            $output = ob_get_clean();
            file_put_contents($logFile, $output);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Kunde inte skapa genereringsjobbet.']);
    }
}

/**
 * Signalera att bakgrundsprocessorn ska starta
 * Cron-jobbet kör processorn var minut, så vi behöver bara vänta
 * Returnerar alltid true eftersom cron hanterar det
 */
function startBackgroundProcessor() {
    // Cron-jobbet i /etc/cron.d/stimma-ai-processor kör processorn var minut
    // Så vi behöver inte starta något manuellt här
    return true;
}

/**
 * Kör processorn efter att ha stängt anslutningen till klienten
 * Detta gör att klienten får svar direkt medan processorn kör i bakgrunden
 */
function runProcessorAfterResponse() {
    // Sätt längre timeout
    set_time_limit(600);
    ignore_user_abort(true);

    // Stäng anslutningen till klienten
    if (function_exists('fastcgi_finish_request')) {
        // FastCGI - bästa metoden
        fastcgi_finish_request();
    } else {
        // Apache mod_php - manuell flush
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        // Stäng sessionen så andra requests inte blockeras
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    // Nu kör vi processorn - klienten har redan fått sitt svar
    $processorPath = __DIR__ . '/../cron/process_ai_jobs.php';

    if (file_exists($processorPath)) {
        // Logga för debugging
        $logFile = '/var/www/html/upload/ai_processor_' . date('Y-m-d_H-i-s') . '.log';

        ob_start();
        try {
            include $processorPath;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
        $output = ob_get_clean();

        file_put_contents($logFile, $output);
    }
}

/**
 * Hämta status för ett specifikt jobb
 */
function getJobStatus() {
    global $currentUser;

    $jobId = (int)($_GET['job_id'] ?? 0);

    if ($jobId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ogiltigt jobb-ID.']);
        exit;
    }

    $job = queryOne(
        "SELECT * FROM " . DB_DATABASE . ".ai_course_jobs WHERE id = ? AND user_id = ?",
        [$jobId, $currentUser['id']]
    );

    if (!$job) {
        echo json_encode(['success' => false, 'message' => 'Jobbet hittades inte.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'job' => [
            'id' => $job['id'],
            'status' => $job['status'],
            'course_name' => $job['course_name'],
            'progress_percent' => $job['progress_percent'],
            'progress_message' => $job['progress_message'],
            'result_course_id' => $job['result_course_id'],
            'error_message' => $job['error_message'],
            'created_at' => $job['created_at'],
            'completed_at' => $job['completed_at']
        ]
    ]);
}

/**
 * Hämta alla jobb för användaren
 */
function getUserJobs() {
    global $currentUser;

    $jobs = query(
        "SELECT id, status, course_name, progress_percent, progress_message,
                result_course_id, error_message, created_at, completed_at
         FROM " . DB_DATABASE . ".ai_course_jobs
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 10",
        [$currentUser['id']]
    );

    echo json_encode([
        'success' => true,
        'jobs' => $jobs
    ]);
}

/**
 * Avbryt ett väntande jobb
 */
function cancelJob() {
    global $currentUser;

    // Validera CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
        exit;
    }

    $jobId = (int)($_POST['job_id'] ?? 0);

    if ($jobId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ogiltigt jobb-ID.']);
        exit;
    }

    // Kan endast avbryta väntande jobb
    $result = execute(
        "UPDATE " . DB_DATABASE . ".ai_course_jobs
         SET status = 'failed', error_message = 'Avbrutet av användaren', completed_at = NOW()
         WHERE id = ? AND user_id = ? AND status = 'pending'",
        [$jobId, $currentUser['id']]
    );

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Jobbet har avbrutits.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Kunde inte avbryta jobbet.']);
    }
}
