<?php
/**
 * Stimma — SCORM-paket (.zip) → strukturerad kursdata.
 *
 * Samma idé som include/pptx_extractor.php: vi kör ALDRIG paketets egen
 * HTML/JS. Vi packar upp zip:en, läser imsmanifest.xml för struktur, plockar
 * ut text och media ur varje SCO och lämnar över en formaterad textklump till
 * AI-pipelinen (public/admin/cron/process_ai_jobs.php), som återförfattar
 * innehållet till Stimma-lektioner.
 *
 * Varför konvertering istället för en SCORM-spelare: en spelare kräver att vi
 * serverar främmande HTML/JS på samma origin som appen (lagrad XSS med
 * sessionsstöld). Konverteringen serverar aldrig någon främmande kod.
 *
 * Ett SCORM-paket är en zip med:
 *   imsmanifest.xml                — struktur: organizations → items → resources
 *   <sco>/index.html (eller likn.) — innehållet, ofta HTML, ofta JS-drivet
 *   media/, images/, res/ ...      — bilder, video, ljud
 *
 * Namnrymderna varierar (imscp_v1p1 för SCORM 1.2, adlcp/imscp för 2004), så
 * all XPath använder local-name() istället för prefix.
 *
 * Beroenden: ZipArchive, DOM, libxml, mbstring — finns redan i web-containern.
 */

