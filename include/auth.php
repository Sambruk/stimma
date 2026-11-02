<?php
require_once 'config.php';
require_once 'database.php';
require_once 'functions.php';
require_once 'mail.php';

// =============================================================================
// Underhållsläge-gate
//
// Körs vid varje sidladdning som inkluderar auth.php. Om underhållsläget är
// aktivt visas maintenance.php (HTTP 503) för alla utom superadmin. Login,
// verify, logout och själva maintenance-sidorna är undantagna så superadmin
// kan logga in och stänga av läget igen.
// =============================================================================
if (PHP_SAPI !== 'cli' && isMaintenanceModeActive()) {
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $bypassScripts = ['login.php', 'verify.php', 'logout.php', 'maintenance.php', 'sms.php'];
    $isBypassPath = in_array($scriptName, $bypassScripts, true);

    // admin/maintenance.php och alla AJAX-endpoints under admin/ajax/ ska också gå
    // igenom så att superadmin kan toggla läget. Slutbedömningen om superadmin
    // görs nedan.
    $requestPath = $_SERVER['REQUEST_URI'] ?? '';
    $isAdminMaintenance = strpos($requestPath, '/admin/maintenance.php') !== false;

    if (!$isBypassPath && !$isAdminMaintenance) {
        // Slå upp om inloggad användare är superadmin
        $isSuperAdminBypass = false;
        if (isset($_SESSION['user_id'])) {
            $maintUser = queryOne(
                "SELECT role FROM " . DB_DATABASE . ".users WHERE id = ?",
                [$_SESSION['user_id']]
            );
            $isSuperAdminBypass = $maintUser && ($maintUser['role'] ?? '') === 'super_admin';
        }

        if (!$isSuperAdminBypass) {
            // Bestäm rätt sökväg till maintenance.php beroende på var requesten kommer ifrån
            $maintenancePath = __DIR__ . '/../maintenance.php';
            if (file_exists($maintenancePath)) {
                require $maintenancePath;
            } else {
                http_response_code(503);
                echo 'Underhåll pågår. Försök igen senare.';
            }
            exit;
        }
    }
}

/**
 * Kontrollera om användaren är inloggad
 * 
 * @return bool True om användaren är inloggad, false annars
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Inloggningskodens giltighetstid som läsbar text, t.ex. "3 timmar" eller "15 minuter".
 * Används i inloggningsmejlet, på inloggningssidan och i användarhandboken så att de följer AUTH_TOKEN_EXPIRY_MINUTES.
 */
