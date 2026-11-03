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