if (!function_exists('scormExtractPackage')) {

// --- Gränsvärden (skydd mot zip-bomber och diskutrymme) ---------------------
define('SCORM_MAX_ENTRIES', 20000);      // antal filer i zip:en
define('SCORM_MAX_UNCOMPRESSED', 1073741824); // 1 GB uppackat totalt
define('SCORM_MIN_TEXT_PER_SCO', 3000);       // golv per avsnitt
define('SCORM_MAX_TEXT_TOTAL', 60000);      // tecken totalt i textklumpen
define('SCORM_MAX_IMAGE_BYTES', 8388608);    // 8 MB per bild
define('SCORM_MIN_IMAGE_WIDTH', 200);        // filtrerar bort ikoner/spacers
define('SCORM_MIN_IMAGE_HEIGHT', 150);
define('SCORM_MAX_VIDEO_BYTES', 62914560);   // 60 MB per video
define('SCORM_MAX_VIDEO_TOTAL', 209715200);  // 200 MB video per import
define('SCORM_MAX_ASSET_FILES', 15);         // fallback: filer att skanna per SCO
define('SCORM_MAX_ASSET_BYTES', 4194304);    // fallback: max 4 MB per skannad fil
define('SCORM_THIN_TEXT_LIMIT', 250);        // under detta anses HTML:en tom

/**
 * Packa upp och analysera ett SCORM-paket.
 *
 * @param string $zipPath      Sökväg till .zip
 * @param string $mediaOutDir  Katalog för bilder (video hamnar i $mediaOutDir/videos)
 * @param array  $opts         ['max_items' => int]
 * @return array {
 *   title:  string,           // kurstitel ur manifestet
 *   schema: string,           // "SCORM 1.2" / "SCORM 2004" / "okänd"
 *   tool:   string,           // gissat författarverktyg
 *   items:  array,            // [['title','text','image_filename','video_filename','href'], ...]
 *   stats:  array             // ['image_count','video_count','fallback_used','empty_items','total_items']
 * }
 * @throws Exception  Om filen inte är ett läsbart SCORM-paket.
 */
function scormExtractPackage(string $zipPath, string $mediaOutDir, array $opts = []): array {
    if (!is_file($zipPath)) {
        throw new Exception('Zip-filen saknas: ' . $zipPath);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new Exception('Kunde inte öppna zip-filen (är den korrupt?).');
    }

    try {
        scormGuardZip($zip);

        $manifestPath = scormFindManifest($zip);
        if ($manifestPath === null) {
            throw new Exception('imsmanifest.xml hittades inte — filen ser inte ut som ett SCORM-paket.');
        }
        $prefix = scormDirname($manifestPath); // '' eller 't.ex. "kurs/"'

        $manifestXml = $zip->getFromName($manifestPath);
        if ($manifestXml === false || $manifestXml === '') {
            throw new Exception('imsmanifest.xml gick inte att läsa.');
        }

        $manifest = scormParseManifest($manifestXml);
        if (empty($manifest['items'])) {
            throw new Exception('Manifestet innehåller inga lektioner (items/resources saknas).');
        }

        $maxItems = (int)($opts['max_items'] ?? 25);
        $items    = array_slice($manifest['items'], 0, max(1, $maxItems));

        if (!is_dir($mediaOutDir)) @mkdir($mediaOutDir, 0755, true);
        $videoOutDir = rtrim($mediaOutDir, '/') . '/videos';

        // Textbudgeten fördelas över avsnitten. Ett paket med EN SCO (Rise 360,
        // iSpring och Storyline packar ofta hela kursen så) får alltså hela
        // budgeten — ett fast tak per SCO skulle klippa bort merparten av kursen.
        $perItemBudget = max(SCORM_MIN_TEXT_PER_SCO, (int)floor(SCORM_MAX_TEXT_TOTAL / max(1, count($items))));

        $mediaCache   = [];   // zip-path → utdatafilnamn (undviker dubbletter på disk)
        $videoBudget  = SCORM_MAX_VIDEO_TOTAL;
        $textBudget   = SCORM_MAX_TEXT_TOTAL;
        $fallbackUsed = false;
        $out          = [];

        foreach ($items as $idx => $item) {
            $entry = $prefix . ltrim($item['href'], '/');
            $html  = scormReadEntry($zip, $entry);

            $text  = '';
            $media = ['images' => [], 'videos' => []];

            if ($html !== null) {
                $parsed = scormParseHtml($html, $entry);
                $text   = $parsed['text'];
                $media  = $parsed['media'];
            }

            // JS-drivna paket (Rise 360, Storyline, iSpring, Captivate) har
            // nästan ingen text i HTML-skalet — innehållet ligger i en JSON-
            // eller XML-payload bredvid. Skanna då syskonfilerna.
            $itemUsedFallback = false;
            if (mb_strlen($text) < SCORM_THIN_TEXT_LIMIT) {
                $harvested = scormHarvestFromAssets($zip, $entry);
                if (mb_strlen($harvested) > mb_strlen($text)) {
                    $text = $harvested;
                    $fallbackUsed = true;
                    $itemUsedFallback = true;
                }
            }

            $text = scormTrimText($text, min($perItemBudget, max(0, $textBudget)));
            $textBudget -= mb_strlen($text);

            // Bild: största kandidaten som klarar minimimåtten
            $imageFilename = scormPickImage($zip, $media['images'], $mediaOutDir, $mediaCache);
            if ($imageFilename === null && $itemUsedFallback) {
                // JS-drivet paket: bilderna refereras från koden, inte från
                // HTML:en. Ta största bilden i SCO:ns katalog istället.
                $imageFilename = scormPickImage(
                    $zip, scormImagesInDir($zip, scormDirname($entry)), $mediaOutDir, $mediaCache
                );
            }

            // Video: första lokala filen som ryms i budgeten
            $videoFilename = null;
            if ($videoBudget > 0) {
                $videoFilename = scormPickVideo($zip, $media['videos'], $videoOutDir, $mediaCache, $videoBudget);
            }

            $out[] = [
                'title'          => $item['title'] !== '' ? $item['title'] : ('Avsnitt ' . ($idx + 1)),
                'text'           => $text,
                'image_filename' => $imageFilename,
                'video_filename' => $videoFilename,
                'href'           => $item['href'],
            ];
        }

        $tool = scormGuessTool($zip);

        $imageCount = 0; $videoCount = 0; $emptyItems = 0;
        foreach ($out as $o) {
            if ($o['image_filename']) $imageCount++;
            if ($o['video_filename']) $videoCount++;
            if (trim($o['text']) === '') $emptyItems++;
        }

        return [
            'title'  => $manifest['title'] !== '' ? $manifest['title'] : '',
            'schema' => $manifest['schema'],
            'tool'   => $tool,
            'items'  => $out,
            'stats'  => [
                'image_count'    => $imageCount,
                'video_count'    => $videoCount,
                'fallback_used'  => $fallbackUsed,
                'empty_items'    => $emptyItems,
                'total_items'    => count($out),
                'manifest_items' => count($manifest['items']),
            ],
        ];
    } finally {
        $zip->close();
    }
}

// ---------------------------------------------------------------------------
// Zip-hjälpare
// ---------------------------------------------------------------------------

/** Kasta om zip:en ser ut som en zip-bomb. */
function scormGuardZip(ZipArchive $zip): void {
    if ($zip->numFiles > SCORM_MAX_ENTRIES) {
        throw new Exception('Zip-filen innehåller för många filer (' . $zip->numFiles . ').');
    }
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) continue;
        $total += (int)$st['size'];
        if ($total > SCORM_MAX_UNCOMPRESSED) {
            throw new Exception('Zip-filen packas upp till mer än 1 GB — avbryter.');
        }
    }
}

