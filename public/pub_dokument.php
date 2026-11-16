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
 * Lämna ut PUB-mallarna efter inloggning (2026-11-09).
 *
 * Mallarna låg förut i public/docs/pdf/ och gick att hämta utan inloggning. Nu
 * ligger de i pub_docs/ utanför webbroten, se pubDocsDir().
 *
 *   ?id=N       PDF-mallen att granska före tecknande (kontrasignerad om den finns).
 *               Alla inloggade, eftersom den som ska teckna måste kunna läsa avtalet.
 *   ?mall=NAMN  Word-mallarna i adminvyn. Bara administratörer och superadmin.
 *
 * Tecknade avtal lämnas ut av admin/pub_download.php, inte här.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

if (!isLoggedIn()) {
    redirect('index.php');
    exit;
}

/** Skicka en fil ur pub_docs/ och avsluta. */
function pubSkickaFil(string $sokvag, string $mime, string $namn, bool $inline): void {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $namn . '"');
    header('Content-Length: ' . filesize($sokvag));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    readfile($sokvag);
    exit;
}

function pubIngenFil(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Dokumentet finns inte.';
    exit;
}

$dir = pubDocsDir();

if (isset($_GET['mall'])) {
    $wordMallar = [
        'PUB_AVTAL_KOMPLETT.docx',
        'PUB_AVTAL.docx',
        'PUB_BILAGA_1_INSTRUKTION.docx',
        'PUB_BILAGA_2_UNDERBITRADEN.docx',
    ];
    $anv = queryOne("SELECT role, is_admin FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
    $behorig = $anv && ($anv['role'] === 'super_admin' || (int)$anv['is_admin'] === 1);
    $namn = (string)$_GET['mall'];
    if (!$behorig) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Endast administratörer kan ladda ner mallarna.';
        exit;
    }
    if (!in_array($namn, $wordMallar, true) || !is_file($dir . $namn)) {
        pubIngenFil();
    }
    pubSkickaFil($dir . $namn, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $namn, false);
}

$doc = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".pub_agreement_documents WHERE id = ?",
    [(int)($_GET['id'] ?? 0)]
);
if (!$doc) {
    pubIngenFil();
}
$original = $dir . basename($doc['filename']);
$kontra = $dir . preg_replace('/\.pdf$/i', '_kontrasignerad.pdf', basename($doc['filename']));
$sokvag = (!empty($doc['sambruk_signed_at']) && is_file($kontra)) ? $kontra : $original;
if (!is_file($sokvag)) {
    pubIngenFil();
}
$namn = 'PUB-avtal-version-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$doc['version']) . '.pdf';
pubSkickaFil($sokvag, 'application/pdf', $namn, true);
