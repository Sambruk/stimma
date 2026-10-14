<?php
/**
 * Insyns-API:t (/api/v1/insyn/), 2026-10-14.
 *
 * Visar att:
 *  - en systemnyckel skapas och återkallas av superadmin i adminvyn, och bara där
 *  - nyckeln bara ger läsåtkomst (alla andra metoder än GET nekas, databasen oförändrad)
 *  - organisationernas synknycklar (stm_) inte når /insyn/, och systemnyckeln inte når synk-API:t
 *  - uppgifter om enskilda deltagare inte lämnas ut; små grupper redovisas som "<5"
 *  - loggning, rate limit, IP-begränsning och spärr efter misslyckade försök fungerar
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/051_system_api_keys.sql'));
dbExec("DELETE FROM system_api_log");
dbExec("DELETE FROM system_api_keys");

// ------------------------------------------------------------------ testdata
// Sex deltagare i org A:s kurs 100 (fem klara), quizsvar, diplom, PUB-avtal,
// lärväg, baskurstagg, kopia, AI-anrop, synk och importhändelser.
db()->exec("SET FOREIGN_KEY_CHECKS=0");
for ($i = 1; $i <= 6; $i++) {
    $id = 30 + $i;
    dbExec("INSERT INTO users (id, email, name, verified_at, last_login_at) VALUES (?, ?, ?, NOW(), NOW() - INTERVAL ? DAY)",
        [$id, "delt$i@orga.test", "Deltagare Hemlig$i", $i * 10]);
    dbExec("INSERT INTO course_enrollments (user_id, course_id, status) VALUES (?, 100, 'active')", [$id]);
    $lessons = $i <= 5 ? [1001, 1002, 1003] : [1001];
    foreach ($lessons as $n => $l) {
        dbExec("INSERT INTO progress (user_id, lesson_id, status, score, started_at, updated_at) VALUES (?, ?, 'completed', 1, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL ? DAY)",
            [$id, $l, $n === 0 ? 10 : 4]);
    }
    if ($i <= 5) {
        dbExec("INSERT INTO quiz_answers (user_id, lesson_id, question_id, answer, is_correct) VALUES (?, 1001, 10011, '1', 1), (?, 1003, 10031, '[0,2]', 1), (?, 1003, 10032, 'false', 0)", [$id, $id, $id]);
    }
    if ($i <= 3) {
        dbExec("INSERT INTO certificates (user_id, course_id, certificate_number, course_title, user_name, completion_date) VALUES (?, 100, ?, 'A1', ?, CURDATE())",
            [$id, "CERT-$id", "Deltagare Hemlig$i"]);
    }
}
dbExec("UPDATE users SET last_login_at = NOW() - INTERVAL 2 DAY WHERE email IN ('admin-a@orga.test', 'super@sambruk.se')");
dbExec("INSERT INTO course_editors (course_id, email, created_by) VALUES (100, 'red-b@orgb.test', 'super@sambruk.se')"); // extern redaktör
dbExec("INSERT INTO tags (id, name, organization_domain) VALUES (1, 'Baskurs', 'sambruk.se'), (2, 'Säkerhet', 'orga.test')");
dbExec("INSERT INTO course_tags (course_id, tag_id) VALUES (100, 1), (100, 2)");
dbExec("UPDATE courses SET status = 'inactive' WHERE id = 201");
dbExec("UPDATE lessons SET ai_chat_enabled = 1 WHERE id = 1002");
dbExec("INSERT INTO logs (email, message, created_at) VALUES
  ('admin-b@orgb.test', 'Kopierade kurs | Kontext: {\"action\":\"course_copied\",\"source_course_id\":100,\"new_course_id\":202,\"target_domain\":\"orgb.test\"}', NOW() - INTERVAL 5 DAY),
  ('admin-a@orga.test', 'Läste in kurspaket ''Test'' som kurs 100', NOW() - INTERVAL 3 DAY),
  ('admin-b@orgb.test', 'Importerade SCORM-paket ''x.zip'' till AI-jobb (id=1)', NOW() - INTERVAL 40 DAY),
  ('admin-a@orga.test', 'Laddade upp ljudfil: abc.mp3', NOW() - INTERVAL 1 DAY)");
dbExec("INSERT INTO ai_usage_log (organization_id, user_email, feature, model, total_tokens, status, created_at) VALUES
  (2, 'anv-a@orga.test', 'chat', 'm', 100, 'ok', NOW() - INTERVAL 1 DAY),
  (2, 'red-a@orga.test', 'course_gen', 'm', 900, 'ok', NOW() - INTERVAL 2 DAY),
  (3, 'red-b@orgb.test', 'image', 'm', 50, 'ok', NOW() - INTERVAL 60 DAY)");
// Inloggningar finns i loggen; users.last_login_at skrivs inte av Stimma.
dbExec("INSERT INTO logs (email, message, created_at) VALUES
  ('admin-b@orgb.test', 'Lyckad inloggning med token | Kontext: {}', NOW() - INTERVAL 3 DAY),
  ('admin-b@orgb.test', 'Automatisk inloggning via kom-ihåg-mig cookie | Kontext: {}', NOW() - INTERVAL 1 DAY),
  ('red-b@orgb.test', 'Misslyckad inloggning: ogiltig eller utgången token | Kontext: {}', NOW() - INTERVAL 1 DAY)");
dbExec("UPDATE courses SET original_organization_domain = 'orga.test' WHERE id = 100"); // original, inte kopia
dbExec("INSERT INTO sync_log (domain, users_in_payload, created_at) VALUES ('orgb.test', 4, NOW() - INTERVAL 2 DAY)");
dbExec("INSERT INTO learning_paths (id, title, status, organization_domain, created_by) VALUES (1, 'Lärväg A', 'active', 'orga.test', 10)");
dbExec("INSERT INTO learning_path_courses (learning_path_id, course_id, sort_order) VALUES (1, 101, 1), (1, 100, 0)");
dbExec("INSERT INTO pub_agreement_documents (id, version, filename, original_filename, file_hash, uploaded_by, is_active) VALUES (1, '5', 'pub5.pdf', 'pub5.pdf', 'abc123', 1, 1)");
$pub = "INSERT INTO pub_agreement_artifacts (agreement_id, version, pdf_hash, signed_at, ip_address, user_id, user_email, user_name, user_title, user_phone, domain, org_name, org_number, agreement_email, certification_text, invalidated_at)
        VALUES (?, ?, ?, ?, '10.0.0.1', ?, ?, ?, ?, '070-1234567', ?, ?, '212000-0001', 'avtal@x.test', 'Jag intygar', ?)";
dbExec($pub, ['uuid-a-gammal', '4', 'h4', '2026-03-01 10:00:00', 10, 'admin-a@orga.test', 'Admin A', 'Kommundirektör', 'orga.test', 'Org A kommun', null]);
dbExec($pub, ['uuid-a-ny', '5', 'h5', '2026-09-01 10:00:00', 10, 'admin-a@orga.test', 'Admin A', 'Kommundirektör', 'orga.test', 'Org A kommun', null]);
dbExec($pub, ['uuid-b-ogiltig', '4', 'hb', '2026-04-01 10:00:00', 20, 'admin-b@orgb.test', 'Admin B', 'IT-chef', 'orgb.test', 'Org B kommun', '2026-05-01 10:00:00']);
// Organisationens synknyckel (stm_) för orga.test
$orgKey = 'stm_' . bin2hex(random_bytes(30));
dbExec("INSERT INTO api_keys (domain, api_key_hash, api_key_prefix, is_active) VALUES ('orga.test', ?, ?, 1)", [hash('sha256', $orgKey), substr($orgKey, 0, 12)]);
dbExec("UPDATE domain_settings SET sync_enabled = 1 WHERE domain = 'orga.test'");
db()->exec("SET FOREIGN_KEY_CHECKS=1");

/** Anrop mot API:t utan session. */
function api(string $path, ?string $key, string $method = 'GET', array $headers = [], ?string $body = null): array {
    $ch = curl_init(BASE . $path);
    $h = ['Host: ' . HOST, 'X-Forwarded-Proto: https'];
    if ($key !== null) $h[] = 'Authorization: Bearer ' . $key;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_TIMEOUT => 30]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'head' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'json' => json_decode(substr($raw, $hs), true)];
}
function byKey(array $items, string $field, $value): ?array {
    foreach ($items as $it) if (($it[$field] ?? null) === $value) return $it;
    return null;
}
/** Kontrollsumma över alla tabeller utom API:ts egna (logg och nycklar). */
function dbFingerprint(): string {
    $tables = array_filter(array_column(dbAll("SHOW TABLES"), 'Tables_in_stimma_test'),
        fn($t) => !in_array($t, ['system_api_log', 'system_api_keys'], true));
    $rows = dbAll("CHECKSUM TABLE " . implode(', ', array_map(fn($t) => "`$t`", $tables)));
    return md5(json_encode($rows));
}

