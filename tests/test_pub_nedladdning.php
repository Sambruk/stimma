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
 */
require __DIR__ . '/lib.php';
reseed();

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
check(substr_count($a->body, '/Type /Page') - substr_count($a->body, '/Type /Pages') === 3, 'mallens 2 sidor + signeringsintyget');
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

done();
