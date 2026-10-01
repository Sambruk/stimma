<?php
/**
 * Stimma — läs in ett kurspaket (JSON) som en ny, inaktiv kurs.
 *
 * Formatet beskrivs i docs/KURSFORMAT.md. Verktyget är till för att Claude
 * (eller en människa) ska kunna skapa kompletta kurser utan att klicka i admin:
 * kurs, lektioner, infosidor och quizfrågor av alla typer som går att skapa
 * utan uppladdade bilder.
 *
 * Körs i webbcontainern (CLI, aldrig via webben — tools/ ligger utanför webbroten):
 *
 *   docker cp paket.json stimma-web-1:/tmp/paket.json
 *   docker exec -u www-data stimma-web-1 php /var/www/html/tools/las_in_kurspaket.php \
 *       /tmp/paket.json --forfattare=thomas.wennersten@sambruk.se [--provkor] [--bilder]
 *
 *   --forfattare  e-post till en befintlig användare. Blir författare och redaktör,
 *                 och kursen hamnar i den användarens organisation (e-postdomänen).
 *   --provkor     validera bara, skriv ingenting
 *   --bilder      generera kursomslag och lektionsbilder via OpenAI efter inläsningen
 *                 (kostar pengar och drar AI-kvot; ~17 US-cent för omslaget,
 *                 ~3–5 cent per lektionsbild). Utan flaggan skapas inga bilder.
 *
 * Kursen skapas ALLTID som inaktiv. Den syns inte för deltagare förrän en admin
 * har granskat och aktiverat den.
 *
 * Avslutas med kod 0 vid lyckad inläsning (eller godkänd provkörning), 1 vid
 * valideringsfel, 2 vid övriga fel. Sista utskriften är en JSON-rad med resultatet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/../public');
require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';

const PAKETFORMAT = 'stimma-kurspaket-1';

// ---------------------------------------------------------------- argument

$args = array_slice($argv, 1);
$fil = null;
$forfattare = null;
$provkor = false;
$bilder = false;
foreach ($args as $a) {
    if ($a === '--provkor') $provkor = true;
    elseif ($a === '--bilder') $bilder = true;
    elseif (strpos($a, '--forfattare=') === 0) $forfattare = strtolower(trim(substr($a, 13)));
    elseif ($a[0] !== '-') $fil = $a;
    else avsluta(2, "Okänd flagga: $a");
}
if (!$fil || !$forfattare) {
    avsluta(2, 'Användning: las_in_kurspaket.php <paket.json> --forfattare=<e-post> [--provkor] [--bilder]');
}
if (!is_readable($fil)) avsluta(2, "Kan inte läsa filen: $fil");

$paket = json_decode(file_get_contents($fil), true);
if (!is_array($paket)) avsluta(1, 'Filen är inte giltig JSON: ' . json_last_error_msg());

$author = queryOne("SELECT id, email FROM " . DB_DATABASE . ".users WHERE LOWER(email) = ?", [$forfattare]);
if (!$author) avsluta(2, "Ingen användare med e-post $forfattare");
$orgDomain = substr(strrchr($author['email'], '@'), 1);

// ---------------------------------------------------------------- validering

$fel = [];
$varningar = [];
validera($paket, $fel, $varningar);

foreach ($varningar as $v) fwrite(STDERR, "VARNING: $v\n");
if ($fel) {
    foreach ($fel as $f) fwrite(STDERR, "FEL: $f\n");
    avsluta(1, count($fel) . ' fel — ingenting har skrivits.', ['fel' => $fel, 'varningar' => $varningar]);
}
if ($provkor) {
    avsluta(0, 'Provkörning godkänd — ingenting har skrivits.', [
        'lektioner' => count($paket['lessons']),
        'fragor' => array_sum(array_map(fn($l) => count($l['questions'] ?? []), $paket['lessons'])),
        'varningar' => $varningar,
    ]);
}

// ---------------------------------------------------------------- inläsning

$c = $paket['course'];
try {
    execute("START TRANSACTION");

    $maxOrder = (int)(queryOne("SELECT COALESCE(MAX(sort_order), 0) AS m FROM " . DB_DATABASE . ".courses")['m'] ?? 0);
    execute(
        "INSERT INTO " . DB_DATABASE . ".courses
         (title, description, difficulty_level, duration_minutes, prerequisites, image_style,
          completion_content, allow_quiz_retry, status, sort_order, featured, author_id,
          organization_domain, original_organization_domain, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?, 0, ?, ?, ?, NOW(), NOW())",
        [
            $c['title'],
            $c['description'],
            $c['difficulty_level'] ?? 'beginner',
            (int)($c['duration_minutes'] ?? array_sum(array_map(fn($l) => (int)($l['estimated_duration'] ?? 5), $paket['lessons']))),
            $c['prerequisites'] ?? null,
            $c['image_style'] ?? 'illustration',
            $c['completion_content'] ?? null,
            array_key_exists('allow_quiz_retry', $c) ? (int)(bool)$c['allow_quiz_retry'] : 1,
            $maxOrder + 1,
            (int)$author['id'],
            $orgDomain,
            $orgDomain,
        ]
    );
    $courseId = (int)queryOne("SELECT LAST_INSERT_ID() AS id")['id'];

    execute(
        "INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by) VALUES (?, ?, ?)",
        [$courseId, $author['email'], (int)$author['id']]
    );

    $idForKey = [];
    foreach ($paket['lessons'] as $i => $l) {
        $typ = ($l['lesson_type'] ?? 'lesson') === 'info_page' ? 'info_page' : 'lesson';
        $belongsTo = ($typ === 'info_page' && !empty($l['belongs_to'])) ? $idForKey[$l['belongs_to']] : null;
        $aiInstruction = $l['ai_chat']['instruction'] ?? null;
        $aiPrompt = $l['ai_chat']['prompt'] ?? null;
        $video = !empty($l['video_url']) ? $l['video_url'] : null;

        execute(
            "INSERT INTO " . DB_DATABASE . ".lessons
             (course_id, lesson_type, belongs_to_lesson_id, title, estimated_duration, content,
              video_url, video_type, video_position, status, sort_order,
              ai_instruction, ai_prompt, ai_chat_enabled, quiz_pass_mode, author_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $courseId, $typ, $belongsTo,
                $l['title'],
                (int)($l['estimated_duration'] ?? 5),
                $l['content'],
                $video, $video ? 'youtube' : null,
                ($l['video_position'] ?? 'bottom') === 'top' ? 'top' : 'bottom',
                $i,
                $aiInstruction, $aiPrompt,
                ($aiInstruction || $aiPrompt) ? 1 : 0,
                ($l['quiz_pass_mode'] ?? 'require_all_correct') === 'any_result' ? 'any_result' : 'require_all_correct',
                (int)$author['id'],
            ]
        );
        $lessonId = (int)queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        $idForKey[$l['key']] = $lessonId;

        if ($typ === 'lesson') {
            foreach (($l['questions'] ?? []) as $qi => $q) {
                execute(
                    "INSERT INTO " . DB_DATABASE . ".quiz_questions
                     (lesson_id, sort_order, question_type, question_text, quiz_data, points)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $lessonId, $qi, $q['question_type'], $q['question_text'],
                        json_encode($q['quiz_data'], JSON_UNESCAPED_UNICODE),
                        max(1, (int)($q['points'] ?? 1)),
                    ]
                );
            }
        }
    }

    execute("COMMIT");
} catch (Throwable $e) {
    execute("ROLLBACK");
    avsluta(2, 'Inläsningen avbröts, ingenting sparades: ' . $e->getMessage());
}

logActivity($author['email'], "Läste in kurspaket '{$c['title']}' (kurs-ID: $courseId) via tools/las_in_kurspaket.php");

// ---------------------------------------------------------------- bilder (valfritt)

$bildresultat = [];
if ($bilder) {
    require_once '../include/ai_lesson_helper.php';
    require_once '../include/ai_image_helper.php';

    $omslag = genereraKursomslag($courseId, $c, $author['email']);
    $bildresultat['omslag'] = $omslag;
    if ($omslag['success']) {
        execute("UPDATE " . DB_DATABASE . ".courses SET image_url = ? WHERE id = ?", [$omslag['image_url'], $courseId]);
    }
    foreach ($paket['lessons'] as $l) {
        if (empty($l['image_wish'])) continue;
        $r = aiGenerateLessonImage($l['title'], $c['title'], $courseId, $author['email'], $l['image_wish']);
        $bildresultat[$l['key']] = $r;
        if ($r['success']) {
            execute("UPDATE " . DB_DATABASE . ".lessons SET image_url = ? WHERE id = ?", [$r['image_url'], $idForKey[$l['key']]]);
        }
        fwrite(STDERR, ($r['success'] ? 'Bild klar' : 'Bild misslyckades') . ": {$l['title']}" . ($r['success'] ? '' : " — {$r['error']}") . "\n");
    }
}

avsluta(0, "Kursen är inläst som INAKTIV: {$c['title']}", [
    'kurs_id' => $courseId,
    'lektioner' => $idForKey,
    'redigera' => "https://stimma.sambruk.se/admin/edit_course.php?id=$courseId",
    'bilder' => $bildresultat,
    'varningar' => $varningar,
]);

// ================================================================ funktioner

function avsluta(int $kod, string $meddelande, array $data = []): void {
    fwrite($kod === 0 ? STDOUT : STDERR, $meddelande . "\n");
    echo json_encode(['ok' => $kod === 0, 'meddelande' => $meddelande] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($kod);
}

/** Kontrollera hela paketet. Fel stoppar inläsningen, varningar gör det inte. */
function validera(array $p, array &$fel, array &$varn): void {
    if (($p['format'] ?? null) !== PAKETFORMAT) $fel[] = "format ska vara \"" . PAKETFORMAT . "\"";
    $c = $p['course'] ?? null;
    if (!is_array($c)) { $fel[] = 'course saknas'; return; }

    textfalt($c, 'title', 'course', 3, 255, $fel);
    textfalt($c, 'description', 'course', 20, 2000, $fel);
    if (isset($c['difficulty_level']) && !in_array($c['difficulty_level'], ['beginner', 'intermediate', 'advanced'], true))
        $fel[] = 'course.difficulty_level ska vara beginner, intermediate eller advanced';
    if (isset($c['image_style']) && !in_array($c['image_style'], ['illustration', 'foto', 'abstrakt'], true))
        $fel[] = 'course.image_style ska vara illustration, foto eller abstrakt';
    if (isset($c['image_wish']) && mb_strlen($c['image_wish']) > 300)
        $fel[] = 'course.image_wish får vara högst 300 tecken';
    if (isset($c['completion_content'])) innehall($c['completion_content'], 'course.completion_content', $fel, $varn);

    $lektioner = $p['lessons'] ?? null;
    if (!is_array($lektioner) || !$lektioner) { $fel[] = 'lessons saknas eller är tom'; return; }
    if (count($lektioner) > 60) $fel[] = 'högst 60 lektioner per kurs';

    $nycklar = [];
    $vanligaLektioner = 0;
    foreach ($lektioner as $i => $l) {
        $var = "lessons[$i]";
        if (!is_array($l)) { $fel[] = "$var är inte ett objekt"; continue; }
        $key = $l['key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-z0-9_-]{1,40}$/', $key)) $fel[] = "$var.key ska vara en kort nyckel (a-z, 0-9, _ -)";
        elseif (isset($nycklar[$key])) $fel[] = "$var.key \"$key\" används redan";
        $var = "lessons[$i] (\"" . ($key ?? '?') . "\")";

        $typ = $l['lesson_type'] ?? 'lesson';
        if (!in_array($typ, ['lesson', 'info_page'], true)) $fel[] = "$var.lesson_type ska vara lesson eller info_page";
        if ($typ === 'lesson') $vanligaLektioner++;

        textfalt($l, 'title', $var, 2, 250, $fel);
        if (isset($l['estimated_duration']) && (!is_int($l['estimated_duration']) || $l['estimated_duration'] < 1 || $l['estimated_duration'] > 120))
            $fel[] = "$var.estimated_duration ska vara ett heltal 1–120 (minuter)";
        if (!isset($l['content']) || !is_string($l['content']) || trim(strip_tags($l['content'])) === '') $fel[] = "$var.content saknas";
        else innehall($l['content'], "$var.content", $fel, $varn);

        if (!empty($l['belongs_to'])) {
            if ($typ !== 'info_page') $fel[] = "$var.belongs_to får bara anges på info_page";
            elseif (($nycklar[$l['belongs_to']] ?? null) !== 'lesson') $fel[] = "$var.belongs_to måste peka på en TIDIGARE lektion av typen lesson";
        }
        if (!empty($l['video_url']) && !preg_match('#^https://(www\.)?(youtube\.com/watch\?v=|youtu\.be/)[A-Za-z0-9_-]{11}#', $l['video_url']))
            $fel[] = "$var.video_url måste vara en YouTube-länk (https://www.youtube.com/watch?v=… eller https://youtu.be/…)";
        if (!empty($l['video_url']) && isset($l['content']) && preg_match_all('/\[video\]/i', $l['content']) > 1)
            $fel[] = "$var.content har mer än en [video]-markör";
        if (empty($l['video_url']) && isset($l['content']) && preg_match('/\[video\]/i', $l['content']))
            $fel[] = "$var.content har en [video]-markör men video_url saknas";
        if (isset($l['image_wish']) && mb_strlen($l['image_wish']) > 300) $fel[] = "$var.image_wish får vara högst 300 tecken";
        if (isset($l['ai_chat'])) {
            if (!is_array($l['ai_chat']) || (empty($l['ai_chat']['instruction']) && empty($l['ai_chat']['prompt'])))
                $fel[] = "$var.ai_chat ska ha instruction och/eller prompt";
        }

        $fragor = $l['questions'] ?? [];
        if ($typ === 'info_page' && $fragor) $fel[] = "$var är en info_page och får inte ha questions";
        if ($typ === 'lesson') {
            if (!is_array($fragor)) $fel[] = "$var.questions ska vara en lista";
            elseif (!$fragor) $varn[] = "$var har inga quizfrågor — lektionen godkänns då utan quiz";
            else foreach ($fragor as $qi => $q) fraga($q, "$var.questions[$qi]", $fel, $varn);
        }
        if (is_string($key)) $nycklar[$key] = $typ;
    }
    if ($vanligaLektioner === 0) $fel[] = 'kursen måste ha minst en lektion av typen lesson';
}