/**
 * Hitta imsmanifest.xml. Vissa paket har en omslutande mapp; välj då den
 * grundaste förekomsten.
 */
function scormFindManifest(ZipArchive $zip): ?string {
    $best = null; $bestDepth = PHP_INT_MAX;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        if (strtolower(basename($name)) !== 'imsmanifest.xml') continue;
        $depth = substr_count(trim($name, '/'), '/');
        if ($depth < $bestDepth) { $best = $name; $bestDepth = $depth; }
    }
    return $best;
}

/** Läs en zip-post, oavsett om paketet använder blandad skiftlägesnotation. */
function scormReadEntry(ZipArchive $zip, string $path): ?string {
    $data = $zip->getFromName($path);
    if ($data !== false) return $data;

    // Fallback: skiftlägesokänslig matchning (Windows-byggda paket refererar
    // ofta "Media/Bild.PNG" fast posten heter "media/bild.png"). Indexet
    // cachas per zip — inte statiskt globalt, annars läcker det mellan paket
    // om flera läses i samma PHP-process.
    static $indexes = [];
    $zipKey = (string)$zip->filename;
    if (!isset($indexes[$zipKey])) {
        $index = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false) $index[strtolower($n)] = $n;
        }
        $indexes[$zipKey] = $index;
    }
    $key = strtolower($path);
    if (isset($indexes[$zipKey][$key])) {
        $data = $zip->getFromName($indexes[$zipKey][$key]);
        if ($data !== false) return $data;
    }
    return null;
}

/** Katalogdelen av en zip-sökväg, med avslutande '/' (eller ''). */
function scormDirname(string $path): string {
    $pos = strrpos($path, '/');
    return $pos === false ? '' : substr($path, 0, $pos + 1);
}

/** Lös en relativ referens mot en zip-sökväg och normalisera bort ".."/".". */
function scormResolvePath(string $baseFile, string $target): string {
    $target = preg_replace('/[?#].*$/', '', $target);
    $target = rawurldecode($target);
    if ($target === '') return '';
    if ($target[0] === '/') {
        $combined = ltrim($target, '/');
    } else {
        $combined = scormDirname($baseFile) . $target;
    }
    $stack = [];
    foreach (explode('/', $combined) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($stack); continue; }
        $stack[] = $p;
    }
    return implode('/', $stack);
}

// ---------------------------------------------------------------------------
// Manifest
// ---------------------------------------------------------------------------

/**
 * Tolka imsmanifest.xml → ['title', 'schema', 'items' => [['title','href'], ...]].
 *
 * Ordningen kommer från <organization>-trädet (den ordning eleven skulle mött
 * innehållet). Saknas organizations används resources i manifest-ordning.
 */