// ------------------------------------------------------------------ adminvyn
section('Adminvyn: bara superadmin skapar och återkallar systemnycklar');
$admin = new Client('admin-a@orga.test');
$admin->get('/admin/system_api_keys.php');
check($admin->status === 302 && strpos($admin->redirectedTo(), 'index.php') !== false, 'org-admin nekas sidan', "{$admin->status} {$admin->redirectedTo()}");
$admin->post('/admin/system_api_keys.php', ['action' => 'create', 'name' => 'Inbrott']);
check((int)dbOne("SELECT COUNT(*) n FROM system_api_keys")['n'] === 0, 'org-admin kan inte skapa en nyckel med POST');

$super = new Client('super@sambruk.se');
$super->get('/admin/system_api_keys.php');
check($super->status === 200 && strpos($super->body, 'Skapa systemnyckel') !== false, 'superadmin ser sidan', (string)$super->status);
$super->post('/admin/system_api_keys.php', ['action' => 'create', 'name' => 'Magnus (AIPL)', 'allowed_ips' => '']);
$super->get('/admin/system_api_keys.php');
preg_match('/value="(sts_[0-9a-f]{64})"/', $super->body, $m);
$key = $m[1] ?? '';
check($key !== '', 'nyckeln visas efter skapandet');
$stored = dbOne("SELECT * FROM system_api_keys WHERE name = 'Magnus (AIPL)'");
check($stored && $stored['api_key_hash'] === hash('sha256', $key) && strpos(json_encode($stored), $key) === false, 'bara hashen lagras');
$super->get('/admin/system_api_keys.php');
check(strpos($super->body, $key) === false, 'nyckeln visas inte en gång till');
$super->post('/admin/system_api_keys.php', ['action' => 'create', 'name' => 'Fel IP', 'allowed_ips' => '999.1.1.1']);
check(!dbOne("SELECT 1 FROM system_api_keys WHERE name = 'Fel IP'"), 'ogiltig IP-lista godtas inte');