function textfalt(array $o, string $falt, string $var, int $min, int $max, array &$fel): void {
    $v = $o[$falt] ?? null;
    if (!is_string($v) || mb_strlen(trim($v)) < $min) $fel[] = "$var.$falt saknas eller är för kort (minst $min tecken)";
    elseif (mb_strlen($v) > $max) $fel[] = "$var.$falt är för lång (högst $max tecken)";
    elseif ($v !== strip_tags($v)) $fel[] = "$var.$falt får inte innehålla HTML";
}

/** HTML som cleanHtml() skulle ändra eller ta bort räknas som fel — då blir resultatet inte det Claude skrev. */
function innehall(string $html, string $var, array &$fel, array &$varn): void {
    $tillatna = ['br','hr','strong','b','em','i','u','s','sub','sup','span','h2','h3','h4','h5','ul','ol','li','p','div','blockquote','a','table','thead','tbody','tr','td','th'];
    preg_match_all('/<\s*([a-z][a-z0-9]*)\b/i', $html, $m);
    foreach (array_unique(array_map('strtolower', $m[1])) as $tagg) {
        if ($tagg === 'img') $fel[] = "$var innehåller <img> — bilder i löptext stöds inte i kurspaket (använd image_wish)";
        elseif ($tagg === 'h1') $fel[] = "$var använder <h1> — använd h3 för huvudrubriker i lektionen";
        elseif (!in_array($tagg, $tillatna, true)) $fel[] = "$var innehåller <$tagg>, som Stimma tar bort vid visning";
    }
    preg_match_all('/<div\b([^>]*)>/i', $html, $d);
    foreach ($d[1] as $attr) {
        if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $attr, $k)
            && !in_array($k[1], ['lesson-intro','lesson-tip','lesson-info','lesson-example','lesson-warning','lesson-summary'], true))
            $fel[] = "$var har div-klassen \"{$k[1]}\", som Stimma tar bort (tillåtna: lesson-intro, -tip, -info, -example, -warning, -summary)";
    }
    preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']*)["\']/i', $html, $l);
    foreach ($l[1] as $href) {
        if (!preg_match('#^https?://#i', $href)) $fel[] = "$var har länken \"$href\" — bara http(s)-länkar fungerar";
    }
    if (preg_match('/<!--(?!\s*pagebreak\s*-->)/i', $html)) $fel[] = "$var har en HTML-kommentar som inte är <!-- pagebreak -->";
    if (preg_match('/\son\w+\s*=|javascript:|<script|<style/i', $html)) $fel[] = "$var innehåller skript eller stil-taggar";
}

