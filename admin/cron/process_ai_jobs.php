<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Background process for AI course generation
 * Run via cron or manually: php process_ai_jobs.php
 */

// Prevent timeout for long-running processes
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../../include/config.php';
require_once __DIR__ . '/../../include/database.php';
require_once __DIR__ . '/../../include/functions.php';

// Lock file to prevent multiple instances
$lockFile = sys_get_temp_dir() . '/stimma_ai_job_processor.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    // If lock is older than 30 minutes, remove it (stale lock)
    if (time() - $lockTime > 1800) {
        unlink($lockFile);
    } else {
        // Another process is running, just return
        return;
    }
}

// Create lock file
file_put_contents($lockFile, getmypid());
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

echo "AI Job Processor started at " . date('Y-m-d H:i:s') . "\n";

// Automatiskt markera jobb som fastnat i processing för länge (mer än 30 minuter)
cleanupStuckJobs();

// Process pending jobs
while (true) {
    // Get next pending job
    $job = queryOne(
        "SELECT * FROM " . DB_DATABASE . ".ai_course_jobs
         WHERE status = 'pending'
         ORDER BY created_at ASC
         LIMIT 1"
    );

    if (!$job) {
        echo "No pending jobs found.\n";
        break;
    }

    echo "Processing job {$job['id']}: {$job['course_name']}\n";

    try {
        processJob($job);
    } catch (Exception $e) {
        echo "Error processing job {$job['id']}: " . $e->getMessage() . "\n";
        updateJobStatus($job['id'], 'failed', 0, 'Ett fel uppstod: ' . $e->getMessage());
    }
}

echo "AI Job Processor finished at " . date('Y-m-d H:i:s') . "\n";

/**
 * Rensa upp jobb som fastnat i processing-status för länge
 */
function cleanupStuckJobs() {
    $stuckJobs = query(
        "SELECT id, course_name FROM " . DB_DATABASE . ".ai_course_jobs
         WHERE status IN ('pending', 'processing')
         AND created_at < NOW() - INTERVAL 30 MINUTE"
    );

    if (empty($stuckJobs)) {
        return;
    }

    echo "Found " . count($stuckJobs) . " stuck jobs, marking as failed...\n";

    foreach ($stuckJobs as $job) {
        execute(
            "UPDATE " . DB_DATABASE . ".ai_course_jobs
             SET status = 'failed', error_message = 'Jobbet avbröts (timeout efter 30 minuter)', completed_at = NOW()
             WHERE id = ?",
            [$job['id']]
        );
        echo "  - Marked job {$job['id']} ({$job['course_name']}) as failed\n";
    }
}

/**
 * Get custom course generation prompt from settings or use default
 */
function getCourseGenerationPrompt() {
    $setting = queryOne(
        "SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'course_generation_prompt'"
    );

    if ($setting && !empty($setting['setting_value'])) {
        return $setting['setting_value'];
    }

    // Default prompt with varied quiz types
    return 'Du är en expert på att skapa utbildningsmaterial. Du ska generera en komplett kurs i JSON-format.

VIKTIGT: Svara ENDAST med giltig JSON, ingen annan text före eller efter.

Kursen ska:
- Ha exakt {{lesson_count}} lektioner
- Vara på {{difficulty_level}}-nivå
- Vara på svenska
- Ha pedagogiskt strukturerat innehåll med tydliga rubriker och stycken
- Innehållet ska vara i HTML-format med <h3>, <p>, <ul>, <li>, <strong> taggar

VIKTIGT FÖR LEKTIONSINNEHÅLL:
- Varje lektion ska ha MINST 400-600 ord med detaljerat och beskrivande innehåll
- Inkludera praktiska exempel, tips och förklaringar
- Använd underrubriker (<h3>) för att strukturera innehållet
- Inkludera punktlistor (<ul><li>) för att sammanfatta viktiga punkter
- Lägg till konkreta råd och steg-för-steg instruktioner där det passar
- Gör innehållet engagerande och lätt att förstå
- Avsluta varje lektion med en kort sammanfattning eller nyckelinsikter

VIKTIGT FÖR QUIZ:
För varje lektion ska du skapa ett quiz. VARIERA frågetyperna mellan lektionerna:
- single_choice: Enkelval med 3-5 svarsalternativ (ett rätt svar)
- multiple_choice: Flerval med 4-5 svarsalternativ (flera rätta svar, ange i quiz_correct_answers som "1,3" eller "2,4,5")

Riktlinjer för quiz:
- Sprid korrekta svar jämnt över positionerna (inte alltid samma position)
- Gör distraktorer (felaktiga svar) rimliga och lärorika
- Använd mestadels single_choice, men inkludera några multiple_choice för variation

JSON-strukturen ska vara:
{
  "course": {
    "title": "Kursnamn",
    "description": "Kursbeskrivning",
    "difficulty_level": "{{difficulty}}",
    "duration_minutes": <total tid i minuter>,
    "prerequisites": null,
    "tags": null,
    "status": "inactive",
    "sort_order": 0,
    "featured": 0
  },
  "lessons": [
    {
      "title": "Lektionsnamn",
      "estimated_duration": <minuter>,
      "content": "<h3>Rubrik</h3><p>Innehåll...</p>",
      "video_url": null,
      "resource_links": null,
      "tags": null,
      "status": "active",
      "sort_order": <nummer>,
      "ai_instruction": {{ai_instruction_value}},
      "ai_prompt": {{ai_prompt_value}},
      "quiz_type": "single_choice|multiple_choice",
      "quiz_question": "Fråga om lektionens innehåll?",
      "quiz_answer1": "Svarsalternativ 1",
      "quiz_answer2": "Svarsalternativ 2",
      "quiz_answer3": "Svarsalternativ 3",
      "quiz_answer4": "Svarsalternativ 4 (valfritt)",
      "quiz_answer5": "Svarsalternativ 5 (valfritt)",
      "quiz_correct_answer": 2,
      "quiz_correct_answers": null
    }
  ]
}';
}

