<?php
/**
 * Stimma — SCORM-paket → färdig kurs, utan AI.
 *
 * Det här är "kopiera"-läget: originalets text, alla bilder och alla filmer
 * följer med så oförändrat som formatet tillåter. AI-läget (se
 * process_ai_jobs.php) skriver istället om innehållet och är ett aktivt val.
 *
 * Paketets HTML/JS körs aldrig. Storyline-innehåll byggs upp från paketets
 * JSON-data; generisk HTML saneras med cleanHtml() innan den sparas.
 */

require_once __DIR__ . '/scorm_extractor.php';
require_once __DIR__ . '/scorm_storyline.php';

if (!function_exists('scormBuildFidelityLessons')) {

define('SCORM_MEDIA_TOTAL_LIMIT', 419430400);   // 400 MB media per import

/**
 * Bygg lektioner ur ett paket.
 *
 * @return array {
 *   title: string, tool: string, schema: string,
 *   lessons: [ ['title','content','image_filename','video_filename'], ... ],
 *   stats: ['image_count','video_count','char_count']
 * }
 */
function scormBuildFidelityLessons(string $zipPath, string $mediaDir): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new Exception('Kunde inte öppna zip-filen.');
    }
    try {
        scormGuardZip($zip);
        $manifestPath = scormFindManifest($zip);
        if ($manifestPath === null) {
            throw new Exception('imsmanifest.xml hittades inte — filen ser inte ut som ett SCORM-paket.');
        }
        $prefix = scormDirname($manifestPath);
        $manifest = scormParseManifest((string)$zip->getFromName($manifestPath));

        $ctx = [
            'zip'      => $zip,
            'prefix'   => $prefix,
            'mediaDir' => rtrim($mediaDir, '/'),
            'cache'    => [],
            'budget'   => SCORM_MEDIA_TOTAL_LIMIT,
            'images'   => 0,
            'videos'   => 0,
        ];

        $tool = scormGuessTool($zip);
        $lessons = storylineDetect($zip, $prefix)
            ? scormLessonsFromStoryline($zip, $prefix, $ctx)
            : scormLessonsFromHtml($zip, $prefix, $manifest, $ctx);

        $chars = 0;
        foreach ($lessons as $l) $chars += mb_strlen(strip_tags($l['content']));

        return [
            'title'   => $manifest['title'],
            'schema'  => $manifest['schema'],
            'tool'    => $tool,
            'lessons' => $lessons,
            'stats'   => [
                'image_count' => $ctx['images'],
                'video_count' => $ctx['videos'],
                'char_count'  => $chars,
            ],
        ];
    } finally {
        $zip->close();
    }
}

// ---------------------------------------------------------------------------
// Storyline
// ---------------------------------------------------------------------------

