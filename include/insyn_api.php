<?php
/**
 * Stimma – insyns-API:t (läs-API för Sambruks förvaltning), 2026-10-14.
 *
 * GET /api/v1/insyn/... med Authorization: Bearer sts_...  Se docs/API-INSYN.md.
 *
 * Regler:
 * - Bara systemnycklar (tabell system_api_keys, prefix sts_). Organisationernas
 *   synknycklar (api_keys, prefix stm_) godtas inte här, och systemnycklar godtas
 *   inte av synk-API:t (authenticateApiKey() läser bara api_keys och kräver stm_).
 * - Bara GET. Inget anrop skriver något utom loggraden och last_used_at.
 * - Uppgiftsminimering: namn och e-post bara för administratörer, redaktörer och
 *   undertecknare av PUB-avtal. Inga uppgifter om enskilda deltagares framsteg.
 *   Antal personer under INSYN_MIN_GROUP redovisas som "<5"; medelvärden över
 *   färre personer redovisas inte alls.
 * - Databasfel ska synas: här används PDO direkt med undantag, inte query(), som
 *   sväljer SQL-fel och returnerar en tom lista (se påminnelsefelet, ärende 782).
 *
 * Organisationer: i Stimma är en organisation antingen en rad i organizations
 * (med flera domäner i organization_domains) eller en ensam domän som inte är
 * grupperad (implicit organisation, se getOrgScopeDomains()). API:t redovisar
 * båda. Id är organisationens nummer ("2") eller domänen ("ale.se").
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ai_quota.php';

const INSYN_API_VERSION = '1.0';
const INSYN_MIN_GROUP = 5;
const INSYN_RATE_PER_MINUTE = 60;
const INSYN_RATE_PER_HOUR = 1000;
const INSYN_FAILED_AUTH_PER_10MIN = 20;
const INSYN_MAX_PER_PAGE = 200;
const INSYN_DEFAULT_PER_PAGE = 50;

$GLOBALS['INSYN'] = ['key_id' => null, 'start' => microtime(true), 'logged' => false];

// ----------------------------------------------------------------- databas

function insynDb(): PDO {
    return getDb();
}

function insynAll(string $sql, array $params = []): array {
    $st = insynDb()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function insynOne(string $sql, array $params = []): ?array {
    $st = insynDb()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Platshållare för en IN-lista. Tom lista ger ett villkor som aldrig är sant. */
function insynIn(array $values): string {
    return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
}

// ----------------------------------------------------------------- svar och logg

/** Klientens IP. Bakom nginx är REMOTE_ADDR proxyns adress; då gäller X-Real-IP. */
function insynClientIp(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $real = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
    $fromProxy = $remote === '127.0.0.1' || $remote === '::1'
        || (filter_var($remote, FILTER_VALIDATE_IP) && !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE));
    if ($fromProxy && $real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
        return $real;
    }
    return $remote !== '' ? $remote : 'unknown';
}

function insynLog(int $status): void {
    if ($GLOBALS['INSYN']['logged']) return;
    $GLOBALS['INSYN']['logged'] = true;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $endpoint = mb_substr($path . ($qs !== '' ? '?' . $qs : ''), 0, 255);
    try {
        insynDb()->prepare(
            "INSERT INTO " . DB_DATABASE . ".system_api_log (key_id, method, endpoint, status, ip_address, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $GLOBALS['INSYN']['key_id'],
            substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 10),
            $endpoint,
            $status,
            insynClientIp(),
            (int)round((microtime(true) - $GLOBALS['INSYN']['start']) * 1000),
        ]);
    } catch (Throwable $e) {
        error_log('insyn: kunde inte logga anrop: ' . $e->getMessage());
    }
}

