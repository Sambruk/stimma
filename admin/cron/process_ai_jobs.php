<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Background process for AI course generation
 * Run via cron or manually: php process_ai_jobs.php
 */

// Blockera webbåtkomst - detta skript ska bara köras via CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

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
 * Uses a two-phase approach:
 *   Phase 1: Generate course structure (metadata + lesson titles + quizzes)
 *   Phase 2: Generate full content for each lesson individually
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

    $lessonCount = $job['lesson_count'];
    $difficultyText = [
        'beginner' => 'nybörjare',
        'intermediate' => 'mellannivå',
        'advanced' => 'avancerad'
    ][$job['difficulty_level']] ?? 'nybörjare';

    $toneText = [
        'pedagogical' => 'pedagogisk och lättförståelig',
        'formal' => 'formell och professionell',
        'casual' => 'avslappnad och vardaglig',
        'inspiring' => 'inspirerande och motiverande'
    ][$job['tone'] ?? 'pedagogical'] ?? 'pedagogisk och lättförståelig';

    $languageStyleText = [
        'formal' => 'formellt språk',
        'informal' => 'informellt och tillgängligt språk',
        'academic' => 'akademiskt språk med korrekt terminologi',
        'conversational' => 'vardagligt och samtalsliknande språk'
    ][$job['language_style'] ?? 'formal'] ?? 'formellt språk';

    $textLengthText = [
        'short' => 'cirka 5-8 meningar (ca 150-250 ord). Var koncis och fokusera på det viktigaste.',
        'medium' => 'cirka 12-18 meningar (ca 400-600 ord). Balansera mellan detaljer och läsbarhet.',
        'long' => 'cirka 25-35 meningar (ca 800-1200 ord). Ge utförliga förklaringar, fler exempel och djupare resonemang.'
    ][$job['text_length'] ?? 'medium'] ?? 'cirka 12-18 meningar (ca 400-600 ord).';

    // Build AI Q&A context string
    $qaContext = '';
    if (!empty($job['ai_answers'])) {
        $answers = json_decode($job['ai_answers'], true);
        if (is_array($answers) && !empty($answers)) {
            $qaContext = "\n\nKompletterande information från kursbeställaren:";
            foreach ($answers as $qa) {
                if (!empty($qa['answer'])) {
                    $qaContext .= "\n- Fråga: {$qa['question']}\n  Svar: {$qa['answer']}";
                }
            }
        }
    }

    // ========================================
    // PHASE 1: Generate course structure
    // ========================================
    updateJobStatus($jobId, 'processing', 8, 'Genererar kursstruktur...');
    echo "  - Phase 1: Generating course structure with {$lessonCount} lessons...\n";

    $structureSystemPrompt = "Du är en expert på att skapa utbildningsmaterial. Du ska generera en kursstruktur i JSON-format.

VIKTIGT: Svara ENDAST med giltig JSON, ingen annan text före eller efter.

Du ska skapa EXAKT {$lessonCount} lektioner. Detta är ett ABSOLUT krav - varken fler eller färre.