/** En scen blir en lektion: sidornas text i ordning, alla bilder, scenens film. */
function scormLessonsFromStoryline(ZipArchive $zip, string $prefix, array &$ctx): array {
    $course = storylineParse($zip, $prefix);
    $lessons = [];

    foreach ($course['lessons'] as $index => $scene) {
        $html = '';
        $usedImages = [];   // samma asset återanvänds ofta mellan sidor i en scen
        foreach ($scene['sections'] as $section) {
            $sectionTitle = trim((string)$section['title']);
            $body = '';

            // Brödtext först, svarsalternativ (rader som inleds med "– ") sist,
            // annars hamnar alternativen före frågan de hör till.
            $paragraphs = [];
            $options = [];
            foreach ($section['texts'] as $text) {
                if (mb_substr($text, 0, 2) === '– ') $options[] = mb_substr($text, 2);
                else $paragraphs[] = $text;
            }
            foreach ($paragraphs as $p) {
                if ($sectionTitle !== '' && trim($p) === $sectionTitle) continue;  // dubblerad rubrik
                $body .= scormParagraphHtml($p);
            }
            if (!empty($options)) {
                $body .= "<ul>\n";
                foreach ($options as $o) $body .= '  <li>' . htmlspecialchars($o, ENT_QUOTES, 'UTF-8') . "</li>\n";
                $body .= "</ul>\n";
            }
            foreach ($section['images'] as $imagePath) {
                if (isset($usedImages[$imagePath])) continue;
                $file = scormCopyAsset($ctx, $imagePath, false);
                if ($file) {
                    $usedImages[$imagePath] = true;
                    $body .= '<p><img src="upload/' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
                           . '" alt="' . htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') . '"></p>' . "\n";
                }
            }
            if (trim($body) === '') continue;
            if ($sectionTitle !== '') {
                $html .= '<h3>' . htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') . "</h3>\n";
            }
            $html .= $body;
        }

        // Filmer: den första blir lektionens video, resten läggs som egna
        // lektioner så att inget klipp tappas bort.
        $videoFiles = [];
        foreach ($scene['videos'] as $video) {
            $file = scormCopyAsset($ctx, $video['url'], true);
            if ($file) $videoFiles[] = ['file' => $file, 'title' => $video['title']];
        }

        if (trim($html) === '' && empty($videoFiles)) continue;

        $lessons[] = [
            'title'          => $scene['title'] !== '' ? $scene['title'] : 'Avsnitt ' . ($index + 1),
            'content'        => trim($html),
            'image_filename' => null,
            'video_filename' => $videoFiles[0]['file'] ?? null,
        ];
        for ($i = 1; $i < count($videoFiles); $i++) {
            $lessons[] = [
                'title'          => trim($scene['title'] . ' — film ' . ($i + 1)),
                'content'        => '<p>Film ur originalpaketet.</p>',
                'image_filename' => null,
                'video_filename' => $videoFiles[$i]['file'],
            ];
        }
    }
    return $lessons;
}

// ---------------------------------------------------------------------------
// Generiska HTML-paket
// ---------------------------------------------------------------------------

/** Ett SCO blir en lektion: originalets HTML sanerad, alla bilder och filmer med. */
function scormLessonsFromHtml(ZipArchive $zip, string $prefix, array $manifest, array &$ctx): array {
    $lessons = [];
    foreach ($manifest['items'] as $index => $item) {
        $entry = $prefix . ltrim($item['href'], '/');
        $raw = scormReadEntry($zip, $entry);
        $title = $item['title'] !== '' ? $item['title'] : 'Avsnitt ' . ($index + 1);

        $html = '';
        $videoFiles = [];

        if ($raw !== null && trim($raw) !== '') {
            $built = scormHtmlToLessonHtml($raw, $entry, $ctx);
            $html = $built['html'];
            $videoFiles = $built['videos'];
        }

        // JS-drivna paket: ingen läsbar HTML — falla tillbaka på textskörden
        if (mb_strlen(strip_tags($html)) < SCORM_THIN_TEXT_LIMIT) {
            $harvested = scormHarvestFromAssets($zip, $entry);
            if ($harvested !== '') {
                $fallbackHtml = '';
                foreach (preg_split('/\n+/', $harvested) as $line) {
                    $fallbackHtml .= scormParagraphHtml($line);
                }
                if (mb_strlen(strip_tags($fallbackHtml)) > mb_strlen(strip_tags($html))) {
                    $html = $fallbackHtml;
                }
            }
            // ... och på mediefilerna som ligger i SCO:ns katalog
            if (empty($videoFiles)) {
                foreach (scormMediaInDir($zip, scormDirname($entry), ['mp4', 'webm', 'm4v']) as $v) {
                    $file = scormCopyAsset($ctx, substr($v, strlen($ctx['prefix'])), true);
                    if ($file) $videoFiles[] = ['file' => $file, 'title' => ''];
                }
            }
            if (strpos($html, '<img') === false) {
                foreach (scormImagesInDir($zip, scormDirname($entry)) as $img) {
                    $file = scormCopyAsset($ctx, substr($img, strlen($ctx['prefix'])), false);
                    if ($file) {
                        $html .= '<p><img src="upload/' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" alt=""></p>' . "\n";
                    }
                }
            }
        }

        if (trim($html) === '' && empty($videoFiles)) continue;

        $lessons[] = [
            'title'          => $title,
            'content'        => trim($html),
            'image_filename' => null,
            'video_filename' => $videoFiles[0]['file'] ?? null,
        ];
        for ($i = 1; $i < count($videoFiles); $i++) {
            $lessons[] = [
                'title'          => $title . ' — film ' . ($i + 1),
                'content'        => '<p>Film ur originalpaketet.</p>',
                'image_filename' => null,
                'video_filename' => $videoFiles[$i]['file'],
            ];
        }
    }
    return $lessons;
}