/** Skicka JSON och avsluta. Varje svar loggas, även fel. */
function insynSend(int $status, array $body, array $headers = []): void {
    insynLog($status);
    // config.php startar en session; ett API-anrop ska varken skapa eller skicka någon.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    header_remove('Set-Cookie');
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function insynError(int $status, string $message, array $headers = []): void {
    insynSend($status, ['error' => $message], $headers);
}

// ----------------------------------------------------------------- autentisering

/** Ligger $ip i någon av de kommaseparerade adresserna/näten i $allowed? */
function insynIpAllowed(string $ip, ?string $allowed): bool {
    $allowed = trim((string)$allowed);
    if ($allowed === '') return true;
    foreach (preg_split('/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        if (strpos($entry, '/') === false) {
            if (inet_pton($entry) !== false && inet_pton($entry) === @inet_pton($ip)) return true;
            continue;
        }
        [$net, $bits] = explode('/', $entry, 2);
        $netBin = @inet_pton($net);
        $ipBin = @inet_pton($ip);
        if ($netBin === false || $ipBin === false || strlen($netBin) !== strlen($ipBin) || !ctype_digit($bits)) continue;
        $bits = (int)$bits;
        if ($bits > strlen($netBin) * 8) continue;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($netBin, 0, $bytes) !== substr($ipBin, 0, $bytes)) continue;
        if ($rest === 0) return true;
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        if ((($netBin[$bytes] ^ $ipBin[$bytes]) & $mask) === "\0") return true;
    }
    return false;
}