Kursen ska:
- Ha EXAKT {$lessonCount} lektioner (detta är obligatoriskt)
- Vara på {$difficultyText}-nivå
- Vara på svenska
- Ha {$toneText} ton
- Använda {$languageStyleText}";

    if (!empty($job['target_audience'])) {
        $structureSystemPrompt .= "\n- Målgrupp: {$job['target_audience']}";
    }

    // Quiz instructions — stöder 11 frågetyper, AI väljer typ efter innehåll
    $quizInstructions = '';
    if ($job['include_quiz']) {
        $quizInstructions = '
QUIZ: Varje lektion ska ha 2-4 frågor i en "questions"-array. VÄLJ frågetyp
som passar bäst för innehållet — variera över lektionerna. För ren faktafråga
räcker enkelval, men för sekventiellt innehåll passar "order" bättre osv.

FRÅGETYPER och när de passar BÄST:
- "single_choice"   : Faktafråga med ett rätt svar bland 3-5 alternativ. Default.
- "multiple_choice" : Fråga där flera alternativ är korrekta (3-5 alt). Använd sparsamt.
- "true_false"      : Påstående som är sant/falskt. Snabb variation.
- "fill_blank"      : Text med glömda ord som deltagaren ska fylla i. Passar
                      termer, årtal, namn. Mall med {{0}}, {{1}} platshållare.
- "order"           : När steg/händelser har en korrekt KRONOLOGISK eller
                      LOGISK ordning (ex: "steg i en process", "tidslinje").
- "match_pairs"     : När fakta naturligt parar ihop (term→definition,
                      land→huvudstad, författare→bok).
- "categorize"      : När objekt ska grupperas i 2-4 kategorier (ex:
                      "organiska/oorganiska föreningar", "vokaler/konsonanter").
- "numeric"         : Beräknings- eller mätfråga där svaret är ett tal.
                      Använd "tolerance" för avrundning.
- "short_text"      : Kort fritt textsvar (1-3 ord). Ange flera accepterade
                      svar vid synonymer ("svar1", "svar2").

(Du ska INTE välja "image_choice" eller "hotspot" — dessa kräver bildfiler
som AI inte kan leverera. Välj en av de andra 9 typerna.)

REGLER:
- Välj EN typ per fråga som verkligen matchar innehållet
- Minst 3 olika typer totalt över kursen (variera!)
- Sprid korrekta svar jämnt (inte alltid position 0)

quiz_data-SCHEMA per typ (följ EXAKT):

single_choice:   { "answers": ["A","B","C"], "correct": 1 }       // index 0-baserat
multiple_choice: { "answers": ["A","B","C","D"], "correct": [0,2] }
true_false:      { "correct": true }                               // eller false
fill_blank:      { "template": "Sverige gick med i EU år {{0}}.",
                   "blanks": [ { "answers": ["1995"], "case_sensitive": false } ] }
order:           { "items": ["Första steget","Andra","Tredje"] }   // EXAKT rätt ordning
match_pairs:     { "pairs": [ { "left": "Frankrike", "right": "Paris" } ] }
categorize:      { "categories": ["Regelbundna","Oregelbundna"],
                   "items": [ { "text": "arbeta", "category": 0 },
                              { "text": "springa", "category": 1 } ] }
numeric:         { "correct": 3.14, "tolerance": 0.01, "unit": "m" }
short_text:      { "answers": ["Paris","paris"], "case_sensitive": false }
';
    }

    // AI tutor instructions
    $aiTutorInstructions = '';
    if ($job['include_ai_tutor']) {
        $aiTutorInstructions = '
AI-HANDLEDARE: Varje lektion ska ha:
- "ai_instruction": Kort instruktion för AI-handledaren om lektionens ämne
- "ai_prompt": Startprompt för dialogen med användaren';
    }

    $aiInstructionValue = $job['include_ai_tutor'] ? '"Instruktion..."' : 'null';
    $aiPromptValue = $job['include_ai_tutor'] ? '"Prompt..."' : 'null';

    $structureSystemPrompt .= "
{$quizInstructions}
{$aiTutorInstructions}

JSON-strukturen ska vara:
{
  \"course\": {
    \"title\": \"Kursnamn\",
    \"description\": \"Utförlig kursbeskrivning (2-3 meningar)\",
    \"difficulty_level\": \"{$job['difficulty_level']}\",
    \"duration_minutes\": <total tid>,
    \"prerequisites\": null,
    \"tags\": null,
    \"status\": \"inactive\",
    \"sort_order\": 0,
    \"featured\": 0
  },
  \"lessons\": [
    {
      \"title\": \"Lektionsnamn\",
      \"estimated_duration\": <minuter>,
      \"description\": \"Kort beskrivning av vad lektionen handlar om (1-2 meningar)\",
      \"video_url\": null,
      \"resource_links\": null,
      \"tags\": null,
      \"status\": \"active\",
      \"sort_order\": <nummer>,
      \"ai_instruction\": {$aiInstructionValue},
      \"ai_prompt\": {$aiPromptValue},
      \"questions\": [
        {
          \"question_type\": \"single_choice\",
          \"question_text\": \"Frågan?\",
          \"quiz_data\": { \"answers\": [\"A\",\"B\",\"C\"], \"correct\": 1 }
        },
        {
          \"question_type\": \"order\",
          \"question_text\": \"Placera stegen i rätt ordning.\",
          \"quiz_data\": { \"items\": [\"Första\",\"Andra\",\"Tredje\"] }
        }
      ]
    }
  ]
}

