<?php
/**
 * Litet testramverk för Stimma (2026-10-13). Körs med PHP CLI i test-containern
 * (tests/run.sh) och gör riktiga HTTP-anrop mot testmiljöns webbserver.
 *
 * Inloggning: en remember_token läggs direkt i testdatabasen och skickas som kaka,
 * samma väg som "Kom ihåg mig". Kakor hanteras för hand eftersom testservern går
 * över http men sätter secure-kakor.
 */

const BASE = 'http://localhost';
const HOST = 'stimma.test';

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=172.17.0.1;port=23306;dbname=stimma_test;charset=utf8mb4',
            'root', 'stimma_test_root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}
function dbOne(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetch(); }
function dbAll(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function dbExec(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->rowCount(); }

/** Återställ databasen till seed.sql (schema + testdata). */
function reseed(): void {
    $tables = array_column(dbAll("SHOW TABLES"), 'Tables_in_stimma_test');
    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) db()->exec("TRUNCATE TABLE `$t`");
    db()->exec('SET FOREIGN_KEY_CHECKS=1');
    db()->exec(file_get_contents(__DIR__ . '/seed.sql'));
    while (db()->query('SELECT 1')->fetch()) { /* töm flera resultat */ break; }
}

class Client {
    public array $cookies = [];
    public string $email;
    public ?string $csrf = null;
    public int $status = 0;
    public string $body = '';
    public array $headers = [];

    public function __construct(string $email) {
        $this->email = $email;
        $u = dbOne("SELECT id FROM users WHERE email = ?", [$email]);
        if (!$u) throw new RuntimeException("Okänd testanvändare $email");
        $tok = bin2hex(random_bytes(32));
        dbExec("DELETE FROM remember_tokens WHERE user_id = ?", [$u['id']]);
        dbExec("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 DAY)",
            [$u['id'], hash('sha256', $tok)]);
        $this->cookies['remember_token'] = $u['id'] . ':' . $tok;
        $this->get('/index.php');
        if (empty($this->cookies['PHPSESSID'])) throw new RuntimeException("Ingen session för $email");
    }

    public function request(string $method, string $path, $data = null, array $extraHeaders = []): self {
        $ch = curl_init(BASE . $path);
        $h = ['Host: ' . HOST, 'X-Forwarded-Proto: https'];
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        foreach ($extraHeaders as $x) $h[] = $x;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
        if ($data !== null) {
            if (is_array($data)) {
                $data = http_build_query($data);
            } elseif (!in_array('Content-Type: application/json', $extraHeaders, true)) {
                $h[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $raw = curl_exec($ch);
        $this->status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr((string)$raw, 0, $hs);
        $this->body = substr((string)$raw, $hs);
        $this->headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                [$kv] = explode(';', trim(substr($line, 11)), 2);
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                if ($v === '' || $v === 'deleted') unset($this->cookies[$k]); else $this->cookies[$k] = $v;
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $this->headers[strtolower(trim($k))] = trim($v);
            }
        }
        if (preg_match('/(?:name="csrf_token"\s+value="|CSRF_TOKEN\s*=\s*"|data-csrf="|name="csrf-token"\s+content=")([a-f0-9]{64})/', $this->body, $m)) {
            $this->csrf = $m[1];
        }
        return $this;
    }
    public function get(string $path): self { return $this->request('GET', $path); }
    public function post(string $path, array $data = [], array $headers = []): self {
        if ($this->csrf === null) $this->fetchCsrf();
        $data += ['csrf_token' => $this->csrf];
        return $this->request('POST', $path, $data, array_merge(['X-CSRF-Token: ' . $this->csrf], $headers));
    }
    public function postJson(string $path, array $data): self {
        if ($this->csrf === null) $this->fetchCsrf();
        $data += ['csrf_token' => $this->csrf];
        return $this->request('POST', $path, json_encode($data), ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf, 'X-Requested-With: XMLHttpRequest']);
    }
    /** Hämta sessionens CSRF-token från en sida som skriver ut den. */
    public function fetchCsrf(): void {
        foreach (['/admin/index.php', '/admin/courses.php', '/index.php', '/lesson.php?id=1001'] as $p) {
            $this->get($p);
            if ($this->csrf) return;
        }
        // Sista utväg: läs sessionsfilen
        $sid = $this->cookies['PHPSESSID'] ?? '';
        $f = "/tmp/sess_$sid";
        if (is_readable($f) && preg_match('/csrf_token\|s:64:"([a-f0-9]{64})"/', file_get_contents($f), $m)) $this->csrf = $m[1];
    }
    public function redirectedTo(): string { return $this->headers['location'] ?? ''; }
    /** Nekad = omdirigering bort, 403/404, eller tydligt felmeddelande, utan att svaret innehåller kursdata. */
    public function json() { return json_decode($this->body, true); }
}

// ---------------------------------------------------------------- påståenden

$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'fails' => []];
function check(bool $ok, string $what, string $detail = ''): void {
    if ($ok) { $GLOBALS['T']['pass']++; echo "  ok    $what\n"; }
    else { $GLOBALS['T']['fail']++; $GLOBALS['T']['fails'][] = $what; echo "  FEL   $what" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s\n"; }
function done(): void {
    $t = $GLOBALS['T'];
    echo "\n{$t['pass']} ok, {$t['fail']} fel\n";
    exit($t['fail'] > 0 ? 1 : 0);
}
function snippet(string $s, int $n = 160): string { return mb_substr(preg_replace('/\s+/', ' ', strip_tags($s)), 0, $n); }

/** Text ur ett xlsx-svar (alla delar i paketet), så att exporter kan sökas i. */
function xlsxText(string $body): string {
    if (substr($body, 0, 2) !== 'PK') return $body;
    $f = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($f, $body);
    $z = new ZipArchive();
    $out = '';
    if ($z->open($f) === true) {
        for ($i = 0; $i < $z->numFiles; $i++) $out .= $z->getFromIndex($i) . "\n";
        $z->close();
    }
    unlink($f);
    return html_entity_decode($out, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ---------------------------------------------------------------- utgående post

/** Starta SMTP-mottagaren (tests/smtp_sink.php) om den inte redan körs, och töm postlådan. */
function mailSinkStart(): void {
    if (!@fsockopen('127.0.0.1', 2525, $e, $s, 0.2)) {
        exec('php ' . escapeshellarg(__DIR__ . '/smtp_sink.php') . ' > /dev/null 2>&1 &');
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 2525, $e, $s, 0.1); $i++) usleep(100000);
    }
    foreach (glob('/tmp/stimma-mail/*.eml') ?: [] as $f) unlink($f);
}

/** Textinnehållet (avkodat) i alla mejl till adressen, äldst först. */
function mailsTo(string $email): array {
    $ut = [];
    foreach (glob('/tmp/stimma-mail/*.eml') ?: [] as $f) {
        $raw = file_get_contents($f);
        if (!preg_match('/^X-Sink-To: (.*)$/m', $raw, $m) || !in_array(strtolower($email), explode(',', trim($m[1])), true)) continue;
        $ut[] = quoted_printable_decode($raw);
    }
    return $ut;
}

/** Senaste inloggningskoden i mejl till adressen (sex siffror, visas "123 456"). */
function lastLoginCode(string $email): ?string {
    $mejl = mailsTo($email);
    if (!$mejl) return null;
    return preg_match('/\b(\d{3}) (\d{3})\b/', end($mejl), $m) ? $m[1] . $m[2] : null;
}