// ------------------------------------------------------------------ autentisering
section('Autentisering och nyckeltyper');
check(api('/api/v1/insyn/health', null)['status'] === 401, 'utan nyckel: 401');
check(api('/api/v1/insyn/health', 'sts_' . str_repeat('0', 64))['status'] === 401, 'påhittad systemnyckel: 401');
check(api('/api/v1/insyn/health', $orgKey)['status'] === 401, 'organisationens synknyckel (stm_) når inte /insyn/: 401');
$r = api('/api/sync_users.php', $key, 'POST', ['Content-Type: application/json'], json_encode(['users' => [['email' => 'x@orga.test', 'name' => 'X']]]));
check($r['status'] === 401 && !dbOne("SELECT 1 FROM users WHERE email = 'x@orga.test'"), 'systemnyckeln når inte synk-API:t: 401', (string)$r['status']);
$r = api('/api/v1/insyn/health', $key);
check($r['status'] === 200 && $r['json']['status'] === 'ok' && $r['json']['organizations'] === 3, 'health med systemnyckel: 200, 3 organisationer', $r['body']);
check(stripos($r['head'], 'Set-Cookie') === false, 'API-svaret sätter ingen sessionskaka');

// ------------------------------------------------------------------ bara läsning
section('Bara läsning');
$before = dbFingerprint();
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $mth) {
    foreach (['/api/v1/insyn/courses', '/api/v1/insyn/admins', '/api/v1/insyn/courses/100/stats'] as $p) {
        $r = api($p, $key, $mth, ['Content-Type: application/json'], json_encode(['status' => 'inactive', 'title' => 'Ändrad']));
        check($r['status'] === 405 && stripos($r['head'], 'Allow: GET') !== false, "$mth $p: 405");
    }
}
$all = [];
foreach (['health', 'organizations', 'admins', 'editors', 'pub-agreements', 'courses', 'courses/100/stats', 'courses/101/stats', 'usage', 'learning-paths',
          'admins?organization_id=2', 'courses?organization_id=orgb.test&status=active'] as $p) {
    $r = api("/api/v1/insyn/$p", $key);
    check($r['status'] === 200, "GET /$p: 200", $r['status'] . ' ' . snippet($r['body']));
    $all[$p] = $r;
}
check(dbFingerprint() === $before, 'databasen är oförändrad efter alla anrop (utom API-loggen)');

