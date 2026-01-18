<?php
require_once 'config.php';
require_once 'database.php';
require_once 'functions.php';
require_once 'mail.php';

/**
 * Kontrollera om användaren är inloggad
 * 
 * @return bool True om användaren är inloggad, false annars
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Skicka inloggningstoken till användarens e-post
 * 
 * @param string $email Användarens e-post
 * @return bool True om det lyckades, false vid fel
 */
function sendLoginToken($email) {
    // Generera en unik token
    $token = bin2hex(random_bytes(32));
    
    // Hämta inloggningstokenexpirering från konfiguration eller använd standardvärde (15 minuter)
    $tokenExpiryMinutes = (int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15;
    
    // Uppdatera användaren med ny token
    $expires = date('Y-m-d H:i:s', strtotime("+{$tokenExpiryMinutes} minutes"));
    execute("UPDATE " . DB_DATABASE . ".users 
             SET verification_token = ?, verified_at = NULL 
             WHERE email = ?", 
             [$token, $email]);
    
    // Skapa inloggningslänk med SYSTEM_URL från konfiguration
    $systemUrl = rtrim(getenv('SYSTEM_URL') ?: ('https://' . $_SERVER['HTTP_HOST']), '/');
    $loginUrl = $systemUrl . '/verify.php?token=' . $token . '&email=' . urlencode($email);
        
    // Hämta systemnamn från .env
    $systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'AI-kurser';
    
    // Förbered e-post med SMTP-funktionen
    $subject = mb_encode_mimeheader("Inloggningslänk till " . $systemName, 'UTF-8', 'Q');
    
    $htmlMessage = "
    <!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
    <html xmlns=\"http://www.w3.org/1999/xhtml\">
    <head>
        <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />
        <title>Inloggningslänk</title>
    </head>
    <body style=\"font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0;\">
        <table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin: 0; padding: 0;\">
            <tr>
                <td style=\"padding: 20px;\">
                    <h2 style=\"font-family: Arial, sans-serif; color: #000000;\">Inloggningslänk till " . $systemName . "</h2>
                    <p style=\"font-family: Arial, sans-serif; color: #000000;\">Klicka på knappen nedan för att logga in:</p>
                    
                    <!--[if mso]>
                    <v:roundrect xmlns:v=\"urn:schemas-microsoft-com:vml\" xmlns:w=\"urn:schemas-microsoft-com:office:word\" href='" . $loginUrl . "' style=\"height:40px;v-text-anchor:middle;width:120px;\" arcsize=\"10%\" stroke=\"f\" fillcolor=\"#0d6efd\">
                    <w:anchorlock/>
                    <center>
                    <![endif]-->
                    <a href='" . $loginUrl . "' style=\"background-color: #0d6efd; border-radius: 5px; color: #ffffff; display: inline-block; font-family: Arial, sans-serif; font-size: 16px; line-height: 40px; text-align: center; text-decoration: none; width: 120px; -webkit-text-size-adjust: none;\">Logga in</a>
                    <!--[if mso]>
                    </center>
                    </v:roundrect>
                    <![endif]-->
                    
                    <p style=\"font-family: Arial, sans-serif; color: #000000; margin-top: 20px;\">Om knappen inte fungerar, kopiera denna länk och klistra in i din webbläsare:</p>
                    <p style=\"font-family: Arial, sans-serif; color: #000000;\">" . $loginUrl . "</p>
                    <p style=\"font-family: Arial, sans-serif; color: #000000;\">Länken är giltig i {$tokenExpiryMinutes} minuter.</p>
                    <p style=\"font-family: Arial, sans-serif; color: #000000;\">Om du inte har begärt denna länk kan du ignorera detta meddelande.</p>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
    
    // Använd SMTP-funktionen från mail.php
    $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
    $mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: 'AI-kurser';
    
    // Använd sendSmtpMail från mail.php
    $mailSent = sendSmtpMail($email, $subject, $htmlMessage, $mailFrom, $mailFromName);
    
    // Logga specifikt för inloggningstoken
    if ($mailSent) {
        logActivity($email, "Inloggningstoken skickat framgångsrikt", [
            'action' => 'login_token_sent',
            'token_expiry_minutes' => $tokenExpiryMinutes,
            'email' => $email
        ]);
    } else {
        logActivity($email, "Inloggningstoken misslyckades att skickas", [
            'action' => 'login_token_failed',
            'email' => $email
        ]);
    }
    
    return $mailSent;
}

/**
 * Verifiera inloggningstoken
 *
 * SECURITY FIX: Added token format validation, expiry check, and single-use invalidation
 *
 * @param string $email Användarens e-post
 * @param string $token Autentiseringstoken
 * @return array|false Användaruppgifter om token är giltig, false annars
 */
function verifyLoginToken($email, $token) {
    // SECURITY FIX: Validate token format (must be 64 hex characters)
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        logActivity($email, "Misslyckad inloggning: ogiltigt token-format");
        return false;
    }

    // Hämta inloggningstokenexpirering från konfiguration eller använd standardvärde (15 minuter)
    $tokenExpiryMinutes = (int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15;

    // SECURITY FIX: Added expiry check in SQL query
    // Väljer användare där:
    // 1. E-posten matchar
    // 2. Verifieringstoken matchar
    // 3. Token har uppdaterats inom expireringstiden
    $sql = "SELECT * FROM " . DB_DATABASE . ".users
            WHERE email = ?
            AND verification_token = ?
            AND updated_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)";

    $user = queryOne($sql, [$email, $token, $tokenExpiryMinutes]);

    if (!$user) {
        // Logga misslyckad tokenverifiering
        logActivity($email, "Misslyckad inloggning: ogiltig eller utgången token");
        return false;
    }

    // SECURITY FIX: Invalidate token after single use (one-time token)
    execute("UPDATE " . DB_DATABASE . ".users
             SET verification_token = NULL, verified_at = NOW()
             WHERE id = ?", [$user['id']]);

    // Logga lyckad tokenverifiering
    logActivity($email, "Lyckad inloggning med token");

    return $user;
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
        // Förnya token (använder REMEMBER_TOKEN_HOURS från .env)
        createRememberToken($userId);

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