function scormParseManifest(string $xml): array {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) {
        throw new Exception('imsmanifest.xml är inte giltig XML.');
    }
    $xp = new DOMXPath($dom);

    // Schema-version (metadata/schemaversion) — bara för loggning/UI.
    $schema = 'okänd';
    $verNodes = $xp->query('//*[local-name()="schemaversion"]');
    if ($verNodes->length > 0) {
        $v = trim($verNodes->item(0)->nodeValue);
        if ($v !== '') {
            $schema = (stripos($v, '2004') !== false || stripos($v, 'CAM') !== false)
                ? 'SCORM 2004 (' . $v . ')' : 'SCORM ' . $v;
        }
    }

    // Resources: identifier → href (med ev. xml:base på både resources och resource)
    $resources = [];
    $resourcesBase = '';
    $resNodes = $xp->query('//*[local-name()="resources"]');
    if ($resNodes->length > 0) {
        $resourcesBase = (string)$resNodes->item(0)->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
    }
    foreach ($xp->query('//*[local-name()="resource"]') as $res) {
        /** @var DOMElement $res */
        $id = (string)$res->getAttribute('identifier');
        if ($id === '') continue;
        $href = (string)$res->getAttribute('href');
        $base = (string)$res->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
        if ($href === '') {
            // Ingen startpunkt angiven — ta första <file> som är HTML.
            foreach ($xp->query('./*[local-name()="file"]', $res) as $f) {
                $fh = (string)$f->getAttribute('href');
                if (preg_match('/\.x?html?($|[?#])/i', $fh)) { $href = $fh; break; }
            }
        }
        if ($href === '') continue;
        $resources[$id] = scormJoinBase($resourcesBase, $base, $href);
    }

    // Organization
    $title = '';
    $items = [];
    $orgsNodes = $xp->query('//*[local-name()="organizations"]');
    $orgNode = null;
    if ($orgsNodes->length > 0) {
        /** @var DOMElement $orgs */
        $orgs = $orgsNodes->item(0);
        $default = (string)$orgs->getAttribute('default');
        foreach ($xp->query('./*[local-name()="organization"]', $orgs) as $o) {
            /** @var DOMElement $o */
            if ($orgNode === null) $orgNode = $o;
            if ($default !== '' && (string)$o->getAttribute('identifier') === $default) {
                $orgNode = $o; break;
            }
        }
    }
    if ($orgNode !== null) {
        $title = scormChildTitle($xp, $orgNode);
        scormWalkItems($xp, $orgNode, $resources, $items, '');
    }

    // Inga items (eller inga med innehåll) → falla tillbaka på resources
    if (empty($items)) {
        $n = 0;
        foreach ($resources as $href) {
            $items[] = ['title' => 'Avsnitt ' . (++$n), 'href' => $href];
        }
    }

    // Kursens titel: manifestets organization-titel, annars <title> var som helst
    if ($title === '') {
        $t = $xp->query('//*[local-name()="title"]');
        if ($t->length > 0) $title = trim($t->item(0)->nodeValue);
    }

    return ['title' => $title, 'schema' => $schema, 'items' => $items];
}

/** Slå ihop xml:base-nivåerna med href till en paketrelativ sökväg. */
function scormJoinBase(string $resourcesBase, string $resourceBase, string $href): string {
    $parts = array_filter([trim($resourcesBase, '/'), trim($resourceBase, '/')], fn($p) => $p !== '');
    $prefix = $parts ? implode('/', $parts) . '/' : '';
    return $prefix . ltrim($href, '/');
}

/** Direkt <title>-barn till en nod (inte barnbarn — de tillhör underliggande items). */
function scormChildTitle(DOMXPath $xp, DOMElement $node): string {
    $t = $xp->query('./*[local-name()="title"]', $node);
    return $t->length > 0 ? trim(preg_replace('/\s+/u', ' ', $t->item(0)->nodeValue)) : '';
}

/**
 * Gå igenom <item>-trädet djupet-först. Items utan identifierref är rubriker
 * (moduler) — deras titel blir prefix på barnens titlar så AI:n ser strukturen.
 */
function scormWalkItems(DOMXPath $xp, DOMElement $parent, array $resources, array &$items, string $titlePrefix): void {
    foreach ($xp->query('./*[local-name()="item"]', $parent) as $item) {
        /** @var DOMElement $item */
        $itemTitle = scormChildTitle($xp, $item);
        $ref       = (string)$item->getAttribute('identifierref');
        $visible   = strtolower((string)$item->getAttribute('isvisible'));
        $fullTitle = $titlePrefix !== '' && $itemTitle !== ''
            ? $titlePrefix . ' — ' . $itemTitle
            : ($itemTitle !== '' ? $itemTitle : $titlePrefix);

        if ($ref !== '' && isset($resources[$ref]) && $visible !== 'false') {
            $items[] = ['title' => $fullTitle, 'href' => $resources[$ref]];
        }
        // Barn kan finnas även på items med identifierref (SCORM 2004-aggregat)
        scormWalkItems($xp, $item, $resources, $items, $fullTitle);
    }
}

// ---------------------------------------------------------------------------
// HTML → text + mediareferenser
// ---------------------------------------------------------------------------

