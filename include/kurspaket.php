<?php
/**
 * Stimma — kurspaket (format stimma-kurspaket-1).
 *
 * Gemensam kärna för admins "Importera kurs" (public/admin/import.php) och
 * kommandoradsverktyget tools/las_in_kurspaket.php, så att båda vägarna har
 * exakt samma regler. Formatet beskrivs i docs/KURSFORMAT.md.
 *
 * Ett kurspaket är antingen en JSON-fil eller en ZIP-fil med course.json och
 * en mapp images/ med färdiga bilder som paketet pekar ut med image_file.
 *
 * Flödet: kurspaketLasFil() → kurspaketValidera() → kurspaketLasIn().
 * Inga bilder kopieras och ingenting skrivs förrän valideringen är godkänd.
 */

if (!defined('KURSPAKET_FORMAT')) {

define('KURSPAKET_FORMAT', 'stimma-kurspaket-1');

define('KP_DIV_KLASSER', ['lesson-intro', 'lesson-tip', 'lesson-info', 'lesson-example', 'lesson-warning', 'lesson-summary']);
define('KP_TAGGAR', ['br','hr','strong','b','em','i','u','s','sub','sup','span','h2','h3','h4','h5','ul','ol','li','p','div',
                     'blockquote','a','table','thead','tbody','tr','td','th']);
define('KP_BILDTILLAGG', ['jpg', 'jpeg', 'png', 'webp']);
define('KP_BILDTYPER', ['image/jpeg', 'image/png', 'image/webp']);
define('KP_MAX_BILD', 5 * 1024 * 1024);

/** Är det här ett kurspaket (och inte en gammal export från "Exportera kurs")? */
function kurspaketAr($data): bool {
    return is_array($data) && ($data['format'] ?? null) === KURSPAKET_FORMAT;
}

/**
 * Läs en JSON- eller ZIP-fil. Returnerar ['paket' => array|null, 'bilder' => [filnamn => sökväg],
 * 'tempdir' => string|null, 'fel' => string|null]. Anroparen ska städa med kurspaketStada().
 */
function kurspaketLasFil(string $sokvag, string $filnamn): array {
    $ext = strtolower(pathinfo($filnamn, PATHINFO_EXTENSION));
    $ut = ['paket' => null, 'bilder' => [], 'tempdir' => null, 'fel' => null];

    if ($ext === 'json') {
        $ut['paket'] = json_decode((string)file_get_contents($sokvag), true);
        if (!is_array($ut['paket'])) $ut['fel'] = 'Filen är inte giltig JSON: ' . json_last_error_msg();
        return $ut;
    }
    if ($ext !== 'zip') {
        $ut['fel'] = 'Kurspaket ska vara en .json- eller .zip-fil.';
        return $ut;
    }

    $zip = new ZipArchive();
    if ($zip->open($sokvag) !== true) { $ut['fel'] = 'Kunde inte öppna ZIP-filen.'; return $ut; }
    if ($zip->numFiles > 500) { $zip->close(); $ut['fel'] = 'ZIP-filen innehåller för många filer (max 500).'; return $ut; }
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        $namn = $st['name'];
        if (strpos($namn, '..') !== false || strpos($namn, '\\') !== false || ($namn[0] ?? '') === '/') {
            $zip->close(); $ut['fel'] = 'ZIP-filen innehåller ogiltiga sökvägar.'; return $ut;
        }
        $total += $st['size'];
    }
    if ($total > 50 * 1024 * 1024) { $zip->close(); $ut['fel'] = 'ZIP-filens innehåll överskrider 50 MB.'; return $ut; }

    // course.json i roten — eller i EN undermapp som rymmer allt. Det senare blir
    // det när man högerklickar på kursmappen och väljer "Komprimera" i Windows
    // (eller macOS, som dessutom lägger till __MACOSX/ och .DS_Store).
    $rot = kpZipRot($zip);
    if ($rot === null) {
        $zip->close();
        $ut['fel'] = 'ZIP-filen saknar course.json. Den ska ligga i roten, eller i en enda mapp tillsammans med images/.';
        return $ut;
    }

    $temp = sys_get_temp_dir() . '/stimma_kurspaket_' . bin2hex(random_bytes(8));
    mkdir($temp, 0700, true);
    $zip->extractTo($temp);
    $zip->close();
    $ut['tempdir'] = $temp;
    $bas = $rot === '' ? $temp : "$temp/" . rtrim($rot, '/');

    $ut['paket'] = json_decode((string)file_get_contents("$bas/course.json"), true);
    if (!is_array($ut['paket'])) { $ut['fel'] = 'course.json är inte giltig JSON: ' . json_last_error_msg(); return $ut; }

    if (is_dir("$bas/images")) {
        foreach (scandir("$bas/images") as $f) {
            if ($f !== '.' && $f !== '..' && $f !== '.DS_Store' && is_file("$bas/images/$f")) $ut['bilder'][$f] = "$bas/images/$f";
        }
    }
    return $ut;
}

