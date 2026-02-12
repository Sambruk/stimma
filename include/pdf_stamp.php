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
 * @param array $signingData Signeringsdata:
 *   - agreement_id: Avtals-ID
 *   - org_name: Organisationsnamn
 *   - org_number: Organisationsnummer
 *   - domain: Domän
 *   - signer_name: Undertecknarens namn
 *   - signer_title: Undertecknarens titel
 *   - signer_email: Undertecknarens e-post
 *   - signer_phone: Undertecknarens telefon
 *   - ip_address: IP-adress vid signering
 *   - signed_at: Datum/tid (Y-m-d H:i:s)
 *   - pdf_hash: SHA-256-hash av original-PDF
 *   - certification_text: Intygandetext
 * @return string|false Binärt PDF-innehåll eller false vid fel
 */
function stampPubAgreementPdf($sourcePdfPath, $signingData) {
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

        // Lägg till signeringsintyg-sida
        $pdf->AddPage('P', 'A4');

        // Bakgrundsfärg för header
        $pdf->SetFillColor(0, 91, 187); // Blå header
        $pdf->Rect(0, 0, 210, 40, 'F');

        // Titel
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 10);
        $pdf->Cell(180, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'SIGNERINGSINTYG'), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetXY(15, 22);
        $pdf->Cell(180, 8, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Personuppgiftsbiträdesavtal (PUB-avtal) med Sambruk'), 0, 1, 'C');

        // Återställ textfärg
        $pdf->SetTextColor(33, 37, 41);
        $pdf->Ln(10);

        // Avtalsinformation
        $y = 50;
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 8, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Avtalsinformation'), 0, 1, 'L');
        $y += 10;

        // Linje
        $pdf->SetDrawColor(0, 91, 187);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $y, 195, $y);
        $y += 5;

        $fields = [
            ['Avtals-ID', $signingData['agreement_id'] ?? ''],
            ['Organisation', $signingData['org_name'] ?? ''],
            ['Org.nummer', $signingData['org_number'] ?? ''],
            ['Domain', $signingData['domain'] ?? '' ],
        ];

        foreach ($fields as $field) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(15, $y);
            $pdf->Cell(50, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(130, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
            $y += 7;
        }

        $y += 8;

        // Undertecknare
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 8, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Undertecknare'), 0, 1, 'L');
        $y += 10;

        $pdf->SetDrawColor(0, 91, 187);
        $pdf->Line(15, $y, 195, $y);
        $y += 5;

        $signerFields = [
            ['Namn', $signingData['signer_name'] ?? ''],
            ['Titel', $signingData['signer_title'] ?? '-'],
            ['E-post', $signingData['signer_email'] ?? ''],
            ['Telefon', $signingData['signer_phone'] ?? ''],
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

        // Signeringsdetaljer
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(15, $y);
        $pdf->Cell(180, 8, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Signeringsdetaljer'), 0, 1, 'L');
        $y += 10;

        $pdf->SetDrawColor(0, 91, 187);
        $pdf->Line(15, $y, 195, $y);
        $y += 5;

        $detailFields = [
            ['Datum och tid', $signingData['signed_at'] ?? date('Y-m-d H:i:s')],
            ['IP-adress', $signingData['ip_address'] ?? ''],
            ['SMS-verifierad', 'Ja'],
            ['SHA-256 (original)', $signingData['pdf_hash'] ?? ''],
        ];

        foreach ($detailFields as $field) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetXY(15, $y);
            $pdf->Cell(50, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[0] . ':'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', ($field[0] === 'SHA-256 (original)') ? 7 : 10);
            $pdf->Cell(130, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $field[1]), 0, 1, 'L');
            $y += 7;
        }

        $y += 12;

        // Intygandetext i ruta
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetDrawColor(0, 91, 187);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect(15, $y, 180, 30, 'DF');

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetXY(20, $y + 3);
        $pdf->Cell(170, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Intygande:'), 0, 1, 'L');

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(20, $y + 10);
        $certText = $signingData['certification_text'] ?? '';
        $pdf->MultiCell(170, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $certText), 0, 'L');

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