/**
 * Plocka läsbar text och mediareferenser ur en SCO:s HTML.
 * Paketets JS körs aldrig — vi läser bara DOM:en som text.
 *
 * @return array ['text' => string, 'media' => ['images' => string[], 'videos' => string[]]]
 */
function scormParseHtml(string $html, string $htmlPath): array {
    $empty = ['text' => '', 'media' => ['images' => [], 'videos' => []]];
    if (trim($html) === '') return $empty;

    $html = scormToUtf8($html);

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return $empty;

    $xp = new DOMXPath($dom);

    // Media innan vi river ut noder
    $images = [];
    $videos = [];
    foreach ($xp->query('//img/@src | //img/@data-src') as $a) {
        $p = scormResolvePath($htmlPath, (string)$a->nodeValue);
        if ($p !== '' && !preg_match('#^(https?:|data:)#i', (string)$a->nodeValue)) $images[] = $p;
    }
    foreach ($xp->query('//video/@src | //video/source/@src | //object/@data | //embed/@src') as $a) {
        $val = (string)$a->nodeValue;
        if (preg_match('#^(https?:|data:)#i', $val)) continue;
        $p = scormResolvePath($htmlPath, $val);
        if ($p === '') continue;
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm', 'm4v'], true)) $videos[] = $p;
    }

    // Bort med sådant som inte är läsbart innehåll
    foreach (['script', 'style', 'noscript', 'head', 'iframe', 'svg'] as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $n = $nodes->item($i);
            if ($n && $n->parentNode) $n->parentNode->removeChild($n);
        }
    }

    $body = $dom->getElementsByTagName('body')->item(0) ?: $dom->documentElement;
    $text = $body ? scormNodeToText($body) : '';

    return [
        'text'  => scormNormalizeText($text),
        'media' => ['images' => array_values(array_unique($images)), 'videos' => array_values(array_unique($videos))],
    ];
}

/** Rekursiv nod → text med radbrytningar vid blockelement och "- " vid listor. */
function scormNodeToText(DOMNode $node): string {
    if ($node->nodeType === XML_TEXT_NODE) {
        return preg_replace('/\s+/u', ' ', (string)$node->nodeValue);
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) return '';

    $tag = strtolower($node->nodeName);
    if ($tag === 'br') return "\n";

    $blocks = ['p','div','section','article','header','footer','h1','h2','h3','h4','h5','h6',
               'ul','ol','table','tr','blockquote','pre','figcaption','dl','dt','dd','main','aside'];

    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= scormNodeToText($child);
    }
    if ($tag === 'li') {
        $line = trim(preg_replace('/\s*\n\s*/u', ' ', $out));
        return $line === '' ? '' : "- " . $line . "\n";
    }
    if ($tag === 'td' || $tag === 'th') {
        return trim($out) . "\t";
    }
    if (in_array($tag, $blocks, true)) {
        return "\n" . $out . "\n";
    }
    return $out;
}

/** Städa text: trimma rader, max en tom rad mellan stycken, ta bort dubbletter i rad. */
function scormNormalizeText(string $text): string {
    $text = str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", ' '], $text);
    // Ligaturer (ﬁ, ﬂ, ﬀ …) förekommer i paket vars text kommer från ett
    // PDF-/InDesign-original. Lämnas de kvar blir orden obegripliga för AI:n.
    $text = strtr($text, [
        "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
        "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{FB05}" => 'st', "\u{FB06}" => 'st',
    ]);
    $lines = [];
    $prevLine = null;
    foreach (explode("\n", $text) as $line) {
        $line = trim(preg_replace('/[ \t]+/u', ' ', $line));
        if ($line === '') {
            if ($prevLine === '') continue;
            $lines[] = '';
            $prevLine = '';
            continue;
        }
        if ($line === $prevLine) continue; // navigations-etiketter upprepas ofta
        $lines[] = $line;
        $prevLine = $line;
    }
    return trim(implode("\n", $lines));
}

/** Klipp texten vid ordgräns till angiven längd. */
function scormTrimText(string $text, int $max): string {
    if ($max <= 0) return '';
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $lastSpace = mb_strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > $max * 0.6) $cut = mb_substr($cut, 0, $lastSpace);
    return rtrim($cut) . ' …';
}