VIKTIGT: Du MÅSTE generera EXAKT {$lessonCount} lektioner i lessons-arrayen. Räkna noga!";

    $structureUserPrompt = "Skapa en kursstruktur med EXAKT {$lessonCount} lektioner för kursen \"{$job['course_name']}\" baserat på följande beskrivning:\n\n{$job['course_description']}{$qaContext}\n\nGENERERA EXAKT {$lessonCount} LEKTIONER.";

    $structureJson = callOpenAI($structureSystemPrompt, $structureUserPrompt);

    if (!$structureJson) {
        throw new Exception('Kunde inte generera kursstruktur från AI.');
    }

    // Log phase 1 response
    $debugLogFile = __DIR__ . '/../../upload/ai_raw_response_job' . $jobId . '_phase1.log';
    file_put_contents($debugLogFile, $structureJson);
    echo "  - Phase 1 response saved (" . strlen($structureJson) . " bytes)\n";

    // Parse the structure JSON
    $courseData = parseAIJson($structureJson);

    if (!$courseData || !isset($courseData['course']) || !isset($courseData['lessons'])) {
        throw new Exception('Kunde inte tolka AI-svaret som giltig JSON (fas 1).');
    }

    $actualLessonCount = count($courseData['lessons']);
    echo "  - Phase 1: Got {$actualLessonCount} lessons (requested {$lessonCount})\n";

    if ($actualLessonCount < $lessonCount) {
        echo "  - Warning: AI returned fewer lessons than requested. Attempting to fill...\n";
        // Pad with placeholder lessons based on course topic
        while (count($courseData['lessons']) < $lessonCount) {
            $idx = count($courseData['lessons']) + 1;
            $courseData['lessons'][] = [
                'title' => "Lektion {$idx}: " . $job['course_name'] . " - fördjupning {$idx}",
                'estimated_duration' => 10,
                'description' => "Fördjupning i ämnet " . $job['course_name'],
                'video_url' => null,
                'resource_links' => null,
                'tags' => null,
                'status' => 'active',
                'sort_order' => $idx,
                'ai_instruction' => null,
                'ai_prompt' => null,
                'questions' => [],
            ];
        }
    }

    // Trim if AI returned too many
    if (count($courseData['lessons']) > $lessonCount) {
        $courseData['lessons'] = array_slice($courseData['lessons'], 0, $lessonCount);
    }

    // Override course name
    $courseData['course']['title'] = $job['course_name'];

    updateJobStatus($jobId, 'processing', 20, 'Kursstruktur klar. Genererar lektionsinnehåll...');

    // ========================================
    // PHASE 2: Generate content for each lesson
    // ========================================
    $contentSystemPrompt = "Du är en expert på att skapa utbildningsmaterial. Du ska generera innehåll för EN lektion i HTML-format.

VIKTIGT: Svara ENDAST med lektionens HTML-innehåll. Ingen JSON, ingen markdown, bara ren HTML.

TONALITET: {$toneText}
SPRÅKSTIL: {$languageStyleText}
TEXTLÄNGD: {$textLengthText}";

    if (!empty($job['target_audience'])) {
        $contentSystemPrompt .= "\nMÅLGRUPP: {$job['target_audience']} - anpassa innehåll, exempel och terminologi.";
    }

    $contentSystemPrompt .= '

FORMATERING - Använd dessa HTML-element:

<div class="lesson-intro"><p>Kort introduktion som sammanfattar lektionen...</p></div>

<h3>Huvudrubrik 1</h3>
<p>Stycketext med förklaringar...</p>

<div class="lesson-tip"><strong>Tips:</strong> Praktiskt råd...</div>
<div class="lesson-info"><strong>Visste du att...</strong> Intressant fakta...</div>
<div class="lesson-example"><strong>Exempel:</strong> Konkret scenario...</div>
<div class="lesson-warning"><strong>Obs!</strong> Viktig varning...</div>

<h3>Huvudrubrik 2</h3>
<p>Mer innehåll...</p>
<ul><li>Punktlista...</li></ul>

<div class="lesson-summary"><h4>Sammanfattning</h4><ul><li>Nyckelinsikt 1</li><li>Nyckelinsikt 2</li><li>Nyckelinsikt 3</li></ul></div>

