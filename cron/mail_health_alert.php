<?php
/**
 * Stimma — Larm när utgående e-post slutar fungera
 *
 * Bakgrund (2026-10-23): Scaleway-nyckeln gick ut 2026-10-22 och i 25 timmar
 * gick inga inloggningskoder ut utan att någon märkte det. Det här skriptet läser
 * Stimmas egen e-postlogg (tabellen logs) och larmar när de senaste utskicken
 * misslyckats i rad.
 *
 * Larm:   minst LARM_MIN_FEL misslyckade utskick i följd, till minst två olika
 *         mottagare (en enskild felstavad adress ska inte larma).
 * Påminnelse: var 6:e timme så länge felet består.
 * Åter OK: ett meddelande när det första utskicket lyckas efter ett larm.
 *
 * Larmet skickas i första hand via LARM_MAIL_* (Postal), eftersom felet oftast
 * sitter i den ordinarie vägen (MAIL_*, Scaleway). Går inte det används MAIL_*.
 * Larmmejlen märks med "[Stimma-larm]" i ämnet och räknas inte i kontrollen.
 *
 * Schemaläggning via Ofelia (label i docker-compose.yml), var 10:e minut.
 *
 * Flaggor: --dry-run  visa beslutet utan att skicka eller spara tillstånd
 *          --test     skicka ett testlarm och avsluta
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

define('CLI_MODE', true);
chdir(dirname(__DIR__));

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/mail.php';

const LARM_MIN_FEL = 5;
const LARM_PAMINNELSE_TIMMAR = 6;
const LARM_MARKERING = '[Stimma-larm]';
$tillstandsFil = ROOT_PATH . '/logs/mail_larm_state.json';

$dryRun = in_array('--dry-run', $argv, true);
$test = in_array('--test', $argv, true);
$mottagare = getenv('MAIL_ALERT_EMAIL') ?: '';

if ($mottagare === '') {
    echo "MAIL_ALERT_EMAIL saknas i .env, inget larm kan skickas.\n";
    exit(0);
}

/**
 * Skickar ett larm, först via LARM_MAIL_* och sedan via den ordinarie vägen.
 * sendSmtpMail() läser MAIL_* med getenv() vid varje anrop, så vägen byts med
 * putenv() och återställs efteråt.
 */
function skickaLarm(string $till, string $amne, string $text): bool {
    $nycklar = ['HOST', 'PORT', 'USERNAME', 'PASSWORD', 'ENCRYPTION'];
    $html = '<pre style="font-family:monospace;white-space:pre-wrap">' . htmlspecialchars($text) . '</pre>';
    $amne = LARM_MARKERING . ' ' . $amne;

    if (getenv('LARM_MAIL_HOST')) {
        $original = [];
        foreach ($nycklar as $n) {
            $original[$n] = getenv("MAIL_$n");
            // Tom LARM_MAIL_ENCRYPTION ska ge tomt, inte falla tillbaka på MAIL_ENCRYPTION
            putenv("MAIL_$n=" . (getenv("LARM_MAIL_$n") ?: ''));
        }
        $ok = sendSmtpMail($till, $amne, $html, null, null, $text);
        foreach ($nycklar as $n) {
            putenv($original[$n] === false ? "MAIL_$n" : "MAIL_$n=" . $original[$n]);
        }
        if ($ok) {
            return true;
        }
        echo "Larmvägen (LARM_MAIL_HOST) misslyckades, provar ordinarie väg.\n";
    }
    return sendSmtpMail($till, $amne, $html, null, null, $text);
}

function lasTillstand(string $fil): array {
    $data = is_file($fil) ? json_decode((string)file_get_contents($fil), true) : null;
    return is_array($data) ? $data : ['larm' => false, 'skickat' => null];
}

if ($test) {
    $ok = skickaLarm($mottagare, 'Test av e-postlarmet',
        "Det här är ett test av Stimmas e-postlarm. Ingen åtgärd behövs.\n\n"
        . "Larmet skickas hit när minst " . LARM_MIN_FEL . " utskick i rad från Stimma har misslyckats.\n"
        . "Skickat " . date('Y-m-d H:i:s T') . ".\n");
    echo $ok ? "Testlarm skickat till $mottagare\n" : "Testlarmet kunde inte skickas\n";
    exit($ok ? 0 : 1);
}

// Senaste utfallen (lyckat/misslyckat), nyast först, utan larmmejlen själva
$rader = queryAll(
    "SELECT created_at, message FROM " . DB_DATABASE . ".logs
     WHERE created_at > NOW() - INTERVAL 2 DAY
       AND (message LIKE 'E-post skickat framgångsrikt%' OR message LIKE 'E-post misslyckades%')
       AND message NOT LIKE ?
     ORDER BY id DESC LIMIT 200",
    ['%' . LARM_MARKERING . '%']
) ?: [];

