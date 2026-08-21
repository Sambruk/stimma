<?php
/**
 * Stimma — Articulate Storyline-paket → scener, text, bilder och filmer.
 *
 * Storyline lägger inte innehållet i HTML utan i JavaScript-filer under
 * html5/data/js/. Varje fil ser ut så här:
 *
 *   window.globalProvideData('slide', '{ ...JSON... }');
 *
 * `data.js` innehåller kursens struktur (scener → slides) och en assetLib som
 * mappar assetId → filnamn. Varje slide har en egen fil <slideId>.js.
 *
 * Texten finns i två former: dels som glyf-runs (vektortext, där ligaturer som
 * ﬁ/ﬀ tappar bokstäver vid extraktion — "inormationssäkerhet"), dels som
 * `altText` på varje objekt. **altText är den rena texten** och det är den vi
 * använder. Objektens `accType` skiljer text, bild, knapp och radioknapp åt.
 *
 * En scen motsvarar ett avsnitt i kursen och blir därför en lektion i Stimma.
 * Ingen kod från paketet körs — vi läser JSON som data.
 */

if (!function_exists('storylineDetect')) {

define('STORYLINE_DATA_JS', 'html5/data/js/data.js');
define('STORYLINE_CHROME_RATIO', 0.34);   // text på >34 % av sidorna = navigation
define('STORYLINE_MIN_IMAGE_PX', 300);    // mindre bilder är ikoner/dekor

/** Är det här ett Storyline-paket? */
function storylineDetect(ZipArchive $zip, string $prefix = ''): bool {
    return $zip->locateName($prefix . STORYLINE_DATA_JS) !== false;
}

/**
 * Läs ut hela kursen.
 *
 * @return array {
 *   title: string,
 *   lessons: array  [ ['title','sections'=>[['title','texts','images']],'videos'], ... ]
 * }
 */
function storylineParse(ZipArchive $zip, string $prefix = ''): array {
    $data = storylineLoadProvideData($zip, $prefix . STORYLINE_DATA_JS);
    if (!$data || empty($data['scenes'])) {
        throw new Exception('Storyline-paketets data.js gick inte att tolka.');
    }

    // assetId → ['url' => 'mobile/xxx.png', 'width' => .., 'height' => ..]
    $assets = [];
    foreach (($data['assetLib'] ?? []) as $a) {
        if (isset($a['id'])) $assets[(int)$a['id']] = $a;
    }

    // Steg 1: läs alla slides
    $scenes = [];
    $textCount = [];      // text → antal slides den förekommer på
    $imageCount = [];     // assetId → antal slides
    $slideTotal = 0;

    foreach ($data['scenes'] as $scene) {
        if (!empty($scene['isMessageScene'])) continue;   // "Ogiltigt svar" m.m.
        $slides = [];
        foreach (($scene['slides'] ?? []) as $slideRef) {
            $slideId = (string)($slideRef['id'] ?? '');
            if ($slideId === '') continue;
            $parsed = storylineParseSlide($zip, $prefix, $slideId, $assets);
            if ($parsed === null) continue;
            $parsed['title'] = storylinePlainText((string)($slideRef['title'] ?? ''));
            $slides[] = $parsed;
            $slideTotal++;
            foreach (array_unique($parsed['texts']) as $t)  $textCount[$t]  = ($textCount[$t] ?? 0) + 1;
            foreach (array_unique($parsed['images']) as $i) $imageCount[$i] = ($imageCount[$i] ?? 0) + 1;
        }
        if (!empty($slides)) $scenes[] = $slides;
    }
    if (empty($scenes)) {
        throw new Exception('Storyline-paketet innehöll inga läsbara sidor.');
    }

    // Steg 2: sålla bort navigation och dekor. Element som återkommer på nästan
    // varje sida ("Huvudmeny", pilikoner, bakgrundsplattor) är ramverk, inte
    // innehåll — frekvensen avslöjar dem utan att vi behöver lista dem.
    $chromeLimit = max(2, (int)ceil($slideTotal * STORYLINE_CHROME_RATIO));

    $lessons = [];
    foreach ($scenes as $slides) {
        $sections = [];
        $videos = [];
        foreach ($slides as $slide) {
            $texts = [];
            foreach ($slide['texts'] as $t) {
                if (($textCount[$t] ?? 0) >= $chromeLimit) continue;
                if (storylineIsUiLabel($t)) continue;
                if (!in_array($t, $texts, true)) $texts[] = $t;
            }
            $images = [];
            foreach ($slide['images'] as $assetId) {
                if (($imageCount[$assetId] ?? 0) >= $chromeLimit) continue;
                $a = $assets[$assetId] ?? null;
                if (!$a || empty($a['url'])) continue;
                $w = (int)($a['width'] ?? 0); $h = (int)($a['height'] ?? 0);
                if ($w < STORYLINE_MIN_IMAGE_PX && $h < STORYLINE_MIN_IMAGE_PX) continue;
                if (!in_array($a['url'], $images, true)) $images[] = $a['url'];
            }
            foreach ($slide['videos'] as $v) $videos[] = $v;

            if (empty($texts) && empty($images)) continue;
            if (storylineLooksLikeMenu($texts)) continue;
            $sections[] = [
                'title'  => $slide['title'],
                'texts'  => $texts,
                'images' => $images,
            ];
        }
        if (empty($sections) && empty($videos)) continue;

        $lessons[] = [
            'title'    => storylineLessonTitle($sections, $videos),
            'sections' => $sections,
            'videos'   => $videos,
        ];
    }

    return [
        'title'   => storylinePlainText((string)($data['courseTitle'] ?? '')),
        'lessons' => $lessons,
    ];
}

/**
 * Läs en slide-fil. Returnerar texter (i visningsordning), bild-assetId:n och
 * videor ['url' => zip-path, 'title' => altText].
 */
function storylineParseSlide(ZipArchive $zip, string $prefix, string $slideId, array $assets): ?array {
    $slide = storylineLoadProvideData($zip, $prefix . 'html5/data/js/' . $slideId . '.js');
    if (!$slide) return null;

    $texts = []; $images = []; $videos = [];
    $visit = function ($node) use (&$visit, &$texts, &$images, &$videos, $assets) {
        if (is_array($node)) {
            if (isset($node['accType'])) {
                $alt = trim((string)($node['data']['vectorData']['altText'] ?? ''));
                if ($node['accType'] === 'text' && $alt !== '') {
                    $texts[] = $alt;
                } elseif ($node['accType'] === 'image') {
                    $assetId = storylineFirstAssetId($node);
                    if ($assetId !== null) $images[] = $assetId;
                } elseif ($node['accType'] === 'radio' || $node['accType'] === 'checkbox') {
                    // Svarsalternativ: objektets egen altText är bara en etikett
                    // ("Svar 1 radio button") — själva alternativtexten ligger på
                    // ett underobjekt, upprepad en gång per knappläge.
                    $label = storylineNestedLabel($node, $alt);
                    if ($label !== null) $texts[] = '– ' . $label;
                }
            }
            if (isset($node['data']['videodata']) && is_array($node['data']['videodata'])) {
                $vd = $node['data']['videodata'];
                $assetId = isset($vd['assetId']) ? (int)$vd['assetId'] : null;
                $url = $assetId !== null ? ($assets[$assetId]['url'] ?? null) : null;
                if ($url) {
                    $videos[] = ['url' => $url, 'title' => trim((string)($vd['altText'] ?? ''))];
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) $visit($child);
            }
        }
    };
    $visit($slide);

    return ['texts' => $texts, 'images' => $images, 'videos' => $videos];
}

/**
 * Hitta svarsalternativets text under en radio-/kryssruta. Den längsta
 * distinkta altText:en under noden är alternativet; objektets egen etikett
 * ("Svar 2 radio button") sorteras bort.
 */
function storylineNestedLabel(array $node, string $ownAlt): ?string {
    $found = [];
    $walk = function ($n) use (&$walk, &$found) {
        if (!is_array($n)) return;
        $alt = trim((string)($n['data']['vectorData']['altText'] ?? ''));
        if ($alt !== '') $found[$alt] = true;
        foreach ($n as $c) if (is_array($c)) $walk($c);
    };
    $walk($node);

    $best = null;
    foreach (array_keys($found) as $candidate) {
        if ($candidate === $ownAlt) continue;
        if (preg_match('/\b(radio button|checkbox|knapp)\b/i', $candidate)) continue;
        if ($best === null || mb_strlen($candidate) > mb_strlen($best)) $best = $candidate;
    }
    return $best;
}

/** Första assetId någonstans under en nod. */
function storylineFirstAssetId(array $node): ?int {
    if (isset($node['assetId']) && is_numeric($node['assetId'])) return (int)$node['assetId'];
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = storylineFirstAssetId($child);
            if ($found !== null) return $found;
        }
    }
    return null;
}