// ------------------------------------------------------------------ uppgiftsminimering
section('Inga uppgifter om enskilda deltagare');
$joined = implode("\n", array_map(fn($r) => $r['body'], $all));
foreach (['delt1@orga.test', 'Hemlig', 'anv-a@orga.test', 'Användare A', 'anv-b@orgb.test', 'las-a@orga.test', 'CERT-31', '070-1234567', '10.0.0.1'] as $needle) {
    check(stripos($joined, $needle) === false, "ingen träff på '$needle' i något svar");
}
$s = $all['courses/100/stats']['json'];
check($s['participants'] === ['started' => 7, 'completed' => 5, 'certificates' => '<5', 'abandoned' => 0], 'kurs 100: 7 påbörjade, 5 klara, diplom "<5"', json_encode($s['participants']));
check($s['quiz']['participants_with_answers'] === 5 && $s['quiz']['average_correct_pct'] === 66.7, 'genomsnittlig andel rätt 66,7 % över 5 personer', json_encode($s['quiz']));
check($s['time']['average_days_to_complete'] === 6.0 && $s['time']['median_days_to_complete'] === 6.0, 'genomsnittlig tid 6 dagar', json_encode($s['time']));
check(count($s['by_organization']) === 1 && $s['by_organization'][0]['organization']['id'] === '2', 'per organisation: bara org A', json_encode($s['by_organization']));
$s = $all['courses/101/stats']['json'];
check($s['participants']['started'] === '<5' && $s['quiz']['average_correct_pct'] === null && $s['time']['average_days_to_complete'] === null,
    'kurs 101 (1 deltagare): "<5" och inga medelvärden', json_encode($s['participants']) . json_encode($s['quiz']));
check(!preg_grep('/user|email|name/i', array_keys($s['participants'])), 'statistiken har inga personfält');
$o = byKey($all['organizations']['json']['data'], 'id', '2');
check($o['users']['total'] === 10 && $o['users']['active_30_days'] === '<5' && $o['users']['active_90_days'] === 7,
    'org A: 10 användare, aktiva 30 dgr "<5", 90 dgr 7', json_encode($o['users']));
$ob = byKey($all['organizations']['json']['data'], 'id', '3');
check($ob['users']['active_30_days'] === '<5' && $ob['users']['active_90_days'] === '<5', 'org B: aktiv via inloggning i loggen (misslyckad inloggning räknas inte)', json_encode($ob['users']));

// ------------------------------------------------------------------ innehåll
section('Innehåll per endpoint');
$org = $all['organizations']['json'];
check($org['total'] === 3 && byKey($org['data'], 'id', '1')['name'] === 'Sambruk' && $o['type'] === 'organization' && $o['domains'] === ['orga.test'], 'organizations: id, namn, domäner');
check($o['courses'] === ['total' => 2, 'active' => 2] && $o['ai_tutor'] === ['enabled' => true, 'courses_with_tutor' => 1] && $o['ai']['tokens_used_this_month'] >= 0 && $o['pub_agreement']['version'] === '5',
    'organizations: kurser, AI-tutor, AI-pott, PUB', json_encode([$o['courses'], $o['ai_tutor'], $o['pub_agreement']]));