/** Är en sträng med IP-adresser/nät giltig? Används av adminsidan. */
function insynValidIpList(string $list): bool {
    foreach (preg_split('/[\s,]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        [$addr, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $bin = @inet_pton($addr);
        if ($bin === false) return false;
        if ($bits !== null && (!ctype_digit($bits) || (int)$bits > strlen($bin) * 8)) return false;
    }
    return true;
}

/** Skapa en ny systemnyckel: [råa nyckeln, hash, prefix]. */
function insynGenerateKey(): array {
    $raw = 'sts_' . bin2hex(random_bytes(32));
    return [$raw, hash('sha256', $raw), substr($raw, 0, 12)];
}

/**
 * Autentisera anropet. Avslutar med 401/403/429 vid fel, annars nyckelraden.
 */
function insynAuthenticate(): array {
    $ip = insynClientIp();

    // Många misslyckade försök från samma adress: stoppa innan nyckeln ens prövas.
    $fails = insynOne(
        "SELECT COUNT(*) AS n FROM " . DB_DATABASE . ".system_api_log
          WHERE key_id IS NULL AND status = 401 AND ip_address = ? AND created_at > NOW() - INTERVAL 10 MINUTE",
        [$ip]
    );
    if ((int)($fails['n'] ?? 0) >= INSYN_FAILED_AUTH_PER_10MIN) {
        insynError(429, 'För många misslyckade försök. Vänta 10 minuter.', ['Retry-After: 600']);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(sts_[0-9a-f]{64})\s*$/', $header, $m)) {
        insynError(401, 'Saknad eller ogiltig nyckel. Använd Authorization: Bearer sts_... (en systemnyckel).',
            ['WWW-Authenticate: Bearer realm="stimma-insyn"']);
    }

    $key = insynOne(
        "SELECT * FROM " . DB_DATABASE . ".system_api_keys WHERE api_key_hash = ? AND scope = 'insyn'",
        [hash('sha256', $m[1])]
    );
    if (!$key || (int)$key['is_active'] !== 1 || $key['revoked_at'] !== null) {
        insynError(401, 'Okänd eller återkallad nyckel.', ['WWW-Authenticate: Bearer realm="stimma-insyn"']);
    }

    $GLOBALS['INSYN']['key_id'] = (int)$key['id'];

    if (!insynIpAllowed($ip, $key['allowed_ips'])) {
        insynError(403, 'Nyckeln får inte användas från den här adressen.');
    }

    $rate = insynOne(
        "SELECT SUM(created_at > NOW() - INTERVAL 1 MINUTE) AS minute, COUNT(*) AS hour
           FROM " . DB_DATABASE . ".system_api_log
          WHERE key_id = ? AND created_at > NOW() - INTERVAL 1 HOUR",
        [(int)$key['id']]
    );
    if ((int)($rate['minute'] ?? 0) >= INSYN_RATE_PER_MINUTE) {
        insynError(429, 'För många anrop. Högst ' . INSYN_RATE_PER_MINUTE . ' per minut.', ['Retry-After: 60']);
    }
    if ((int)($rate['hour'] ?? 0) >= INSYN_RATE_PER_HOUR) {
        insynError(429, 'För många anrop. Högst ' . INSYN_RATE_PER_HOUR . ' per timme.', ['Retry-After: 600']);
    }

    insynDb()->prepare(
        "UPDATE " . DB_DATABASE . ".system_api_keys SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?"
    )->execute([$ip, (int)$key['id']]);

    return $key;
}

// ----------------------------------------------------------------- hjälp

/** Antal personer: 0 och 5 eller fler som tal, 1–4 som "<5". */
function insynCount($n) {
    $n = (int)$n;
    return ($n > 0 && $n < INSYN_MIN_GROUP) ? '<' . INSYN_MIN_GROUP : $n;
}

/** Datum (ÅÅÅÅ-MM-DD) ur en tidsstämpel, eller null. */
function insynDate($ts): ?string {
    return $ts ? substr((string)$ts, 0, 10) : null;
}

/** Tidsstämpel i ISO 8601 med tidszon, eller null. */
function insynTime($ts): ?string {
    if (!$ts) return null;
    $t = strtotime((string)$ts);
    return $t ? date('c', $t) : null;
}

/** ?page= och ?per_page= (högst 200). */
function insynPaging(): array {
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $per = filter_var($_GET['per_page'] ?? INSYN_DEFAULT_PER_PAGE, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false) insynError(400, 'page måste vara ett heltal från 1.');
    if ($per === false) insynError(400, 'per_page måste vara ett heltal från 1 till ' . INSYN_MAX_PER_PAGE . '.');
    $per = min($per, INSYN_MAX_PER_PAGE);
    return ['page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per];
}

/** Sidindela en färdig lista och skicka den. $map körs bara på sidans poster. */
function insynSendPage(array $items, array $paging, ?callable $map = null, array $extra = []): void {
    $total = count($items);
    $slice = array_slice($items, $paging['offset'], $paging['per_page']);
    if ($map) $slice = array_map($map, $slice);
    insynSend(200, $extra + [
        'page' => $paging['page'],
        'per_page' => $paging['per_page'],
        'total' => $total,
        'total_pages' => (int)ceil($total / $paging['per_page']),
        'data' => array_values($slice),
    ]);
}

/** Tillåt bara kända frågeparametrar, så att felstavningar inte tyst ger allt. */
function insynAllowParams(array $allowed): void {
    $unknown = array_diff(array_keys($_GET), array_merge($allowed, ['page', 'per_page']));
    if ($unknown) {
        insynError(400, 'Okänd parameter: ' . implode(', ', $unknown) . '. Tillåtna: ' . implode(', ', array_merge($allowed, ['page', 'per_page'])) . '.');
    }
}

// ----------------------------------------------------------------- organisationer

/**
 * Alla organisationer i Stimma: grupperade (organizations) och ensamma
 * medlemsdomäner. Nyckel = organisationens id i API:t.
 *
 * @return array<string, array{id:string,type:string,organization_id:?int,name:string,domains:array,primary_domain:string,org_number:?string,created_at:?string}>
 */
function insynUnits(): array {
    static $units = null;
    if ($units !== null) return $units;
    $units = [];
    $db = DB_DATABASE;

    $orgs = insynAll("SELECT id, name, org_number, created_at FROM $db.organizations ORDER BY name");
    $orgDomains = [];
    foreach (insynAll("SELECT organization_id, LOWER(domain) AS domain FROM $db.organization_domains ORDER BY is_primary DESC, domain") as $r) {
        $orgDomains[(int)$r['organization_id']][] = $r['domain'];
    }
    $grouped = [];
    foreach ($orgs as $o) {
        $doms = $orgDomains[(int)$o['id']] ?? [];
        foreach ($doms as $d) $grouped[$d] = true;
        $units[(string)$o['id']] = [
            'id' => (string)$o['id'],
            'type' => 'organization',
            'organization_id' => (int)$o['id'],
            'name' => $o['name'],
            'domains' => $doms,
            'primary_domain' => $doms[0] ?? null,
            'org_number' => $o['org_number'],
            'created_at' => $o['created_at'],
        ];
    }

    // Ensamma domäner: medlemsdomäner (vitlistan) och domäner som tecknat PUB-avtal.
    $names = [];
    foreach (insynAll("SELECT LOWER(domain) AS domain, org_name, org_number FROM $db.pub_agreement_artifacts ORDER BY signed_at") as $r) {
        $names[$r['domain']] = ['name' => $r['org_name'], 'org_number' => $r['org_number']];
    }
    $first = [];
    foreach (insynAll("SELECT LOWER(domain) AS domain, created_at FROM $db.domain_settings") as $r) {
        $first[$r['domain']] = $r['created_at'];
    }
    $single = array_unique(array_merge(getMemberDomains(), array_keys($names)));
    sort($single);
    foreach ($single as $d) {
        if ($d === '' || isset($grouped[$d])) continue;
        $units[$d] = [
            'id' => $d,
            'type' => 'domain',
            'organization_id' => null,
            'name' => $names[$d]['name'] ?? $d,
            'domains' => [$d],
            'primary_domain' => $d,
            'org_number' => $names[$d]['org_number'] ?? null,
            'created_at' => $first[$d] ?? null,
        ];
    }
    return $units;
}

/** Domän → organisationens id i API:t, eller null (t.ex. gmail.com). */
function insynUnitIdForDomain(?string $domain): ?string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (insynUnits() as $id => $u) {
            foreach ($u['domains'] as $d) $map[$d] = $id;
        }
    }
    $domain = strtolower(trim((string)$domain));
    return $map[$domain] ?? null;
}