/**
 * Originalets HTML → lektions-HTML: skript, stilar och navigation bort,
 * bilder kopieras och pekas om, filmer plockas ut. Resultatet saneras med
 * cleanHtml() (samma vitlista som lektionseditorn använder).
 */
function scormHtmlToLessonHtml(string $rawHtml, string $entry, array &$ctx): array {
    $html = scormToUtf8($rawHtml);
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return ['html' => '', 'videos' => []];

    $xp = new DOMXPath($dom);

    // Filmer först — noderna försvinner i saneringen
    $videos = [];
    foreach ($xp->query('//video/@src | //video/source/@src | //embed/@src | //object/@data') as $attr) {
        $val = (string)$attr->nodeValue;
        if ($val === '' || preg_match('#^(https?:|data:)#i', $val)) continue;
        $path = scormResolvePath($entry, $val);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'm4v'], true)) continue;
        $file = scormCopyAsset($ctx, substr($path, strlen($ctx['prefix'])), true);
        if ($file) $videos[] = ['file' => $file, 'title' => ''];
    }

    foreach (['script', 'style', 'noscript', 'head', 'iframe', 'svg', 'nav', 'video', 'object', 'embed'] as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $n = $nodes->item($i);
            if ($n && $n->parentNode) $n->parentNode->removeChild($n);
        }
    }

    // Bilder: kopiera ut och peka om src
    foreach ($xp->query('//img') as $img) {
        /** @var DOMElement $img */
        $src = (string)$img->getAttribute('src');
        if ($src === '' && $img->hasAttribute('data-src')) $src = (string)$img->getAttribute('data-src');
        $keep = false;
        if ($src !== '' && !preg_match('#^(https?:|data:)#i', $src)) {
            $path = scormResolvePath($entry, $src);
            $file = scormCopyAsset($ctx, substr($path, strlen($ctx['prefix'])), false);
            if ($file) {
                $img->setAttribute('src', 'upload/' . $file);
                $img->removeAttribute('data-src');
                $keep = true;
            }
        }
        if (!$keep && $img->parentNode) $img->parentNode->removeChild($img);
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    $inner = '';
    if ($body) {
        foreach ($body->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }
    }

    return ['html' => trim(cleanHtml($inner)), 'videos' => $videos];
}

// ---------------------------------------------------------------------------
// Gemensamt
// ---------------------------------------------------------------------------

/** En textklump → ett eller flera <p>, med radbrytningar bevarade. */
function scormParagraphHtml(string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    $out = '';
    foreach (preg_split('/\n{2,}/', $text) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        $escaped = htmlspecialchars($para, ENT_QUOTES, 'UTF-8');
        $escaped = nl2br($escaped, false);
        $out .= '<p>' . $escaped . "</p>\n";
    }
    return $out;
}

/**
 * Kopiera en fil ur paketet till upload/ (bilder) eller upload/videos/ (film).
 * Samma källfil kopieras bara en gång. Returnerar filnamnet eller null.
 */
