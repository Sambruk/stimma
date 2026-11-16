<?php
/**
 * Backfill till migration 056 (2026-11-09): spara tecknade PUB-avtal som filer.
 *
 * Avtal tecknade före 2026-11-09 sparades aldrig. De återskapas ur avtalsraden och
 * sparas i pub_docs/signerade/ med signed_pdf_source = 'aterskapad'. Avtal som redan
 * har en sparad fil rörs inte. Omkörbar.
 *
 * Kör som www-data i webbcontainern:
 *   docker exec -u www-data stimma-web-1 php /var/www/html/migrations/backfill_pub_signerade.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';

$rader = query("SELECT * FROM " . DB_DATABASE . ".pub_agreement_artifacts WHERE signed_pdf_file IS NULL ORDER BY id") ?: [];
echo count($rader) . " avtal utan sparad fil\n";
$fel = 0;
foreach ($rader as $a) {
    $r = getSignedPubAgreementPdf($a);
    if ($r['pdf'] === null) {
        $fel++;
        echo "  id {$a['id']} ({$a['org_name']}): FEL {$r['error']}\n";
        continue;
    }
    echo "  id {$a['id']} ({$a['org_name']}): {$r['hash']}\n";
}
exit($fel ? 1 : 0);
