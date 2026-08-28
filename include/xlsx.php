<?php
/**
 * Stimma - Minimal XLSX-skrivare
 *
 * Exporterna skrev tidigare ut en HTML-tabell med filändelsen .xls. Excel öppnar
 * den, men varnar först: "Filformatet och filnamnstillägget för … stämmer inte
 * överens. Filen kan vara skadad eller osäker." Varningen är befogad — filen ÄR
 * inte en Excel-fil. Här skrivs i stället ett äkta xlsx-paket, som öppnas utan
 * dialog och som Google Kalkylark och LibreOffice läser lika bra.
 *
 * Medvetet minimalt: inline-strängar i stället för en sharedStrings-tabell, ett
 * blad, en handfull format. Det räcker för exportfilerna och sparar ett externt
 * beroende. Behövs formler, flera blad eller diagram är det dags för ett riktigt
 * bibliotek i stället för att bygga vidare här.
 *
 * Kräver ZipArchive (php-zip), som redan installeras i projektets docker-compose.
 */

// Formatindex som anropare använder i cellernas 's'-nyckel.
define('XLSX_STYLE_NORMAL', 0);
define('XLSX_STYLE_TITLE', 1);   // fet, 16 pt
define('XLSX_STYLE_HEADER', 2);  // fet, vit text på mörkblå botten
define('XLSX_STYLE_DONE', 3);    // vit text på grön botten, centrerad
define('XLSX_STYLE_TODO', 4);    // grå botten, centrerad
define('XLSX_STYLE_BOLD', 5);
define('XLSX_STYLE_CELL', 6);    // vanlig cell med ram

/**
 * Översätt kolumnnummer (1-baserat) till bokstavsbeteckning: 1 → A, 27 → AA.
 */
function xlsxColumnName($index) {
    $name = '';
    while ($index > 0) {
        $rest = ($index - 1) % 26;
        $name = chr(65 + $rest) . $name;
        $index = (int)(($index - $rest - 1) / 26);
    }
    return $name;
}

/**
 * XML-escapa ett textvärde. ENT_XML1 krävs — htmlspecialchars standardläge
 * lämnar tecken som är olagliga i XML.
 */
function xlsxEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Bladnamn får max 31 tecken och inte innehålla : \ / ? * [ ]
 */
function xlsxSanitizeSheetName($name) {
    $name = str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', (string)$name);
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        $name = 'Blad1';
    }
    return mb_substr($name, 0, 31);
}

/**
 * Skriv ett xlsx-paket till en temporär fil och returnera sökvägen.
 *
 * @param array $rows Lista av rader. Varje rad är en lista av celler, där en
 *                    cell antingen är ett skalärt värde eller en array:
 *                      ['v' => värde, 's' => formatindex, 'merge' => antal
 *                       kolumner cellen ska spänna över]
 *                    Heltal och flyttal skrivs som tal, allt annat som text.
 * @param string $sheetName Bladets namn
 * @param array $colWidths Valfria kolumnbredder, 1-baserat index => bredd
 * @return string Sökväg till den skrivna filen. Anroparen ansvarar för att
 *                ta bort den efter utskrift.
 * @throws RuntimeException om paketet inte kan skrivas
 */
function xlsxWrite(array $rows, $sheetName = 'Blad1', array $colWidths = []) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive saknas — kan inte skriva xlsx.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'stimma_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('Kunde inte skapa temporär fil för export.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte öppna xlsx-paketet för skrivning.');
    }

    $sheetName = xlsxSanitizeSheetName($sheetName);

    // ---- Blad: rader, celler och sammanslagningar -------------------------
    $sheetXml = '';
    $merges = [];
    $rowNumber = 0;

    foreach ($rows as $row) {
        $rowNumber++;
        $sheetXml .= '<row r="' . $rowNumber . '">';
        $col = 0;

        foreach ($row as $cell) {
            $col++;
            $value = is_array($cell) ? ($cell['v'] ?? '') : $cell;
            $style = is_array($cell) ? (int)($cell['s'] ?? XLSX_STYLE_NORMAL) : XLSX_STYLE_NORMAL;
            $merge = is_array($cell) ? (int)($cell['merge'] ?? 1) : 1;

            $ref = xlsxColumnName($col) . $rowNumber;

            if (is_int($value) || is_float($value)) {
                $sheetXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            } elseif ($value === null || $value === '') {
                $sheetXml .= '<c r="' . $ref . '" s="' . $style . '"/>';
            } else {
                $sheetXml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                          . xlsxEscape($value) . '</t></is></c>';
            }

            if ($merge > 1) {
                // De överhoppade cellerna måste ändå finnas, annars ritar Excel
                // ingen ram runt det sammanslagna området.
                for ($i = 1; $i < $merge; $i++) {
                    $col++;
                    $sheetXml .= '<c r="' . xlsxColumnName($col) . $rowNumber . '" s="' . $style . '"/>';
                }
                $merges[] = $ref . ':' . xlsxColumnName($col) . $rowNumber;
            }
        }
        $sheetXml .= '</row>';
    }

    $colsXml = '';
    if (!empty($colWidths)) {
        $colsXml = '<cols>';
        foreach ($colWidths as $idx => $width) {
            $colsXml .= '<col min="' . (int)$idx . '" max="' . (int)$idx . '" width="' . (float)$width . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    $mergeXml = '';
    if (!empty($merges)) {
        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $m) {
            $mergeXml .= '<mergeCell ref="' . $m . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $colsXml
        . '<sheetData>' . $sheetXml . '</sheetData>'
        . $mergeXml
        . '</worksheet>';

    // ---- Paketets övriga delar --------------------------------------------
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsxEscape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // Ordningen i varje lista är index-ordningen som cellXfs pekar på.
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="16"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF0F3B5F"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF4CAF50"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F5"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
        .   '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        .     '<alignment vertical="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        .     '<alignment horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">'
        .     '<alignment horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
        . '</cellXfs>'
        // Utan en namngiven standardstil varnar strikta läsare (openpyxl m.fl.)
        // för att arbetsboken saknar default style. Excel tolererar det, andra
        // verktyg gör det inte alltid.
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

    if ($zip->close() !== true) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte stänga xlsx-paketet.');
    }

    return $tmp;
}

/**
 * Skicka en xlsx-fil till webbläsaren och ta bort den temporära filen.
 *
 * @param string $path Sökväg från xlsxWrite()
 * @param string $downloadName Filnamn användaren får, med eller utan .xlsx
 */
function xlsxSend($path, $downloadName) {
    if (substr($downloadName, -5) !== '.xlsx') {
        $downloadName .= '.xlsx';
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    readfile($path);
    @unlink($path);
}
