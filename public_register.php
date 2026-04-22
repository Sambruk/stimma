<?php
/**
 * Stimma - Publik kursregistrering
 *
 * Anonym landningssida för externa deltagare. Admin genererar en unik länk per
 * publik kurs och delar den. Användaren registrerar sig med e-post + namn, får
 * en magic-link i sin inkorg, och när länken verifieras skapas public_course_access
 * automatiskt via verify.php. Användaren får access_mode='public_only' om de
 * inte redan finns i users-tabellen.
 *
 * URL-mönster: /public_register.php?course_id=X&token=HEX
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/mail.php';

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$token = $_GET['token'] ?? '';

// Validera kurs + token innan vi renderar något — fel länk = 404-liknande svar
$course = validatePublicRegistrationToken($courseId, $token);
if (!$course) {
    http_response_code(404);
    $page_title = 'Registreringslänken är inte giltig';
    ?>
    <!DOCTYPE html>
    <html lang="sv"><head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    </head><body>
    <div class="container py-5">
        <div class="row justify-content-center"><div class="col-12 col-md-6">
            <div class="alert alert-warning">
                <h4><i class="bi bi-exclamation-triangle-fill me-2"></i>Länken är inte giltig</h4>
                <p class="mb-0">Den här registreringslänken finns inte eller har gått ut. Kontakta kursens administratör för en ny länk.</p>
            </div>
        </div></div>
    </div>
    </body></html>
    <?php
    exit;
}

$error = null;
$success = null;

// Hastighetsbegränsning per IP + e-post (enkel sessionsbaserad — räcker mot
// snabb abuse, bot-trafik får magic-link som gatekeeper ändå).
function publicRegisterRateLimit($email) {
    $key = 'pub_reg_' . md5(strtolower($email) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $now = time();
    $window = 300; // 5 min
    $max = 3;      // 3 försök per 5 min
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset_at' => $now + $window];
    }
    if ($_SESSION[$key]['reset_at'] < $now) {
        $_SESSION[$key] = ['count' => 0, 'reset_at' => $now + $window];
    }
    $_SESSION[$key]['count']++;
    return $_SESSION[$key]['count'] <= $max;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Ogiltig säkerhetstoken. Försök igen.';
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ange en giltig e-postadress.';
        } elseif ($name === '' || mb_strlen($name) < 2) {
            $error = 'Ange ditt namn.';
        } elseif (mb_strlen($name) > 150) {
            $error = 'Namnet är för långt (max 150 tecken).';
        } elseif (!publicRegisterRateLimit($email)) {
            $error = 'För många försök. Vänta några minuter och försök igen.';
        } else {
            // Skapa användaren om hen är ny — access_mode = public_only. Befintliga
            // användare rörs inte (deras access_mode behålls).
            $existing = queryOne(
                "SELECT id, access_mode FROM " . DB_DATABASE . ".users WHERE email = ? LIMIT 1",
                [$email]
            );
            if (!$existing) {
                execute(
                    "INSERT INTO " . DB_DATABASE . ".users (email, name, access_mode, created_at) VALUES (?, ?, 'public_only', NOW())",
                    [$email, $name]
                );
            } elseif (empty($existing['access_mode'])) {
                // Gamla rader utan värde — sätt default
                execute("UPDATE " . DB_DATABASE . ".users SET access_mode = 'domain' WHERE id = ?", [$existing['id']]);
            } else if ($existing['access_mode'] === 'public_only') {
                // Uppdatera namnet om det skiljer sig (låter deltagare korrigera sitt namn)
                execute("UPDATE " . DB_DATABASE . ".users SET name = ? WHERE id = ? AND (name IS NULL OR name = '')", [$name, $existing['id']]);
            }

            // Skicka magic-link. sendLoginToken skriver en ny verification_token
            // på users-raden — vi läser den direkt efteråt.
            $sent = sendLoginToken($email);
            if ($sent) {
                $row = queryOne(
                    "SELECT verification_token FROM " . DB_DATABASE . ".users WHERE email = ?",
                    [$email]
                );
                if ($row && !empty($row['verification_token'])) {
                    $expiryMin = (int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15;
                    execute(
                        "INSERT INTO " . DB_DATABASE . ".public_registration_intents
                         (verification_token, email, course_id, name, expires_at)
                         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
                         ON DUPLICATE KEY UPDATE email = VALUES(email), course_id = VALUES(course_id), name = VALUES(name), expires_at = VALUES(expires_at)",
                        [$row['verification_token'], $email, $course['id'], $name, $expiryMin]
                    );
                }
                // Samma meddelande oavsett om e-posten redan fanns — undviker
                // e-post-enumeration.
                $success = 'Vi har skickat en inloggningslänk till din e-post. Länken är giltig i ' . ((int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15) . ' minuter. Kolla även skräpposten om du inte ser den.';
            } else {
                $error = 'Det gick inte att skicka e-postmeddelandet just nu. Försök igen.';
            }
        }
    }
}

$page_title = 'Registrera dig för ' . $course['title'];
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="include/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <img src="images/stimma-logo.png" height="80" alt="Stimma">
                    </div>
                    <h1 class="h4 text-center mb-3">Registrera dig för kursen</h1>
                    <h2 class="h5 text-primary text-center mb-4"><?= htmlspecialchars($course['title']) ?></h2>

                    <?php if (!empty($course['description'])): ?>
                    <p class="text-muted small"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
                    <hr>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-envelope-paper-fill me-2"></i>
                            <strong>Inloggningslänk på väg!</strong>
                            <p class="mb-0 mt-2"><?= htmlspecialchars($success) ?></p>
                        </div>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <p class="small text-muted">
                            Fyll i dina uppgifter så skickar vi en inloggningslänk till din e-post.
                            När du klickar på länken kopplas du till kursen och kan börja studera direkt.
                            Du får endast tillgång till den här kursen.
                        </p>

                        <form method="post" action="public_register.php?course_id=<?= (int)$course['id'] ?>&token=<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                            <div class="form-floating mb-3">
                                <input type="email" class="form-control" id="email" name="email" placeholder="namn@exempel.se" required maxlength="150"
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                <label for="email">E-postadress</label>
                            </div>
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="name" name="name" placeholder="Ditt namn" required maxlength="150"
                                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                                <label for="name">Ditt namn</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-envelope me-1"></i>Skicka inloggningslänk
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-center text-muted small mt-3">
                Drivs av <a href="https://stimma.sambruk.se" class="text-muted">Stimma</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