/**
 * Var i ZIP:en ligger course.json? '' = roten, 'mapp/' = en enda undermapp som
 * rymmer allt innehåll, null = ingen entydig plats.
 */
function kpZipRot(ZipArchive $zip): ?string {
    if ($zip->locateName('course.json') !== false) return '';
    $namn = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        // Skräp som operativsystemen lägger till räknas inte
        if (strpos($n, '__MACOSX/') === 0 || basename($n) === '.DS_Store' || basename($n) === 'Thumbs.db') continue;
        $namn[] = $n;
    }
    $kandidater = array_values(array_filter($namn, fn($n) => preg_match('#^[^/]+/course\.json$#', $n)));
    if (count($kandidater) !== 1) return null;
    $prefix = dirname($kandidater[0]) . '/';
    foreach ($namn as $n) {
        if (strpos($n, $prefix) !== 0) return null;   // annat innehåll utanför mappen — inte entydigt
    }
    return $prefix;
}

function kurspaketStada(?string $tempdir): void {
    if (!$tempdir || !is_dir($tempdir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempdir, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($tempdir);
}

/**
 * Kontrollera hela paketet. $bilder = bilderna i ZIP:ens images/ (filnamn => sökväg),
 * eller null när paketet är en ren JSON-fil (då får image_file inte användas).
 * Returnerar ['fel' => [...], 'varningar' => [...]]. Fel stoppar inläsningen.
 */
function kurspaketValidera($p, ?array $bilder = null): array {
    $fel = [];
    $varn = [];
    if (!is_array($p)) return ['fel' => ['Paketet är inte ett JSON-objekt'], 'varningar' => []];
    if (($p['format'] ?? null) !== KURSPAKET_FORMAT) $fel[] = 'format ska vara "' . KURSPAKET_FORMAT . '"';
    $c = $p['course'] ?? null;
    if (!is_array($c)) return ['fel' => array_merge($fel, ['course saknas']), 'varningar' => $varn];

    kpTextfalt($c, 'title', 'course', 3, 255, $fel);
    kpTextfalt($c, 'description', 'course', 20, 2000, $fel);
    if (isset($c['difficulty_level']) && !in_array($c['difficulty_level'], ['beginner', 'intermediate', 'advanced'], true))
        $fel[] = 'course.difficulty_level ska vara beginner, intermediate eller advanced';
    if (isset($c['image_style']) && !in_array($c['image_style'], ['illustration', 'foto', 'abstrakt'], true))
        $fel[] = 'course.image_style ska vara illustration, foto eller abstrakt';
    if (isset($c['image_wish']) && mb_strlen($c['image_wish']) > 300) $fel[] = 'course.image_wish får vara högst 300 tecken';
    kpBildfil($c, 'course', $bilder, $fel);
    if (isset($c['completion_content'])) kpInnehall($c['completion_content'], 'course.completion_content', $fel);

    $lektioner = $p['lessons'] ?? null;
    if (!is_array($lektioner) || !$lektioner) return ['fel' => array_merge($fel, ['lessons saknas eller är tom']), 'varningar' => $varn];
    if (count($lektioner) > 60) $fel[] = 'högst 60 lektioner per kurs';

    $nycklar = [];
    $vanliga = 0;
    foreach ($lektioner as $i => $l) {
        $var = "lessons[$i]";
        if (!is_array($l)) { $fel[] = "$var är inte ett objekt"; continue; }
        $key = $l['key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-z0-9_-]{1,40}$/', $key)) $fel[] = "$var.key ska vara en kort nyckel (a-z, 0-9, _ -)";
        elseif (isset($nycklar[$key])) $fel[] = "$var.key \"$key\" används redan";
        $var = "lessons[$i] (\"" . (is_string($key) ? $key : '?') . "\")";

        $typ = $l['lesson_type'] ?? 'lesson';
        if (!in_array($typ, ['lesson', 'info_page'], true)) $fel[] = "$var.lesson_type ska vara lesson eller info_page";
        if ($typ === 'lesson') $vanliga++;

        kpTextfalt($l, 'title', $var, 2, 250, $fel);
        if (isset($l['estimated_duration']) && (!is_int($l['estimated_duration']) || $l['estimated_duration'] < 1 || $l['estimated_duration'] > 120))
            $fel[] = "$var.estimated_duration ska vara ett heltal 1–120 (minuter)";
        if (!isset($l['content']) || !is_string($l['content']) || trim(strip_tags($l['content'])) === '') $fel[] = "$var.content saknas";
        else kpInnehall($l['content'], "$var.content", $fel);

        if (!empty($l['belongs_to'])) {
            if ($typ !== 'info_page') $fel[] = "$var.belongs_to får bara anges på info_page";
            elseif (($nycklar[$l['belongs_to']] ?? null) !== 'lesson') $fel[] = "$var.belongs_to måste peka på en TIDIGARE lektion av typen lesson";
        }
        if (!empty($l['video_url']) && !preg_match('#^https://(www\.)?(youtube\.com/watch\?v=|youtu\.be/)[A-Za-z0-9_-]{11}#', $l['video_url']))
            $fel[] = "$var.video_url måste vara en YouTube-länk (https://www.youtube.com/watch?v=… eller https://youtu.be/…)";
        $markorer = isset($l['content']) && is_string($l['content']) ? preg_match_all('/\[video\]/i', $l['content']) : 0;
        if (!empty($l['video_url']) && $markorer > 1) $fel[] = "$var.content har mer än en [video]-markör";
        if (empty($l['video_url']) && $markorer) $fel[] = "$var.content har en [video]-markör men video_url saknas";
        if (isset($l['image_wish']) && mb_strlen($l['image_wish']) > 300) $fel[] = "$var.image_wish får vara högst 300 tecken";
        kpBildfil($l, $var, $bilder, $fel);
        if (isset($l['ai_chat']) && (!is_array($l['ai_chat']) || (empty($l['ai_chat']['instruction']) && empty($l['ai_chat']['prompt']))))
            $fel[] = "$var.ai_chat ska ha instruction och/eller prompt";

        $fragor = $l['questions'] ?? [];
        if ($typ === 'info_page' && $fragor) $fel[] = "$var är en info_page och får inte ha questions";
        if ($typ === 'lesson') {
            if (!is_array($fragor)) $fel[] = "$var.questions ska vara en lista";
            elseif (!$fragor) $varn[] = "$var har inga quizfrågor — lektionen godkänns då utan quiz";
            else foreach ($fragor as $qi => $q) kpFraga($q, "$var.questions[$qi]", $fel, $varn);
        }
        if (is_string($key)) $nycklar[$key] = $typ;
    }
    if ($vanliga === 0) $fel[] = 'kursen måste ha minst en lektion av typen lesson';

    // Bilder i ZIP:en som inget pekar på — sannolikt ett stavfel i image_file
    if ($bilder) {
        $anvanda = array_filter(array_merge([$c['image_file'] ?? null], array_map(fn($l) => is_array($l) ? ($l['image_file'] ?? null) : null, $lektioner)));
        foreach (array_diff(array_keys($bilder), $anvanda) as $oanvand) $varn[] = "images/$oanvand används inte av någon image_file";
    }
    return ['fel' => $fel, 'varningar' => $varn];
}

/**
 * Skriv kursen. Förutsätter ett validerat paket. $forfattare = ['id' => int, 'email' => string].
 * Kursen hamnar i författarens organisation (e-postdomänen) och är alltid inaktiv.
 * Returnerar ['kurs_id', 'lektioner' => [key => id], 'bilder' => antal, 'saknar_bild' => [...]].
 * Kastar Exception — då är ingenting sparat och inga bilder kvar.
 */
function kurspaketLasIn(array $p, array $forfattare, array $bilder = []): array {
    $org = substr(strrchr($forfattare['email'], '@'), 1);
    $c = $p['course'];

    // Bilderna kopieras med nya, slumpade namn. Listan behövs för att kunna ta
    // bort dem igen om databasskrivningen misslyckas.
    $kopierade = [];
    $bildFor = function (array $o) use ($bilder, &$kopierade): ?string {
        $fil = $o['image_file'] ?? null;
        if (!$fil) return null;
        if (!isset($kopierade[$fil])) {
            $ext = strtolower(pathinfo($fil, PATHINFO_EXTENSION));
            $nytt = 'kp_' . bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            if (!copy($bilder[$fil], ROOT_PATH . '/public/upload/' . $nytt)) throw new Exception("Kunde inte spara bilden $fil");
            chmod(ROOT_PATH . '/public/upload/' . $nytt, 0644);
            $kopierade[$fil] = $nytt;
        }
        return $kopierade[$fil];
    };

    try {
        execute("START TRANSACTION");
        $maxOrder = (int)(queryOne("SELECT COALESCE(MAX(sort_order), 0) AS m FROM " . DB_DATABASE . ".courses")['m'] ?? 0);
        execute(
            "INSERT INTO " . DB_DATABASE . ".courses
             (title, description, difficulty_level, duration_minutes, prerequisites, image_url, image_style,
              completion_content, allow_quiz_retry, status, sort_order, featured, author_id,
              organization_domain, original_organization_domain, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?, 0, ?, ?, ?, NOW(), NOW())",
            [
                $c['title'], $c['description'],
                $c['difficulty_level'] ?? 'beginner',
                (int)($c['duration_minutes'] ?? array_sum(array_map(fn($l) => (int)($l['estimated_duration'] ?? 5), $p['lessons']))),
                $c['prerequisites'] ?? null,
                $bildFor($c),
                $c['image_style'] ?? 'illustration',
                $c['completion_content'] ?? null,
                array_key_exists('allow_quiz_retry', $c) ? (int)(bool)$c['allow_quiz_retry'] : 1,
                $maxOrder + 1,
                (int)$forfattare['id'], $org, $org,
            ]
        );
        $kursId = (int)queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        execute("INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by) VALUES (?, ?, ?)",
                [$kursId, $forfattare['email'], (int)$forfattare['id']]);

        $idFor = [];
        $saknarBild = [];
        foreach ($p['lessons'] as $i => $l) {
            $typ = ($l['lesson_type'] ?? 'lesson') === 'info_page' ? 'info_page' : 'lesson';
            $ai1 = $l['ai_chat']['instruction'] ?? null;
            $ai2 = $l['ai_chat']['prompt'] ?? null;
            $video = !empty($l['video_url']) ? $l['video_url'] : null;
            $bild = $bildFor($l);
            if (!$bild && !empty($l['image_wish'])) $saknarBild[$l['key']] = $l['image_wish'];

            execute(
                "INSERT INTO " . DB_DATABASE . ".lessons
                 (course_id, lesson_type, belongs_to_lesson_id, title, estimated_duration, image_url, content,
                  video_url, video_type, video_position, status, sort_order,
                  ai_instruction, ai_prompt, ai_chat_enabled, quiz_pass_mode, author_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $kursId, $typ,
                    ($typ === 'info_page' && !empty($l['belongs_to'])) ? $idFor[$l['belongs_to']] : null,
                    $l['title'], (int)($l['estimated_duration'] ?? 5), $bild, $l['content'],
                    $video, $video ? 'youtube' : null,
                    ($l['video_position'] ?? 'bottom') === 'top' ? 'top' : 'bottom',
                    $i,   // paketets ordning = deltagarens ordning
                    $ai1, $ai2, ($ai1 || $ai2) ? 1 : 0,
                    ($l['quiz_pass_mode'] ?? 'require_all_correct') === 'any_result' ? 'any_result' : 'require_all_correct',
                    (int)$forfattare['id'],
                ]
            );
            $lektionId = (int)queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
            $idFor[$l['key']] = $lektionId;

            if ($typ === 'lesson') {
                foreach (($l['questions'] ?? []) as $qi => $q) {
                    execute(
                        "INSERT INTO " . DB_DATABASE . ".quiz_questions
                         (lesson_id, sort_order, question_type, question_text, quiz_data, points) VALUES (?, ?, ?, ?, ?, ?)",
                        [$lektionId, $qi, $q['question_type'], $q['question_text'],
                         json_encode($q['quiz_data'], JSON_UNESCAPED_UNICODE), max(1, (int)($q['points'] ?? 1))]
                    );
                }
            }
        }
        execute("COMMIT");
    } catch (Throwable $e) {
        execute("ROLLBACK");
        foreach ($kopierade as $f) @unlink(ROOT_PATH . '/public/upload/' . $f);
        throw new Exception('Inläsningen avbröts, ingenting sparades: ' . $e->getMessage());
    }

    logActivity($forfattare['email'], "Läste in kurspaket '{$c['title']}' (kurs-ID: $kursId, " . count($kopierade) . " bilder)");
    return [
        'kurs_id' => $kursId,
        'lektioner' => $idFor,
        'bilder' => count($kopierade),
        'kursbild' => !empty($c['image_file']),
        'kursbild_onskemal' => empty($c['image_file']) ? ($c['image_wish'] ?? null) : null,
        'saknar_bild' => $saknarBild,
    ];
}

// ------------------------------------------------------------------ regler

function kpTextfalt(array $o, string $falt, string $var, int $min, int $max, array &$fel): void {
    $v = $o[$falt] ?? null;
    if (!is_string($v) || mb_strlen(trim($v)) < $min) $fel[] = "$var.$falt saknas eller är för kort (minst $min tecken)";
    elseif (mb_strlen($v) > $max) $fel[] = "$var.$falt är för lång (högst $max tecken)";
    elseif ($v !== strip_tags($v)) $fel[] = "$var.$falt får inte innehålla HTML";
}

/** image_file måste peka på en riktig bild i ZIP:ens images/. */
function kpBildfil(array $o, string $var, ?array $bilder, array &$fel): void {
    if (!isset($o['image_file'])) return;
    $f = $o['image_file'];
    if (!is_string($f) || $f === '' || $f !== basename($f)) { $fel[] = "$var.image_file ska vara ett filnamn i images/, utan sökväg"; return; }
    if ($bilder === null) { $fel[] = "$var.image_file kräver att paketet är en ZIP-fil med bilden i images/"; return; }
    if (!isset($bilder[$f])) { $fel[] = "$var.image_file: images/$f finns inte i ZIP-filen"; return; }
    $sok = $bilder[$f];
    if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), KP_BILDTILLAGG, true)) $fel[] = "$var.image_file: bara JPG, PNG och WebP";
    elseif (filesize($sok) > KP_MAX_BILD) $fel[] = "$var.image_file: images/$f är större än 5 MB";
    elseif (!in_array(mime_content_type($sok), KP_BILDTYPER, true) || @getimagesize($sok) === false) $fel[] = "$var.image_file: images/$f är ingen giltig bild";
}