/** Konvertera till UTF-8 om dokumentet deklarerar en annan teckenkodning. */
function scormToUtf8(string $html): string {
    if (preg_match('/charset\s*=\s*["\']?\s*([a-z0-9_\-]+)/i', substr($html, 0, 4096), $m)) {
        $charset = strtoupper($m[1]);
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            if ($converted !== false && $converted !== '') return scormRewriteCharsetMeta($converted);
        }
    }
    if (!mb_check_encoding($html, 'UTF-8')) {
        $converted = @mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        if ($converted !== false && $converted !== '') return scormRewriteCharsetMeta($converted);
    }
    return $html;
}

/**
 * Skriv om <meta charset=...> till UTF-8. Utan detta läser libxml den redan
 * konverterade texten som latin-1 igen och man får mojibake ("NÃ¤sta").
 */
function scormRewriteCharsetMeta(string $html): string {
    $head = substr($html, 0, 4096);
    $rewritten = preg_replace(
        ['/(<meta[^>]*charset\s*=\s*["\']?)([a-z0-9_\-]+)/i',
         '/(content\s*=\s*["\'][^"\']*charset=)([a-z0-9_\-]+)/i'],
        ['${1}UTF-8', '${1}UTF-8'],
        $head
    );
    return $rewritten === null ? $html : $rewritten . substr($html, 4096);
}

// ---------------------------------------------------------------------------
// Fallback för JS-drivna paket (Rise 360, Storyline, iSpring, Captivate)
// ---------------------------------------------------------------------------

/**
 * När HTML-skalet är tomt ligger innehållet i en JSON/XML-payload bredvid.
 * Skanna SCO:ns katalog (och närmaste underkataloger) efter js/json/xml och
 * skörda läsbara strängar. Ingen kod körs — vi läser bara data.
 */
function scormHarvestFromAssets(ZipArchive $zip, string $htmlPath): string {
    $dir = scormDirname($htmlPath);
    $candidates = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) continue;
        $name = $st['name'];
        if ($dir !== '' && strpos($name, $dir) !== 0) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['js', 'json', 'xml'], true)) continue;
        if ((int)$st['size'] > SCORM_MAX_ASSET_BYTES || (int)$st['size'] < 64) continue;
        // Bibliotek och ramverk innehåller ingen kurstext
        if (preg_match('#(jquery|bootstrap|angular|react|vue|modernizr|polyfill|require|\.min\.js$|runtime|vendor|bundle)#i', $name)) continue;
        $score = preg_match('#(data|content|course|story|slides?|text|lang|strings)#i', basename($name)) ? 0 : 1;
        $candidates[] = ['name' => $name, 'score' => $score, 'size' => (int)$st['size']];
    }
    if (empty($candidates)) return '';

    usort($candidates, fn($a, $b) => [$a['score'], -$a['size']] <=> [$b['score'], -$b['size']]);
    $candidates = array_slice($candidates, 0, SCORM_MAX_ASSET_FILES);

    $strings = [];
    foreach ($candidates as $c) {
        $raw = $zip->getFromName($c['name']);
        if ($raw === false || $raw === '') continue;
        $raw = scormToUtf8($raw);
        $ext = strtolower(pathinfo($c['name'], PATHINFO_EXTENSION));

        if ($ext === 'xml') {
            scormCollectXmlStrings($raw, $strings);
        } else {
            $data = scormDecodeJsonPayload($raw);
            if ($data !== null) scormCollectStrings($data, $strings, 0);
        }
        if (count($strings) > 800) break;
    }
    if (empty($strings)) return '';

    return scormNormalizeText(implode("\n", array_slice(array_values($strings), 0, 800)));
}

/**
 * Plocka ut ett JSON-objekt ur en .json-fil eller ur en JS-tilldelning
 * (t.ex. Rise 360:s `window.courseData = {...};`).
 */
