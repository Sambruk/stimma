<?php
/**
 * Stimma — PowerPoint (PPTX) → strukturerad slide-data.
 *
 * Extraherar text och inbäddade bilder från en .pptx-fil utan externa
 * Composer-paket — använder bara den inbyggda ZipArchive- och
 * DOMDocument-extension som redan finns i web-containern.
 *
 * En PPTX är en zip med XML-filer:
 *   ppt/slides/slide{N}.xml          — slide-innehåll (text i <a:t>)
 *   ppt/slides/_rels/slide{N}.xml.rels  — kopplar slide till bilder + notes
 *   ppt/notesSlides/notesSlide{N}.xml — talar-notes
 *   ppt/media/image*.{png,jpg,gif}   — inbäddade bilder
 *
 * Beroenden: ingen — antas inkluderas där queryOne()/etc inte krävs.
 */

if (!function_exists('pptxExtractSlides')) {

/**
 * Extrahera slides ur en PPTX-fil.
 *
 * @param string $pptxPath     Filsökväg till .pptx
 * @param string $imageOutDir  Katalog dit ev. inbäddade bilder kopieras
 *                             (t.ex. /opt/app/stimma/upload). Filnamn blir
 *                             slumpmässiga; befintliga filer skrivs aldrig
 *                             över.
 * @return array  Lista i slide-ordning, varje element:
 *                ['title' => string, 'body' => string, 'notes' => string,
 *                 'image_filename' => string|null]
 *
 * @throws Exception Om filen inte är en giltig PPTX.
 */
function pptxExtractSlides(string $pptxPath, string $imageOutDir): array {
    if (!is_file($pptxPath)) {
        throw new Exception('PPTX-fil saknas: ' . $pptxPath);
    }
    $zip = new ZipArchive();
    if ($zip->open($pptxPath) !== true) {
        throw new Exception('Kunde inte öppna PPTX (är filen korrupt?).');
    }

    // Indexera vilka slide-XML-filer som finns. Sortera numeriskt så
    // slide10 inte hamnar före slide2.
    $slideEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
            $slideEntries[(int)$m[1]] = $name;
        }
    }
    if (empty($slideEntries)) {
        $zip->close();
        throw new Exception('Inga slides hittades i PPTX-filen.');
    }
    ksort($slideEntries, SORT_NUMERIC);

    if (!is_dir($imageOutDir)) {
        @mkdir($imageOutDir, 0755, true);
    }

    $result = [];
    foreach ($slideEntries as $slideNum => $slideXmlPath) {
        $slideXml = $zip->getFromName($slideXmlPath);
        if ($slideXml === false) continue;

        // Plocka all text — Office Open XML lägger text i <a:t>-element
        // (drawing-namespace). Vi sätter ihop varje paragraf med radbrytning.
        $textParas = pptxExtractTextRuns($slideXml);
        $title = '';
        $body  = '';
        if (!empty($textParas)) {
            $title = trim(array_shift($textParas));
            $body  = trim(implode("\n", $textParas));
        }

        // Hämta tillhörande _rels för att hitta bild- och notes-relations
        $relsPath = sprintf('ppt/slides/_rels/slide%d.xml.rels', $slideNum);
        $relsXml  = $zip->getFromName($relsPath);
        $imageRel = pptxFirstRelTarget($relsXml, '/relationships/image');
        $notesRel = pptxFirstRelTarget($relsXml, '/relationships/notesSlide');

        // Notes
        $notes = '';
        if ($notesRel) {
            $notesPath = pptxResolveRelTarget('ppt/slides/', $notesRel);
            $notesXml  = $zip->getFromName($notesPath);
            if ($notesXml !== false) {
                $notesParas = pptxExtractTextRuns($notesXml);
                $notes = trim(implode("\n", $notesParas));
                // Filtrera bort sliders-default ("Click to add notes" är
                // inget eget — placeholders innehåller faktisk talartext
                // som ändå hamnar här.) Inget mer behövs.
            }
        }

        // Bild
        $imageFilename = null;
        if ($imageRel) {
            $imagePath = pptxResolveRelTarget('ppt/slides/', $imageRel);
            $imageBytes = $zip->getFromName($imagePath);
            if ($imageBytes !== false && strlen($imageBytes) > 0) {
                $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                    $ext = 'png'; // fallback
                }
                $imageFilename = 'pptx_' . bin2hex(random_bytes(12)) . '.' . $ext;
                $target = rtrim($imageOutDir, '/') . '/' . $imageFilename;
                if (file_put_contents($target, $imageBytes) !== false) {
                    @chmod($target, 0644);
                } else {
                    $imageFilename = null;
                }
            }
        }

        $result[] = [
            'title'          => $title !== '' ? $title : ('Slide ' . $slideNum),
            'body'           => $body,
            'notes'          => $notes,
            'image_filename' => $imageFilename,
        ];
    }

    $zip->close();
    return $result;
}

