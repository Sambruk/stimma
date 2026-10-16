<?php
/**
 * Inloggning med engångskod (migration 053, 2026-10-16) och users.last_login_at.
 *
 * Visar att:
 *  - mejlet har en sexsiffrig kod och ingen länk; koden sparas bara som hash
 *  - rätt kod loggar in med samma session och kom-ihåg-mig som förut, och skriver
 *    last_login_at utan att röra updated_at
 *  - koden fungerar en gång, bara i webbläsaren som begärde den, och spärras efter
 *    5 felaktiga försök; en ny kod gör den förra ogiltig
 *  - gamla inloggningslänkar loggar inte in
 *  - omdirigering efter inloggning och publik kursregistrering fungerar som förut
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/053_login_codes.sql'));
dbExec("DELETE FROM login_codes");
mailSinkStart();

/** Webbläsare som inte är inloggad. */
class Anon extends Client {
    public function __construct() { $this->email = ''; $this->get('/index.php'); }
    public function begarKod(string $email, bool $kom = true): self {
        return $this->post('/index.php', ['email' => $email, 'remember_me' => $kom ? '1' : '0']);
    }
    public function angeKod(string $kod): self {
        $this->get('/index.php'); // färsk CSRF-token
        return $this->post('/index.php', ['login_code' => $kod]);
    }
    public function inloggad(): bool {
        $this->get('/index.php');
        return strpos($this->body, 'Mina kurser') !== false;
    }
}

section('Kod begärs');
dbExec("UPDATE users SET last_login_at = NULL, updated_at = NOW() - INTERVAL 2 HOUR WHERE email = 'anv-a@orga.test'");
$fore = dbOne("SELECT updated_at FROM users WHERE email = 'anv-a@orga.test'")['updated_at'];
$w = new Anon();
check(strpos($w->body, 'Skicka kod') !== false, 'inloggningssidan har knappen Skicka kod');
$w->begarKod('anv-a@orga.test');
check(strpos($w->body, 'Skriv in koden vi skickade till') !== false && strpos($w->body, 'one-time-code') !== false, 'kodrutan visas', snippet($w->body, 300));
$mejl = mailsTo('anv-a@orga.test');
$kod = lastLoginCode('anv-a@orga.test');
check(count($mejl) === 1 && $kod !== null, 'ett mejl med sexsiffrig kod', (string)count($mejl));
check(stripos(end($mejl), 'href=') === false && strpos(end($mejl), 'verify.php') === false && strpos(end($mejl), 'token=') === false, 'mejlet har ingen länk');
$rad = dbOne("SELECT * FROM login_codes WHERE email = 'anv-a@orga.test'");
check($rad && strpos(json_encode($rad), $kod) === false && strlen($rad['code_hash']) === 64, 'koden sparas bara som hash');

section('Fel kod');
$w->angeKod($kod === '000000' ? '111111' : '000000');
check(strpos($w->body, 'Koden stämmer inte') !== false, 'fel kod nekas med tydligt besked');
check((int)dbOne("SELECT attempts FROM login_codes WHERE email = 'anv-a@orga.test'")['attempts'] === 1, 'försöket räknas');
check(!$w->inloggad(), 'inte inloggad');

section('Rätt kod loggar in – session och kom-ihåg-mig som förut');
$w->angeKod(substr($kod, 0, 3) . ' ' . substr($kod, 3)); // mellanslag som i mejlet går bra
check($w->status === 302 && preg_match('#/index\.php$#', $w->redirectedTo()), 'omdirigeras till startsidan', $w->status . ' ' . $w->redirectedTo() . ' ' . snippet($w->body));
check(!empty($w->cookies['remember_token']), 'kom-ihåg-mig-kaka satt');
check($w->inloggad(), 'inloggad');
$u = dbOne("SELECT last_login_at, updated_at FROM users WHERE email = 'anv-a@orga.test'");
check($u['last_login_at'] !== null, 'last_login_at skrivs');
check($u['updated_at'] === $fore, 'updated_at oförändrad', "$fore → {$u['updated_at']}");
check((bool)dbOne("SELECT 1 FROM logs WHERE email = 'anv-a@orga.test' AND message LIKE 'Lyckad inloggning med kod%'"), 'inloggningen loggas');
check(dbOne("SELECT used_at FROM login_codes WHERE email = 'anv-a@orga.test'")['used_at'] !== null, 'koden markerad som använd');

section('Koden fungerar en gång och bara i rätt webbläsare');
$w2 = new Anon();
$w2->begarKod('anv-b@orgb.test', false);
$kodB = lastLoginCode('anv-b@orgb.test');
$angripare = new Anon();
$angripare->begarKod('anv-b@orgb.test');
$angripare->angeKod($kodB);
check(!$angripare->inloggad(), 'kod från en annan webbläsares begäran fungerar inte');
$w2->angeKod($kodB);
$svar = $w2->body;
check(!$w2->inloggad() && strpos($svar, 'gäller inte längre') !== false, 'en ny kod gör den förra ogiltig', preg_match('#alert-danger[^>]*>(.{0,300})#s', $svar, $m) ? snippet($m[1]) : $w2->status . ' ' . $w2->redirectedTo());
$w2->begarKod('anv-b@orgb.test', false);
$kodB = lastLoginCode('anv-b@orgb.test');
$w2->angeKod($kodB);
check($w2->inloggad() && empty($w2->cookies['remember_token']), 'utan kom-ihåg-mig: inloggad, ingen kaka');
$w2->get('/logout.php');
$w2->angeKod($kodB);
check(!$w2->inloggad(), 'förbrukad kod fungerar inte igen efter utloggning');