/**
 * Process a single AI generation job
 */
function processJob($job) {
    $jobId = $job['id'];

    // Mark as processing
    execute(
        "UPDATE " . DB_DATABASE . ".ai_course_jobs
         SET status = 'processing', started_at = NOW(), progress_percent = 5, progress_message = 'Startar AI-generering...'
         WHERE id = ?",
        [$jobId]
    );

    // Build the AI prompt
    $difficultyText = [
        'beginner' => 'nybörjare',
        'intermediate' => 'mellannivå',
        'advanced' => 'avancerad'
    ][$job['difficulty_level']] ?? 'nybörjare';

    $lessonCount = $job['lesson_count'];

    // Get custom prompt template or use default
    $promptTemplate = getCourseGenerationPrompt();

    // Replace placeholders
    $aiInstructionValue = $job['include_ai_tutor'] ? '"Instruktion för AI-handledare..."' : 'null';
    $aiPromptValue = $job['include_ai_tutor'] ? '"Prompt för AI-handledare..."' : 'null';

    $systemPrompt = str_replace(
        ['{{lesson_count}}', '{{difficulty_level}}', '{{difficulty}}', '{{ai_instruction_value}}', '{{ai_prompt_value}}'],
        [$lessonCount, $difficultyText, $job['difficulty_level'], $aiInstructionValue, $aiPromptValue],
        $promptTemplate
    );

    // Add quiz-specific instructions if not included in custom prompt
    if ($job['include_quiz'] && strpos($systemPrompt, 'quiz_type') === false) {
        $systemPrompt .= "\n\nVIKTIGT FÖR QUIZ: För varje lektion, skapa ett quiz. VARIERA frågetyperna (single_choice, multiple_choice). Sprid korrekta svar jämnt över positionerna.";
    }

    // Add AI tutor instructions if needed and not in custom prompt
    if ($job['include_ai_tutor'] && strpos($systemPrompt, 'ai_instruction') === false) {
        $systemPrompt .= "\n\nFör varje lektion, skapa ai_instruction (instruktioner för AI-handledaren) och ai_prompt (startprompt för dialog med studenten).";
    }

    $userPrompt = "Skapa en kurs med namnet \"{$job['course_name']}\" baserat på följande beskrivning:\n\n{$job['course_description']}\n\nKursen ska ha exakt {$lessonCount} lektioner.";

    updateJobStatus($jobId, 'processing', 10, 'Skickar förfrågan till AI...');

    // Call OpenAI API
    $courseJson = callOpenAI($systemPrompt, $userPrompt);

    if (!$courseJson) {
        throw new Exception('Kunde inte generera kursinnehåll från AI.');
    }

    updateJobStatus($jobId, 'processing', 40, 'Bearbetar AI-svar...');

    // Parse JSON response
    $courseData = json_decode($courseJson, true);

    if (!$courseData || !isset($courseData['course']) || !isset($courseData['lessons'])) {
        // Try to extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $courseJson, $matches)) {
            $courseData = json_decode($matches[0], true);
        }

        if (!$courseData || !isset($courseData['course'])) {
            throw new Exception('Kunde inte tolka AI-svaret som giltig JSON.');
        }
    }

    // Override course name with user-specified name
    $courseData['course']['title'] = $job['course_name'];

    // Randomize quiz answers if quiz is included
    if ($job['include_quiz']) {
        foreach ($courseData['lessons'] as &$lesson) {
            if (!empty($lesson['quiz_question']) && !empty($lesson['quiz_answer1'])) {
                // Get current answers and correct answer index
                $answers = [
                    $lesson['quiz_answer1'],
                    $lesson['quiz_answer2'],
                    $lesson['quiz_answer3']
                ];
                $correctIndex = ($lesson['quiz_correct_answer'] ?? 1) - 1; // Convert to 0-based
                $correctAnswer = $answers[$correctIndex] ?? $answers[0];

                // Shuffle the answers
                shuffle($answers);

                // Find new position of correct answer (1-based)
                $newCorrectPosition = array_search($correctAnswer, $answers) + 1;

                // Update lesson with shuffled answers
                $lesson['quiz_answer1'] = $answers[0];
                $lesson['quiz_answer2'] = $answers[1];
                $lesson['quiz_answer3'] = $answers[2];
                $lesson['quiz_correct_answer'] = $newCorrectPosition;
            }
        }
        unset($lesson); // Break reference
    }

    updateJobStatus($jobId, 'processing', 50, 'Söker efter videolänkar...');

    // Add YouTube links if requested
    if ($job['include_video_links']) {
        foreach ($courseData['lessons'] as $index => &$lesson) {
            $videoUrl = searchYouTube($lesson['title'] . ' ' . $job['course_name']);
            if ($videoUrl) {
                $lesson['video_url'] = $videoUrl;
            }
            updateJobStatus($jobId, 'processing', 50 + (($index + 1) / count($courseData['lessons']) * 20),
                "Söker video för lektion " . ($index + 1) . "...");
        }
    }

    updateJobStatus($jobId, 'processing', 80, 'Importerar kursen...');

    // Import the course
    $courseId = importCourse($courseData, $job['user_id'], $job['organization_domain']);

    if (!$courseId) {
        throw new Exception('Kunde inte importera kursen till databasen.');
    }

    // Save generated JSON and mark as completed
    execute(
        "UPDATE " . DB_DATABASE . ".ai_course_jobs
         SET status = 'completed', progress_percent = 100, progress_message = 'Kursen har skapats!',
             generated_json = ?, result_course_id = ?, completed_at = NOW()
         WHERE id = ?",
        [json_encode($courseData, JSON_UNESCAPED_UNICODE), $courseId, $jobId]
    );

    echo "Job {$jobId} completed successfully. Course ID: {$courseId}\n";
}

