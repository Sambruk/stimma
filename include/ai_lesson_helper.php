<?php
/**
 * Stimma — AI-generering av enstaka lektion
 *
 * Hjälpfunktioner som låter admin/AJAX-endpointen skapa en ny lektion i en
 * befintlig kurs via OpenAI. Bygger på samma promptstruktur som
 * `admin/cron/process_ai_jobs.php` (fas 1+2 sammanslagna till EN JSON-respons
 * eftersom en enstaka lektion kan genereras i ett anrop).
 *
 * Beroenden: include/config.php, include/database.php, include/functions.php
 * måste vara laddade innan denna fil inkluderas.
 */

if (!function_exists('aiLessonGenerateContent')) {

/**
 * Anropa OpenAI:s chat-API. Identisk i beteende med callOpenAI() i
 * process_ai_jobs.php men namespacad så vi inte kolliderar om båda råkar
 * laddas i samma request.
 *
 * @throws Exception vid nätverks-, auth- eller parserfel
 */
function aiLessonCallOpenAI(string $systemPrompt, string $userPrompt, int $maxTokens = 8192, array $context = []): string {
    $apiServer = defined('AI_SERVER') && AI_SERVER ? AI_SERVER : 'https://api.openai.com/v1/chat/completions';
    $apiKey    = defined('AI_API_KEY') ? AI_API_KEY : '';

    if (empty($apiKey)) {
        throw new Exception('AI API-nyckel saknas i konfigurationen.');
    }

    require_once __DIR__ . '/ai_quota.php';
    enforceAiQuotaForCurrentSession();
    $model = getModelForFeature($context['feature'] ?? 'lesson_gen', 'gpt-4o');

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'max_tokens'  => $maxTokens,
        'temperature' => 0.7,
    ];

    $ch = curl_init($apiServer);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 180,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new Exception("cURL-fel mot AI: {$curlErr}");
    }
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? substr((string)$response, 0, 300);
        throw new Exception("AI API svarade {$httpCode}: {$errMsg}");
    }

    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        logAiUsage($context, [], $model, 'error');
        throw new Exception('Oväntat svar från AI API.');
    }
    logAiUsage($context, $result['usage'] ?? [], $model, 'ok');
    return $result['choices'][0]['message']['content'];
}

/**
 * Parsa JSON-respons från AI. Hanterar ```json-omslag och nested-objekt på
 * samma sätt som parseAIJson() i process_ai_jobs.php.
 */
function aiLessonParseJson(string $responseText): ?array {
    $cleaned = trim($responseText);
    $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', $cleaned);
    $cleaned = preg_replace('/\n?```\s*$/', '', $cleaned);
    $cleaned = trim($cleaned);

    $data = json_decode($cleaned, true);
    if (is_array($data)) return $data;

    if (preg_match('/\{[\s\S]*\}/', $responseText, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) return $data;
    }
    return null;
}

/**
 * Hämta adminens kompletterande prompt-instruktioner från ai_settings.
 * Återanvänder samma nyckel som kursgenereringen.
 */
function aiLessonGetAdminSupplement(): string {
    $row = queryOne(
        "SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'course_generation_prompt'"
    );
    return ($row && !empty($row['setting_value'])) ? trim($row['setting_value']) : '';
}

/**
 * Deduplicera "answers"-listor i single_choice / multiple_choice så identiska
 * alternativ inte sparas dubbelt (AI har observerats returnera svarslistor med
 * upprepningar). Behåller första förekomst, mappar om correct-indexen så de
 * fortfarande pekar på rätt svar.
 *
 * Måste köras INNAN aiLessonRandomizeAnswers() — annars är correct-indexen
 * shufflade och svårare att mappa.
 */