/** HTML som cleanHtml() skulle ändra eller ta bort räknas som fel — då blir resultatet inte det som skrevs. */
function kpInnehall(string $html, string $var, array &$fel): void {
    preg_match_all('/<\s*([a-z][a-z0-9]*)\b/i', $html, $m);
    foreach (array_unique(array_map('strtolower', $m[1])) as $tagg) {
        if ($tagg === 'img') $fel[] = "$var innehåller <img> — bilder i löptext stöds inte (använd image_file eller image_wish)";
        elseif ($tagg === 'h1') $fel[] = "$var använder <h1> — använd h3 för huvudrubriker i lektionen";
        elseif (!in_array($tagg, KP_TAGGAR, true)) $fel[] = "$var innehåller <$tagg>, som Stimma tar bort vid visning";
    }
    preg_match_all('/<div\b([^>]*)>/i', $html, $d);
    foreach ($d[1] as $attr) {
        if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $attr, $k) && !in_array($k[1], KP_DIV_KLASSER, true))
            $fel[] = "$var har div-klassen \"{$k[1]}\", som Stimma tar bort (tillåtna: lesson-intro, -tip, -info, -example, -warning, -summary)";
    }
    preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']*)["\']/i', $html, $l);
    foreach ($l[1] as $href) {
        if (!preg_match('#^https?://#i', $href)) $fel[] = "$var har länken \"$href\" — bara http(s)-länkar fungerar";
    }
    if (preg_match('/<!--(?!\s*pagebreak\s*-->)/i', $html)) $fel[] = "$var har en HTML-kommentar som inte är <!-- pagebreak -->";
    if (preg_match('/\son\w+\s*=|javascript:|<script|<style/i', $html)) $fel[] = "$var innehåller skript eller stil-taggar";
}