/**
 * Update job status
 */
function updateJobStatus($jobId, $status, $progress, $message) {
    execute(
        "UPDATE " . DB_DATABASE . ".ai_course_jobs
         SET status = ?, progress_percent = ?, progress_message = ?
         WHERE id = ?",
        [$status, $progress, $message, $jobId]
    );
}

/**
 * Call OpenAI API
 */
function callOpenAI($systemPrompt, $userPrompt) {
    // Use defined constants from config.php
    $apiServer = defined('AI_SERVER') && AI_SERVER ? AI_SERVER : 'https://api.openai.com/v1/chat/completions';
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    // Use gpt-4o for larger context window and output
    $model = 'gpt-4o';

    if (empty($apiKey)) {
        throw new Exception('AI API-nyckel saknas i konfigurationen.');
    }

    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'max_tokens' => 16384,
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout

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
 * Search YouTube for relevant video
 */
function searchYouTube($query) {
    $apiKey = getenv('YOUTUBE_API_KEY') ?: '';

    if (empty($apiKey)) {
        // Return null if no API key - videos will be skipped
        return null;
    }

    $query = urlencode($query . ' tutorial swedish');
    $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&q={$query}&type=video&maxResults=1&key={$apiKey}";

    $response = @file_get_contents($url);
    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);

    if (isset($data['items'][0]['id']['videoId'])) {
        return 'https://www.youtube.com/watch?v=' . $data['items'][0]['id']['videoId'];
    }

    return null;
}

/**
 * Search for an image from the internet
 */
function searchImage($query) {
    // Using Unsplash API (free tier)
    $accessKey = getenv('UNSPLASH_ACCESS_KEY') ?: '';

    if (empty($accessKey)) {
        return null;
    }

    $query = urlencode($query);
    $url = "https://api.unsplash.com/search/photos?query={$query}&per_page=1&client_id={$accessKey}";

    $response = @file_get_contents($url);
    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);

    if (isset($data['results'][0]['urls']['regular'])) {
        return $data['results'][0]['urls']['regular'];
    }

    return null;
}

/**
 * Generate AI image using DALL-E
 */