function aiLessonDedupeAnswers(array &$questions): void {
    foreach ($questions as &$q) {
        $type = $q['question_type'] ?? '';
        $data = $q['quiz_data']     ?? [];
        if (!is_array($data)) continue;
        if (!in_array($type, ['single_choice', 'multiple_choice'], true)) continue;
        if (empty($data['answers']) || !is_array($data['answers'])) continue;

        $seen      = [];
        $newAns    = [];
        $oldToNew  = [];
        foreach ($data['answers'] as $oldIdx => $ans) {
            $key = trim((string)$ans);
            if ($key === '') continue;
            if (isset($seen[$key])) {
                $oldToNew[$oldIdx] = $seen[$key];
            } else {
                $seen[$key]        = count($newAns);
                $oldToNew[$oldIdx] = count($newAns);
                $newAns[]          = $ans;
            }
        }
        // Bara skriv tillbaka om något faktiskt deduperades (annars påverkar
        // vi inte fält som är OK)
        if (count($newAns) === count($data['answers'])) continue;
        $data['answers'] = $newAns;

        if ($type === 'single_choice' && isset($data['correct'])) {
            $oldC = (int)$data['correct'];
            $data['correct'] = $oldToNew[$oldC] ?? 0;
        } elseif ($type === 'multiple_choice' && !empty($data['correct'])) {
            $newC = [];
            foreach ((array)$data['correct'] as $oldC) {
                $oldC = (int)$oldC;
                if (isset($oldToNew[$oldC])) {
                    $mapped = $oldToNew[$oldC];
                    if (!in_array($mapped, $newC, true)) $newC[] = $mapped;
                }
            }
            sort($newC);
            $data['correct'] = $newC;
        }
        $q['quiz_data'] = $data;
    }
}

/**
 * Slumpa svarspositioner för single_choice / multiple_choice så facit inte
 * alltid hamnar i samma slot. Identisk logik som process_ai_jobs.php
 * (rad 545-580).
 */
function aiLessonRandomizeAnswers(array &$questions): void {
    foreach ($questions as &$q) {
        $type = $q['question_type'] ?? '';
        $data = $q['quiz_data']     ?? [];
        if (!is_array($data)) continue;

        if ($type === 'single_choice' && !empty($data['answers']) && isset($data['correct'])) {
            $correctIdx   = (int)$data['correct'];
            $correctValue = $data['answers'][$correctIdx] ?? null;
            shuffle($data['answers']);
            $newIdx = array_search($correctValue, $data['answers'], true);
            $data['correct'] = ($newIdx === false) ? 0 : (int)$newIdx;
        } elseif ($type === 'multiple_choice' && !empty($data['answers']) && !empty($data['correct'])) {
            $correctIdxs   = array_map('intval', (array)$data['correct']);
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
        }
        $q['quiz_data'] = $data;
    }
}

/**
 * Generera lektionsinnehåll via OpenAI. Returnerar en strukturerad array
 * redo att skickas till aiLessonInsert().
 *
 * $opts:
 *   - course        : array  — full kursrad
 *   - lesson_idea   : string — fri text om vad lektionen ska handla om
 *   - lesson_type   : 'lesson'|'info_page'
 *   - include_quiz  : bool   — bara relevant för lesson_type='lesson'
 *   - text_length   : 'short'|'medium'|'long'
 *   - tone          : 'pedagogical'|'formal'|'casual'|'inspiring'
 *   - language_style: 'formal'|'informal'|'academic'|'conversational' (valfri)
 *
 * @throws Exception
 */