$ad = $all['admins']['json']['data'];
check(count($ad) === 3 && array_keys($ad[0]) === ['name', 'email', 'role', 'organization', 'last_login'], 'admins: 3 st, bara namn, e-post, roll, organisation, senaste inloggning', json_encode(array_keys($ad[0] ?? [])));
$sa = byKey($ad, 'email', 'super@sambruk.se');
check($sa['role'] === 'superadmin' && $sa['organization']['id'] === '1' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$sa['last_login']), 'admins: superadmin, Sambruk, datum utan klockslag', json_encode($sa));
check(byKey($ad, 'email', 'admin-b@orgb.test')['last_login'] === date('Y-m-d', strtotime('-1 day')), 'admins: senaste inloggning ur loggen (kom-ihåg-mig)', json_encode(byKey($ad, 'email', 'admin-b@orgb.test')));
$adA = $all['admins?organization_id=2']['json']['data'];
check(count($adA) === 1 && $adA[0]['email'] === 'admin-a@orga.test', 'admins?organization_id=2: bara admin A');
check(api('/api/v1/insyn/admins?organization_id=orga.test', $key)['json']['data'] == $adA, 'organization_id tar även domänen');
check(api('/api/v1/insyn/admins?organization_id=finnsinte.se', $key)['status'] === 404, 'okänd organisation: 404');

$ed = $all['editors']['json']['data'];
$redB = byKey($ed, 'email', 'red-b@orgb.test');
check($redB && $redB['organization']['id'] === '3' && in_array(100, array_column($redB['courses'], 'id'), true)
    && byKey($redB['courses'], 'id', 100)['organization']['id'] === '2', 'editors: red-b (org B) är redaktör för org A:s kurs 100', json_encode($redB));
check(byKey($ed, 'email', 'super@sambruk.se')['editor_role'] === true && array_column(byKey($ed, 'email', 'red-a@orga.test')['courses'], 'id') === [100, 101], 'editors: roll och kurslista');
check(!byKey($ed, 'email', 'anv-a@orga.test'), 'editors: vanliga användare finns inte med');

$pubs = $all['pub-agreements']['json'];
$pa = null; $pb = null;
foreach ($pubs['data'] as $p) { if ($p['organization']['id'] === '2') $pa = $p; if ($p['organization']['id'] === '3') $pb = $p; }
check($pubs['active_document']['version'] === '5', 'pub-agreements: aktivt dokument version 5');
check($pa['status'] === 'gällande' && $pa['current']['agreement_id'] === 'uuid-a-ny' && $pa['current_version_signed'] === true
    && array_column($pa['agreements'], 'status') === ['gällande', 'ersatt'], 'org A: gällande v5, äldre ersatt', json_encode(array_column($pa['agreements'], 'status')));
check($pa['current']['signer'] === ['name' => 'Admin A', 'title' => 'Kommundirektör', 'email' => 'admin-a@orga.test'] && $pa['current']['checksum_sha256'] === 'h5',
    'org A: undertecknare (namn, titel, e-post) och kontrollsumma');
check($pb['status'] === 'saknas' && $pb['agreements'][0]['status'] === 'ogiltigförklarat' && $pb['pub_flag_in_stimma'] === true,
    'org B: bara ogiltigförklarat avtal → saknas (flaggan i Stimma är fortfarande satt)');
$r = api('/api/v1/insyn/pub-agreements?status=saknas', $key);
$ids = array_column(array_column($r['json']['data'], 'organization'), 'id'); sort($ids);
check($ids === ['1', '3'], 'pub-agreements?status=saknas: Sambruk och org B', json_encode(array_column(array_column($r['json']['data'], 'organization'), 'id')));

$cs = $all['courses']['json']['data'];
$c100 = byKey($cs, 'id', 100);
check($c100['baskurs'] === true && $c100['tags'] === ['Baskurs', 'Säkerhet'] && $c100['lessons'] === 3 && $c100['ai_tutor'] === true && $c100['internal'] === true,
    'courses: kurs 100 baskurs, taggar, 3 lektioner, AI-tutor, intern', json_encode($c100));
