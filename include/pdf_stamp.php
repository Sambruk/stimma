<?php
/**
 * Stimma - PDF-stämpling för PUB-avtal
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Lägger till en signeringsintyg-sida i PUB-avtalets PDF.
 */

require_once __DIR__ . '/../lib/fpdf/fpdf.php';
require_once __DIR__ . '/../lib/fpdi/autoload.php';

use setasign\Fpdi\Fpdi;

/**
 * Stämpla PUB-avtalets PDF med signeringsuppgifter
 *
 * @param string $sourcePdfPath Sökväg till original-PDF
 * @param array $signingData Signeringsdata (organisationens undertecknare)
 * @param array|null $sambrukData Sambruks kontrasigneringsdata (från getSambrukSignatureData())
 * @return string|false Binärt PDF-innehåll eller false vid fel
 */
/**
 * Partstabellen i avsnitt 1 (2026-11-03): sida och mitten av varje rad i mm, för
 * mallen "Personuppgiftsbiträdesavtal Version 2.1 (23.04.06)" (mallversion 3 och 4).
 * Flyttar en ny mall tabellen måste värdena justeras – superadmin ser resultatet i
 * förhandsgranskningen (pub_download.php?forhandsgranska=) innan mallen kontrasigneras.
 */
const PUB_PARTY_TABLE = [
    'page' => 3,
    'x' => 67.1,          // vänsterkant för text i Användningspartens kolumn
    'width' => 51.5,      // till kolumnens högerkant (119.9 mm) minus marginal
    'org_name' => 82.2,   // mellan "stimma.sambruk.se" och "Nedan kallad Användningspart"
    'rows' => [
        'org_number' => 96.6,
        'org_address' => 106.1,
        'org_postal_address' => 115.5,
        'signer' => 127.7,
        'signer_phone' => 139.8,
        'signer_email' => 149.3,
        'peppol_id' => 158.8,
        'invoice_reference' => 168.2,
    ],
    'contact' => [177.7, 185.0, 190.3], // namn, e-post, telefon som i Sambruks kolumn
];

/**
 * Skriv en rad i partstabellen. Krymper texten till som minst 7,5 pt; ryms den
 * ändå inte bryts den på två rader (e-post vid @), så att inget kapas eller
 * flödar ut i Sambruks kolumn.
 */
function pubPartyCell($pdf, float $yCenter, string $text, string $style = ''): void {
    $text = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
    $w = PUB_PARTY_TABLE['width'];
    for ($size = 10; $size >= 7.5; $size -= 0.5) {
        $pdf->SetFont('Helvetica', $style, $size);
        if ($pdf->GetStringWidth($text) <= $w) {
            $pdf->SetXY(PUB_PARTY_TABLE['x'], $yCenter - 2.5);
            $pdf->Cell($w, 5, $text, 0, 0, 'L');
            return;
        }
    }
    foreach (pubPartySplit($pdf, $text, $w) as $n => $rad) {
        $pdf->SetXY(PUB_PARTY_TABLE['x'], $yCenter - 3.6 + $n * 3.6);
        $pdf->Cell($w, 3.6, $rad, 0, 0, 'L');
    }
}

/** Dela text i två rader som ryms (aktuellt typsnitt sänks vid behov). */
function pubPartySplit($pdf, string $text, float $w): array {
    $at = strpos($text, '@');
    if ($at !== false && strpos($text, ' ') === false) {
        $rader = [substr($text, 0, $at), substr($text, $at)];
    } else {
        $ord = explode(' ', $text);
        $rader = [$text, ''];
        for ($i = count($ord) - 1; $i > 0; $i--) {
            $forsta = implode(' ', array_slice($ord, 0, $i));
            if ($pdf->GetStringWidth($forsta) <= $w) {
                $rader = [$forsta, implode(' ', array_slice($ord, $i))];
                break;
            }
        }
    }
    $size = 7.5;
    while ($size > 5 && max($pdf->GetStringWidth($rader[0]), $pdf->GetStringWidth($rader[1])) > $w) {
        $size -= 0.5;
        $pdf->SetFontSize($size);
    }
    return $rader;
}