function scormCopyAsset(array &$ctx, string $relPath, bool $isVideo): ?string {
    $relPath = ltrim($relPath, '/');
    if ($relPath === '') return null;
    if (isset($ctx['cache'][$relPath])) return $ctx['cache'][$relPath];

    $entry = $ctx['prefix'] . $relPath;
    $stat = $ctx['zip']->statName($entry);
    if ($stat === false) {
        $bytes = scormReadEntry($ctx['zip'], $entry);
        if ($bytes === null) return null;
    } else {
        if ((int)$stat['size'] > $ctx['budget']) return null;
        $bytes = scormReadEntry($ctx['zip'], $entry);
        if ($bytes === null) return null;
    }
    if ($bytes === '' || strlen($bytes) > $ctx['budget']) return null;

    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    if ($isVideo) {
        if (!in_array($ext, ['mp4', 'webm', 'm4v'], true)) return null;
        if ($ext === 'm4v') $ext = 'mp4';
        $dir = $ctx['mediaDir'] . '/videos';
    } else {
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) return null;
        if ($ext === 'jpeg') $ext = 'jpg';
        if (@getimagesizefromstring($bytes) === false) return null;
        $dir = $ctx['mediaDir'];
    }
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $filename = 'scorm_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $filename, $bytes) === false) return null;
    @chmod($dir . '/' . $filename, 0644);

    $ctx['budget'] -= strlen($bytes);
    $ctx[$isVideo ? 'videos' : 'images']++;
    $ctx['cache'][$relPath] = $filename;
    return $filename;
}

/** Mediefiler med angivna ändelser under en katalog i zip:en. */
function scormMediaInDir(ZipArchive $zip, string $dir, array $extensions): array {
    $found = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        if ($dir !== '' && strpos($name, $dir) !== 0) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) $found[] = $name;
        if (count($found) >= 40) break;
    }
    return $found;
}

/**
 * Skapa kursen och lektionerna direkt i databasen. Kursen läggs som inaktiv.
 *
 * @return int Kurs-id
 */
function scormCreateCourse(string $courseTitle, string $description, array $lessons, int $userId, string $organizationDomain): int {
    execute("START TRANSACTION");
    try {
        $maxOrder = queryOne("SELECT MAX(sort_order) AS max_order FROM " . DB_DATABASE . ".courses")['max_order'] ?? 0;

        execute(
            "INSERT INTO " . DB_DATABASE . ".courses
               (title, description, difficulty_level, duration_minutes, image_url, status,
                sort_order, featured, author_id, organization_domain, original_organization_domain,
                created_at, updated_at)
             VALUES (?, ?, 'beginner', ?, NULL, 'inactive', ?, 0, ?, ?, ?, NOW(), NOW())",
            [
                $courseTitle,
                $description,
                max(5, count($lessons) * 5),
                (int)$maxOrder + 1,
                $userId,
                $organizationDomain,
                $organizationDomain,
            ]
        );
        $courseId = (int)queryOne("SELECT LAST_INSERT_ID() AS id")['id'];

        execute(
            "INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by)
             SELECT ?, email, id FROM " . DB_DATABASE . ".users WHERE id = ?",
            [$courseId, $userId]
        );

        foreach ($lessons as $index => $lesson) {
            execute(
                "INSERT INTO " . DB_DATABASE . ".lessons
                   (course_id, title, estimated_duration, image_url, video_url, video_type,
                    content, status, sort_order, author_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())",
                [
                    $courseId,
                    mb_substr($lesson['title'], 0, 255),
                    5,
                    $lesson['image_filename'],
                    $lesson['video_filename'],
                    $lesson['video_filename'] ? 'local' : null,
                    $lesson['content'],
                    $index + 1,
                    $userId,
                ]
            );
        }

        execute("COMMIT");
        return $courseId;
    } catch (Throwable $e) {
        execute("ROLLBACK");
        throw $e;
    }
}

} // end function_exists guard