check(byKey($cs, 'id', 202)['copied_from'] === ['organization' => ['id' => '2', 'name' => 'Org A'], 'domain' => 'orga.test', 'course_id' => 100],
    'courses: kurs 202 kopierad från org A, kurs 100', json_encode(byKey($cs, 'id', 202)['copied_from']));
check($c100['copied_from'] === null, 'courses: originalkurs med ursprung = egen domän är ingen kopia');
check(byKey($cs, 'id', 101)['internal'] === false && byKey($cs, 'id', 101)['public'] === true, 'courses: publik kurs är inte intern');
check(array_column($all['courses?organization_id=orgb.test&status=active']['json']['data'], 'id') === [200, 202], 'courses?organization_id=orgb.test&status=active: 200 och 202');
check(array_column(api('/api/v1/insyn/courses?baskurs=1', $key)['json']['data'], 'id') === [100], 'courses?baskurs=1: bara kurs 100');

$u = $all['usage']['json'];
check($u['last_30_days']['course_import'] === ['events' => 1, 'organizations' => 1] && $u['last_90_days']['course_import'] === ['events' => 2, 'organizations' => 2],
    'usage: kursimport 30/90 dagar', json_encode([$u['last_30_days']['course_import'], $u['last_90_days']['course_import']]));
check($u['last_30_days']['ai_tutor']['events'] === 1 && $u['last_30_days']['ai_generation']['events'] === 1 && $u['last_90_days']['ai_generation']['organizations'] === 2,
    'usage: AI-tutor och AI-generering');
check($u['last_30_days']['user_sync'] === ['events' => 1, 'organizations' => 1] && $u['last_30_days']['audio']['events'] === 1 && $u['last_30_days']['course_copy']['events'] === 1,
    'usage: användarsynk, ljudklipp, kopiering');
check(($u['current']['question_types_total']['single_choice'] ?? 0) > 0 && $u['current']['active_learning_paths'] === ['count' => 1, 'organizations' => 1], 'usage: frågetyper och lärvägar');

$lp = $all['learning-paths']['json']['data'];
check(count($lp) === 1 && array_column($lp[0]['courses'], 'id') === [100, 101] && $lp[0]['organization']['id'] === '2', 'learning-paths: lärväg A med kurs 100, 101 i ordning');

// ------------------------------------------------------------------ sidindelning och fel
section('Sidindelning och felhantering');
$r = api('/api/v1/insyn/admins?per_page=1&page=2', $key)['json'];
check($r['page'] === 2 && $r['per_page'] === 1 && $r['total'] === 3 && $r['total_pages'] === 3 && count($r['data']) === 1, 'per_page=1&page=2');
check(api('/api/v1/insyn/admins?per_page=5000', $key)['json']['per_page'] === 200, 'per_page kapas till 200');
check(api('/api/v1/insyn/admins?page=0', $key)['status'] === 400, 'page=0: 400');
check(api('/api/v1/insyn/admins?organisation_id=2', $key)['status'] === 400, 'felstavad parameter: 400 (inte tyst alla)');
check(api('/api/v1/insyn/finnsinte', $key)['status'] === 404, 'okänd endpoint: 404');
check(api('/api/v1/insyn/courses/999/stats', $key)['status'] === 404, 'okänd kurs: 404');

// ------------------------------------------------------------------ logg, rate limit, IP
section('Loggning, rate limit och IP-begränsning');
$kid = (int)$stored['id'];
$last = dbOne("SELECT * FROM system_api_log WHERE key_id = ? ORDER BY id DESC LIMIT 1", [$kid]);
check($last && $last['method'] === 'GET' && $last['endpoint'] === '/api/v1/insyn/courses/999/stats' && (int)$last['status'] === 404, 'varje anrop loggas med tid, endpoint, nyckel-id och status');
check((int)dbOne("SELECT COUNT(*) n FROM system_api_log WHERE key_id IS NULL AND status = 401")['n'] >= 3, 'misslyckade autentiseringar loggas utan nyckel-id');
check(dbOne("SELECT last_used_at FROM system_api_keys WHERE id = ?", [$kid])['last_used_at'] !== null, 'last_used_at uppdateras');