function kpFraga($q, string $var, array &$fel, array &$varn): void {
    if (!is_array($q)) { $fel[] = "$var är inte ett objekt"; return; }
    $typ = $q['question_type'] ?? null;
    if (in_array($typ, ['image_choice', 'hotspot'], true)) { $fel[] = "$var: $typ kräver uppladdade bilder och stöds inte i kurspaket"; return; }
    if (!in_array($typ, ['single_choice','multiple_choice','true_false','fill_blank','order','match_pairs','categorize','numeric','short_text'], true)) {
        $fel[] = "$var.question_type \"" . (is_string($typ) ? $typ : '?') . "\" är okänd"; return;
    }
    if (!is_string($q['question_text'] ?? null) || trim(strip_tags($q['question_text'])) === '') $fel[] = "$var.question_text saknas";
    else kpInnehall($q['question_text'], "$var.question_text", $fel);
    $d = $q['quiz_data'] ?? null;
    if (!is_array($d)) { $fel[] = "$var.quiz_data saknas"; return; }

    $text = fn($v, int $min) => is_array($v) && array_is_list($v) && count($v) >= $min
        && count(array_filter($v, fn($x) => is_string($x) && trim($x) !== '')) === count($v);
    $unika = fn(array $v) => count(array_unique(array_map(fn($x) => mb_strtolower(trim((string)$x)), $v))) === count($v);

    switch ($typ) {
        case 'single_choice':
            if (!$text($d['answers'] ?? null, 2)) { $fel[] = "$var: answers ska vara minst 2 icke-tomma alternativ"; break; }
            if (!$unika($d['answers'])) $fel[] = "$var: svarsalternativen måste vara unika";
            if (!is_int($d['correct'] ?? null) || $d['correct'] < 0 || $d['correct'] >= count($d['answers']))
                $fel[] = "$var: correct ska vara index (0-baserat) för det rätta svaret";
            break;
        case 'multiple_choice':
            if (!$text($d['answers'] ?? null, 3)) { $fel[] = "$var: answers ska vara minst 3 icke-tomma alternativ"; break; }
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
                if (!$text($blank['answers'] ?? null, 1)) $fel[] = "$var: blanks[$bi].answers ska vara minst ett godkänt svar";
            }
            break;
        case 'order':
            if (!$text($d['items'] ?? null, 3)) $fel[] = "$var: items ska vara minst 3 steg, i RÄTT ordning";
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
            if (!$text($kat, 2) || !is_array($obj) || count($obj) < 4) { $fel[] = "$var: minst 2 categories och 4 items krävs"; break; }
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
            if (!$text($d['answers'] ?? null, 1)) $fel[] = "$var: answers ska vara minst ett godkänt svar";
            break;
    }
}

} // end KURSPAKET_FORMAT guard
