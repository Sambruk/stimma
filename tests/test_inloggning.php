<?php
/**
 * users.last_login_at skrivs vid inloggning (2026-10-14), utan att inloggningslänkens
 * giltighet förlängs (den räknas från users.updated_at).
 */
require __DIR__ . '/lib.php';
reseed();

section('Kom-ihåg-mig skriver last_login_at');
dbExec("UPDATE users SET last_login_at = NULL, updated_at = NOW() - INTERVAL 2 HOUR WHERE email = 'anv-a@orga.test'");
$before = dbOne("SELECT updated_at FROM users WHERE email = 'anv-a@orga.test'")['updated_at'];
new Client('anv-a@orga.test'); // loggar in via remember_token
$u = dbOne("SELECT last_login_at, updated_at, TIMESTAMPDIFF(SECOND, last_login_at, NOW()) AS age FROM users WHERE email = 'anv-a@orga.test'");
check($u['last_login_at'] !== null && (int)$u['age'] < 60, 'last_login_at satt vid automatisk inloggning', json_encode($u));
check($u['updated_at'] === $before, 'updated_at oförändrad', "$before → {$u['updated_at']}");

section('Inloggningslänk skriver last_login_at, gammal länk förblir ogiltig');
$tok = bin2hex(random_bytes(32));
dbExec("UPDATE users SET last_login_at = NULL, verification_token = ?, updated_at = NOW() - INTERVAL 1 MINUTE WHERE email = 'anv-b@orgb.test'", [$tok]);
$before = dbOne("SELECT updated_at FROM users WHERE email = 'anv-b@orgb.test'")['updated_at'];
$c = new class('anv-b@orgb.test') extends Client { public function __construct(string $e) { $this->email = $e; } };
$c->get('/verify.php?email=' . urlencode('anv-b@orgb.test') . '&token=' . $tok);
$u = dbOne("SELECT last_login_at, updated_at FROM users WHERE email = 'anv-b@orgb.test'");
check($u['last_login_at'] !== null, 'last_login_at satt vid inloggning med länk', json_encode($u) . ' ' . $c->status . ' ' . $c->redirectedTo());
check($u['updated_at'] === $before, 'updated_at oförändrad', "$before → {$u['updated_at']}");

// En länk som gått ut ska inte bli giltig för att användaren loggar in på annat sätt.
$old = bin2hex(random_bytes(32));
dbExec("UPDATE users SET verification_token = ?, updated_at = NOW() - INTERVAL 1 DAY WHERE email = 'anv-a@orga.test'", [$old]);
new Client('anv-a@orga.test');
$c2 = new class('anv-a@orga.test') extends Client { public function __construct(string $e) { $this->email = $e; } };
$c2->get('/verify.php?email=' . urlencode('anv-a@orga.test') . '&token=' . $old);
check(empty($c2->cookies['remember_token']) && !dbOne("SELECT 1 FROM logs WHERE email = 'anv-a@orga.test' AND message LIKE 'Lyckad inloggning med token%'"),
    'utgången länk ger ingen inloggning efter en automatisk inloggning');

done();