dbExec("INSERT INTO system_api_log (key_id, method, endpoint, status, created_at) SELECT ?, 'GET', '/fyll', 200, NOW() FROM seq_1_to_60", [$kid]);
$r = api('/api/v1/insyn/health', $key);
check($r['status'] === 429 && stripos($r['head'], 'Retry-After') !== false, 'över 60 anrop per minut: 429', (string)$r['status']);
dbExec("DELETE FROM system_api_log WHERE endpoint = '/fyll'");
check(api('/api/v1/insyn/health', $key)['status'] === 200, 'efter minuten: 200 igen');

$super->post('/admin/system_api_keys.php', ['action' => 'create', 'name' => 'IP-låst', 'allowed_ips' => '203.0.113.0/24, 2001:db8::1']);
$super->get('/admin/system_api_keys.php');
preg_match('/value="(sts_[0-9a-f]{64})"/', $super->body, $m);
$ipKey = $m[1] ?? '';
check(api('/api/v1/insyn/health', $ipKey)['status'] === 403, 'IP-låst nyckel från fel adress: 403');
check(api('/api/v1/insyn/health', $ipKey, 'GET', ['X-Real-IP: 203.0.113.77'])['status'] === 200, 'IP-låst nyckel från tillåtet nät (via proxyns X-Real-IP): 200');
check(api('/api/v1/insyn/health', $ipKey, 'GET', ['X-Real-IP: 203.0.114.1'])['status'] === 403, 'grannätet 203.0.114.0: 403');

for ($i = 0; $i < 20; $i++) api('/api/v1/insyn/health', 'sts_' . str_repeat('f', 64), 'GET', ['X-Real-IP: 198.51.100.7']);
check(api('/api/v1/insyn/health', $key, 'GET', ['X-Real-IP: 198.51.100.7'])['status'] === 429, 'efter 20 misslyckade försök spärras adressen, även med giltig nyckel');
check(api('/api/v1/insyn/health', $key)['status'] === 200, 'andra adresser påverkas inte');

// ------------------------------------------------------------------ återkallelse
section('Återkallelse');
$super->post('/admin/system_api_keys.php', ['action' => 'revoke', 'key_id' => $kid]);
$k = dbOne("SELECT is_active, revoked_at, revoked_by FROM system_api_keys WHERE id = ?", [$kid]);
check((int)$k['is_active'] === 0 && $k['revoked_at'] !== null && (int)$k['revoked_by'] === 1, 'återkallad med tid och av vem');
check(api('/api/v1/insyn/health', $key)['status'] === 401, 'återkallad nyckel: 401 direkt');
$admin->post('/admin/system_api_keys.php', ['action' => 'revoke', 'key_id' => dbOne("SELECT id FROM system_api_keys WHERE name = 'IP-låst'")['id']]);
check(dbOne("SELECT revoked_at FROM system_api_keys WHERE name = 'IP-låst'")['revoked_at'] === null, 'org-admin kan inte återkalla');
$super->get('/admin/system_api_keys.php');
check(strpos($super->body, 'Återkallad') !== false && strpos($super->body, '/api/v1/insyn/health') !== false, 'adminvyn visar status och anropsloggen');

// ------------------------------------------------------------------ före migreringen
section('Före migreringen');
db()->exec("DROP TABLE system_api_log, system_api_keys");
$r = api('/api/v1/insyn/health', $key);
check($r['status'] === 503 && strpos($r['body'], 'inte aktiverat') !== false, 'utan tabellerna: 503 inte aktiverat', $r['status'] . ' ' . snippet($r['body']));
check(api('/api/v1/insyn/health', $key, 'POST')['status'] === 405, 'utan tabellerna: POST ger fortfarande 405');
$super->get('/admin/system_api_keys.php');
check(strpos($super->body, 'Tabellerna för systemnycklar saknas') !== false, 'adminvyn visar att migreringen saknas');
db()->exec(file_get_contents(__DIR__ . '/../migrations/051_system_api_keys.sql'));

done();