// Angriparen begär först, offret sedan: angriparens session har fortfarande
// adressen, men bara offrets webbläsare kan använda offrets kod.
$angripare2 = new Anon();
$angripare2->begarKod('admin-b@orgb.test');
$offer = new Anon();
$offer->begarKod('admin-b@orgb.test');
$angripare2->angeKod(lastLoginCode('admin-b@orgb.test'));
check(!$angripare2->inloggad(), 'offrets kod fungerar inte i angriparens webbläsare');
$offer->angeKod(lastLoginCode('admin-b@orgb.test'));
check($offer->inloggad(), 'offret loggar in med sin kod');

section('Engångs även vid samtidiga försök (direkt mot verifyLoginCode)');
// Webbflödet glömmer adressen efter inloggningen, så återanvändning stoppas redan
// där. Här provas spärren i själva funktionen: samma kod två gånger i samma session.
$ut = shell_exec('cd /var/www/html/public && php -r ' . escapeshellarg('
    $_SERVER["HTTP_HOST"] = "stimma.test"; $_SESSION = [];
    require "include/config.php"; require "include/database.php"; require "include/functions.php"; require "include/auth.php";
    $r = sendLoginCode("red-b@orgb.test");
    $f = glob("/tmp/stimma-mail/*.eml"); sort($f);
    preg_match("/\\b(\\d{3}) (\\d{3})\\b/", quoted_printable_decode(file_get_contents(end($f))), $m);
    $a = verifyLoginCode("red-b@orgb.test", $m[1] . $m[2]);
    $b = verifyLoginCode("red-b@orgb.test", $m[1] . $m[2]);
    echo json_encode([$r["ok"], !empty($a["user"]), $b["error"]]);
') . ' 2>&1');
check(trim((string)$ut) === '[true,true,"expired"]', 'andra försöket med samma kod nekas', (string)$ut);

section('Spärr efter 5 felaktiga försök');
$w3 = new Anon();
$w3->begarKod('red-a@orga.test');
$kodR = lastLoginCode('red-a@orga.test');
$fel = $kodR === '123456' ? '654321' : '123456';
for ($i = 0; $i < 5; $i++) $w3->angeKod($fel);
$w3->angeKod($kodR);
check(!$w3->inloggad(), 'rätt kod efter 5 fel nekas');

section('Gränser');
$w4 = new Anon();
$w4->begarKod('ny@inte-ansluten.test');
check(strpos($w4->body, 'godkända domäner') !== false && !mailsTo('ny@inte-ansluten.test'), 'ny adress på domän som inte är ansluten: inget konto, ingen kod');
dbExec("DELETE FROM login_codes WHERE email = 'las-a@orga.test'");
dbExec("INSERT INTO login_codes (email, request_id, code_hash, binding_hash, expires_at, created_at)
        SELECT 'las-a@orga.test', SHA2(CONCAT('r', n), 256), REPEAT('0', 64), REPEAT('0', 64), NOW(), NOW()
        FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) t");
$w5 = new Anon();
$w5->begarKod('las-a@orga.test');
check(strpos($w5->body, 'många koder') !== false && !mailsTo('las-a@orga.test'), 'högst 5 koder per timme', snippet($w5->body, 200));
dbExec("UPDATE login_codes SET created_at = NOW() - INTERVAL 2 HOUR, attempts = 4 WHERE email = 'las-a@orga.test'");
$w5->begarKod('las-a@orga.test');
check(strpos($w5->body, 'felaktiga koder') !== false && !mailsTo('las-a@orga.test'), 'spärr efter 20 felaktiga försök per dygn', snippet($w5->body, 200));

section('Gammal inloggningslänk loggar inte in');
$tok = bin2hex(random_bytes(32));
dbExec("UPDATE users SET verification_token = ?, updated_at = NOW() WHERE email = 'admin-a@orga.test'", [$tok]);
$w6 = new Anon();
$w6->get('/verify.php?email=' . urlencode('admin-a@orga.test') . '&token=' . $tok);
check($w6->status === 200 && strpos($w6->body, 'Inloggningen har ändrats') !== false, 'förklarande sida');
check(!$w6->inloggad() && empty($w6->cookies['remember_token']), 'inte inloggad');

section('Omdirigering efter inloggning');
$w7 = new Anon();
$w7->get('/course.php?id=100');
$w7->begarKod('admin-a@orga.test');
$w7->angeKod(lastLoginCode('admin-a@orga.test'));
check(preg_match('#/course\.php\?id=100$#', $w7->redirectedTo()) === 1, 'tillbaka till kursen', $w7->redirectedTo());

section('Publik kursregistrering');
$w8 = new Anon();
$pubTok = str_repeat('a2', 32);
dbExec("UPDATE courses SET public_registration_token = ? WHERE id = 101", [$pubTok]);
$w8->get('/public_register.php?course_id=101&token=' . $pubTok);
$w8->post('/public_register.php?course_id=101&token=' . $pubTok, ['email' => 'extern@valfri.test', 'name' => 'Extern Deltagare']);
check($w8->status === 302 && preg_match('#/index\.php$#', $w8->redirectedTo()), 'skickas till kodrutan', $w8->status . ' ' . $w8->redirectedTo() . ' ' . snippet($w8->body));
$w8->angeKod(lastLoginCode('extern@valfri.test'));
$ny = dbOne("SELECT id, access_mode FROM users WHERE email = 'extern@valfri.test'");
check($ny && $ny['access_mode'] === 'public_only' && dbOne("SELECT 1 FROM public_course_access WHERE user_id = ? AND course_id = 101", [$ny['id']]), 'deltagaren får tillgång till kursen');
check(!dbOne("SELECT 1 FROM public_registration_intents WHERE email = 'extern@valfri.test'"), 'registreringen förbrukad');

done();