/**
 * Plocka ut alla <a:t>-text-runs ur en slide-XML och gruppera dem per
 * <a:p>-paragraf. Returnerar en array av paragraf-strängar.
 */
function pptxExtractTextRuns(string $xml): array {
    if ($xml === '') return [];
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return [];

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

    $paragraphs = [];
    foreach ($xp->query('//a:p') as $p) {
        $parts = [];
        foreach ($xp->query('.//a:t', $p) as $t) {
            $val = $t->nodeValue;
            if ($val !== null && $val !== '') $parts[] = $val;
            // Hantera <a:br/>
        }
        $line = trim(implode('', $parts));
        if ($line !== '') {
            $paragraphs[] = $line;
        }
    }
    return $paragraphs;
}

/**
 * Hitta första <Relationship> med Type som slutar på $typeSuffix
 * (t.ex. "/relationships/image"). Returnerar Target-attributet eller null.
 */
function pptxFirstRelTarget($relsXml, string $typeSuffix): ?string {
    if (!$relsXml) return null;
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadXML($relsXml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return null;

    foreach ($dom->getElementsByTagName('Relationship') as $rel) {
        $type = (string)$rel->getAttribute('Type');
        if ($type !== '' && substr($type, -strlen($typeSuffix)) === $typeSuffix) {
            $target = (string)$rel->getAttribute('Target');
            if ($target !== '') return $target;
        }
    }
    return null;
}

/**
 * Lös en relations-Target-sökväg (oftast "../media/image1.png" eller
 * "../notesSlides/notesSlide1.xml") relativt $baseDir (t.ex. "ppt/slides/")
 * till en kanonisk path inom zip:en (t.ex. "ppt/media/image1.png").
 */
function pptxResolveRelTarget(string $baseDir, string $target): string {
    $combined = rtrim($baseDir, '/') . '/' . $target;
    $parts = explode('/', $combined);
    $stack = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') array_pop($stack);
        else $stack[] = $p;
    }
    return implode('/', $stack);
}

/**
 * Formatera slides till en kursbeskrivning som AI:n läser. Inkluderar
 * markörer ("SLIDE N:") och bildfilreferenser ("BILDFIL: namn") så cron-
 * jobbet senare kan mappa lektion → bildfil.
 */
function pptxBuildCourseDescription(array $slides): string {
    $lines = [];
    foreach ($slides as $i => $s) {
        $n = $i + 1;
        $lines[] = "SLIDE {$n}: " . $s['title'];
        if (!empty($s['body']))  $lines[] = $s['body'];
        if (!empty($s['notes'])) $lines[] = "NOTES: " . $s['notes'];
        if (!empty($s['image_filename'])) {
            $lines[] = "BILDFIL: " . $s['image_filename'];
        }
        $lines[] = '';
    }
    return rtrim(implode("\n", $lines));
}

} // end function_exists guard
