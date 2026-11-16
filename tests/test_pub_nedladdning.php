<?php
/**
 * Nedladdning av tecknade PUB-avtal i adminvyn (2026-11-03).
 *
 * Visar att:
 *  - administratörer i organisationen (alla dess domäner) och superadmin får ladda ner
 *    avtalet; andra organisationers admins, redaktörer, läsbehöriga och användare får inte
 *  - avtal tecknade innan domänen grupperades (organization_id NULL) följer med organisationen
 *  - avtalet återskapas ur avtalsraden: rätt uppgifter, Sambruks kontrasignering bara om
 *    den fanns när avtalet tecknades
 *  - mallen kontrolleras mot hashen från signeringen; ändrad eller saknad mall ger inget avtal
 *  - nedladdningen loggas och filen cachas inte
 *  - partstabellen (migration 054): nya avtal får Användningspartens uppgifter ifyllda,
 *    gamla avtal återskapas oförändrade; formuläret frågar efter fälten
 *  - superadmin kan förhandsgranska en mall ifylld med exempeldata
 *  - tecknade avtal sparas som filer med SHA-256 och lämnas ut oförändrade (2026-11-09);
 *    ändrad fil lämnas inte ut; mallarna lämnas bara ut efter inloggning (pub_dokument.php)
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/054_pub_partsuppgifter.sql'));
db()->exec(file_get_contents(__DIR__ . '/../migrations/056_pub_signerade_avtal.sql'));
$signDir = getenv('PUB_SIGNED_DIR') ?: '/var/www/html/tests/runtime/pub_signerade';
foreach (glob($signDir . '/*.pdf') ?: [] as $f) { unlink($f); }

$mall = __DIR__ . '/fixtures/pub_mall_test.pdf';
$hash = hash_file('sha256', $mall);
$fel = str_repeat('ab', 32);

// Org A har en andra domän och en domän som tecknade innan den grupperades
dbExec("INSERT INTO organization_domains (organization_id, domain, is_primary) VALUES (2, 'orga2.test', 0), (2, 'gammal.test', 0)");
dbExec("INSERT INTO users (id, email, name, verified_at, role, is_admin) VALUES (30, 'admin@orga2.test', 'Admin A2', NOW(), 'student', 1)");

dbExec("INSERT INTO pub_agreement_documents (id, version, filename, original_filename, file_hash, uploaded_by, is_active,
          sambruk_signed_at, sambruk_signer_name, sambruk_signer_email, sambruk_signature_hash, sambruk_certification_text)
        VALUES (1, '9', 'pub_mall_test.pdf', 'pub_avtal.pdf', ?, 1, 1,
          '2026-02-01 10:00:00', 'Kontra Signerare', 'kontra@sambruk.se', REPEAT('c', 64), 'Sambruk intygar')", [$hash]);
dbExec("INSERT INTO pub_agreement_documents (id, version, filename, original_filename, file_hash, uploaded_by, is_active)
        VALUES (2, '8', 'pub_mall_test.pdf', 'pub_avtal.pdf', ?, 1, 0)", [$fel]);

function avtal(int $id, string $domain, ?int $org, string $signed, string $version, string $pdfHash, string $namn): void {
    dbExec("INSERT INTO pub_agreement_artifacts (id, agreement_id, version, pdf_filename, pdf_hash, signed_at, ip_address,
              user_id, user_email, user_name, user_title, user_phone, domain, organization_id, org_name, org_number,
              agreement_email, certification_text)
            VALUES (?, ?, ?, 'pub_avtal.pdf', ?, ?, '192.0.2.7', 10, ?, ?, 'Kommundirektör', '070-1234567', ?, ?, ?, '212000-0001',
              'avtal@example.test', 'Jag intygar behörighet')",
        [$id, sprintf('00000000-0000-0000-0000-%012d', $id), $version, $pdfHash, $signed, "signer@$domain", $namn, $domain, $org, "Org $id"]);
}
avtal(1, 'orga.test',   2,    '2026-03-01 09:00:00', '9', $hash, 'Anna Efter');    // efter kontrasigneringen
avtal(2, 'orgb.test',   3,    '2026-03-02 09:00:00', '9', $hash, 'Bengt B');
avtal(3, 'gammal.test', null, '2026-01-15 09:00:00', '9', $hash, 'Gunnel Före');   // före kontrasigneringen, ogrupperad
avtal(4, 'orga.test',   2,    '2026-03-03 09:00:00', '8', $fel,  'Hash Fel');      // mallens fil har annan hash
avtal(5, 'orga.test',   2,    '2026-03-04 09:00:00', '7', $hash, 'Mall Saknas');   // ingen mall med version 7
dbExec("UPDATE pub_agreement_artifacts SET invalidated_at = NOW(), invalidation_comment = 'test' WHERE id = 3");

/** Text ur en PDF från FPDF (komprimerade strömmar). */
function pdfText(string $pdf): string {
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
    $ut = '';
    foreach ($m[1] as $s) { $d = @gzuncompress($s); $ut .= $d !== false ? $d : $s; }
    preg_match_all('/\((.*?)\)\s*Tj/s', $ut, $t);
    return mb_convert_encoding(implode(' ', $t[1]), 'UTF-8', 'ISO-8859-1');
}

