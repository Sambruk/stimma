<?php
/**
 * Omtecknande av PUB-avtal när mallen uppdateras (2026-11-03).
 *
 * Visar att:
 *  - en ny aktiv mall ger besked om omtecknande först när den är kontrasignerad
 *  - jämförelsen görs på mallens hash: ny version av samma fil kräver inget omtecknande
 *  - bara administratörer får beskedet; det gamla avtalet gäller under tiden, så
 *    ingen får varningen om saknat PUB-avtal
 *  - signeringssidan släpper in organisationen och förklarar varför
 *  - när det nya är tecknat försvinner beskedet och det gamla visas som ersatt
 *  - PUB registrerat för hand (flaggan utan tecknat avtal) påverkas inte
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/054_pub_partsuppgifter.sql'));

$h1 = hash_file('sha256', __DIR__ . '/fixtures/pub_mall_test.pdf');
$h2 = str_repeat('2', 64);

function mall(int $id, string $version, string $hash, bool $aktiv, bool $kontra): void {
    if ($aktiv) dbExec("UPDATE pub_agreement_documents SET is_active = 0");
    dbExec("INSERT INTO pub_agreement_documents (id, version, filename, original_filename, file_hash, uploaded_by, is_active,
              sambruk_signed_at, sambruk_signer_name) VALUES (?, ?, 'pub_mall_test.pdf', 'pub.pdf', ?, 1, ?, ?, ?)",
        [$id, $version, $hash, $aktiv ? 1 : 0, $kontra ? '2026-01-01 00:00:00' : null, $kontra ? 'Kontra' : null]);
}
function avtal(int $id, string $hash, string $version, string $signed): void {
    dbExec("INSERT INTO pub_agreement_artifacts (id, agreement_id, version, pdf_filename, pdf_hash, signed_at, ip_address,
              user_id, user_email, user_name, domain, organization_id, org_name, org_number, agreement_email, certification_text)
            VALUES (?, ?, ?, 'pub.pdf', ?, ?, '192.0.2.1', 10, 'admin-a@orga.test', 'Admin A', 'orga.test', 2, 'Org A', '212000-0001',
              'admin-a@orga.test', 'c')", [$id, "avtal-$id", $version, $hash, $signed]);
}
function harBesked(Client $c): bool {
    $c->get('/index.php');
    return strpos($c->body, 'PUB-avtalet har uppdaterats till version') !== false;
}

mall(1, '3', $h1, true, true);
avtal(1, $h1, '3', '2026-02-01 10:00:00');
$admin = new Client('admin-a@orga.test');
$anv = new Client('anv-a@orga.test');
$red = new Client('red-a@orga.test');

section('Avtalet är tecknat på den aktiva mallen');
check(!harBesked($admin), 'inget besked');
$admin->get('/pub_agreement.php');
check($admin->status === 302, 'signeringssidan stängd när avtalet redan är tecknat', (string)$admin->status);

section('Ny version av samma fil (som v3 → v4)');
mall(2, '4', $h1, true, true);
check(!harBesked($admin), 'inget besked – samma innehåll, bara nytt versionsnummer');

section('Ny mall, ännu inte kontrasignerad');
mall(3, '5', $h2, true, false);
check(!harBesked($admin), 'inget besked innan Sambruk kontrasignerat');

section('Ny mall kontrasignerad');
dbExec("UPDATE pub_agreement_documents SET sambruk_signed_at = NOW(), sambruk_signer_name = 'Kontra' WHERE id = 3");
check(harBesked($admin), 'administratören får beskedet');
check(strpos($admin->body, 'Teckna ny version') !== false, 'med knapp till signeringen');
check(strpos($admin->body, 'har inte tecknat ett PUB-avtal') === false, 'ingen varning om saknat avtal');
check(!harBesked($anv) && strpos($anv->body, 'har inte tecknat ett PUB-avtal') === false, 'användaren märker inget');
check(!harBesked($red), 'redaktören får inget besked');
$admin2 = new Client('admin-b@orgb.test');
check(!harBesked($admin2), 'PUB registrerat för hand (inget tecknat avtal) påverkas inte');

$admin->get('/pub_agreement.php');
check($admin->status === 200 && strpos($admin->body, 'Ny version av PUB-avtalet att teckna') !== false, 'signeringssidan öppen med förklaring', $admin->status . ' ' . snippet($admin->body));
check(strpos($admin->body, 'tecknade version 3 den 2026-02-01') !== false && strpos($admin->body, 'till version 5') !== false, 'rätt versioner i förklaringen');
$admin->get('/admin/pub_documents.php');
check(strpos($admin->body, 'PUB-avtal: Ny version att teckna') !== false, 'adminvyns märke visar ny version');
check(strpos($admin->body, 'Ny version att teckna.') !== false, 'kortet "Ert tecknade PUB-avtal" visar beskedet');
check((int)dbOne("SELECT has_pub_agreement FROM organizations WHERE id = 2")['has_pub_agreement'] === 1, 'organisationen har fortfarande sitt PUB-avtal');

section('Det nya avtalet är tecknat');
avtal(2, $h2, '5', '2026-11-03 10:00:00');
check(!harBesked($admin), 'beskedet försvinner');
$admin->get('/pub_agreement.php');
check($admin->status === 302, 'signeringssidan stängd igen');
$admin->get('/admin/pub_documents.php');
check(strpos($admin->body, 'Ersatt av nyare avtal') !== false && strpos($admin->body, 'PUB-avtal: Ja') !== false, 'gamla avtalet visas som ersatt');
$s = new Client('super@sambruk.se');
$s->get('/admin/pub_documents.php');
check(substr_count($s->body, '>Ersatt</span>') === 1, 'superadmins lista: ett ersatt avtal');

section('Ogiltigförklarat nytt avtal → det gamla är senaste giltiga igen');
dbExec("UPDATE pub_agreement_artifacts SET invalidated_at = NOW() WHERE id = 2");
check(harBesked($admin), 'beskedet kommer tillbaka');

done();