/**
 * Fyll Användningspartens kolumn med uppgifterna som lämnades vid signeringen.
 * Ombud = undertecknaren. Kontaktperson faller tillbaka på undertecknaren.
 */
function fillPubPartyTable($pdf, array $d): void {
    $pdf->SetTextColor(0, 0, 0);
    $r = PUB_PARTY_TABLE['rows'];
    pubPartyCell($pdf, PUB_PARTY_TABLE['org_name'], (string)($d['org_name'] ?? ''), 'B');
    pubPartyCell($pdf, $r['org_number'], (string)($d['org_number'] ?? ''));
    pubPartyCell($pdf, $r['org_address'], (string)($d['org_address'] ?? ''));
    pubPartyCell($pdf, $r['org_postal_address'], (string)($d['org_postal_address'] ?? ''));
    $titel = trim((string)($d['signer_title'] ?? ''));
    pubPartyCell($pdf, $r['signer'], (string)($d['signer_name'] ?? '') . ($titel !== '' && $titel !== '-' ? ", $titel" : ''));
    pubPartyCell($pdf, $r['signer_phone'], (string)($d['signer_phone'] ?? ''));
    pubPartyCell($pdf, $r['signer_email'], (string)($d['signer_email'] ?? ''));
    pubPartyCell($pdf, $r['peppol_id'], (string)($d['peppol_id'] ?? ''));
    pubPartyCell($pdf, $r['invoice_reference'], (string)($d['invoice_reference'] ?? ''));
    [$yNamn, $yEpost, $yTel] = PUB_PARTY_TABLE['contact'];
    // Bryts e-postadressen på två rader flyttas namn och telefon isär
    $pdf->SetFont('Helvetica', '', 7.5);
    $kontaktEpost = (string)(($d['contact_email'] ?? '') ?: ($d['signer_email'] ?? ''));
    if ($pdf->GetStringWidth(iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $kontaktEpost)) > PUB_PARTY_TABLE['width']) {
        [$yNamn, $yEpost, $yTel] = [$yNamn - 1.5, $yEpost, $yTel + 1.8];
    }
    pubPartyCell($pdf, $yNamn, (string)(($d['contact_name'] ?? '') ?: ($d['signer_name'] ?? '')));
    pubPartyCell($pdf, $yEpost, $kontaktEpost);
    pubPartyCell($pdf, $yTel, (string)(($d['contact_phone'] ?? '') ?: ($d['signer_phone'] ?? '')));
}

