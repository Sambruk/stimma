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
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/054_pub_partsuppgifter.sql'));

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

section('Partstabellen fylls i för avtal tecknade med de nya fälten (2026-11-03)');
avtal(6, 'orga.test', 2, '2026-11-03 09:00:00', '9', $hash, 'Nya Ny');
dbExec("UPDATE pub_agreement_artifacts SET org_address = 'Storgatan 1', org_postal_address = '123 45 Exempelstad',
          peppol_id = '0007:2120000001', invoice_reference = 'Ref 4711', contact_name = 'Kalle Kontakt',
          contact_email = 'kalle@orga.test', contact_phone = '0300-11 22 33' WHERE id = 6");
$a->get('/admin/pub_download.php?id=6');
$t6 = pdfText($a->body);
foreach (['Storgatan 1', '123 45 Exempelstad', '0007:2120000001', 'Ref 4711', 'Kalle Kontakt', 'kalle@orga.test', '0300-11 22 33', 'Nya Ny, Kommundirektör'] as $v) {
    check(strpos($t6, $v) !== false, "partstabellen har $v");
}
check(substr_count($t6, 'Org 6') >= 2, 'organisationens namn både i tabellen och i intyget');
check(strpos($text, 'Storgatan') === false && substr_count($text, 'Anna Efter') === 1 && strpos($text, 'Anna Efter, Kommundirektör') === false, 'gammalt avtal återskapas utan ifylld tabell');
dbExec("UPDATE pub_agreement_artifacts SET contact_name = NULL, contact_email = NULL, contact_phone = NULL WHERE id = 6");
$a->get('/admin/pub_download.php?id=6');
$t6b = pdfText($a->body);
check(substr_count($t6b, 'Nya Ny') >= 3 && strpos($t6b, 'Kalle') === false, 'tom kontaktperson = undertecknaren');

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
foreach (['org_address" name="org_address"', 'org_postal_address" name="org_postal_address"', 'name="peppol_id"', 'name="invoice_reference"', 'name="contact_name"', 'name="contact_email"', 'name="contact_phone"'] as $f) {
    check(strpos($ny->body, $f) !== false, "fältet $f finns", $ny->status . ' ' . snippet($ny->body));
}
check(preg_match('/id="org_address"[^>]*required/s', $ny->body) === 1 && preg_match('/id="org_postal_address"[^>]*required/s', $ny->body) === 1, 'adress och postadress obligatoriska');

section('Superadmin förhandsgranskar mallen ifylld');
$s->get('/admin/pub_documents.php');
check(strpos($s->body, 'pub_download.php?forhandsgranska=1"') !== false, 'länk till förhandsgranskning');
$s->get('/admin/pub_download.php?forhandsgranska=1');
$tp = pdfText($s->body);
check($s->status === 200 && strpos($tp, 'Storgatan 1') !== false && strpos($tp, 'EXEMPEL') !== false && strpos($tp, 'Kontra Signerare') !== false, 'exempeldata och kontrasignering', $s->status);
check(strpos($s->headers['content-disposition'] ?? '', 'EXEMPEL-PUB-avtal') !== false, 'filnamnet säger EXEMPEL');
$a->get('/admin/pub_download.php?forhandsgranska=1');
check(strncmp($a->body, '%PDF', 4) !== 0, 'vanlig admin får inte förhandsgranska');

done();