function aiLessonGenerateContent(array $opts): array {
    $course           = $opts['course'];
    $lessonIdea       = trim($opts['lesson_idea'] ?? '');
    $lessonType       = ($opts['lesson_type'] ?? 'lesson') === 'info_page' ? 'info_page' : 'lesson';
    $includeQuiz      = ($lessonType === 'lesson') && !empty($opts['include_quiz']);
    // Sidbrytning är default på — Stimma föredrar flersidiga, viewport-anpassade
    // lektioner framför långa scroll-sidor. Skicka 'generate_multipage' => false
    // explicit för att stänga av.
    $generateMultipage = !array_key_exists('generate_multipage', $opts)
        || !empty($opts['generate_multipage']);
    $textLength       = $opts['text_length']   ?? 'medium';
    $tone             = $opts['tone']          ?? 'pedagogical';
    $languageStyle    = $opts['language_style'] ?? 'formal';

    if (mb_strlen($lessonIdea) < 20) {
        throw new Exception('Beskriv lektionens ämne med minst 20 tecken.');
    }
    if (mb_strlen($lessonIdea) > 10000) {
        throw new Exception('Lektionsbeskrivningen är för lång (max 10000 tecken).');
    }

    $toneText = [
        'pedagogical' => 'pedagogisk och lättförståelig',
        'formal'      => 'formell och professionell',
        'casual'      => 'avslappnad och vardaglig',
        'inspiring'   => 'inspirerande och motiverande',
    ][$tone] ?? 'pedagogisk och lättförståelig';

    $languageText = [
        'formal'         => 'formellt språk',
        'informal'       => 'informellt och tillgängligt språk',
        'academic'       => 'akademiskt språk med korrekt terminologi',
        'conversational' => 'vardagligt och samtalsliknande språk',
    ][$languageStyle] ?? 'formellt språk';

    $textLengthText = [
        'short'  => 'cirka 5-8 meningar (ca 150-250 ord). Var koncis.',
        'medium' => 'cirka 12-18 meningar (ca 400-600 ord). Balansera detaljer och läsbarhet.',
        'long'   => 'cirka 25-35 meningar (ca 800-1200 ord). Ge utförliga förklaringar och fler exempel.',
    ][$textLength] ?? 'cirka 12-18 meningar (ca 400-600 ord).';

    // Promptar — sammanslagen variant av Phase 1+2 från process_ai_jobs.php så
    // vi får ett enda OpenAI-anrop med komplett lektionsdata.
    $courseTitle       = (string)($course['title']        ?? '');
    $courseDescription = (string)($course['description']  ?? '');

    $sys  = "Du är en expert på utbildningsmaterial. Du ska skapa innehåll för EN ";
    $sys .= ($lessonType === 'info_page') ? "informationssida" : "lektion";
    $sys .= " som passar in i en befintlig kurs.\n\n";
    $sys .= "TONALITET: {$toneText}\n";
    $sys .= "SPRÅKSTIL: {$languageText}\n";
    $sys .= "TEXTLÄNGD: {$textLengthText}\n\n";

    $sys .= "KURSEN:\n";
    $sys .= "- Titel: {$courseTitle}\n";
    $sys .= "- Beskrivning: {$courseDescription}\n\n";

    $sys .= "VIKTIGT: Svara ENDAST med giltig JSON, ingen text före eller efter.\n\n";

    $sys .= "JSON-strukturen ska vara EXAKT:\n";
    $sys .= "{\n";
    $sys .= "  \"title\": \"Kort beskrivande titel (max 80 tecken)\",\n";
    $sys .= "  \"estimated_duration\": <minuter, heltal 3-30>,\n";
    $sys .= "  \"content\": \"<HTML-innehåll för lektionen — se nedan>\",\n";
    $sys .= "  \"ai_instruction\": null,\n";
    $sys .= "  \"ai_prompt\": null";
    if ($includeQuiz) {
        $sys .= ",\n  \"questions\": [ /* 2-4 frågor — se nedan */ ]";
    }
    $sys .= "\n}\n\n";

    $sys .= "HTML-INNEHÅLL — använd dessa block och inga andra:\n";
    $sys .= "<div class=\"lesson-intro\"><p>Kort introduktion...</p></div>\n";
    $sys .= "<h3>Huvudrubrik 1</h3>\n";
    $sys .= "<p>Stycketext...</p>\n";
    $sys .= "<div class=\"lesson-tip\"><strong>Tips:</strong> ...</div>\n";
    $sys .= "<div class=\"lesson-info\"><strong>Visste du att...</strong> ...</div>\n";
    $sys .= "<div class=\"lesson-example\"><strong>Exempel:</strong> ...</div>\n";
    $sys .= "<div class=\"lesson-warning\"><strong>Obs!</strong> ...</div>\n";
    $sys .= "<ul><li>Punktlista...</li></ul>\n";
    $sys .= "<div class=\"lesson-summary\"><h4>Sammanfattning</h4><ul><li>Punkt 1</li></ul></div>\n\n";

    $sys .= "KRAV:\n";
    $sys .= "1. Börja med en lesson-intro\n";
    $sys .= "2. Minst 2 huvudsektioner med h3-rubriker\n";
    $sys .= "3. Minst en lesson-tip ELLER lesson-info\n";
    $sys .= "4. Minst ett lesson-example\n";
    $sys .= "5. Avsluta med lesson-summary\n";
    $sys .= "6. Använd <strong> för nyckelord och <ul>/<li> för listor\n";

    if ($generateMultipage) {
        $sys .= "\nFLERA SIDOR (multi-page):\n";
        $sys .= "Lektionen ska delas upp i flera bläddringsbara sidor som var och en\n";
        $sys .= "ska få plats i webbläsarens viewport utan att kräva scroll.\n";
        $sys .= "Sätt in markören <!-- pagebreak --> mellan logiska sektioner.\n";
        $sys .= "Riktlinjer:\n";
        $sys .= "  - Sida 1: lesson-intro + översikt över vad lektionen handlar om\n";
        $sys .= "  - Mellan varje h3-huvudsektion: <!-- pagebreak -->\n";
        $sys .= "  - Före lesson-summary: <!-- pagebreak --> (sammanfattningen ligger på sista sidan)\n";
        $sys .= "  - Varje sida ska ha ungefär 80-180 ord — kort nog att rymmas utan scroll\n";
        $sys .= "  - Mål: 4-7 sidor totalt\n";
        $sys .= "Pagebreak-markören är en HTML-kommentar och ska skrivas EXAKT som\n";
        $sys .= "<!-- pagebreak --> (inkl. mellanslag, inga andra tecken).\n";
    }

    if ($includeQuiz) {
        $sys .= "\nQUIZ-FRÅGOR (2-4 st):\n\n";
        $sys .= "Tillåtna question_type: single_choice, multiple_choice, true_false, fill_blank,\n";
        $sys .= "order, match_pairs, categorize, numeric, short_text. (Använd INTE image_choice eller hotspot.)\n";
        $sys .= "Variera typerna efter innehåll. Sprid korrekta svar jämnt.\n";
        $sys .= "VIKTIGT: Upprepa ALDRIG samma svarsalternativ flera gånger i en fråga — varje alternativ måste vara unikt. Använd inte heller 'Alla ovanstående' / 'Inget av ovanstående' i multiple_choice (välj då hellre flera korrekta alternativ direkt).\n\n";
        $sys .= "quiz_data-SCHEMA per typ:\n";
        $sys .= "single_choice:   { \"answers\": [\"A\",\"B\",\"C\"], \"correct\": 1 }\n";
        $sys .= "multiple_choice: { \"answers\": [\"A\",\"B\",\"C\",\"D\"], \"correct\": [0,2] }\n";
        $sys .= "true_false:      { \"correct\": true }\n";
        $sys .= "fill_blank:      { \"template\": \"Sverige gick med i EU år {{0}}.\",\n";
        $sys .= "                   \"blanks\": [ { \"answers\": [\"1995\"], \"case_sensitive\": false } ] }\n";
        $sys .= "order:           { \"items\": [\"Första\",\"Andra\",\"Tredje\"] }\n";
        $sys .= "match_pairs:     { \"pairs\": [ { \"left\": \"Frankrike\", \"right\": \"Paris\" } ] }\n";
        $sys .= "categorize:      { \"categories\": [\"Vokaler\",\"Konsonanter\"],\n";
        $sys .= "                   \"items\": [ { \"text\": \"a\", \"category\": 0 } ] }\n";
        $sys .= "numeric:         { \"correct\": 3.14, \"tolerance\": 0.01, \"unit\": \"\" }\n";
        $sys .= "short_text:      { \"answers\": [\"Paris\",\"paris\"], \"case_sensitive\": false }\n";

        $sys .= "\nFrågeobjekt-format:\n";
        $sys .= "{ \"question_type\": \"...\", \"question_text\": \"Frågan?\", \"quiz_data\": { ... } }\n";
    }

    if ($lessonType === 'info_page') {
        $sys .= "\nDETTA ÄR EN INFORMATIONSSIDA — den ska INTE innehålla quizfrågor. ";
        $sys .= "Lämna bort \"questions\"-fältet helt. Innehållet ska vara informativt och ";
        $sys .= "inte kräva interaktion.\n";
    }

    $supplement = aiLessonGetAdminSupplement();
    if ($supplement !== '') {
        $sys .= "\nEGNA INSTRUKTIONER FRÅN ADMINISTRATÖR (följ alltid dessa):\n" . $supplement . "\n";
    }

    $user = "Skapa en lektion med följande beskrivning:\n\n{$lessonIdea}";

    $maxTokens = $includeQuiz ? 8192 : 6144;
    $rawJson   = aiLessonCallOpenAI($sys, $user, $maxTokens, [
        'feature' => 'lesson_gen',
        'course_id' => isset($course['id']) ? (int)$course['id'] : null,
    ]);
    $parsed    = aiLessonParseJson($rawJson);

    if (!is_array($parsed) || empty($parsed['title']) || empty($parsed['content'])) {
        throw new Exception('AI returnerade ogiltig lektionsdata. Försök igen eller justera beskrivningen.');
    }

    // Rensa ev. markdown-omslag på content (om AI råkat lägga till det)
    $parsed['content'] = trim(preg_replace('/^```(?:html)?\s*|\s*```$/', '', $parsed['content']));

    if ($includeQuiz && !empty($parsed['questions']) && is_array($parsed['questions'])) {
        aiLessonDedupeAnswers($parsed['questions']);
        aiLessonRandomizeAnswers($parsed['questions']);
    } else {
        $parsed['questions'] = [];
    }

    return $parsed;
}