function fraga($q, string $var, array &$fel, array &$varn): void {
    if (!is_array($q)) { $fel[] = "$var är inte ett objekt"; return; }
    $typ = $q['question_type'] ?? null;
    $giltiga = ['single_choice','multiple_choice','true_false','fill_blank','order','match_pairs','categorize','numeric','short_text'];
    if (in_array($typ, ['image_choice', 'hotspot'], true)) { $fel[] = "$var: $typ kräver uppladdade bilder och stöds inte i kurspaket"; return; }
    if (!in_array($typ, $giltiga, true)) { $fel[] = "$var.question_type \"$typ\" är okänd"; return; }
    if (!is_string($q['question_text'] ?? null) || trim(strip_tags($q['question_text'])) === '') $fel[] = "$var.question_text saknas";
    else innehall($q['question_text'], "$var.question_text", $fel, $varn);
    $d = $q['quiz_data'] ?? null;
    if (!is_array($d)) { $fel[] = "$var.quiz_data saknas"; return; }

    $listaAvText = fn($v, int $min) => is_array($v) && array_is_list($v) && count($v) >= $min
        && count(array_filter($v, fn($x) => is_string($x) && trim($x) !== '')) === count($v);
    $unika = fn(array $v) => count(array_unique(array_map(fn($x) => mb_strtolower(trim($x)), $v))) === count($v);

    switch ($typ) {
        case 'single_choice':
            if (!$listaAvText($d['answers'] ?? null, 2)) { $fel[] = "$var: answers ska vara minst 2 icke-tomma alternativ"; break; }
            if (!$unika($d['answers'])) $fel[] = "$var: svarsalternativen måste vara unika";
            if (!is_int($d['correct'] ?? null) || $d['correct'] < 0 || $d['correct'] >= count($d['answers']))
                $fel[] = "$var: correct ska vara index (0-baserat) för det rätta svaret";
            break;
        case 'multiple_choice':
            if (!$listaAvText($d['answers'] ?? null, 3)) { $fel[] = "$var: answers ska vara minst 3 icke-tomma alternativ"; break; }
            if (!$unika($d['answers'])) $fel[] = "$var: svarsalternativen måste vara unika";
            $r = $d['correct'] ?? null;
            if (!is_array($r) || !$r || count(array_filter($r, fn($x) => is_int($x) && $x >= 0 && $x < count($d['answers']))) !== count($r) || count(array_unique($r)) !== count($r))
                $fel[] = "$var: correct ska vara en lista med unika index (0-baserade) för de rätta svaren";
            elseif (count($r) === count($d['answers'])) $varn[] = "$var: alla alternativ är rätt";
            elseif (count($r) === 1) $varn[] = "$var: bara ett rätt svar — single_choice passar bättre";
            break;
        case 'true_false':
            if (!is_bool($d['correct'] ?? null)) $fel[] = "$var: correct ska vara true eller false";
            break;
        case 'fill_blank':
            $t = $d['template'] ?? null;
            $b = $d['blanks'] ?? null;
            if (!is_string($t) || !is_array($b) || !$b) { $fel[] = "$var: template och blanks krävs"; break; }
            preg_match_all('/\{\{(\d+)\}\}/', $t, $m);
            $index = array_map('intval', $m[1]);
            sort($index);
            if ($index !== range(0, count($b) - 1)) {
                $markorer = count($b) === 1 ? '{{0}}' : '{{0}}…{{' . (count($b) - 1) . '}}';
                $fel[] = "$var: template ska innehålla $markorer exakt en gång var — en markör per blank, numrerade från 0";
            }
            foreach ($b as $bi => $blank) {
                if (!$listaAvText($blank['answers'] ?? null, 1)) $fel[] = "$var: blanks[$bi].answers ska vara minst ett godkänt svar";
            }
            break;
        case 'order':
            if (!$listaAvText($d['items'] ?? null, 3)) $fel[] = "$var: items ska vara minst 3 steg, i RÄTT ordning";
            elseif (!$unika($d['items'])) $fel[] = "$var: stegen måste vara unika";
            break;
        case 'match_pairs':
            $par = $d['pairs'] ?? null;
            if (!is_array($par) || count($par) < 3) { $fel[] = "$var: pairs ska vara minst 3 par"; break; }
            foreach ($par as $pi => $pp) {
                if (!is_string($pp['left'] ?? null) || !is_string($pp['right'] ?? null) || trim($pp['left']) === '' || trim($pp['right']) === '')
                    $fel[] = "$var: pairs[$pi] ska ha left och right";
            }
            if (!$unika(array_map(fn($pp) => (string)($pp['right'] ?? ''), $par))) $fel[] = "$var: högerleden måste vara unika, annars går paren inte att skilja åt";
            break;
        case 'categorize':
            $kat = $d['categories'] ?? null;
            $obj = $d['items'] ?? null;
            if (!$listaAvText($kat, 2) || !is_array($obj) || count($obj) < 4) { $fel[] = "$var: minst 2 categories och 4 items krävs"; break; }
            foreach ($obj as $oi => $o) {
                if (!is_string($o['text'] ?? null) || !is_int($o['category'] ?? null) || $o['category'] < 0 || $o['category'] >= count($kat))
                    $fel[] = "$var: items[$oi] ska ha text och category (index i categories)";
            }
            break;
        case 'numeric':
            if (!is_int($d['correct'] ?? null) && !is_float($d['correct'] ?? null)) $fel[] = "$var: correct ska vara ett tal";
            if (isset($d['tolerance']) && (!is_numeric($d['tolerance']) || $d['tolerance'] < 0)) $fel[] = "$var: tolerance ska vara ett tal ≥ 0";
            break;
        case 'short_text':
            if (!$listaAvText($d['answers'] ?? null, 1)) $fel[] = "$var: answers ska vara minst ett godkänt svar";
            break;
    }
}