function insynUnitIdForEmail(?string $email): ?string {
    return insynUnitIdForDomain(substr(strrchr((string)$email, '@') ?: '', 1));
}

/**
 * Senaste inloggning per e-postadress (gemener => 'ÅÅÅÅ-MM-DD hh:mm:ss').
 *
 * users.last_login_at skrevs inte av inloggningen före 2026-10-14 (recordUserLogin()
 * i include/auth.php), så inloggningarna läses också ur aktivitetsloggen: inloggning
 * med e-postlänk och automatisk inloggning med kom-ihåg-mig. Det senaste vinner.
 */
function insynLastLogins(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $db = DB_DATABASE;
    foreach (insynAll(
        "SELECT LOWER(email) AS email, MAX(created_at) AS last FROM $db.logs
          WHERE message LIKE 'Lyckad inloggning med token%' OR message LIKE 'Automatisk inloggning via kom-ihåg-mig%'
          GROUP BY LOWER(email)"
    ) as $r) {
        $map[$r['email']] = $r['last'];
    }
    foreach (insynAll("SELECT LOWER(email) AS email, last_login_at FROM $db.users WHERE last_login_at IS NOT NULL") as $r) {
        if (!isset($map[$r['email']]) || $r['last_login_at'] > $map[$r['email']]) $map[$r['email']] = $r['last_login_at'];
    }
    return $map;
}

function insynLastLogin(?string $email): ?string {
    return insynLastLogins()[strtolower((string)$email)] ?? null;
}

/** Kort referens till en organisation i svaren. */
function insynUnitRef(?string $unitId): ?array {
    if ($unitId === null) return null;
    $u = insynUnits()[$unitId] ?? null;
    return $u ? ['id' => $u['id'], 'name' => $u['name']] : null;
}

/** Läs ?organization_id= (id eller någon av organisationens domäner). 404 om den inte finns. */
function insynRequestedUnit(): ?array {
    $raw = trim((string)($_GET['organization_id'] ?? ''));
    if ($raw === '') return null;
    $units = insynUnits();
    if (isset($units[$raw])) return $units[$raw];
    $id = insynUnitIdForDomain($raw);
    if ($id !== null) return $units[$id];
    insynError(404, "Organisationen '$raw' finns inte. Ange id eller domän från /organizations.");
    return null;
}