$felIFoljd = [];
foreach ($rader as $r) {
    if (strpos($r['message'], 'E-post skickat framgångsrikt') === 0) {
        break;
    }
    $felIFoljd[] = $r;
}
$senasteLyckade = null;
foreach ($rader as $r) {
    if (strpos($r['message'], 'E-post skickat framgångsrikt') === 0) {
        $senasteLyckade = $r['created_at'];
        break;
    }
}

$adresser = [];
foreach ($felIFoljd as $r) {
    if (preg_match('/"to_email":"([^"]+)"/', $r['message'], $m)) {
        $adresser[strtolower($m[1])] = true;
    }
}
$felar = count($felIFoljd) >= LARM_MIN_FEL && count($adresser) >= 2;

$tillstand = lasTillstand($tillstandsFil);
$nu = time();
$atgard = 'ingen';

if ($felar) {
    $sedanSenast = $tillstand['skickat'] ? $nu - strtotime($tillstand['skickat']) : PHP_INT_MAX;
    if (!$tillstand['larm'] || $sedanSenast >= LARM_PAMINNELSE_TIMMAR * 3600) {
        $atgard = $tillstand['larm'] ? 'påminnelse' : 'larm';
    }
} elseif ($tillstand['larm'] && $rader && strpos($rader[0]['message'], 'E-post skickat framgångsrikt') === 0) {
    $atgard = 'åter OK';
}

echo date('Y-m-d H:i:s') . " fel i följd: " . count($felIFoljd) . ", mottagare: " . count($adresser)
    . ", larm aktivt: " . ($tillstand['larm'] ? 'ja' : 'nej') . ", åtgärd: $atgard\n";

if ($atgard === 'ingen' || $dryRun) {
    exit(0);
}

$systemUrl = rtrim(getenv('SYSTEM_URL') ?: 'https://stimma.sambruk.se', '/');

if ($atgard === 'åter OK') {
    $ok = skickaLarm($mottagare, 'E-posten fungerar igen',
        "Stimma skickar e-post igen. Första lyckade utskicket: {$rader[0]['created_at']}.\n"
        . "Larmet skickades första gången {$tillstand['forst']}.\n\n$systemUrl\n");
    if ($ok) {
        @file_put_contents($tillstandsFil, json_encode(['larm' => false, 'skickat' => null]));
    }
    exit($ok ? 0 : 1);
}

// Senaste felorsaken utan JSON-kontexten
$orsak = preg_replace('/\s*\|\s*Kontext:.*$/s', '', $felIFoljd[0]['message']);
$orsak = str_replace('\n', '', $orsak);
$forstaFel = end($felIFoljd)['created_at'];
$tips = '';
if (stripos($orsak, 'Autentisering') !== false) {
    $tips = "\nTrolig orsak: SMTP-inloggningen nekas. Om svaret säger \"token is expired\" har\n"
        . "Scaleway-nyckeln gått ut: skapa en ny i Scaleway (IAM > Applications > API keys)\n"
        . "och byt MAIL_PASSWORD i Stimmas .env.\n";
} elseif (stripos($orsak, 'ansluta') !== false) {
    $tips = "\nTrolig orsak: SMTP-servern går inte att nå (" . getenv('MAIL_HOST') . ").\n";
}

$text = "Stimma kan inte skicka e-post. Användare får inga inloggningskoder och kan inte logga in.\n\n"
    . "Misslyckade utskick i följd: " . count($felIFoljd) . (count($felIFoljd) >= 200 ? '+' : '')
    . " (till " . count($adresser) . " olika adresser)\n"
    . "Första felet i följden: $forstaFel\n"
    . "Senaste lyckade utskick: " . ($senasteLyckade ?: 'inget de senaste två dygnen') . "\n"
    . "Senaste fel: $orsak\n"
    . $tips
    . "\nNästa påminnelse om " . LARM_PAMINNELSE_TIMMAR . " timmar om felet består.\n"
    . "Server: app.sambruk.se, /opt/app/stimma (cron/mail_health_alert.php)\n";

$amne = ($atgard === 'påminnelse' ? 'Påminnelse: ' : '') . 'Stimma kan inte skicka e-post – inloggningen fungerar inte';
if (skickaLarm($mottagare, $amne, $text)) {
    @file_put_contents($tillstandsFil, json_encode([
        'larm' => true,
        'skickat' => date('c', $nu),
        'forst' => $tillstand['forst'] ?? date('Y-m-d H:i:s', $nu),
    ]));
    echo "Larm ($atgard) skickat till $mottagare\n";
} else {
    echo "Larmet kunde inte skickas via någon väg\n";
    exit(1);
}