/**
 * Skriv en AI-genererad lektion till databasen. Beräknar nästa sort_order
 * inom kursen och insert:ar quiz_questions om de finns.
 *
 * $extras:
 *   - lesson_type          : 'lesson'|'info_page' (default 'lesson')
 *   - belongs_to_lesson_id : int|null (bara meningsfull för info_page)
 *
 * @return int Nya lektionens id
 */
function aiLessonInsert(int $courseId, array $lessonData, int $authorId, array $extras = []): int {
    $lessonType  = ($extras['lesson_type'] ?? 'lesson') === 'info_page' ? 'info_page' : 'lesson';
    $belongsTo   = $extras['belongs_to_lesson_id'] ?? null;
    if ($lessonType !== 'info_page') $belongsTo = null;
    if ($belongsTo !== null) {
        $belongsTo = (int)$belongsTo;
        if ($belongsTo <= 0) $belongsTo = null;
    }

    $maxOrderRow = queryOne(
        "SELECT COALESCE(MAX(sort_order), -1) AS m FROM " . DB_DATABASE . ".lessons WHERE course_id = ?",
        [$courseId]
    );
    $sortOrder = (int)($maxOrderRow['m'] ?? -1) + 1;

    execute(
        "INSERT INTO " . DB_DATABASE . ".lessons
         (course_id, lesson_type, belongs_to_lesson_id, title, estimated_duration,
          content, status, sort_order, ai_instruction, ai_prompt,
          author_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, NOW(), NOW())",
        [
            $courseId,
            $lessonType,
            $belongsTo,
            substr((string)($lessonData['title'] ?? 'Ny lektion'), 0, 250),
            (int)($lessonData['estimated_duration'] ?? 5),
            (string)($lessonData['content'] ?? ''),
            $sortOrder,
            $lessonData['ai_instruction'] ?? null,
            $lessonData['ai_prompt']      ?? null,
            $authorId,
        ]
    );
    $lessonId = (int)(queryOne("SELECT LAST_INSERT_ID() AS id")['id'] ?? 0);
    if ($lessonId <= 0) {
        throw new Exception('Kunde inte spara lektionen i databasen.');
    }

    $validTypes = ['single_choice','multiple_choice','true_false','fill_blank',
                   'image_choice','order','match_pairs','categorize','numeric',
                   'hotspot','short_text'];

    if ($lessonType === 'lesson' && !empty($lessonData['questions']) && is_array($lessonData['questions'])) {
        foreach ($lessonData['questions'] as $idx => $q) {
            $type = $q['question_type'] ?? 'single_choice';
            if (!in_array($type, $validTypes, true)) $type = 'single_choice';
            $text = (string)($q['question_text'] ?? '');
            $data = $q['quiz_data'] ?? [];
            if (!is_array($data)) $data = [];
            execute(
                "INSERT INTO " . DB_DATABASE . ".quiz_questions
                 (lesson_id, sort_order, question_type, question_text, quiz_data, points)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [
                    $lessonId,
                    (int)$idx,
                    $type,
                    $text,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                ]
            );
        }
    }

    return $lessonId;
}