/** Kursomslag med samma prompt, storlek och kvalitet som knappen i admin (generate_course_image.php). */
function genereraKursomslag(int $courseId, array $c, string $email): array {
    require_once '../include/ai_quota.php';
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    if ($apiKey === '') return ['success' => false, 'error' => 'AI API-nyckel saknas i konfigurationen.'];
    try { enforceAiQuotaForEmail($email); } catch (Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }

    $context = ['feature' => 'image', 'course_id' => $courseId, 'is_image' => true, 'user_email' => $email];
    $model = getModelForFeature('image', 'gpt-image-1-mini');
    $prompt = aiImageBuildPrompt('kurs', $c['title'], $c['description'], $c['image_style'] ?? 'illustration', (string)($c['image_wish'] ?? ''));
    $payload = aiImageBuildPayload($model, $prompt, aiImageSizeFor('kurs'), aiImageQualityFor('kurs'));

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 240,
    ]);
    $svar = curl_exec($ch);
    $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFel = curl_error($ch);
    curl_close($ch);
    if ($curlFel || $kod !== 200) {
        logAiUsage($context, [], $model, 'error');
        $msg = $curlFel ?: (json_decode((string)$svar, true)['error']['message'] ?? "HTTP $kod");
        return ['success' => false, 'error' => $msg];
    }
    $resultat = json_decode($svar, true);
    $bytes = aiImageExtractBytes(is_array($resultat) ? $resultat : []);
    if (!$bytes) {
        logAiUsage($context, [], $model, 'error');
        return ['success' => false, 'error' => 'Inga bilddata i API-svaret.'];
    }
    $filnamn = 'ai_' . uniqid() . '.' . aiImageFileExtension($model);
    if (!file_put_contents(ROOT_PATH . '/public/upload/' . $filnamn, $bytes)) {
        logAiUsage($context, [], $model, 'error');
        return ['success' => false, 'error' => 'Kunde inte spara bildfilen.'];
    }
    $usage = aiImageUsageFromResponse(is_array($resultat) ? $resultat : []);
    logAiUsage($context, $usage, $model, 'ok');
    return ['success' => true, 'image_url' => $filnamn,
            'cost_cents' => calculateAiCostCents($model, $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0, true)];
}