/**
 * Ladda och avkoda en window.globalProvideData(...)-fil.
 * Nyttolasten är JSON inuti en enkelciterad JS-sträng.
 */
function storylineLoadProvideData(ZipArchive $zip, string $path): ?array {
    $raw = $zip->getFromName($path);
    if ($raw === false || $raw === '') return null;
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);   // BOM

    if (!preg_match("/globalProvideData\\(\\s*'[^']*'\\s*,\\s*'(.*)'\\s*\\)\\s*;?\\s*$/s", $raw, $m)) {
        return null;
    }
    $json = storylineUnescapeJsString($m[1]);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Gör om en enkelciterad JS-sträng till JSON-text.
 *
 * Nyttolasten är JSON inbäddad i en JS-sträng. Att avkoda den för hand är
 * fel väg — escape-reglerna skiljer sig på ett par punkter och man förstör
 * lätt \n inuti JSON-strängar. Istället normaliserar vi bara de två saker
 * som skiljer JS från JSON (\' som JSON inte tillåter, och oescapade
 * citattecken) och låter json_decode göra själva avkodningen.
 */
function storylineUnescapeJsString(string $s): string {
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $next = $s[$i + 1];
            // \' är giltigt i JS men olagligt i JSON — behåll bara tecknet
            $out .= $next === "'" ? "'" : '\\' . $next;
            $i++;
            continue;
        }
        // Citattecken i JS-strängen är JSON-syntax och måste escapas för att
        // hela nyttolasten ska kunna läsas som ETT JSON-strängliteral
        $out .= $c === '"' ? '\\"' : $c;
    }
    $decoded = json_decode('"' . $out . '"');
    return is_string($decoded) ? $decoded : '';
}