KRAV på varje lektion:
1. Börja med en lesson-intro
2. Minst 2-3 huvudsektioner med h3-rubriker
3. Minst en lesson-tip ELLER lesson-info ruta
4. Minst ett lesson-example
5. Avsluta med lesson-summary
6. Använd <strong> för nyckelord, <ul>/<li> för listor';

    $totalLessons = count($courseData['lessons']);
    $progressStart = 20;
    $progressEnd = 70;

    for ($i = 0; $i < $totalLessons; $i++) {
        $lesson = &$courseData['lessons'][$i];
        $lessonNum = $i + 1;
        $progressPercent = $progressStart + (($i / $totalLessons) * ($progressEnd - $progressStart));

        updateJobStatus($jobId, 'processing', round($progressPercent),
            "Genererar innehåll för lektion {$lessonNum} av {$totalLessons}: {$lesson['title']}...");
        echo "  - Phase 2: Generating content for lesson {$lessonNum}/{$totalLessons}: {$lesson['title']}\n";

        $lessonDescription = $lesson['description'] ?? '';
        $contentUserPrompt = "Skapa innehåll för lektion {$lessonNum} av {$totalLessons} i kursen \"{$job['course_name']}\".

Lektionstitel: {$lesson['title']}
Beskrivning: {$lessonDescription}
Kursbeskrivning: {$job['course_description']}
Svårighetsgrad: {$difficultyText}

Skriv ett komplett, informativt och engagerande lektionsinnehåll i HTML.";

        $contentResponse = callOpenAI($contentSystemPrompt, $contentUserPrompt, 4096);

        if ($contentResponse) {
            // Clean the response - remove any markdown wrapping
            $content = trim($contentResponse);
            $content = preg_replace('/^```(?:html)?\s*\n?/i', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
            $lesson['content'] = trim($content);
            echo "    - Content generated (" . strlen($lesson['content']) . " chars)\n";
        } else {
            // Fallback: minimal content
            $lesson['content'] = '<div class="lesson-intro"><p>' . htmlspecialchars($lesson['title']) . '</p></div><p>' . htmlspecialchars($lessonDescription) . '</p>';
            echo "    - WARNING: Failed to generate content, using fallback\n";
        }

        unset($lesson); // Break reference
    }

    // Log full course data
    $debugLogFile = __DIR__ . '/../../upload/ai_raw_response_job' . $jobId . '.log';
    file_put_contents($debugLogFile, json_encode($courseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "  - Full course data saved to: {$debugLogFile}\n";

    // Slumpa svar för single_choice/multiple_choice/image_choice så rätt svar
    // inte alltid hamnar i samma position. För andra typer (order, fill_blank,
    // hotspot etc) har ordningen betydelse — rör inte.
    if ($job['include_quiz']) {
        foreach ($courseData['lessons'] as &$lesson) {
            if (empty($lesson['questions']) || !is_array($lesson['questions'])) continue;
            foreach ($lesson['questions'] as &$q) {
                $type = $q['question_type'] ?? '';
                $data = $q['quiz_data'] ?? [];
                if (!is_array($data)) continue;

                if ($type === 'single_choice' && !empty($data['answers']) && isset($data['correct'])) {
                    $correctIdx = (int)$data['correct'];
                    $correctValue = $data['answers'][$correctIdx] ?? null;
                    shuffle($data['answers']);
                    $newIdx = array_search($correctValue, $data['answers'], true);
                    $data['correct'] = ($newIdx === false) ? 0 : (int)$newIdx;
                    $q['quiz_data'] = $data;
                } elseif ($type === 'multiple_choice' && !empty($data['answers']) && !empty($data['correct'])) {
                    $correctIdxs = array_map('intval', (array)$data['correct']);
                    $correctValues = [];
                    foreach ($correctIdxs as $ci) {
                        if (isset($data['answers'][$ci])) $correctValues[] = $data['answers'][$ci];
                    }
                    shuffle($data['answers']);
                    $newIdxs = [];
                    foreach ($correctValues as $cv) {
                        $p = array_search($cv, $data['answers'], true);
                        if ($p !== false) $newIdxs[] = (int)$p;
                    }
                    sort($newIdxs);
                    $data['correct'] = $newIdxs;
                    $q['quiz_data'] = $data;
                }
            }
            unset($q);
        }
        unset($lesson);
    }

    updateJobStatus($jobId, 'processing', 70, 'Söker efter videolänkar...');

    // Add YouTube links if requested
    if ($job['include_video_links']) {
        foreach ($courseData['lessons'] as $index => &$lesson) {
            $videoUrl = searchYouTube($lesson['title'] . ' ' . $job['course_name']);
            if ($videoUrl) {
                $lesson['video_url'] = $videoUrl;
            }
            updateJobStatus($jobId, 'processing', 70 + (($index + 1) / count($courseData['lessons']) * 5),
                "Söker video för lektion " . ($index + 1) . "...");
        }
    }

    updateJobStatus($jobId, 'processing', 75, 'Importerar kursen...');

    // Import the course
    $courseId = importCourse($courseData, $job['user_id'], $job['organization_domain']);

    if (!$courseId) {
        throw new Exception('Kunde inte importera kursen till databasen.');
    }

    updateJobStatus($jobId, 'processing', 80, 'Kursen importerad.');

    // Generate images if enabled
    $generateImages = isset($job['generate_images']) ? (int)$job['generate_images'] : 0;

    if ($generateImages) {
        $tone = $job['tone'] ?? 'pedagogical';
        $colorTheme = $job['color_theme'] ?? '#007bff';
        $targetAudience = $job['target_audience'] ?? '';
        $courseName = $job['course_name'];

        // Generate course cover image
        updateJobStatus($jobId, 'processing', 82, 'Genererar kursbild...');
        $courseImagePrompt = "Educational course cover illustration for '{$courseName}'. Theme: {$tone}. Color palette inspired by {$colorTheme}. " .
            (!empty($targetAudience) ? "Target audience: {$targetAudience}. " : "") .
            "Clean, modern, professional design suitable for e-learning. Abstract and conceptual. No text in image.";
        $courseImage = generateAIImageWithPrompt($courseImagePrompt, '1792x1024');
        if ($courseImage) {
            execute(
                "UPDATE " . DB_DATABASE . ".courses SET image_url = ? WHERE id = ?",
                [$courseImage, $courseId]
            );
            echo "  - Course image saved: {$courseImage}\n";
        }

        // Generate lesson images
        $lessons = query(
            "SELECT id, title FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order",
            [$courseId]
        );

        $lessonTotal = count($lessons);
        foreach ($lessons as $lIndex => $lessonRow) {
            $progressPercent = 84 + (($lIndex + 1) / $lessonTotal * 9); // 84-93%
            updateJobStatus($jobId, 'processing', round($progressPercent),
                "Genererar bild för lektion " . ($lIndex + 1) . " av {$lessonTotal}...");

            $lessonImagePrompt = "Educational illustration for lesson '{$lessonRow['title']}' in course '{$courseName}'. " .
                "Style: {$tone}, color accent: {$colorTheme}. " .
                "Clean, suitable for e-learning. No text in image.";
            $lessonImage = generateAIImageWithPrompt($lessonImagePrompt, '1024x1024');
            if ($lessonImage) {
                execute(
                    "UPDATE " . DB_DATABASE . ".lessons SET image_url = ? WHERE id = ?",
                    [$lessonImage, $lessonRow['id']]
                );
                echo "  - Lesson image saved for '{$lessonRow['title']}': {$lessonImage}\n";
            }
        }

        // Generate certificate/diploma image
        updateJobStatus($jobId, 'processing', 94, 'Genererar diplombild...');
        $diplomaImagePrompt = "Elegant certificate decoration for a course about '{$courseName}'. " .
            "Achievement theme, celebratory. Color accent: {$colorTheme}. " .
            "Abstract ornamental design, elegant borders and flourishes. No text in image.";
        $diplomaImage = generateAIImageWithPrompt($diplomaImagePrompt, '1792x1024');
        if ($diplomaImage) {
            execute(
                "UPDATE " . DB_DATABASE . ".courses SET certificate_image_url = ? WHERE id = ?",
                [$diplomaImage, $courseId]
            );
            echo "  - Diploma image saved: {$diplomaImage}\n";
        }

        updateJobStatus($jobId, 'processing', 95, 'Bilder genererade.');
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
    if ($status === 'failed') {
        execute(
            "UPDATE " . DB_DATABASE . ".ai_course_jobs
             SET status = ?, progress_percent = ?, progress_message = ?, error_message = ?, completed_at = NOW()
             WHERE id = ?",
            [$status, $progress, $message, $message, $jobId]
        );
    } else {
        execute(
            "UPDATE " . DB_DATABASE . ".ai_course_jobs
             SET status = ?, progress_percent = ?, progress_message = ?
             WHERE id = ?",
            [$status, $progress, $message, $jobId]
        );
    }
}

/**
 * Parse AI response JSON, handling markdown code blocks and nested JSON
 */
function parseAIJson($responseText) {
    $cleaned = trim($responseText);

    // Remove markdown code block markers
    $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', $cleaned);
    $cleaned = preg_replace('/\n?```\s*$/', '', $cleaned);
    $cleaned = trim($cleaned);

    $data = json_decode($cleaned, true);
    if ($data && (isset($data['course']) || isset($data['lessons']))) {
        return $data;
    }

    // Try greedy match for outermost braces
    if (preg_match('/\{(?:[^{}]|(?:\{(?:[^{}]|(?:\{[^{}]*\}))*\}))*\}/s', $cleaned, $matches)) {
        $data = json_decode($matches[0], true);
        if ($data && (isset($data['course']) || isset($data['lessons']))) {
            return $data;
        }
    }

    // Fallback: broadest possible match
    if (preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
        $data = json_decode($matches[0], true);
        if ($data) {
            return $data;
        }
    }

    $jsonError = json_last_error_msg();
    echo "  - JSON parse error: {$jsonError}\n";
    echo "  - First 500 chars: " . substr($responseText, 0, 500) . "\n";
    return null;
}

/**
 * Call OpenAI API
 */
function callOpenAI($systemPrompt, $userPrompt, $maxTokens = 16384) {
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
        'max_tokens' => $maxTokens,
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
 * Generate AI image using DALL-E with custom prompt and size
 */
function generateAIImageWithPrompt($prompt, $size = '1024x1024') {
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    $imageApiServer = 'https://api.openai.com/v1/images/generations';

    if (empty($apiKey)) {
        echo "  - No API key for image generation\n";
        return null;
    }

    // Validate size
    $validSizes = ['1024x1024', '1792x1024', '1024x1792'];
    if (!in_array($size, $validSizes)) {
        $size = '1024x1024';
    }

    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'n' => 1,
        'size' => $size,
        'quality' => 'standard'
    ];

    echo "  - Generating DALL-E image ({$size}): " . substr($prompt, 0, 80) . "...\n";

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
        $imageUrl = $result['data'][0]['url'];
        $imageContent = @file_get_contents($imageUrl);

        if ($imageContent) {
            $uploadDir = __DIR__ . '/../../upload/';
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

        // Create course. original_organization_domain = skaparens org — AI-genererade
        // kurser har per definition skapats av användarens organisation.
        execute(
            "INSERT INTO " . DB_DATABASE . ".courses
             (title, description, difficulty_level, duration_minutes, prerequisites, tags,
              image_url, status, sort_order, featured, author_id, organization_domain, original_organization_domain, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'inactive', ?, ?, ?, ?, ?, NOW(), NOW())",
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
                $organizationDomain,
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

        // Create lessons (quiz-frågor hanteras separat i quiz_questions-tabellen)
        if (isset($courseData['lessons']) && is_array($courseData['lessons'])) {
            $validTypes = ['single_choice','multiple_choice','true_false','fill_blank',
                           'image_choice','order','match_pairs','categorize','numeric',
                           'hotspot','short_text'];
            foreach ($courseData['lessons'] as $index => $lesson) {
                execute(
                    "INSERT INTO " . DB_DATABASE . ".lessons
                     (course_id, title, estimated_duration, image_url, video_url, content,
                      resource_links, tags, status, sort_order, ai_instruction, ai_prompt,
                      author_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
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
                        $userId,
                    ]
                );
                $lessonId = (int)(queryOne("SELECT LAST_INSERT_ID() AS id")['id'] ?? 0);

                // Lägg in quiz-frågor i quiz_questions
                if ($lessonId > 0 && !empty($lesson['questions']) && is_array($lesson['questions'])) {
                    foreach ($lesson['questions'] as $qIndex => $q) {
                        $qType = $q['question_type'] ?? 'single_choice';
                        if (!in_array($qType, $validTypes, true)) $qType = 'single_choice';
                        $qText = $q['question_text'] ?? '';
                        $qData = $q['quiz_data'] ?? [];
                        if (!is_array($qData)) $qData = [];
                        $qJson = json_encode($qData, JSON_UNESCAPED_UNICODE);
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".quiz_questions
                             (lesson_id, sort_order, question_type, question_text, quiz_data, points)
                             VALUES (?, ?, ?, ?, ?, 1)",
                            [$lessonId, $qIndex, $qType, $qText, $qJson]
                        );
                    }
                }
            }
        }

        execute("COMMIT");
        return $courseId;

    } catch (Exception $e) {
        execute("ROLLBACK");
        throw $e;
    }
}