function loginLinkValidityText(): string {
    $minuter = (int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15;
    if ($minuter % 60 === 0) {
        $timmar = intdiv($minuter, 60);
        return $timmar === 1 ? '1 timme' : "{$timmar} timmar";
    }
    return "{$minuter} minuter";
}

// =============================================================================
// Inloggning med engångskod (migration 053, 2026-10-16)
//
// Ersätter inloggningslänken. Länken gick att använda flera gånger under hela
// giltighetstiden, så säkerhetsfilter som öppnar länkar i mejl blev inloggade.
// Koden är sex siffror, gäller en gång, bara i webbläsaren som begärde den och
// spärras efter LOGIN_CODE_MAX_ATTEMPTS felaktiga försök. Allt efter godkänd
// kod (session, kom-ihåg-mig, last_login_at) är detsamma som förut.
// =============================================================================

const LOGIN_CODE_MAX_ATTEMPTS = 5;      // felaktiga försök per kod
const LOGIN_CODE_MAX_PER_HOUR = 5;      // nya koder per adress och timme
const LOGIN_CODE_MAX_FAILS_PER_DAY = 20; // felaktiga försök per adress och dygn
const LOGIN_CODE_DUPLICATE_SECONDS = 30; // samma webbläsare får ingen ny kod inom denna tid (dubbelklick)

/** Slumpvärdet som binder en kod till den här webbläsarens session. */
function loginCodeBinding(): string {
    if (empty($_SESSION['login_code_binding'])) {
        $_SESSION['login_code_binding'] = bin2hex(random_bytes(32));
    }
    return hash('sha256', $_SESSION['login_code_binding']);
}

/** Har adressen gjort för många felaktiga försök det senaste dygnet? */
function loginCodeLockedOut(string $email): bool {
    $row = queryOne(
        "SELECT COALESCE(SUM(attempts), 0) AS n FROM " . DB_DATABASE . ".login_codes
         WHERE email = ? AND created_at > NOW() - INTERVAL 1 DAY",
        [$email]
    );
    return (int)($row['n'] ?? 0) >= LOGIN_CODE_MAX_FAILS_PER_DAY;
}

/**
 * Skicka en inloggningskod till adressen.
 *
 * Tidigare koder för adressen upphör. Koden sparas bara som hash. Sätter
 * $_SESSION['login_code_email'] så att inloggningssidan visar kodrutan.
 *
 * @return array ['ok' => bool, 'request_id' => ?string, 'error' => ?string, 'duplicate' => ?bool]
 *               error: 'rate' (för många koder), 'locked' (för många fel), 'mail'
 *               duplicate: samma webbläsare fick en kod nyss, ingen ny skickades
 */
function sendLoginCode(string $email): array {
    $email = strtolower(trim($email));
    $expiryMinutes = (int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15;

    if (loginCodeLockedOut($email)) {
        logActivity($email, "Inloggningskod nekad: för många felaktiga försök");
        return ['ok' => false, 'request_id' => null, 'error' => 'locked'];
    }

    // Dubbel postning (dubbelklick, klick + Enter): har samma webbläsare nyss fått
    // en kod skickas ingen ny, annars gör den andra koden den första ogiltig och
    // mejlen kan komma i omvänd ordning (2026-11-02, två koder på en sekund).
    $nyss = queryOne(
        "SELECT request_id FROM " . DB_DATABASE . ".login_codes
         WHERE email = ? AND binding_hash = ? AND used_at IS NULL AND expires_at > NOW()
           AND created_at > NOW() - INTERVAL " . LOGIN_CODE_DUPLICATE_SECONDS . " SECOND
         ORDER BY id DESC LIMIT 1",
        [$email, loginCodeBinding()]
    );
    if ($nyss) {
        $_SESSION['login_code_email'] = $email;
        logActivity($email, "Inloggningskod inte skickad igen: dubbel begäran", ['action' => 'login_code_duplicate']);
        return ['ok' => true, 'request_id' => $nyss['request_id'], 'error' => null, 'duplicate' => true];
    }

    $senaste = queryOne(
        "SELECT COUNT(*) AS n FROM " . DB_DATABASE . ".login_codes WHERE email = ? AND created_at > NOW() - INTERVAL 1 HOUR",
        [$email]
    );
    if ((int)($senaste['n'] ?? 0) >= LOGIN_CODE_MAX_PER_HOUR) {
        logActivity($email, "Inloggningskod nekad: för många koder senaste timmen");
        return ['ok' => false, 'request_id' => null, 'error' => 'rate'];
    }

    // Städa bort gamla rader och låt tidigare koder för adressen upphöra
    execute("DELETE FROM " . DB_DATABASE . ".login_codes WHERE created_at < NOW() - INTERVAL 2 DAY");
    execute(
        "UPDATE " . DB_DATABASE . ".login_codes SET expires_at = NOW() WHERE email = ? AND used_at IS NULL AND expires_at > NOW()",
        [$email]
    );

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $requestId = bin2hex(random_bytes(32));
    if (!execute(
        "INSERT INTO " . DB_DATABASE . ".login_codes (email, request_id, code_hash, binding_hash, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)",
        [$email, $requestId, hash('sha256', $requestId . $code), loginCodeBinding(), $expiryMinutes]
    )) {
        logActivity($email, "Inloggningskod kunde inte sparas");
        return ['ok' => false, 'request_id' => null, 'error' => 'mail'];
    }

    $systemUrl = rtrim(getenv('SYSTEM_URL') ?: ('https://' . $_SERVER['HTTP_HOST']), '/');
    $systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
    $systemHost = parse_url($systemUrl, PHP_URL_HOST) ?: $systemUrl;
    $giltighetstid = loginLinkValidityText();

    // Mejlet har ingen länk alls (2026-10-16): koden skrivs in på inloggningssidan.
    // Adressen till Stimma står som text, så att den som stängt fliken hittar tillbaka.
    $subject = mb_encode_mimeheader("Din kod till " . $systemName, 'UTF-8', 'Q');
    $logoUrl = $systemUrl . '/images/mail-logo.png';
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8');
    $safeHost = htmlspecialchars($systemHost, ENT_QUOTES, 'UTF-8');
    $textStyle = "font-family: Arial, sans-serif; font-size: 15px; color: #1a1a1a; line-height: 1.6;";
    $kodVisning = substr($code, 0, 3) . ' ' . substr($code, 3);

    $htmlMessage = "
    <!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
    <html xmlns=\"http://www.w3.org/1999/xhtml\" lang=\"sv\">
    <head>
        <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />
        <title>Din kod till {$safeName}</title>
    </head>
    <body style=\"margin: 0; padding: 0; background-color: #ffffff;\">
        <table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin: 0; padding: 0;\">
            <tr>
                <td style=\"padding: 24px 20px;\">
                    <table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"max-width: 560px;\">
                        <tr>
                            <td style=\"padding-bottom: 16px;\">
                                <img src=\"{$logoUrl}\" alt=\"{$safeName}\" width=\"80\" height=\"83\" style=\"display: block; border: 0;\" />
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <h1 style=\"font-family: Arial, sans-serif; font-size: 22px; color: #1a1a1a; margin: 0 0 16px 0;\">Hej!</h1>
                                <p style=\"{$textStyle} margin: 0 0 16px 0;\">Du har bett om att få logga in i {$safeName}, Sambruks lärplattform för korta utbildningar, med adressen {$safeEmail}.</p>
                                <p style=\"{$textStyle} margin: 0 0 12px 0;\">Skriv in den här koden på inloggningssidan:</p>
                                <p style=\"font-family: 'Courier New', monospace; font-size: 32px; font-weight: bold; letter-spacing: 4px; color: #1a1a1a; background-color: #f2f5ef; border-radius: 6px; padding: 12px 20px; margin: 0 0 20px 0; display: inline-block;\">{$kodVisning}</p>
                                <p style=\"{$textStyle} margin: 0 0 16px 0;\">Koden gäller i {$giltighetstid} och bara i webbläsaren där du bad om den. Har du stängt sidan kan du gå till {$safeHost} och be om en ny kod.</p>
                                <p style=\"{$textStyle} margin: 0 0 24px 0;\">Har du inte bett om att logga in kan du strunta i det här mejlet. Ge aldrig koden till någon annan.</p>
                                <p style=\"font-family: Arial, sans-serif; font-size: 13px; color: #555555; line-height: 1.5; margin: 0; border-top: 1px solid #dddddd; padding-top: 12px;\">{$safeName} är en tjänst från Sambruk, en medlemsförening för samverkan kring digitalisering i offentlig sektor.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";

    $textMessage = "Hej!\n\n"
        . "Du har bett om att få logga in i {$systemName}, Sambruks lärplattform för korta utbildningar, med adressen {$email}.\n\n"
        . "Skriv in den här koden på inloggningssidan:\n\n"
        . "    {$kodVisning}\n\n"
        . "Koden gäller i {$giltighetstid} och bara i webbläsaren där du bad om den. Har du stängt sidan kan du gå till {$systemHost} och be om en ny kod.\n\n"
        . "Har du inte bett om att logga in kan du strunta i det här mejlet. Ge aldrig koden till någon annan.\n\n"
        . "{$systemName} är en tjänst från Sambruk.\n";

    $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@sambruk.se';
    $mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: $systemName;
    $mailSent = sendSmtpMail($email, $subject, $htmlMessage, $mailFrom, $mailFromName, $textMessage);

    if (!$mailSent) {
        execute("DELETE FROM " . DB_DATABASE . ".login_codes WHERE request_id = ?", [$requestId]);
        logActivity($email, "Inloggningskod misslyckades att skickas", ['action' => 'login_code_failed']);
        return ['ok' => false, 'request_id' => null, 'error' => 'mail'];
    }

    $_SESSION['login_code_email'] = $email;
    logActivity($email, "Inloggningskod skickad", ['action' => 'login_code_sent', 'expiry_minutes' => $expiryMinutes]);
    return ['ok' => true, 'request_id' => $requestId, 'error' => null];
}

/**
 * Kontrollera en inloggningskod. Godkänd kod förbrukas och inloggningen
 * registreras; sessionen skapas av completeLogin().
 *
 * @return array ['user' => ?array, 'request_id' => ?string, 'error' => ?string]
 *               error: 'invalid' (fel kod), 'expired' (ingen giltig kod i den här
 *               webbläsaren), 'locked' (för många fel)
 */
function verifyLoginCode(string $email, string $code): array {
    $email = strtolower(trim($email));
    $code = preg_replace('/\D/', '', $code);
    $nej = fn(string $e) => ['user' => null, 'request_id' => null, 'error' => $e];

    if (loginCodeLockedOut($email)) {
        logActivity($email, "Misslyckad inloggning: för många felaktiga koder");
        return $nej('locked');
    }

    $row = queryOne(
        "SELECT id, request_id, code_hash, attempts FROM " . DB_DATABASE . ".login_codes
         WHERE email = ? AND binding_hash = ? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1",
        [$email, loginCodeBinding()]
    );
    if (!$row || (int)$row['attempts'] >= LOGIN_CODE_MAX_ATTEMPTS) {
        logActivity($email, "Misslyckad inloggning: ingen giltig kod");
        return $nej('expired');
    }

    if (strlen($code) !== 6 || !hash_equals($row['code_hash'], hash('sha256', $row['request_id'] . $code))) {
        execute("UPDATE " . DB_DATABASE . ".login_codes SET attempts = attempts + 1 WHERE id = ?", [$row['id']]);
        logActivity($email, "Misslyckad inloggning: fel kod");
        return $nej((int)$row['attempts'] + 1 >= LOGIN_CODE_MAX_ATTEMPTS ? 'expired' : 'invalid');
    }

    // Engångs: förbruka koden innan något annat görs. Villkoret på used_at gör att
    // två samtidiga försök med samma kod inte båda godtas.
    $db = getDb();
    $stmt = $db->prepare("UPDATE " . DB_DATABASE . ".login_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $stmt->execute([$row['id']]);
    if ($stmt->rowCount() !== 1) return $nej('expired');

    $user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$email]);
    if (!$user) return $nej('expired');

    // updated_at lämnas orörd (se recordUserLogin)
    if ($user['verified_at'] === null) {
        execute("UPDATE " . DB_DATABASE . ".users SET verified_at = NOW(), updated_at = updated_at WHERE id = ?", [$user['id']]);
    }

    // Auto-promotion: om en 'public_only'-användare loggar in från en domän som
    // nu är grupperad i en organisation, flippa dem till 'domain' så de får
    // normal domänbaserad åtkomst utöver sina publika kurser. Envägs — aldrig
    // demotering.
    if (($user['access_mode'] ?? 'domain') === 'public_only') {
        $domain = getUserDomain($email);
        if (!empty($domain)) {
            $org = getOrganizationByDomain($domain);
            if ($org) {
                execute(
                    "UPDATE " . DB_DATABASE . ".users SET access_mode = 'domain', updated_at = updated_at WHERE id = ?",
                    [$user['id']]
                );
                $user['access_mode'] = 'domain';
                logActivity($email, "access_mode uppgraderad: public_only → domain (org: " . $org['name'] . ")");
            }
        }
    }

    recordUserLogin($user['id']);
    logActivity($email, "Lyckad inloggning med kod");
    return ['user' => $user, 'request_id' => $row['request_id'], 'error' => null];
}

/**
 * Avsluta en godkänd inloggning: session, kom-ihåg-mig, publik kursregistrering
 * och vart användaren ska skickas. Samma steg som verify.php gjorde för länken.
 *
 * @return string URL att omdirigera till
 */
function completeLogin(array $user, string $requestId): string {
    createLoginSession($user);

    if (!empty($_SESSION['pending_remember_me'])) {
        createRememberToken($user['id']); // REMEMBER_TOKEN_HOURS, standard 720 h = 30 dagar
    }
    unset($_SESSION['pending_remember_me'], $_SESSION['login_code_email']);

    // Publik kursregistrering: raden i public_registration_intents är kopplad
    // till kodbegärans request_id (kolumnen heter verification_token).
    $publicRedirectLesson = null;
    $pendingIntent = queryOne(
        "SELECT * FROM " . DB_DATABASE . ".public_registration_intents
         WHERE verification_token = ? AND expires_at > NOW() LIMIT 1",
        [$requestId]
    );
    if ($pendingIntent) {
        grantPublicCourseAccess($user['id'], $pendingIntent['course_id']);
        $course = queryOne("SELECT sequential_mode FROM " . DB_DATABASE . ".courses WHERE id = ?", [$pendingIntent['course_id']]);
        if ($course && !empty($course['sequential_mode'])) {
            $firstLesson = queryOne(
                "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1",
                [$pendingIntent['course_id']]
            );
            if ($firstLesson) $publicRedirectLesson = (int)$firstLesson['id'];
        }
        execute("DELETE FROM " . DB_DATABASE . ".public_registration_intents WHERE verification_token = ?", [$requestId]);
    }

    if ($publicRedirectLesson) return 'lesson.php?id=' . $publicRedirectLesson;
    if (!empty($_SESSION['redirect_after_login'])) {
        // Same-origin-check: endast relativa URL:er eller samma host
        $candidate = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
        $parsed = parse_url($candidate);
        if (empty($parsed['host']) || $parsed['host'] === ($_SERVER['HTTP_HOST'] ?? '')) {
            return $candidate;
        }
    }
    return 'index.php';
}

/**
 * Registrera en lyckad inloggning i users.last_login_at (2026-10-14).
 *
 * updated_at lämnas orörd. Fram till 2026-10-16 räknades inloggningslänkens
 * giltighet från users.updated_at (ON UPDATE CURRENT_TIMESTAMP); inloggningskoden
 * har egen giltighetstid i login_codes, men updated_at ska ändå spegla verkliga
 * ändringar av kontot.
 *
 * @param int $userId
 */
function recordUserLogin($userId) {
    execute(
        "UPDATE " . DB_DATABASE . ".users SET last_login_at = NOW(), updated_at = updated_at WHERE id = ?",
        [(int)$userId]
    );
}

/**
 * Skapa en inloggningssession för användaren
 *
 * SECURITY FIX: Added session regeneration to prevent session fixation attacks
 *
 * @param array $user Användaruppgifter från databasen
 * @return void
 */
function createLoginSession($user) {
    // SECURITY FIX: Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['login_time'] = time();

    // Check if user is admin or editor, and set admin_logged_in flag
    if (isset($user['is_admin']) && $user['is_admin'] == 1 || isset($user['is_editor']) && $user['is_editor'] == 1) {
        $_SESSION['admin_logged_in'] = true;
    }
}

/**
 * Logga ut användaren
 */
function logout() {
    // Spara e-post för loggning innan sessionen tas bort
    $email = $_SESSION['user_email'] ?? 'okänd användare';
    $userId = $_SESSION['user_id'] ?? null;

    // Ta bort remember token från databasen om det finns
    if ($userId) {
        clearRememberToken($userId);
    }

    // Ta bort remember cookie
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }

    // Rensa sessionsvariabler och förstör sessionen
    unset($_SESSION['user_id']);
    unset($_SESSION['user_email']);
    session_destroy();

    redirect('index.php');
}