function generateAIImage($lessonTitle, $courseName) {
    // Use defined constant from config.php
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    $imageApiServer = 'https://api.openai.com/v1/images/generations';

    if (empty($apiKey)) {
        echo "  - No API key for image generation\n";
        return null;
    }

    $prompt = "Educational illustration for a lesson about '{$lessonTitle}' in a course about '{$courseName}'. Clean, professional, minimalist style suitable for e-learning. No text in image.";

    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'n' => 1,
        'size' => '1024x1024',
        'quality' => 'standard'
    ];

    echo "  - Generating DALL-E image for: {$lessonTitle}\n";

    $ch = curl_init($imageApiServer);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo "  - cURL error: {$curlError}\n";
        return null;
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Unknown error';
        echo "  - DALL-E API error ({$httpCode}): {$errorMsg}\n";
        return null;
    }

    $result = json_decode($response, true);

    if (isset($result['data'][0]['url'])) {
        // Download and save image locally
        $imageUrl = $result['data'][0]['url'];
        $imageContent = @file_get_contents($imageUrl);

        if ($imageContent) {
            $uploadDir = __DIR__ . '/../../upload/';

            // Ensure upload directory exists
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = 'ai_' . uniqid() . '.png';
            $filePath = $uploadDir . $fileName;

            if (file_put_contents($filePath, $imageContent)) {
                echo "  - Image saved: {$fileName}\n";
                return $fileName;
            } else {
                echo "  - Failed to save image to: {$filePath}\n";
            }
        } else {
            echo "  - Failed to download image from URL\n";
        }
    }

    return null;
}

/**
 * Import course data into database
 */
function importCourse($courseData, $userId, $organizationDomain) {
    try {
        execute("START TRANSACTION");

        // Get max sort_order
        $maxOrder = queryOne("SELECT MAX(sort_order) as max_order FROM " . DB_DATABASE . ".courses")['max_order'] ?? 0;

        // Create course
        execute(
            "INSERT INTO " . DB_DATABASE . ".courses
             (title, description, difficulty_level, duration_minutes, prerequisites, tags,
              image_url, status, sort_order, featured, author_id, organization_domain, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'inactive', ?, ?, ?, ?, NOW(), NOW())",
            [
                $courseData['course']['title'],
                $courseData['course']['description'] ?? '',
                $courseData['course']['difficulty_level'] ?? 'beginner',
                $courseData['course']['duration_minutes'] ?? 0,
                $courseData['course']['prerequisites'] ?? null,
                $courseData['course']['tags'] ?? null,
                $courseData['course']['image_url'] ?? null,
                $maxOrder + 1,
                $courseData['course']['featured'] ?? 0,
                $userId,
                $organizationDomain
            ]
        );

        $courseId = queryOne("SELECT LAST_INSERT_ID() as id")['id'];

        // Add user as course editor
        execute(
            "INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by)
             SELECT ?, email, id FROM " . DB_DATABASE . ".users WHERE id = ?",
            [$courseId, $userId]
        );

        // Create lessons
        if (isset($courseData['lessons']) && is_array($courseData['lessons'])) {
            foreach ($courseData['lessons'] as $index => $lesson) {
                // Determine quiz type, default to single_choice for backwards compatibility
                $quizType = $lesson['quiz_type'] ?? 'single_choice';
                // Validate quiz type
                $validTypes = ['single_choice', 'multiple_choice', 'drag_drop', 'image_choice'];
                if (!in_array($quizType, $validTypes)) {
                    $quizType = 'single_choice';
                }

                execute(
                    "INSERT INTO " . DB_DATABASE . ".lessons
                     (course_id, title, estimated_duration, image_url, video_url, content,
                      resource_links, tags, status, sort_order, ai_instruction, ai_prompt,
                      quiz_type, quiz_question, quiz_answer1, quiz_answer2, quiz_answer3, quiz_answer4, quiz_answer5,
                      quiz_correct_answer, quiz_correct_answers,
                      author_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $courseId,
                        $lesson['title'] ?? 'Lektion ' . ($index + 1),
                        $lesson['estimated_duration'] ?? 5,
                        $lesson['image_url'] ?? null,
                        $lesson['video_url'] ?? null,
                        $lesson['content'] ?? '',
                        $lesson['resource_links'] ?? null,
                        $lesson['tags'] ?? null,
                        $lesson['status'] ?? 'active',
                        $lesson['sort_order'] ?? $index,
                        $lesson['ai_instruction'] ?? null,
                        $lesson['ai_prompt'] ?? null,
                        $quizType,
                        $lesson['quiz_question'] ?? null,
                        $lesson['quiz_answer1'] ?? null,
                        $lesson['quiz_answer2'] ?? null,
                        $lesson['quiz_answer3'] ?? null,
                        $lesson['quiz_answer4'] ?? null,
                        $lesson['quiz_answer5'] ?? null,
                        $lesson['quiz_correct_answer'] ?? null,
                        $lesson['quiz_correct_answers'] ?? null,
                        $userId
                    ]
                );
            }
        }

        execute("COMMIT");
        return $courseId;

    } catch (Exception $e) {
        execute("ROLLBACK");
        throw $e;
    }
}
