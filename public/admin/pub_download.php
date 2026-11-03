<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

/**
 * Ladda ner ett tecknat PUB-avtal (2026-11-03).
 *
 * Avtalet återskapas ur avtalsraden, se buildSignedPubAgreementPdf(). Filen
 * innehåller undertecknarens e-post, telefon och IP-adress och sparas därför
 * aldrig på disk; den lämnas bara ut här, efter behörighetskontroll.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

require_once 'include/auth_check.php';

// Superadmin: mallen ifylld med exempeldata (2026-11-03). Partstabellens placering
// gäller en viss mall (PUB_PARTY_TABLE i pdf_stamp.php) – kontrollera en ny mall här
// innan den kontrasigneras och organisationer tecknar den.
if (isset($_GET['forhandsgranska'])) {
    $roll = queryOne("SELECT role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
    $mall = queryOne("SELECT * FROM " . DB_DATABASE . ".pub_agreement_documents WHERE id = ?", [(int)$_GET['forhandsgranska']]);
    $mallPdf = $mall ? rtrim(getenv('PUB_PDF_DIR') ?: __DIR__ . '/../docs/pdf', '/') . '/' . basename($mall['filename']) : '';
    if (!$roll || $roll['role'] !== 'super_admin' || !$mall || !is_file($mallPdf)) {
        $_SESSION['message'] = 'Förhandsgranskningen kunde inte visas.';
        $_SESSION['message_type'] = 'warning';
        redirect('pub_documents.php');
        exit;
    }
    require_once __DIR__ . '/../include/pdf_stamp.php';
    $pdf = stampPubAgreementPdf($mallPdf, [
        'agreement_id' => 'EXEMPEL-0000-0000-0000-000000000000',
        'org_name' => 'Exempelstads kommun (EXEMPEL)',
        'org_number' => '212000-0000',
        'domain' => 'exempelstad.se',
        'signer_name' => 'Anna Andersson',
        'signer_title' => 'Kommundirektör',
        'signer_email' => 'anna.andersson@exempelstad.se',
        'signer_phone' => '070-123 45 67',
        'ip_address' => '192.0.2.1',
        'signed_at' => date('Y-m-d H:i:s'),
        'pdf_hash' => $mall['file_hash'],
        'certification_text' => 'EXEMPEL – inget tecknat avtal.',
        'org_address' => 'Storgatan 1',
        'org_postal_address' => '123 45 Exempelstad',
        'peppol_id' => '0007:2120000000',
        'invoice_reference' => 'PUB-Stimma 4711',
        'contact_name' => 'Bertil Berg',
        'contact_email' => 'bertil.berg@exempelstad.se',
        'contact_phone' => '0300-12 34 56',
    ], !empty($mall['sambruk_signed_at']) ? getSambrukSignatureData($mall['id']) : null);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="EXEMPEL-PUB-avtal-version-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $mall['version']) . '.pdf"');
    header('Cache-Control: no-store, private');
    echo $pdf;
    exit;
}

$artifact = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".pub_agreement_artifacts WHERE id = ?",
    [(int)($_GET['id'] ?? 0)]
);

if (!$artifact || !canDownloadPubArtifact($artifact, $_SESSION['user_email'])) {
    logActivity($_SESSION['user_email'], "Nekad nedladdning av PUB-avtal", [
        'action' => 'pub_download_denied',
        'artifact_id' => (int)($_GET['id'] ?? 0),
    ]);
    $_SESSION['message'] = 'Du har inte behörighet att ladda ner det avtalet.';
    $_SESSION['message_type'] = 'warning';
    redirect('pub_documents.php');
    exit;
}

$resultat = buildSignedPubAgreementPdf($artifact);
if ($resultat['pdf'] === null) {
    logActivity($_SESSION['user_email'], "PUB-avtal kunde inte återskapas: {$resultat['error']}", [
        'action' => 'pub_download_failed',
        'artifact_id' => (int)$artifact['id'],
        'error' => $resultat['error'],
    ]);
    $_SESSION['message'] = [
        'no_source'     => 'Avtalsmallen som avtalet tecknades på finns inte längre, så avtalet kan inte tas fram. Kontakta Sambruk.',
        'hash_mismatch' => 'Avtalsmallen har ändrats sedan avtalet tecknades, så avtalet kan inte tas fram. Kontakta Sambruk.',
    ][$resultat['error']] ?? 'Avtalet kunde inte tas fram. Försök igen eller kontakta Sambruk.';
    $_SESSION['message_type'] = 'danger';
    redirect('pub_documents.php');
    exit;
}

logActivity($_SESSION['user_email'], "Laddade ner PUB-avtal för {$artifact['org_name']} ({$artifact['domain']})", [
    'action' => 'pub_download',
    'artifact_id' => (int)$artifact['id'],
    'agreement_id' => $artifact['agreement_id'],
]);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . signedPubAgreementFilename($artifact) . '"');
header('Content-Length: ' . strlen($resultat['pdf']));
header('Cache-Control: no-store, private');
echo $resultat['pdf'];