function scormDecodeJsonPayload(string $raw): ?array {
    $trimmed = trim($raw);
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) return $decoded;

    // JS-tilldelning: hitta första { eller [ och testa fram till sista matchande
    foreach (['{' => '}', '[' => ']'] as $open => $close) {
        $start = strpos($trimmed, $open);
        $end   = strrpos($trimmed, $close);
        if ($start === false || $end === false || $end <= $start) continue;
        $candidate = substr($trimmed, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

/** Rekursivt skörda läsbara strängar ur en avkodad struktur. */
function scormCollectStrings($data, array &$out, int $depth): void {
    if ($depth > 12 || count($out) > 800) return;
    if (is_string($data)) {
        $s = scormCleanHarvestedString($data);
        if ($s !== null) $out[md5($s)] = $s;
        return;
    }
    if (!is_array($data)) return;
    foreach ($data as $v) {
        scormCollectStrings($v, $out, $depth + 1);
    }
}

/** Textnoder ur en XML-payload (Storyline/iSpring lägger ofta texten där). */
function scormCollectXmlStrings(string $xml, array &$out): void {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return;
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//text() | //@*[local-name()="text" or local-name()="title" or local-name()="caption"]') as $n) {
        $s = scormCleanHarvestedString((string)$n->nodeValue);
        if ($s !== null) $out[md5($s)] = $s;
        if (count($out) > 800) return;
    }
}

/**
 * Filtrera bort allt som inte är läsbar prosa: id:n, filnamn, färgkoder,
 * base64-klumpar, CSS. Returnerar null om strängen ska kastas.
 */
function scormCleanHarvestedString(string $s): ?string {
    $s = trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $s = preg_replace('/\s+/u', ' ', $s);
    if ($s === null) return null;
    $len = mb_strlen($s);
    if ($len < 12 || $len > 3000) return null;
    if (preg_match('~^(https?://|/|\./|data:)~i', $s)) return null;
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $s)) return null;
    if (preg_match('/\.(js|css|png|jpe?g|gif|svg|webm|mp4|mp3|woff2?|html?|xml|json)$/i', $s)) return null;
    if (preg_match('/^[A-Za-z0-9+\/=_-]{40,}$/', $s)) return null;      // id/base64
    // Typsnitts- och assetdefinitioner: "Poppins SemiBold ChBold1D9B48A7",
    // "Open Sans Charset1_ 60554D6E12F". Kännetecknas av ett id-liknande
    // token med både versaler och siffror.
    if (preg_match('/\b[A-Za-z]{2,}[0-9A-F]{6,}\b/', $s)) return null;
    if (preg_match('/(Charset\d|Bold[0-9A-F]{4,}|Regular\d|\bglyphs?\b|\bkerning\b|font-family)/i', $s)) return null;
    // Fristående hex-id ("60554D6E12F") — kräver både siffror och A-F så att
    // vanliga tal (organisationsnummer, årtal) inte råkar fastna.
    if (preg_match('/\b[0-9A-F]{8,}\b/', $s, $hex)
        && preg_match('/[A-F]/', $hex[0]) && preg_match('/[0-9]/', $hex[0])) return null;
    if (preg_match('/[{};]\s*$/', $s) && !preg_match('/[.!?]$/u', $s)) return null; // CSS/kod
    // Kräv riktig text: minst tre ord och en rimlig andel bokstäver
    if (substr_count($s, ' ') < 2) return null;
    $letters = preg_match_all('/\p{L}/u', $s);
    if ($letters < $len * 0.55) return null;
    return $s;
}

// ---------------------------------------------------------------------------
// Media
// ---------------------------------------------------------------------------

/**
 * Välj hero-bild: största bilden som klarar minimimåtten (ikoner och spacers
 * filtreras bort). Returnerar filnamnet i $outDir eller null.
 */
function scormPickImage(ZipArchive $zip, array $candidates, string $outDir, array &$cache): ?string {
    $best = null; $bestArea = 0; $bestBytes = null; $bestExt = 'png';
    foreach ($candidates as $path) {
        if (isset($cache[$path])) return $cache[$path]; // redan uttagen bild
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) continue;
        $bytes = scormReadEntry($zip, $path);
        if ($bytes === null || $bytes === '' || strlen($bytes) > SCORM_MAX_IMAGE_BYTES) continue;
        $info = @getimagesizefromstring($bytes);
        if ($info === false) continue;
        [$w, $h] = $info;
        if ($w < SCORM_MIN_IMAGE_WIDTH || $h < SCORM_MIN_IMAGE_HEIGHT) continue;
        if ($w * $h > $bestArea) {
            $bestArea = $w * $h; $best = $path; $bestBytes = $bytes; $bestExt = $ext === 'jpeg' ? 'jpg' : $ext;
        }
    }
    if ($best === null || $bestBytes === null) return null;

    $filename = 'scorm_' . bin2hex(random_bytes(12)) . '.' . $bestExt;
    $target = rtrim($outDir, '/') . '/' . $filename;
    if (file_put_contents($target, $bestBytes) === false) return null;
    @chmod($target, 0644);
    $cache[$best] = $filename;
    return $filename;
}