/** Storyline-titlar är små HTML-fragment. */
function storylinePlainText(string $html): string {
    $text = preg_replace('/<[^>]+>/', ' ', $html);
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text, " \t\n\r\0\x0B…");
}

/** Etiketter som hör till spelaren, inte till innehållet. */
function storylineIsUiLabel(string $t): bool {
    if (mb_strlen($t) < 3) return true;
    $patterns = '/(\b(icon|arrow|button|radio|checkbox|line)\b'
        . '|^\s*(rubrik|bild|text|fråga|svara|nästa|tillbaka|meny|huvudmeny|stäng|klicka'
        . '|rätt|fel|korrekt|försök igen|indikator[^.]*)\s*[0-9!.]*\s*$)/iu';
    return (bool)preg_match($patterns, $t);
}

/**
 * Är sidan en innehållsmeny? Storyline-kurser har ofta en "Lär dig om"-sida
 * med en knapp per avsnitt — bara korta etiketter, ingen brödtext.
 */
function storylineLooksLikeMenu(array $texts): bool {
    if (count($texts) < 6) return false;
    $short = 0;
    foreach ($texts as $t) {
        if (mb_strlen($t) < 40) $short++;
    }
    return $short / count($texts) >= 0.7;
}

/**
 * Lektionsrubrik ur scenen. Storyline namnger ofta första sidan
 * "Filmen om X" — då är X avsnittets namn.
 */
function storylineLessonTitle(array $sections, array $videos): string {
    foreach ($sections as $s) {
        $t = trim((string)$s['title']);
        if ($t === '') continue;
        if (preg_match('/^Filmen om\s+(.+)$/iu', $t, $m)) {
            return mb_strtoupper(mb_substr($m[1], 0, 1)) . mb_substr($m[1], 1);
        }
    }
    foreach ($sections as $s) {
        $t = trim((string)$s['title']);
        if ($t === '') continue;
        if (preg_match('/^(förstasida|startsida|titelsida|start|intro)$/iu', $t)) return 'Introduktion';
        return $t;
    }
    foreach ($videos as $v) {
        if (!empty($v['title'])) return storylinePlainText($v['title']);
    }
    return 'Avsnitt';
}

} // end function_exists guard