/**
 * Generera AI-bild för en lektion via OpenAI:s images-API. Sparar bilden under
 * upload/ och returnerar antingen ['success' => true, 'image_url' => 'ai_xxx.png']
 * eller ['success' => false, 'error' => '...'].
 *
 * Kvot kontrolleras via enforceAiQuotaForCurrentSession (eller -ForEmail om
 * $userEmail anges — användbart för cron/jobb). Användning loggas i ai_usage_log.
 *
 * @param string $lessonTitle
 * @param string|null $courseName
 * @param int|null $courseId   för loggning
 * @param string|null $userEmail om null används $_SESSION
 * @return array ['success' => bool, 'image_url'? => string, 'error'? => string]
 */
function aiGenerateLessonImage(string $lessonTitle, ?string $courseName, ?int $courseId = null, ?string $userEmail = null): array {
    require_once __DIR__ . '/ai_quota.php';

    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'AI API-nyckel saknas i konfigurationen.'];
    }

    try {
        if ($userEmail !== null) {
            enforceAiQuotaForEmail($userEmail);
        } else {
            enforceAiQuotaForCurrentSession();
        }
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    require_once __DIR__ . '/ai_image_helper.php';

    $context = [
        'feature'    => 'image',
        'course_id'  => $courseId,
        'is_image'   => true,
        'user_email' => $userEmail,
    ];
    $imageModel = getModelForFeature('image', 'gpt-image-1-mini');

    $prompt = "Educational illustration for a lesson about '{$lessonTitle}'" .
              ($courseName ? " in a course about '{$courseName}'" : "") .
              ". Clean, professional, minimalist style suitable for e-learning. No text in image.";

    $data = aiImageBuildPayload($imageModel, $prompt, '1024x1024');

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        logAiUsage($context, [], $imageModel, 'error');
        return ['success' => false, 'error' => 'Curl-fel: ' . $curlErr];
    }
    if ($httpCode !== 200) {
        logAiUsage($context, [], $imageModel, 'error');
        $errData = json_decode($response, true);
        $msg = $errData['error']['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'error' => 'API-fel: ' . $msg];
    }

    $result = json_decode($response, true);
    $imageContent = aiImageExtractBytes(is_array($result) ? $result : []);
    if (!$imageContent) {
        logAiUsage($context, [], $imageModel, 'error');
        return ['success' => false, 'error' => 'Inga bilddata i API-svaret.'];
    }

    $uploadDir = __DIR__ . '/../upload/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        logAiUsage($context, [], $imageModel, 'error');
        return ['success' => false, 'error' => 'Kunde inte skapa upload-mappen.'];
    }
    $fileName = 'ai_' . uniqid() . '.png';
    if (!file_put_contents($uploadDir . $fileName, $imageContent)) {
        logAiUsage($context, [], $imageModel, 'error');
        return ['success' => false, 'error' => 'Kunde inte spara bildfilen.'];
    }

    logAiUsage($context, [], $imageModel, 'ok');
    return ['success' => true, 'image_url' => $fileName];
}

} // end function_exists guard