/**
 * Skapa en "kom ihåg mig" token för användaren
 *
 * @param int $userId Användarens ID
 * @param int|null $hours Antal timmar token ska vara giltig (standard från REMEMBER_TOKEN_HOURS eller 720 = 30 dagar)
 * @return bool True om det lyckades
 */
function createRememberToken($userId, $hours = null) {
    // Använd miljövariabel eller standardvärde (720 timmar = 30 dagar)
    if ($hours === null) {
        $hours = (int)(getenv('REMEMBER_TOKEN_HOURS') ?: 720);
    }

    // Generera en säker token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    // Beräkna utgångstid
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

    // Ta bort gamla tokens för denna användare
    execute("DELETE FROM " . DB_DATABASE . ".remember_tokens WHERE user_id = ?", [$userId]);

    // Spara ny token i databasen
    $result = execute(
        "INSERT INTO " . DB_DATABASE . ".remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)",
        [$userId, $tokenHash, $expiresAt]
    );

    if ($result) {
        // Sätt cookie med token
        $cookieExpiry = time() + ($hours * 3600);
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        setcookie(
            'remember_token',
            $userId . ':' . $token,
            [
                'expires' => $cookieExpiry,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );

        return true;
    }

    return false;
}

/**
 * Validera och använd "kom ihåg mig" token för automatisk inloggning
 *
 * @return array|false Användaruppgifter om token är giltig, false annars
 */
function validateRememberToken() {
    if (!isset($_COOKIE['remember_token'])) {
        return false;
    }

    $cookieValue = $_COOKIE['remember_token'];
    $parts = explode(':', $cookieValue, 2);

    if (count($parts) !== 2) {
        clearRememberCookie();
        return false;
    }

    list($userId, $token) = $parts;
    $userId = (int)$userId;
    $tokenHash = hash('sha256', $token);

    // Hämta token från databasen
    $storedToken = queryOne(
        "SELECT rt.*, u.email, u.name, u.is_admin, u.is_editor, u.role
         FROM " . DB_DATABASE . ".remember_tokens rt
         JOIN " . DB_DATABASE . ".users u ON rt.user_id = u.id
         WHERE rt.user_id = ? AND rt.token_hash = ? AND rt.expires_at > NOW()",
        [$userId, $tokenHash]
    );

    if (!$storedToken) {
        clearRememberCookie();
        return false;
    }

    // Token är giltig - hämta användaren
    $user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);

    if ($user) {
        // Förnya token vid varje automatisk inloggning: fönstret är glidande, så
        // 30 dagar räknas från senaste besöket och inte från inloggningen. Den som
        // använder Stimma regelbundet loggas därmed aldrig ut. Medvetet valt
        // 2026-08-25 — vill man ha en bortre gräns krävs ett tak på token-ålder.
        createRememberToken($userId);

        recordUserLogin($userId);
        logActivity($user['email'], "Automatisk inloggning via kom-ihåg-mig cookie");

        return $user;
    }

    return false;
}

/**
 * Rensa remember token från databasen
 *
 * @param int $userId Användarens ID
 */
function clearRememberToken($userId) {
    execute("DELETE FROM " . DB_DATABASE . ".remember_tokens WHERE user_id = ?", [$userId]);
}

/**
 * Rensa remember cookie
 */
function clearRememberCookie() {
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
}

/**
 * Rensa utgångna remember tokens (kan köras via cron)
 */
function cleanupExpiredRememberTokens() {
    execute("DELETE FROM " . DB_DATABASE . ".remember_tokens WHERE expires_at < NOW()");
}

/**
 * Kontrollerar om en användare är admin
 * @param string $email Användarens e-postadress
 * @return bool True om användaren är admin, annars false
 */
function isAdmin($email) {
    $user = queryOne("SELECT is_admin FROM " . DB_DATABASE . ".users WHERE email = ?", [$email]);
    return $user && $user['is_admin'] == 1;
}
