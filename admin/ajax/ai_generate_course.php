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
    case 'ask_questions':
        askQuestions();
        break;
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
 * Ask AI for follow-up questions based on course description
 */
function askQuestions() {
    // Validera CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
        exit;
    }

    $courseName = trim($_POST['course_name'] ?? '');
    $courseDescription = trim($_POST['course_description'] ?? '');
    $tone = trim($_POST['tone'] ?? 'pedagogical');
    $targetAudience = trim($_POST['target_audience'] ?? '');
    $difficultyLevel = $_POST['difficulty_level'] ?? 'beginner';

    if (empty($courseName) || empty($courseDescription)) {
        echo json_encode(['success' => false, 'message' => 'Kursnamn och beskrivning krävs.']);
        exit;
    }

    // Build prompt for GPT-4o-mini to generate follow-up questions
    $systemPrompt = 'Du är en expert på att skapa utbildningsmaterial. Analysera kursbeskrivningen nedan och ställ 2-4 korta, relevanta följdfrågor som hjälper dig skapa en bättre och mer anpassad kurs.

Frågorna ska hjälpa till att förtydliga:
- Specifika ämnesområden eller fokusområden
- Praktiska eller teoretiska inslag
- Förkunskaper hos målgruppen
- Önskat djup eller bredd i materialet

Svara ENDAST med giltig JSON, ingen annan text. Formatet ska vara en array med objekt:
[
  {"question": "Frågan här?", "placeholder": "Exempelsvar..."},
  {"question": "Frågan här?", "placeholder": "Exempelsvar..."}
]';

    $userPrompt = "Kursnamn: {$courseName}\nBeskrivning: {$courseDescription}";
    if (!empty($targetAudience)) {
        $userPrompt .= "\nMålgrupp: {$targetAudience}";
    }
    $userPrompt .= "\nSvårighetsnivå: {$difficultyLevel}";
    $userPrompt .= "\nTonalitet: {$tone}";

    try {
        $response = callOpenAIMini($systemPrompt, $userPrompt);

        if (!$response) {
            echo json_encode(['success' => false, 'message' => 'Kunde inte generera frågor från AI.']);
            exit;
        }

        // Parse JSON response
        $questions = json_decode($response, true);

        // Try to extract JSON array from response if direct parse failed
        if (!is_array($questions)) {
            if (preg_match('/\[[\s\S]*\]/', $response, $matches)) {
                $questions = json_decode($matches[0], true);
            }
        }

        if (!is_array($questions) || empty($questions)) {
            echo json_encode(['success' => false, 'message' => 'Kunde inte tolka AI-svaret.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'questions' => $questions
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Fel vid AI-kommunikation: ' . $e->getMessage()]);
    }
}

/**
 * Call OpenAI API with GPT-4o-mini for quick tasks
 */
function callOpenAIMini($systemPrompt, $userPrompt) {
    $apiServer = defined('AI_SERVER') && AI_SERVER ? AI_SERVER : 'https://api.openai.com/v1/chat/completions';
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    $model = 'gpt-4o-mini';

    if (empty($apiKey)) {
        throw new Exception('AI API-nyckel saknas i konfigurationen.');
    }

    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'max_tokens' => 1024,
        'temperature' => 0.7
    ];

    $ch = curl_init($apiServer);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("cURL-fel: {$curlError}");
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? $response;
        throw new Exception("AI API returnerade felkod: {$httpCode} - {$errorMsg}");
    }

    $result = json_decode($response, true);

    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    }

    throw new Exception('Oväntat svar från AI API.');
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

    // New settings fields
    $tone = trim($_POST['tone'] ?? 'pedagogical');
    $colorTheme = trim($_POST['color_theme'] ?? '#007bff');
    $targetAudience = trim($_POST['target_audience'] ?? '');
    $languageStyle = trim($_POST['language_style'] ?? 'formal');
    $generateImages = isset($_POST['generate_images']) && $_POST['generate_images'] === '1';
    $textLength = trim($_POST['text_length'] ?? 'medium');
    $aiQuestions = $_POST['ai_questions'] ?? null;
    $aiAnswers = $_POST['ai_answers'] ?? null;

    // Validate new fields
    if (!in_array($tone, ['pedagogical', 'formal', 'casual', 'inspiring'])) {
        $tone = 'pedagogical';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorTheme)) {
        $colorTheme = '#007bff';
    }
    if (!in_array($languageStyle, ['formal', 'informal', 'academic', 'conversational'])) {
        $languageStyle = 'formal';
    }
    if (!in_array($textLength, ['short', 'medium', 'long'])) {
        $textLength = 'medium';
    }

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

    // Get dynamic max lesson count from settings
    $maxLessonSetting = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'max_lesson_count'");
    $maxLessonCount = (int)($maxLessonSetting['setting_value'] ?? 20);
    if ($maxLessonCount < 1) $maxLessonCount = 20;

    if ($lessonCount < 1 || $lessonCount > $maxLessonCount) {
        echo json_encode(['success' => false, 'message' => "Antal lektioner måste vara mellan 1 och {$maxLessonCount}."]);
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
          tone, color_theme, target_audience, language_style, generate_images, text_length,
          ai_questions, ai_answers,
          status, progress_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'Väntar på bearbetning...')",
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
            $includeAiTutor ? 1 : 0,
            $tone,
            $colorTheme,
            $targetAudience ?: null,
            $languageStyle,
            $generateImages ? 1 : 0,
            $textLength,
            $aiQuestions ?: null,
            $aiAnswers ?: null
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