section('Adminvyn listar organisationens avtal');
$a = new Client('admin-a@orga.test');
$a->get('/admin/pub_documents.php');
check($a->status === 200 && strpos($a->body, 'Ert tecknade PUB-avtal') !== false, 'kortet visas', $a->status . ' ' . snippet($a->body));
foreach ([1 => true, 3 => true, 4 => true, 5 => true, 2 => false] as $id => $ska) {
    check((strpos($a->body, "pub_download.php?id=$id\"") !== false) === $ska, ($ska ? 'listar' : 'listar inte') . " avtal $id");
}
check(strpos($a->body, 'Ogiltigt') !== false, 'ogiltigförklarat avtal markeras');
$b = new Client('admin-b@orgb.test');
$b->get('/admin/pub_documents.php');
check(strpos($b->body, 'pub_download.php?id=2"') !== false && strpos($b->body, 'pub_download.php?id=1"') === false, 'org B ser bara sitt eget');
dbExec("DELETE FROM organization_domains WHERE domain = 'orgb.test'");
dbExec("UPDATE pub_agreement_artifacts SET organization_id = NULL WHERE id = 2");
$b->get('/admin/pub_documents.php');
check(strpos($b->body, 'pub_download.php?id=2"') !== false, 'domän utan organisation ser avtalet för sin domän');
dbExec("INSERT INTO organization_domains (organization_id, domain, is_primary) VALUES (3, 'orgb.test', 1)");
dbExec("UPDATE pub_agreement_artifacts SET organization_id = 3 WHERE id = 2");

section('Nedladdning – rätt avtal med rätt uppgifter');
$a->get('/admin/pub_download.php?id=1');
check($a->status === 200 && ($a->headers['content-type'] ?? '') === 'application/pdf' && strncmp($a->body, '%PDF', 4) === 0, 'PDF lämnas ut', $a->status . ' ' . ($a->headers['content-type'] ?? '') . ' ' . snippet($a->body));
check(strpos($a->headers['content-disposition'] ?? '', 'attachment; filename="PUB-avtal-Org_1-signerat.pdf"') !== false, 'filnamn som i mejlet', $a->headers['content-disposition'] ?? '');
check(strpos($a->headers['cache-control'] ?? '', 'no-store') !== false, 'cachas inte');
$text = pdfText($a->body);
foreach (['00000000-0000-0000-0000-000000000001', 'Org 1', '212000-0001', 'Anna Efter', 'Kommundirektör', 'signer@orga.test', '070-1234567', '192.0.2.7', '2026-03-01 09:00:00', $hash] as $v) {
    check(strpos($text, $v) !== false, "avtalet innehåller $v");
}
check(strpos($text, 'Kontra Signerare') !== false, 'Sambruks kontrasignering med (gjordes före signeringen)');
check(substr_count($a->body, '/Type /Page') - substr_count($a->body, '/Type /Pages') === 4, 'mallens 3 sidor + signeringsintyget');
check((bool)dbOne("SELECT 1 FROM logs WHERE email = 'admin-a@orga.test' AND message LIKE 'Laddade ner PUB-avtal för Org 1%'"), 'nedladdningen loggas');