/**
 * Lista bildfiler under en katalog i zip:en (för JS-drivna paket där HTML:en
 * inte refererar någon bild). Begränsad så vi inte läser hela paketet.
 */
function scormImagesInDir(ZipArchive $zip, string $dir): array {
    $found = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) continue;
        $name = $st['name'];
        if ($dir !== '' && strpos($name, $dir) !== 0) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) continue;
        if ((int)$st['size'] > SCORM_MAX_IMAGE_BYTES || (int)$st['size'] < 4096) continue;
        $found[] = $name;
        if (count($found) >= 60) break;
    }
    return $found;
}

/** Kopiera ut första videon som ryms i budgeten. Minskar $budget. */
function scormPickVideo(ZipArchive $zip, array $candidates, string $outDir, array &$cache, int &$budget): ?string {
    foreach ($candidates as $path) {
        if (isset($cache[$path])) return $cache[$path];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'm4v'], true)) continue;
        $stat = $zip->statName($path);
        $size = $stat === false ? null : (int)$stat['size'];
        if ($size !== null && ($size > SCORM_MAX_VIDEO_BYTES || $size > $budget)) continue;
        $bytes = scormReadEntry($zip, $path);
        if ($bytes === null || $bytes === '') continue;
        if (strlen($bytes) > SCORM_MAX_VIDEO_BYTES || strlen($bytes) > $budget) continue;

        if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
        $filename = 'scorm_' . bin2hex(random_bytes(12)) . '.' . ($ext === 'm4v' ? 'mp4' : $ext);
        $target = rtrim($outDir, '/') . '/' . $filename;
        if (file_put_contents($target, $bytes) === false) continue;
        @chmod($target, 0644);
        $budget -= strlen($bytes);
        $cache[$path] = $filename;
        return $filename;
    }
    return null;
}

/** Gissa författarverktyg — används bara i admin-återkopplingen. */
function scormGuessTool(ZipArchive $zip): string {
    $names = '';
    for ($i = 0; $i < min($zip->numFiles, 3000); $i++) {
        $n = $zip->getNameIndex($i);
        if ($n !== false) $names .= strtolower($n) . "\n";
    }
    if (strpos($names, 'scormcontent/') !== false)   return 'Articulate Rise 360';
    if (strpos($names, 'story_content/') !== false || strpos($names, 'story.html') !== false) return 'Articulate Storyline';
    if (strpos($names, 'ispring') !== false || strpos($names, 'res/data.js') !== false)       return 'iSpring';
    if (strpos($names, 'captivate') !== false || strpos($names, 'cpm.js') !== false)          return 'Adobe Captivate';
    if (strpos($names, 'h5p') !== false)             return 'H5P';
    if (strpos($names, 'lectora') !== false)         return 'Lectora';
    return 'okänt verktyg';
}

// ---------------------------------------------------------------------------
// Textklump till AI-pipelinen
// ---------------------------------------------------------------------------

/**
 * Formatera SCO:erna som en kursbeskrivning AI:n läser. Samma kontrakt som
 * pptxBuildCourseDescription(), men med markören "SCO N:" — cron-jobbet
 * känner igen den och mappar BILDFIL/VIDEOFIL till rätt lektion efteråt.
 */
function scormBuildCourseDescription(array $items): string {
    $lines = [];
    foreach ($items as $i => $it) {
        $n = $i + 1;
        $lines[] = "SCO {$n}: " . $it['title'];
        if (!empty($it['text']))           $lines[] = $it['text'];
        if (!empty($it['image_filename'])) $lines[] = "BILDFIL: " . $it['image_filename'];
        if (!empty($it['video_filename'])) $lines[] = "VIDEOFIL: " . $it['video_filename'];
        $lines[] = '';
    }
    return rtrim(implode("\n", $lines));
}

} // end function_exists guard