function stampPubAgreementPdf($sourcePdfPath, $signingData, $sambrukData = null) {
    if (!file_exists($sourcePdfPath)) {
        return false;
    }

    try {
        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);

        // Importera alla befintliga sidor
        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tplId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);
            if ($i === PUB_PARTY_TABLE['page'] && !empty($signingData['org_address'])) {
                fillPubPartyTable($pdf, $signingData);
            }
        }

        // Har vi Sambruk-data? Då används tvåparts-layout
        $hasSambruk = $sambrukData && !empty($sambrukData['sambruk_signed_at']);

        // Lägg till signeringsintyg-sida
        $pdf->AddPage('P', 'A4');

        // Bakgrundsfärg för header
        $pdf->SetFillColor(0, 91, 187);
        $pdf->Rect(0, 0, 210, 35, 'F');

        // Titel
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 8);
        $pdf->Cell(180, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'SIGNERINGSINTYG'), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetXY(15, 20);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Personuppgiftsbiträdesavtal (PUB-avtal) med Sambruk'), 0, 1, 'C');

        // Återställ textfärg
        $pdf->SetTextColor(33, 37, 41);

        // --- Avtalsinformation ---
        $y = 42;
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Avtalsinformation'), 0, 1, 'L');
        $y += 8;

        $pdf->SetDrawColor(0, 91, 187);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $y, 195, $y);
        $y += 4;

        $fields = [
            ['Avtals-ID', $signingData['agreement_id'] ?? ''],
            ['Organisation', $signingData['org_name'] ?? ''],
            ['Org.nummer', $signingData['org_number'] ?? ''],
            ['Domain', $signingData['domain'] ?? ''],
        ];

        foreach ($fields as $field) {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetXY(15, $y);
            $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
            $y += 6;
        }

        if ($hasSambruk) {
            // --- TVÅPARTS-LAYOUT ---

            // --- Part 1: Sambruk (Biträde) ---
            $y += 5;
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->SetXY(15, $y);
            $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Part 1: Sambruk (Personuppgiftsbiträde)'), 0, 1, 'L');
            $y += 8;

            $pdf->SetDrawColor(0, 91, 187);
            $pdf->Line(15, $y, 195, $y);
            $y += 4;

            $sambrukFields = [
                ['Namn', $sambrukData['sambruk_signer_name'] ?? ''],
                ['Titel', $sambrukData['sambruk_signer_title'] ?? '-'],
                ['E-post', $sambrukData['sambruk_signer_email'] ?? ''],
                ['Signerat', $sambrukData['sambruk_signed_at'] ?? ''],
                ['IP-adress', $sambrukData['sambruk_ip_address'] ?? ''],
                ['SMS-verifierad', 'Ja'],
            ];

            foreach ($sambrukFields as $field) {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetXY(15, $y);
                $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
                $y += 6;
            }

            // Signatur-hash för Sambruk
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetXY(15, $y);
            $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Signatur-hash:'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $sambrukData['sambruk_signature_hash'] ?? ''), 0, 1, 'L');
            $y += 6;

            // --- Part 2: Organisation (PuA) ---
            $y += 5;
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->SetXY(15, $y);
            $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Part 2: ' . ($signingData['org_name'] ?? 'Organisation') . ' (Personuppgiftsansvarig)'), 0, 1, 'L');
            $y += 8;

            $pdf->SetDrawColor(0, 91, 187);
            $pdf->Line(15, $y, 195, $y);
            $y += 4;

            $orgSignerFields = [
                ['Namn', $signingData['signer_name'] ?? ''],
                ['Titel', $signingData['signer_title'] ?? '-'],
                ['E-post', $signingData['signer_email'] ?? ''],
                ['Telefon', $signingData['signer_phone'] ?? ''],
            ];

            foreach ($orgSignerFields as $field) {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetXY(15, $y);
                $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
                $y += 6;
            }

            // --- Signeringsdetaljer (organisation) ---
            $y += 5;
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->SetXY(15, $y);
            $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Signeringsdetaljer'), 0, 1, 'L');
            $y += 8;

            $pdf->SetDrawColor(0, 91, 187);
            $pdf->Line(15, $y, 195, $y);
            $y += 4;

            $detailFields = [
                ['Datum och tid', $signingData['signed_at'] ?? date('Y-m-d H:i:s')],
                ['IP-adress', $signingData['ip_address'] ?? ''],
                ['SMS-verifierad', 'Ja'],
            ];

            foreach ($detailFields as $field) {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetXY(15, $y);
                $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
                $y += 6;
            }

            // SHA-256 med mindre font
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetXY(15, $y);
            $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'SHA-256 (original):'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $signingData['pdf_hash'] ?? ''), 0, 1, 'L');
            $y += 6;

            // --- Intyganden (båda parter) ---
            $y += 5;
            $pdf->SetFillColor(248, 249, 250);
            $pdf->SetDrawColor(0, 91, 187);
            $pdf->SetLineWidth(0.3);

            // Beräkna höjd dynamiskt
            $certBoxHeight = 38;
            $pdf->Rect(15, $y, 180, $certBoxHeight, 'DF');

            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetXY(20, $y + 2);
            $pdf->Cell(170, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Intyganden:'), 0, 1, 'L');

            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetXY(20, $y + 8);
            $sambrukCert = $sambrukData['sambruk_certification_text'] ?? '';
            $pdf->MultiCell(170, 4, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Sambruk: ' . $sambrukCert), 0, 'L');

            $pdf->SetXY(20, $y + 22);
            $orgCert = $signingData['certification_text'] ?? '';
            $pdf->MultiCell(170, 4, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Organisation: ' . $orgCert), 0, 'L');

        } else {
            // --- ENPARTS-LAYOUT (bakåtkompatibel) ---

            $y += 5;

            // Undertecknare
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->SetXY(15, $y);
            $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Undertecknare'), 0, 1, 'L');
            $y += 8;

            $pdf->SetDrawColor(0, 91, 187);
            $pdf->Line(15, $y, 195, $y);
            $y += 4;

            $signerFields = [
                ['Namn', $signingData['signer_name'] ?? ''],
                ['Titel', $signingData['signer_title'] ?? '-'],
                ['E-post', $signingData['signer_email'] ?? ''],
                ['Telefon', $signingData['signer_phone'] ?? ''],
            ];

            foreach ($signerFields as $field) {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetXY(15, $y);
                $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
                $y += 6;
            }

            $y += 5;

            // Signeringsdetaljer
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->SetXY(15, $y);
            $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Signeringsdetaljer'), 0, 1, 'L');
            $y += 8;

            $pdf->SetDrawColor(0, 91, 187);
            $pdf->Line(15, $y, 195, $y);
            $y += 4;

            $detailFields = [
                ['Datum och tid', $signingData['signed_at'] ?? date('Y-m-d H:i:s')],
                ['IP-adress', $signingData['ip_address'] ?? ''],
                ['SMS-verifierad', 'Ja'],
                ['SHA-256 (original)', $signingData['pdf_hash'] ?? ''],
            ];

            foreach ($detailFields as $field) {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetXY(15, $y);
                $pdf->Cell(45, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', ($field[0] === 'SHA-256 (original)') ? 7 : 9);
                $pdf->Cell(135, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
                $y += 6;
            }

            $y += 8;

            // Intygandetext i ruta
            $pdf->SetFillColor(248, 249, 250);
            $pdf->SetDrawColor(0, 91, 187);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect(15, $y, 180, 28, 'DF');

            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetXY(20, $y + 3);
            $pdf->Cell(170, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Intygande:'), 0, 1, 'L');

            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetXY(20, $y + 9);
            $certText = $signingData['certification_text'] ?? '';
            $pdf->MultiCell(170, 4, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $certText), 0, 'L');
        }

        // Footer-text
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->SetXY(15, 270);
        $pdf->Cell(180, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Detta signeringsintyg genererades automatiskt av Stimma (stimma.sambruk.se)'), 0, 1, 'C');
        $pdf->SetXY(15, 275);
        $pdf->Cell(180, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Dokumentet utgör en del av det digitalt tecknade PUB-avtalet.'), 0, 1, 'C');

        // Returnera PDF som sträng
        return $pdf->Output('S');

    } catch (\Exception $ex) {
        logActivity('system', 'PDF-stämpling misslyckades: ' . $ex->getMessage(), [
            'action' => 'pdf_stamp_failed',
            'error' => $ex->getMessage()
        ]);
        return false;
    }
}

/**
 * Stämpla PUB-avtalets PDF med Sambruks kontrasigneringsintyg.
 * Denna stämplade version visas för organisationer innan de signerar,
 * så att de kan se att Sambruk redan har kontrasignerat avtalet.
 *
 * @param string $sourcePdfPath Sökväg till original-PDF
 * @param array $sambrukData Sambruks signeringsdata:
 *   - sambruk_signer_name, sambruk_signer_email, sambruk_signer_title,
 *   - sambruk_signer_phone, sambruk_signed_at, sambruk_ip_address,
 *   - sambruk_signature_hash, sambruk_certification_text
 * @param string $version Dokumentets versionsnummer
 * @param string $fileHash SHA-256-hash av original-PDF
 * @return string|false Binärt PDF-innehåll eller false vid fel
 */
function stampSambrukCountersignPdf($sourcePdfPath, $sambrukData, $version, $fileHash) {
    if (!file_exists($sourcePdfPath)) {
        return false;
    }

    try {
        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);

        // Importera alla befintliga sidor
        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tplId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);
        }

        // Lägg till kontrasigneringsintyg-sida
        $pdf->AddPage('P', 'A4');

        // Bakgrundsfärg för header
        $pdf->SetFillColor(0, 91, 187);
        $pdf->Rect(0, 0, 210, 40, 'F');

        // Titel
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 8);
        $pdf->Cell(180, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'KONTRASIGNERINGSINTYG'), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetXY(15, 20);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Personuppgiftsbiträdesavtal (PUB-avtal)'), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(15, 28);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Sambruk har kontrasignerat detta avtal som personuppgiftsbiträde'), 0, 1, 'C');

        // Återställ textfärg
        $pdf->SetTextColor(33, 37, 41);

        // --- Dokumentinformation ---
        $y = 48;
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Dokumentinformation'), 0, 1, 'L');
        $y += 9;

        $pdf->SetDrawColor(0, 91, 187);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $y, 195, $y);
        $y += 5;

        $docFields = [
            ['Avtalstyp', 'Personuppgiftsbiträdesavtal (PUB-avtal)'],
            ['Version', $version],
            ['SHA-256 (original)', $fileHash],
        ];

        foreach ($docFields as $field) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(15, $y);
            $pdf->Cell(50, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
            $fontSize = ($field[0] === 'SHA-256 (original)') ? 7 : 10;
            $pdf->SetFont('Helvetica', '', $fontSize);
            $pdf->Cell(130, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
            $y += 7;
        }

        $y += 8;

        // --- Kontrasignerare ---
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Kontrasignerare (Sambruk - Personuppgiftsbiträde)'), 0, 1, 'L');
        $y += 9;

        $pdf->SetDrawColor(0, 91, 187);
        $pdf->Line(15, $y, 195, $y);
        $y += 5;

        $signerFields = [
            ['Namn', $sambrukData['sambruk_signer_name'] ?? ''],
            ['Titel', $sambrukData['sambruk_signer_title'] ?? '-'],
            ['E-post', $sambrukData['sambruk_signer_email'] ?? ''],
            ['Telefon', $sambrukData['sambruk_signer_phone'] ?? '-'],
        ];

        foreach ($signerFields as $field) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(15, $y);
            $pdf->Cell(50, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(130, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
            $y += 7;
        }

        $y += 8;

        // --- Signeringsdetaljer ---
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Signeringsdetaljer'), 0, 1, 'L');
        $y += 9;

        $pdf->SetDrawColor(0, 91, 187);
        $pdf->Line(15, $y, 195, $y);
        $y += 5;

        $detailFields = [
            ['Datum och tid', $sambrukData['sambruk_signed_at'] ?? ''],
            ['IP-adress', $sambrukData['sambruk_ip_address'] ?? ''],
            ['SMS-verifierad', 'Ja'],
            ['Signatur-hash', $sambrukData['sambruk_signature_hash'] ?? ''],
        ];

        foreach ($detailFields as $field) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(15, $y);
            $pdf->Cell(50, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
            $fontSize = ($field[0] === 'Signatur-hash') ? 7 : 10;
            $pdf->SetFont('Helvetica', '', $fontSize);
            $pdf->Cell(130, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
            $y += 7;
        }

        $y += 10;

        // --- Intygandetext ---
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetDrawColor(0, 91, 187);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect(15, $y, 180, 28, 'DF');

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetXY(20, $y + 3);
        $pdf->Cell(170, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Intygande:'), 0, 1, 'L');

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(20, $y + 10);
        $certText = $sambrukData['sambruk_certification_text'] ?? '';
        $pdf->MultiCell(170, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $certText), 0, 'L');

        // Footer-text
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->SetXY(15, 265);
        $pdf->Cell(180, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Detta kontrasigneringsintyg genererades automatiskt av Stimma (stimma.sambruk.se)'), 0, 1, 'C');
        $pdf->SetXY(15, 270);
        $pdf->Cell(180, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Dokumentet visar att Sambruk, i egenskap av personuppgiftsbiträde, har kontrasignerat PUB-avtalet.'), 0, 1, 'C');
        $pdf->SetXY(15, 275);
        $pdf->Cell(180, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Organisationen (personuppgiftsansvarig) signerar avtalet separat.'), 0, 1, 'C');

        return $pdf->Output('S');

    } catch (\Exception $ex) {
        logActivity('system', 'PDF-kontrasigneringsstämpling misslyckades: ' . $ex->getMessage(), [
            'action' => 'pdf_countersign_stamp_failed',
            'error' => $ex->getMessage()
        ]);
        return false;
    }
}