$a->get('/admin/pub_download.php?id=3');
$text3 = pdfText($a->body);
check($a->status === 200 && strpos($text3, 'Gunnel Före') !== false, 'avtal tecknat innan domänen grupperades går att ladda ner');
check(strpos($text3, 'Kontra Signerare') === false, 'utan kontrasignering när avtalet tecknades före den');

section('Det tecknade avtalet sparas som fil med SHA-256 (2026-11-09)');
$forsta = $a->body; // avtal 3, nyss nedladdat
$r3 = dbOne("SELECT signed_pdf_file, signed_pdf_hash, signed_pdf_source FROM pub_agreement_artifacts WHERE id = 3");
check($r3 && $r3['signed_pdf_hash'] === hash('sha256', $forsta), 'hashen på avtalsraden är filens SHA-256', json_encode($r3));
check($r3 && $r3['signed_pdf_source'] === 'aterskapad', 'gammalt avtal märks som återskapat');
check($r3 && is_file($signDir . '/' . $r3['signed_pdf_file']), 'filen ligger i katalogen för tecknade avtal');
$a->get('/admin/pub_download.php?id=3');
check($a->body === $forsta, 'andra nedladdningen ger samma fil byte för byte');
$a->get('/admin/pub_documents.php');
check(strpos($a->body, substr($r3['signed_pdf_hash'], 0, 12)) !== false && strpos($a->body, 'Återskapad') !== false, 'adminlistan visar hashen och att filen är återskapad');
file_put_contents($signDir . '/' . $r3['signed_pdf_file'], $forsta . '%ändrad');
$a->get('/admin/pub_download.php?id=3');
check(strncmp($a->body, '%PDF', 4) !== 0, 'ändrad fil lämnas inte ut');
$a->get('/admin/pub_documents.php');
check(strpos($a->body, 'stämmer inte med sin kontrollsumma') !== false, 'tydligt besked vid ändrad fil', snippet($a->body, 200));
file_put_contents($signDir . '/' . $r3['signed_pdf_file'], $forsta);
$ut = shell_exec('cd /var/www/html/public && php -r ' . escapeshellarg('
    $_SERVER["HTTP_HOST"] = "stimma.test"; $_SESSION = [];
    require "include/config.php"; require "include/database.php"; require "include/functions.php";
    $rad = queryOne("SELECT id, agreement_id FROM " . DB_DATABASE . ".pub_agreement_artifacts WHERE id = 2");
    $h1 = storeSignedPubAgreementPdf($rad, "%PDF-signering", "signering");
    $h2 = storeSignedPubAgreementPdf($rad, "%PDF-annan", "signering");
    chmod(pubSignedDir() . $rad["agreement_id"] . ".pdf", 0644); // skapad som root här, Apache ska kunna läsa
    echo json_encode([$h1 === hash("sha256", "%PDF-signering"), $h2]);
') . ' 2>/dev/null');
check(trim((string)$ut) === '[true,null]', 'vid signering sparas filen, och ett sparat avtal skrivs aldrig över', (string)$ut);
check(dbOne("SELECT signed_pdf_source FROM pub_agreement_artifacts WHERE id = 2")['signed_pdf_source'] === 'signering', 'avtal sparat vid signeringen märks så');

section('Mallarna lämnas bara ut efter inloggning (2026-11-09)');
$anvA = new Client('anv-a@orga.test');
$anvA->get('/pub_dokument.php?id=1');
check($anvA->status === 200 && strncmp($anvA->body, '%PDF', 4) === 0, 'inloggad får läsa avtalsmallen före tecknande', $anvA->status);
$anvA->get('/pub_dokument.php?mall=PUB_AVTAL.docx');
check($anvA->status === 403, 'Word-mallarna bara för administratörer', $anvA->status);
$a->get('/pub_dokument.php?mall=../.env');
check($a->status === 404 && strpos($a->body, 'DB_') === false, 'bara de namngivna mallarna, inga andra filer', $a->status);
$a->get('/pub_dokument.php?id=999');
check($a->status === 404, 'okänd mall ger 404');
$anonD = new class extends Client { public function __construct() { $this->email = ''; } };
$anonD->get('/pub_dokument.php?id=1');
check(strncmp($anonD->body, '%PDF', 4) !== 0, 'utloggad får ingen mall');
$anonD->get('/docs/pdf/pub_mall_test.pdf');
check($anonD->status === 404, 'gamla sökvägen docs/pdf/ ger 404');

section('Mallen kontrolleras');
$a->get('/admin/pub_download.php?id=4');
check($a->status === 302 && strncmp($a->body, '%PDF', 4) !== 0, 'ändrad mall: inget avtal', $a->status);
$a->get('/admin/pub_documents.php');
check(strpos($a->body, 'Avtalsmallen har ändrats') !== false, 'tydligt besked vid ändrad mall', snippet($a->body, 200));
$a->get('/admin/pub_download.php?id=5');
$a->get('/admin/pub_documents.php');
check(strpos($a->body, 'finns inte längre') !== false, 'tydligt besked när mallen saknas');

section('Behörighet');
$a2 = new Client('admin@orga2.test');
$a2->get('/admin/pub_download.php?id=1');
check($a2->status === 200 && strncmp($a2->body, '%PDF', 4) === 0, 'admin på en annan domän i samma organisation får ladda ner');
$b->get('/admin/pub_download.php?id=1');
check($b->status === 302 && strncmp($b->body, '%PDF', 4) !== 0, 'admin i annan organisation nekas');
check((bool)dbOne("SELECT 1 FROM logs WHERE email = 'admin-b@orgb.test' AND message LIKE 'Nekad nedladdning av PUB-avtal%'"), 'nekat försök loggas');
foreach (['red-a@orga.test', 'las-a@orga.test', 'anv-a@orga.test'] as $em) {
    $c = new Client($em);
    $c->get('/admin/pub_download.php?id=1');
    check(strncmp($c->body, '%PDF', 4) !== 0, "$em nekas");
}
$s = new Client('super@sambruk.se');
$s->get('/admin/pub_download.php?id=2');
check($s->status === 200 && strncmp($s->body, '%PDF', 4) === 0, 'superadmin får ladda ner alla');
$s->get('/admin/pub_documents.php');
check(strpos($s->body, 'pub_download.php?id=2"') !== false && strpos($s->body, 'pub_download.php?id=4"') !== false, 'superadmins avtalslista har nedladdning');
$s->get('/admin/pub_download.php?id=999');
check($s->status === 302, 'okänt avtal nekas');
$anon = new class extends Client { public function __construct() { $this->email = ''; } };
$anon->get('/admin/pub_download.php?id=1');
check(strncmp($anon->body, '%PDF', 4) !== 0, 'utloggad nekas');

section('Partstabellen fylls i för avtal tecknade med de nya fälten (2026-11-03; v8-layout 2026-11-16)');
// Testmallen har en okänd SHA-256 och får därför den senaste layouten (v8): organisationsnr, adress,
// postadress, undertecknaren (namn, mobil, e-post) och e-post till kontaktpersonen för dataskyddsarbetet.
// Inga rader för Peppol eller faktura, inget namn eller telefon för kontaktpersonen.
avtal(6, 'orga.test', 2, '2026-11-03 09:00:00', '9', $hash, 'Nya Ny');
dbExec("UPDATE pub_agreement_artifacts SET org_address = 'Storgatan 1', org_postal_address = '123 45 Exempelstad',
          peppol_id = '0007:2120000001', invoice_reference = 'Ref 4711', contact_name = 'Kalle Kontakt',
          contact_email = 'kalle@orga.test', contact_phone = '0300-11 22 33' WHERE id = 6");
$a->get('/admin/pub_download.php?id=6');
$t6 = pdfText($a->body);
foreach (['Storgatan 1', '123 45 Exempelstad', '070-1234567', 'signer@orga.test', 'kalle@orga.test'] as $v) {
    check(strpos($t6, $v) !== false, "partstabellen har $v");
}
foreach (['0007:2120000001', 'Ref 4711', 'Nya Ny, Kommundirektör', 'Kalle Kontakt', '0300-11 22 33'] as $v) {
    check(strpos($t6, $v) === false, "v8: partstabellen har inte $v");
}
$antalUndertecknare = substr_count($t6, 'Nya Ny');
check($antalUndertecknare >= 2, 'v8: undertecknarens namn i tabellen och i intyget');
check(substr_count($t6, 'Org 6') >= 2, 'organisationens namn både i tabellen och i intyget');
check(strpos($text, 'Storgatan') === false && substr_count($text, 'Anna Efter') === 1 && strpos($text, 'Anna Efter, Kommundirektör') === false, 'gammalt avtal återskapas utan ifylld tabell');
// Avtalet är nu sparat som fil; släng den så att det återskapas med ändrade uppgifter
foreach (glob($signDir . '/*000000000006.pdf') ?: [] as $f) { unlink($f); }
dbExec("UPDATE pub_agreement_artifacts SET contact_name = NULL, contact_email = NULL, contact_phone = NULL,
          signed_pdf_file = NULL, signed_pdf_hash = NULL, signed_pdf_source = NULL WHERE id = 6");
$a->get('/admin/pub_download.php?id=6');
$t6b = pdfText($a->body);
check(substr_count($t6b, 'Nya Ny') === $antalUndertecknare && strpos($t6b, 'kalle@orga.test') === false
    && substr_count($t6b, 'signer@orga.test') > substr_count($t6, 'signer@orga.test'), 'v8: utan dataskydds-e-post står undertecknarens e-post på raden');

section('Layout per mall: gamla v4-avtal återskapas som de tecknades');
$ut = shell_exec('cd /var/www/html/public && php -r ' . escapeshellarg('
    $_SERVER["HTTP_HOST"] = "stimma.test"; $_SESSION = [];
    require "include/config.php"; require "include/database.php"; require "include/functions.php"; require "include/pdf_stamp.php";
    $v4 = pubPartyLayout("20c4675da51fe02e2aa63404cd54f1fcb9617d3959fe027f34170f9f84d3b1e0");
    $pdf = new setasign\Fpdi\Fpdi("P", "mm", "A4"); $pdf->AddPage();
    fillPubPartyTable($pdf, ["org_name" => "O", "org_number" => "212000-0003", "org_address" => "A", "org_postal_address" => "P",
        "signer_name" => "Sigge", "signer_title" => "Chef", "signer_phone" => "070", "signer_email" => "s@x.test",
        "peppol_id" => "0007:1", "invoice_reference" => "Ref 9"], $v4);
    $v7 = pubPartyLayout("8332e149c6b0460711d8f632b50aa907d52561b1b69e8b9f3ab995300cb3a45f")["namn"];
    $v8 = pubPartyLayout("114f2ff6b6b88025da67a0f432195c25c0213bf5825d853409563de94aacfff0")["namn"];
    echo json_encode([$v4["namn"], pubPartyLayout("okand")["namn"], pubPartyLayout(null)["namn"], base64_encode($pdf->Output("S")), $v7, $v8]);
') . ' 2>&1');
$lay = json_decode((string)$ut, true);
check(is_array($lay) && $lay[0] === 'v4' && $lay[1] === 'v8' && $lay[2] === 'v8', 'v4-mallens hash ger v4, okänd mall ger v8', (string)$ut);
check(is_array($lay) && $lay[4] === 'v7' && $lay[5] === 'v8', 'v7- och v8-mallarnas hashar ger sina layouter', (string)$ut);
$t4 = is_array($lay) ? pdfText(base64_decode($lay[3])) : '';
check(strpos($t4, 'Sigge, Chef') !== false && strpos($t4, 'Ref 9') !== false && strpos($t4, '0007:1') !== false && substr_count($t4, 'Sigge') === 2,
    'v4: ombud, Peppol, fakturareferens och kontaktperson (= undertecknaren) som förut', $t4);

$ut = shell_exec('cd /var/www/html/public && php -r ' . escapeshellarg('
    $_SERVER["HTTP_HOST"] = "stimma.test"; $_SESSION = [];
    require "include/config.php"; require "include/database.php"; require "include/functions.php";
    $id = savePubAgreementArtifact(["agreement_id" => "rundtur", "version" => "9", "pdf_filename" => "x.pdf", "pdf_hash" => "h",
        "ip_address" => "192.0.2.9", "user_id" => 10, "user_email" => "admin-a@orga.test", "user_name" => "R", "domain" => "orga.test",
        "org_name" => "R", "org_number" => "212000-0002", "agreement_email" => "r@orga.test", "certification_text" => "c",
        "org_address" => "Väg 2", "org_postal_address" => "111 11 Ort", "peppol_id" => "P", "invoice_reference" => "F",
        "contact_name" => "K", "contact_email" => "k@orga.test", "contact_phone" => "1"]);
    $r = queryOne("SELECT org_address, org_postal_address, peppol_id, invoice_reference, contact_name, contact_email, contact_phone FROM " . DB_DATABASE . ".pub_agreement_artifacts WHERE id = ?", [$id]);
    echo json_encode(array_values($r), JSON_UNESCAPED_UNICODE);
') . ' 2>&1');
check(trim((string)$ut) === '["Väg 2","111 11 Ort","P","F","K","k@orga.test","1"]', 'savePubAgreementArtifact sparar de nya fälten', (string)$ut);

section('Formuläret frågar efter partsuppgifterna');
dbExec("INSERT INTO organizations (id, name, has_pub_agreement) VALUES (4, 'Org Ny', 0)");
dbExec("INSERT INTO organization_domains (organization_id, domain, is_primary) VALUES (4, 'ny.test', 1)");
dbExec("INSERT INTO users (id, email, name, verified_at, role, is_admin) VALUES (40, 'admin@ny.test', 'Admin Ny', NOW(), 'student', 1)");
$ny = new Client('admin@ny.test');
$ny->get('/pub_agreement.php');
foreach (['org_address" name="org_address"', 'org_postal_address" name="org_postal_address"'] as $f) {
    check(strpos($ny->body, $f) !== false, "fältet $f finns", $ny->status . ' ' . snippet($ny->body));
}
// Peppol-ID/GLN och fakturareferens efterfrågas inte (Thomas 2026-11-09)
check(strpos($ny->body, 'name="peppol_id"') === false && strpos($ny->body, 'name="invoice_reference"') === false, 'frågar inte efter Peppol-ID/GLN eller fakturareferens');
check(strpos($ny->body, 'name="contact_name"') === false && strpos($ny->body, 'name="contact_phone"') === false, 'v8: inget namn eller telefon för kontaktpersonen');
check(preg_match('/id="contact_email"[^>]*required/s', $ny->body) === 1 && strpos($ny->body, 'Kontaktperson för dataskyddsarbetet') !== false,
    'v8: obligatorisk e-post till kontaktpersonen för dataskyddsarbetet');
check(strpos($ny->body, 'Undertecknare – behörig företrädare') !== false && strpos($ny->body, 'ej ett växelnummer') === false
    && strpos($ny->body, 'inte ett växelnummer') !== false && strpos($ny->body, 'ombud (avtalsansvarig)') === false
    && strpos($ny->body, 'administration av PUB-avtalet') === false, 'v8: rubrikerna för undertecknaren');
$falt = ['sign_agreement' => '1', 'org_name' => 'Org Ny', 'org_number' => '212000-0004', 'org_address' => 'Väg 1',
    'org_postal_address' => '111 11 Ort', 'signer_name' => 'Admin Ny', 'signer_email' => 'admin@ny.test',
    'signer_phone' => '0701234567', 'agreement_email' => 'reg@ny.test', 'certification' => '1'];
$ny->post('/pub_agreement.php', $falt);
check(strpos($ny->body, 'E-postadressen till kontaktperson för dataskyddsarbetet måste anges') !== false, 'v8: tom dataskydds-e-post nekas', snippet($ny->body));
$ny->post('/pub_agreement.php', $falt + ['contact_email' => 'inte-en-adress']);
check(strpos($ny->body, 'E-postadressen till kontaktperson för dataskyddsarbetet måste anges') !== false, 'v8: ogiltig dataskydds-e-post nekas');
$ny->post('/pub_agreement.php', $falt + ['contact_email' => 'dso@ny.test']);
check(strpos($ny->body, 'kontaktperson för dataskyddsarbetet måste') === false && strpos($ny->body, 'SMS-verifiering krävs') !== false,
    'v8: giltig dataskydds-e-post godtas (stoppas bara av SMS-kravet)');
check(!dbOne("SELECT 1 FROM pub_agreement_artifacts WHERE domain = 'ny.test'"), 'inget avtal sparas utan SMS-verifiering');
// Med v7-mallen aktiv: undertecknaren är kontaktperson, ingen egen fråga
dbExec("UPDATE pub_agreement_documents SET file_hash = '8332e149c6b0460711d8f632b50aa907d52561b1b69e8b9f3ab995300cb3a45f' WHERE id = 1");
$ny->get('/pub_agreement.php');
check(strpos($ny->body, 'name="contact_email"') === false && strpos($ny->body, 'Undertecknare – kontaktperson för administration av PUB-avtalet') !== false,
    'v7: ingen separat kontaktperson, undertecknaren är kontaktperson för administration');
// Med v4-mallen aktiv ser formuläret ut som förut
dbExec("UPDATE pub_agreement_documents SET file_hash = '20c4675da51fe02e2aa63404cd54f1fcb9617d3959fe027f34170f9f84d3b1e0' WHERE id = 1");
$ny->get('/pub_agreement.php');
check(strpos($ny->body, 'Kontaktperson för personuppgiftsarbete') !== false && strpos($ny->body, 'ombud (avtalsansvarig)') !== false
    && preg_match('/id="contact_name"[^>]*required/s', $ny->body) === 1 && preg_match('/id="contact_phone"[^>]*required/s', $ny->body) === 0,
    'v4: egen kontaktperson, rubrikerna och valfri telefon som förut');
dbExec("UPDATE pub_agreement_documents SET file_hash = ? WHERE id = 1", [$hash]);
check(preg_match('/id="org_address"[^>]*required/s', $ny->body) === 1 && preg_match('/id="org_postal_address"[^>]*required/s', $ny->body) === 1, 'adress och postadress obligatoriska');

section('Superadmin förhandsgranskar mallen ifylld');
$s->get('/admin/pub_documents.php');
check(strpos($s->body, 'pub_download.php?forhandsgranska=1"') !== false, 'länk till förhandsgranskning');
$s->get('/admin/pub_download.php?forhandsgranska=1');
$tp = pdfText($s->body);
check($s->status === 200 && strpos($tp, 'Storgatan 1') !== false && strpos($tp, 'EXEMPEL') !== false && strpos($tp, 'Kontra Signerare') !== false, 'exempeldata och kontrasignering', $s->status);
check(strpos($tp, '0007:') === false && strpos($tp, '4711') === false, 'förhandsgranskningen har tomt Peppol-ID och fakturareferens');
check(strpos($s->headers['content-disposition'] ?? '', 'EXEMPEL-PUB-avtal') !== false, 'filnamnet säger EXEMPEL');
$a->get('/admin/pub_download.php?forhandsgranska=1');
check(strncmp($a->body, '%PDF', 4) !== 0, 'vanlig admin får inte förhandsgranska');

done();
