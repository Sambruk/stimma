<?php
require_once 'config.php';
require_once 'database.php';

/**
 * Omdirigera till en annan sida
 *
 * @param string $url URL att omdirigera till
 */
function redirect($url) {
    // Om URL:en är relativ (inte börjar med http:// eller https://), lägg till base path
    if (!preg_match('/^https?:\/\//', $url)) {
        $systemUrl = rtrim(getenv('SYSTEM_URL') ?: '', '/');
        if ($systemUrl) {
            $url = $systemUrl . '/' . ltrim($url, '/');
        }
    }
    header("Location: $url");
    exit;
}

/**
 * Sanera användarinmatning
 * 
 * @param string $input Användarinmatning
 * @return string Sanerad inmatning
 */
function sanitize($input) {
    return htmlspecialchars($input ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Kortform för HTML-escaping (XSS-skydd)
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Säker extern URL-hämtning med SSRF-skydd
 */
function secureUrlFetch($url, $allowedDomains = [], $timeout = 30) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
        return false;
    }
    $host = $parsed['host'];
    $scheme = $parsed['scheme'] ?? 'http';
    if (!in_array($scheme, ['http', 'https'])) {
        return false;
    }
    if (!empty($allowedDomains)) {
        $domainAllowed = false;
        foreach ($allowedDomains as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                $domainAllowed = true;
                break;
            }
        }
        if (!$domainAllowed) {
            return false;
        }
    }
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    if ($ip === '127.0.0.1' || $ip === '::1' || $host === 'localhost') {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($httpCode === 200) ? $content : false;
}

/**
 * Hämta standard API-URL baserat på leverantör
 */
function getDefaultApiUrl($provider) {
    $urls = [
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'anthropic' => 'https://api.anthropic.com/v1/messages',
        'google' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
        'azure' => '',
        'custom' => ''
    ];
    return $urls[$provider] ?? $urls['openai'];
}

/**
 * Förnya sessionen och uppdatera utgångstiden
 */
function renewSession() {
    // Säkerställ att sessionen är startad
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Kontrollera om användaren är inloggad
    if (isset($_SESSION['user_id'])) {
        $currentTime = time();
        
        // Hämta sessionens livstid från .env eller använd standardvärdet (4 timmar)
        $sessionLifetimeHours = (int)getenv('SESSION_LIFETIME_HOURS') ?: 4;
        $sessionLifetime = $sessionLifetimeHours * 60 * 60; // Konvertera till sekunder
        
        // Hämta regenereringsintervall från .env eller använd standardvärdet (30 minuter)
        $regenerateMinutes = (int)getenv('SESSION_REGENERATE_MINUTES') ?: 30;
        $regenerateInterval = $regenerateMinutes * 60; // Konvertera till sekunder
        
        // Kontrollera om sessionen har gått ut
        if (!isset($_SESSION['last_activity']) || 
            ($currentTime - $_SESSION['last_activity']) > $sessionLifetime) {
            
            // Sessionen har gått ut, regenerera ID:t
            session_regenerate_id(true);
            $_SESSION['last_activity'] = $currentTime;
        } 
        // Eller om det har gått tillräckligt lång tid sedan senaste ID-regenereringen
        else if (!isset($_SESSION['last_regenerated']) || 
                 ($currentTime - $_SESSION['last_regenerated']) > $regenerateInterval) {
            
            // Regenerera sessions-ID för säkerhet med jämna intervall
            session_regenerate_id(true);
            
            // Uppdatera senaste regenereringstidpunkten
            $_SESSION['last_regenerated'] = $currentTime;
            $_SESSION['last_activity'] = $currentTime;
        }
        // Annars uppdatera bara aktivitetstidsstämpeln
        else {
            $_SESSION['last_activity'] = $currentTime;
        }
    }
}

/**
 * Generera en CSRF-token
 * 
 * @return string CSRF-token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validera en CSRF-token
 *
 * @param string $token Token att validera
 * @return bool True om token är giltig, false annars
 */
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Hämta headertexten som ska visas i top-nav för en användare.
 *
 * Resolutionsordning:
 *   1. Om domänen är grupperad i en org och orgen har en satt header_text → den
 *   2. Om domain_settings för användarens domän har header_text → den
 *   3. Publik deltagare: org:ens header_text för deras första publika kurs
 *   4. Default: "Stimma - en utbildningsplattform från Sambruk"
 *
 * Platshållare i lagrad text substitueras:
 *   {{domain}}       — användarens e-postdomän
 *   {{organization}} — organisationens namn (tom sträng om okänd)
 *   {{date}}         — dagens datum (svensk format)
 *
 * @param int $userId
 * @return string Den renderade texten, redan htmlspecialchar-säker (platshållare
 *   substitueras från säkra källor, men anroparen bör ändå echo:a den som HTML-
 *   tillåten då "Stimma" ska kunna innehålla <span>).
 */
function getHeaderText($userId) {
    $user = queryOne(
        "SELECT email, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
        [(int)$userId]
    );
    if (!$user) {
        return 'Stimma - en utbildningsplattform från Sambruk';
    }

    $domain = getUserDomain($user['email']);
    $orgName = '';
    $template = null;

    if (($user['access_mode'] ?? 'domain') === 'public_only') {
        // Publik deltagare: hämta från orgen som publicerat deras kurs
        $row = queryOne(
            "SELECT o.name, o.header_text
             FROM " . DB_DATABASE . ".public_course_access pca
             LEFT JOIN " . DB_DATABASE . ".organizations o ON o.id = pca.organization_id
             WHERE pca.user_id = ? AND pca.organization_id IS NOT NULL
             ORDER BY pca.registered_at DESC LIMIT 1",
            [(int)$userId]
        );
        if ($row) {
            $orgName = $row['name'] ?? '';
            if (!empty($row['header_text'])) $template = $row['header_text'];
        }
    } else {
        // Domain-användare: först org:ens header_text
        $org = $domain !== '' ? getOrganizationByDomain($domain) : null;
        if ($org) {
            $orgName = $org['name'] ?? '';
            if (!empty($org['header_text'])) $template = $org['header_text'];
        }
        // Fallback: domain_settings
        if ($template === null && $domain !== '') {
            $ds = queryOne(
                "SELECT header_text FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?",
                [$domain]
            );
            if ($ds && !empty($ds['header_text'])) {
                $template = $ds['header_text'];
            }
        }
    }

    if ($template === null || $template === '') {
        return 'Stimma - en utbildningsplattform från Sambruk';
    }

    // Substituera platshållare. Eskapera domain/orgName för säkerhet.
    $monthNames = ['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'];
    $today = date('j') . ' ' . $monthNames[(int)date('n') - 1] . ' ' . date('Y');

    $rendered = str_replace(
        ['{{domain}}', '{{organization}}', '{{date}}'],
        [
            htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($today, ENT_QUOTES, 'UTF-8'),
        ],
        // Själva mall-strängen eskaperas också så att taggar i sparad text
        // inte kan injicera HTML.
        htmlspecialchars($template, ENT_QUOTES, 'UTF-8')
    );

    return $rendered;
}

/**
 * Hämta listan av domäner en kurs är begränsad till (via
 * course_shared_domains). Tom array = kursen delas med hela organisationen.
 *
 * @param int $courseId
 * @return string[] Domännamn i listan (möjligen tom)
 */
function getCourseSharedDomains($courseId) {
    $rows = query(
        "SELECT domain FROM " . DB_DATABASE . ".course_shared_domains WHERE course_id = ? ORDER BY domain",
        [(int)$courseId]
    );
    return array_column($rows ?: [], 'domain');
}

/**
 * Spara listan av delade domäner för en kurs. Ersätter eventuella tidigare
 * rader. Tom array rensar helt (= delas med hela organisationen).
 *
 * @param int $courseId
 * @param string[] $domains
 */
function setCourseSharedDomains($courseId, array $domains) {
    execute(
        "DELETE FROM " . DB_DATABASE . ".course_shared_domains WHERE course_id = ?",
        [(int)$courseId]
    );
    $clean = array_filter(array_unique(array_map(function($d) {
        return strtolower(trim($d));
    }, $domains)));
    foreach ($clean as $d) {
        execute(
            "INSERT IGNORE INTO " . DB_DATABASE . ".course_shared_domains (course_id, domain) VALUES (?, ?)",
            [(int)$courseId, $d]
        );
    }
}

/**
 * Hämta organisationsikonen som ska visas i top-nav för en användare.
 *
 * Regler:
 * - Vanlig användare (access_mode='domain'): ikonen för organisationen som
 *   användarens e-postdomän tillhör.
 * - Publik deltagare (access_mode='public_only'): ikonen för den
 *   organisation som publicerat den (första) kurs hen är registrerad på.
 * - Ingen match: null.
 *
 * @param int $userId
 * @return array{url:string,name:string}|null URL till ikon + orgnamn för alt-text,
 *   eller null om ingen kan bestämmas.
 */
function getHeaderOrganizationIcon($userId) {
    $user = queryOne(
        "SELECT email, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
        [(int)$userId]
    );
    if (!$user) return null;

    $org = null;

    if (($user['access_mode'] ?? 'domain') === 'public_only') {
        // Slå upp första publika kurs hen har access till → organization_id
        $row = queryOne(
            "SELECT pca.organization_id, o.name, o.icon_url
             FROM " . DB_DATABASE . ".public_course_access pca
             LEFT JOIN " . DB_DATABASE . ".organizations o ON o.id = pca.organization_id
             WHERE pca.user_id = ? AND pca.organization_id IS NOT NULL
             ORDER BY pca.registered_at DESC LIMIT 1",
            [(int)$userId]
        );
        if ($row && !empty($row['icon_url'])) {
            return ['url' => $row['icon_url'], 'name' => $row['name'] ?? ''];
        }
        return null;
    }

    // Domain-användare: hitta via organization_domains
    $domain = getUserDomain($user['email']);
    if ($domain !== '') {
        $org = getOrganizationByDomain($domain);
    }
    if ($org && !empty($org['icon_url'])) {
        return ['url' => $org['icon_url'], 'name' => $org['name'] ?? ''];
    }
    return null;
}

/**
 * Bygg en visningsetikett för kursens ursprungsorganisation.
 *
 * Returnerar ett namn + domän om domänen är grupperad i en organisation, annars
 * enbart domänen. Används av admin-vyerna för att visa den permanenta "ursprung"-
 * etiketten på kopierade/importerade kurser. Returnerar null om fältet saknas.
 *
 * @param string|null $originalDomain Värdet av courses.original_organization_domain
 * @return string|null Etikett som "Säters kommun (sater.se)" eller "sater.se"
 */
function getOriginalOrganizationLabel($originalDomain) {
    if (empty($originalDomain)) {
        return null;
    }
    $org = getOrganizationByDomain($originalDomain);
    if ($org && !empty($org['name'])) {
        return $org['name'] . ' (' . $originalDomain . ')';
    }
    return $originalDomain;
}

/**
 * Kontrollera om aktuell session är en superadmin som "visar som" en annan användare.
 *
 * Används av header-banner och eventuella behörighetskontroller som vill skilja
 * på riktig sessionsägare vs. den användare vars vy visas just nu.
 *
 * @return bool
 */
function isImpersonating() {
    return isset($_SESSION['impersonator_user_id']);
}

function sendOpenAIRequest($messages, array $context = []) {
    // Hämta API-konfiguration från .env
    $provider = getenv('AI_PROVIDER') ?: 'openai';
    $apiServer = getenv('AI_SERVER') ?: '';
    if (empty($apiServer)) {
        $apiServer = getDefaultApiUrl($provider);
    }
    $apiKey = getenv('AI_API_KEY') ?: '';
    // Modell väljs i superadmin-UI per feature (chat är default-fallback för
    // sendOpenAIRequest eftersom det är den generella anrops-funktionen).
    require_once __DIR__ . '/ai_quota.php';
    $featureForModel = $context['feature'] ?? 'chat';
    $defaultModel = getenv('AI_MODEL') ?: 'gpt-4o-mini';
    $model = getModelForFeature($featureForModel, $defaultModel);
    $maxTokens = (int)(getenv('AI_MAX_COMPLETION_TOKENS') ?: 4096);
    $temperature = (float)(getenv('AI_TEMPERATURE') ?: 0.7);
    $topP = (float)(getenv('AI_TOP_P') ?: 0.9);
    // Retry-policy (uppdaterad 2026-05-13):
    //   - Max 1 retry (= 2 anropsförsök totalt). Tidigare 3 → vid OpenAI-
    //     timeouts kunde vi konsumera tokens 3 gånger utan att räknas i
    //     statistiken; det bidrog till missrapportering i förhållande
    //     till OpenAI:s billing.
    //   - Vi retry:ar bara på cURL-fel (nätverksstörning) och HTTP 429/5xx.
    //     4xx-fel som 400/401/403 retry:as INTE — de är klientfel och
    //     fixas inte av att försöka igen.
    //   - Timeout höjs från 30 s → 120 s. 30 s räcker inte för stora chat-
    //     anrop (4096+ tokens output), vilket triggade onödiga retries.
    $maxRetries = 1;
    $timeout = 120; // sekunder

    if (empty($apiKey)) {
        throw new Exception('API-nyckel saknas i konfigurationen.');
    }

    // Kvotkontroll innan vi spenderar pengar (kastar Exception om kvot full + behavior=block)
    enforceAiQuotaForCurrentSession();

    // Avgör API-typ baserat på URL
    $isOpenRoute = strpos($apiServer, 'openrouter.ai') !== false;

    // Skapa API-förfrågan baserat på API-typ
    if ($isOpenRoute) {
        $requestData = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'top_p' => $topP,
            'max_tokens' => $maxTokens
        ];
    } else {
        $requestData = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'top_p' => $topP
        ];
    }
    
    // Spara användar-ID från session för loggning
    $userId = $_SESSION['user_id'] ?? 'ingen_användar_id';
    $userEmail = $_SESSION['user_email'] ?? 'okänd användare';

    // Retry bara på transient fel: cURL-fel (timeout/nätverk) + HTTP 429/5xx.
    // 4xx-fel (utom 429) är klientfel och fixas inte av retry.
    $attempts = 0;
    $maxAttempts = $maxRetries + 1; // 1 ursprung + N retries
    $lastError = '';

    while ($attempts < $maxAttempts) {
        $attempts++;

        $ch = curl_init($apiServer);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && empty($error)) {
            $responseData = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $content = null;
                if ($isOpenRoute) {
                    $content = $responseData['choices'][0]['message']['content']
                        ?? $responseData['choices'][0]['text']
                        ?? null;
                } else {
                    $content = $responseData['choices'][0]['message']['content']
                        ?? $responseData['content']
                        ?? null;
                }
                if ($content !== null) {
                    $usage = $responseData['usage'] ?? [];
                    logActivity($userEmail, "AI-anrop lyckades efter $attempts försök");
                    logAiUsage($context, $usage, $model, 'ok');
                    return $content;
                }
            }
        }

        $lastError = "HTTP $httpCode: " . ($error ?: $response);

        // Bedöm om felet är värt en retry. Klientfel (400/401/403/404) och
        // 200-OK utan giltigt content blir omedelbart 'error' — retry skulle
        // bara förbränna tokens. Vi retry:ar bara nätverksfel och OpenAI-
        // serverfel.
        $isTransient = !empty($error)                  // cURL-fel (timeout, conn reset, dns…)
                    || $httpCode === 429              // rate limit
                    || ($httpCode >= 500 && $httpCode < 600);

        if (!$isTransient || $attempts >= $maxAttempts) {
            break;
        }

        sleep(1); // backoff innan retry
    }

    logAiUsage($context, [], $model, 'error');
    throw new Exception("Kunde inte få svar från AI efter $attempts försök. Senaste fel: $lastError");
}

/**
 * Konvertera Markdown-text till HTML
 * 
 * Denna funktion konverterar Markdown-text till HTML utan att förlita sig på externa bibliotek
 * som marked.js eller highlight.js. Den stödjer följande markdown-element:
 * - Kodblock (med språkspecifikation)
 * - Inline kod
 * - Rubriker (h1-h6)
 * - Fet och kursiv text
 * - Länkar (med säker hantering)
 * - Listor (numrerade och punkter)
 * - Blockquotes
 * - Horisontella linjer
 * 
 * @param string $text Markdown-text som ska konverteras
 * @return string HTML-formaterad text
 */
function parseMarkdown($text) {
    // Sanera inkommande text för att förhindra XSS
    $text = strip_tags($text);
    
    // Ta bort överflödiga radbrytningar
    $text = preg_replace('/\n\n+/', "\n\n", $text);
    
    // Ersätt kodblock med syntax highlighting
    $text = preg_replace_callback('/```(\w+)?\n([\s\S]*?)```/', function($matches) {
        $lang = $matches[1] ?? '';
        $code = htmlspecialchars($matches[2]);
        $langClass = !empty($lang) ? ' class="language-' . htmlspecialchars($lang) . '"' : '';
        return '<pre><code' . $langClass . '>' . $code . '</code></pre>';
    }, $text);

    // Ersätt inline kod
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    // Hantera listor först
    $text = preg_replace_callback('/(?:^|\n)(?:([0-9]+\.) |\- )(.*?)(?=\n|$)/', function($matches) {
        $isOrdered = isset($matches[1]);
        $content = $matches[2];
        $listType = $isOrdered ? 'ol' : 'ul';
        $item = $isOrdered ? "<li>$content</li>" : "<li>$content</li>";
        return "\n<$listType>$item</$listType>";
    }, $text);

    // Kombinera intilliggande listor av samma typ
    $text = preg_replace('/<\/(ol|ul)>\s*<\1>/', '', $text);

    // Ersätt rubriker (upp till 6 nivåer)
    $text = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $text);
    $text = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^#### (.*$)/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^##### (.*$)/m', '<h5>$1</h5>', $text);
    $text = preg_replace('/^###### (.*$)/m', '<h6>$1</h6>', $text);

    // Ersätt fetstil och kursiv
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.*?)__/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_(.*?)_/', '<em>$1</em>', $text);
    
    // Ersätt genomstruken text
    $text = preg_replace('/~~(.*?)~~/', '<del>$1</del>', $text);

    // Konvertera återstående radbrytningar till <br> och <p>
    $text = '<p>' . str_replace("\n\n", '</p><p>', $text) . '</p>';
    $text = str_replace("\n", '<br>', $text);
    
    // Ta bort tomma paragrafer
    $text = preg_replace('/<p>\s*<\/p>/', '', $text);
    
    return $text;
}

/**
 * Logga en aktivitet i databasen
 * 
 * @param string $email Användarens e-post
 * @param string $message Meddelande om aktiviteten
 * @param array $context Extra kontext att inkludera i loggen (frivilligt)
 * @return bool True om det lyckades, false vid fel
 */
function logActivity($email, $message, $context = []) {
    try {
        // Standardisera e-post
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'okänd_användare';
        
        // Lägg till användar-ID om tillgängligt
        if (!isset($context['user_id']) && isset($_SESSION['user_id'])) {
            $context['user_id'] = $_SESSION['user_id'];
        }
        
        // Lägg till IP-adress om tillgänglig
        if (!isset($context['ip']) && isset($_SERVER['REMOTE_ADDR'])) {
            $context['ip'] = $_SERVER['REMOTE_ADDR'];
        }
        
        // Lägg till User-Agent om tillgänglig
        if (!isset($context['user_agent']) && isset($_SERVER['HTTP_USER_AGENT'])) {
            $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }
        
        // Skapa ett detaljerat meddelande om det finns ytterligare kontext
        $detailedMessage = $message;
        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // Lägg till kontext som JSON i meddelandet men begränsa till 1000 tecken för att undvika för stora loggar
            if (strlen($contextStr) > 1000) {
                $contextStr = substr($contextStr, 0, 997) . '...';
            }
            $detailedMessage .= ' | Kontext: ' . $contextStr;
        }
        
        execute("INSERT INTO " . DB_DATABASE . ".logs (email, message) VALUES (?, ?)", 
                [$email, $detailedMessage]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Sökväg till upload-mappen. Webbroten är public/ sedan 2026-08-17, så den
// relativa vägen från include/ pekade på projektroten och skapade där en tom
// katalog som www-data inte kunde skriva i. Sidor som lutar sig mot den här
// globalen skrev alltså till fel ställe.
$uploadDir = ROOT_PATH . '/public/upload/';

// Kontrollera om mappen finns, annars skapa den
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/**
 * Sanera och validera en bild-URL för säker användning
 *
 * SECURITY FIX: Prevents path traversal attacks by:
 * - Using basename() to remove directory components
 * - Validating file extension against whitelist
 * - Checking for null bytes and other malicious patterns
 *
 * @param string $imageUrl Bild-URL från databasen
 * @return string|null Sanerad URL eller null om ogiltig
 */
function sanitizeImageUrl($imageUrl) {
    if (empty($imageUrl)) {
        return null;
    }

    // SECURITY FIX: Remove null bytes that could truncate strings
    $imageUrl = str_replace("\0", '', $imageUrl);

    // SECURITY FIX: Use basename to remove any directory traversal attempts
    $imageUrl = basename($imageUrl);

    // SECURITY FIX: Check for double extensions (e.g., file.php.jpg)
    if (preg_match('/\.(php|phtml|php3|php4|php5|phar|htaccess|sh|pl|py|rb|cgi)/i', $imageUrl)) {
        return null;
    }

    // Validera att det är ett tillåtet filformat
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    $extension = strtolower(pathinfo($imageUrl, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions)) {
        return null;
    }

    // Validera filnamnet (endast alfanumeriska, bindestreck, understreck och punkt)
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $imageUrl)) {
        return null;
    }

    return $imageUrl;
}

/**
 * Rensa HTML-innehåll och behåll endast grundläggande formatering
 *
 * SECURITY FIX: Enhanced XSS protection with:
 * - Removal of javascript: URLs
 * - Removal of data: URLs
 * - Removal of event handlers (onclick, onerror, etc.)
 * - More restrictive tag whitelist
 *
 * @param string $html HTML-innehållet som ska rensas
 * @return string Rensat HTML-innehåll
 */
function cleanHtml($html) {
    if (empty($html)) {
        return '';
    }

    // Ta bort escaped quotes
    $html = str_replace('"', '"', $html);

    // Konvertera HTML-entiteter till deras motsvarande tecken
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // SECURITY FIX: Remove script tags and their content first
    $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);

    // SECURITY FIX: Remove style tags and their content
    $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html);

    // SECURITY FIX: Remove all event handlers (onclick, onerror, onload, etc.)
    $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
    $html = preg_replace('/\s*on\w+\s*=\s*[^\s>]*/i', '', $html);

    // SECURITY FIX: Remove javascript: URLs
    $html = preg_replace('/javascript\s*:/i', 'blocked:', $html);

    // SECURITY FIX: Remove data: URLs (can contain embedded scripts)
    $html = preg_replace('/data\s*:/i', 'blocked:', $html);

    // SECURITY FIX: Remove vbscript: URLs
    $html = preg_replace('/vbscript\s*:/i', 'blocked:', $html);

    // Lista över tillåtna HTML-taggar
    $allowedTags = [
        'br',         // Radbrytning
        'hr',         // Horisontell linje
        'strong',     // Fet stil
        'b',          // Fet stil (alternativ)
        'em',         // Kursiv stil
        'i',          // Kursiv stil (alternativ)
        'u',          // Understruken
        's',          // Genomstruken
        'sub',        // Nedsänkt
        'sup',        // Upphöjd
        'span',       // Inline-formatering (typsnitt, färger, storlekar)
        'h2',         // Rubrik
        'h3',         // Underrubrik
        'h4',         // Underrubrik
        'h5',         // Underrubrik
        'ul',         // Punktlista
        'ol',         // Numrerad lista
        'li',         // Listobjekt
        'p',          // Stycke
        'div',        // Div (för lesson-*-block och styling)
        'blockquote', // Citat
        'img',        // Bilder
        'a',          // Länkar
        'table',      // Tabell
        'thead',      // Tabellhuvud
        'tbody',      // Tabellkropp
        'tr',         // Tabellrad
        'td',         // Tabellcell
        'th'          // Tabellrubrikcell
    ];

    // Ta bort alla HTML-taggar förutom de tillåtna
    $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

    // Tillåtna CSS-egenskaper (för inline style-attribut)
    $allowedCssProperties = [
        'color', 'background-color', 'background',
        'font-family', 'font-size', 'font-weight', 'font-style',
        'text-align', 'text-decoration', 'text-indent', 'text-transform',
        'line-height', 'letter-spacing', 'word-spacing',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-collapse',
        'border-radius',
        'width', 'max-width', 'min-width', 'height', 'max-height', 'min-height',
        'float', 'clear', 'display',
        'list-style-type', 'list-style',
        'vertical-align',
        'opacity',
        'white-space',
        'overflow'
    ];

    /**
     * Saniterar ett style-attribut: behåller bara tillåtna CSS-egenskaper
     */
    $sanitizeStyle = function($styleValue) use ($allowedCssProperties) {
        if (empty($styleValue)) return '';

        // Ta bort javascript: och liknande protokoll
        $styleValue = preg_replace('/javascript\s*:/i', '', $styleValue);
        $styleValue = preg_replace('/vbscript\s*:/i', '', $styleValue);

        // Farliga CSS-funktioner (XSS-vektorer)
        $dangerousFunctions = '/\b(url|expression|import|behavior)\s*\(/i';

        $declarations = explode(';', $styleValue);
        $safe = [];
        foreach ($declarations as $decl) {
            $decl = trim($decl);
            if (empty($decl)) continue;
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) continue;
            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            // Blockera farliga CSS-funktioner men tillåt rgb(), rgba(), hsl(), hsla(), calc()
            if (in_array($property, $allowedCssProperties) && !empty($value) && !preg_match($dangerousFunctions, $value)) {
                $safe[] = $property . ': ' . $value;
            }
        }
        return implode('; ', $safe);
    };

    /**
     * Extraherar ett attributvärde från en HTML-tagg
     */
    $extractAttr = function($tag, $attrName) {
        if (preg_match('/\b' . $attrName . '\s*=\s*"([^"]*)"/', $tag, $m) ||
            preg_match("/\b" . $attrName . "\s*=\s*'([^']*)'/", $tag, $m)) {
            return $m[1];
        }
        return '';
    };

    // Tillåtna div-klasser (lesson-block-typer)
    $allowedDivClasses = ['lesson-intro', 'lesson-tip', 'lesson-info', 'lesson-example', 'lesson-warning', 'lesson-summary'];

    // Sanitera alla öppningstaggar med attribut
    $html = preg_replace_callback('/<([a-z][a-z0-9]*)\b([^>]*)>/i', function($match) use ($sanitizeStyle, $extractAttr, $allowedDivClasses) {
        $tagName = strtolower($match[1]);
        $attrs = $match[2];
        $safeAttrs = '';

        // Taggar som inte ska ha några attribut alls
        $noAttrTags = ['br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'thead', 'tbody', 'tr'];

        // --- IMG: src, alt, class, style, width, height ---
        if ($tagName === 'img') {
            $src = $extractAttr($attrs, 'src');
            $src = preg_replace('#^(\.\./)+upload/#', 'upload/', $src);
            if (empty($src) || !preg_match('#^upload/[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$#', $src)) {
                return '';
            }
            $safeAttrs = 'src="' . $src . '"';

            $alt = $extractAttr($attrs, 'alt');
            if ($alt) $safeAttrs .= ' alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"';

            $class = $extractAttr($attrs, 'class');
            if ($class) $safeAttrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';

            $style = $sanitizeStyle($extractAttr($attrs, 'style'));
            if ($style) $safeAttrs .= ' style="' . $style . '"';

            $width = $extractAttr($attrs, 'width');
            if ($width && preg_match('/^\d+(%|px)?$/', $width)) $safeAttrs .= ' width="' . $width . '"';

            $height = $extractAttr($attrs, 'height');
            if ($height && preg_match('/^\d+(%|px)?$/', $height)) $safeAttrs .= ' height="' . $height . '"';

            return '<img ' . $safeAttrs . '>';
        }

        // --- A: href, target, rel, style ---
        if ($tagName === 'a') {
            $href = $extractAttr($attrs, 'href');
            if (empty($href) || !preg_match('#^https?://#i', $href)) {
                return '<a>';
            }
            $safeAttrs = 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer"';

            $style = $sanitizeStyle($extractAttr($attrs, 'style'));
            if ($style) $safeAttrs .= ' style="' . $style . '"';

            return '<a ' . $safeAttrs . '>';
        }

        // --- TD/TH: colspan, rowspan, style ---
        if ($tagName === 'td' || $tagName === 'th') {
            $colspan = $extractAttr($attrs, 'colspan');
            if ($colspan && preg_match('/^\d+$/', $colspan)) $safeAttrs .= ' colspan="' . $colspan . '"';

            $rowspan = $extractAttr($attrs, 'rowspan');
            if ($rowspan && preg_match('/^\d+$/', $rowspan)) $safeAttrs .= ' rowspan="' . $rowspan . '"';

            $style = $sanitizeStyle($extractAttr($attrs, 'style'));
            if ($style) $safeAttrs .= ' style="' . $style . '"';

            return '<' . $tagName . $safeAttrs . '>';
        }

        // --- DIV: class (lesson-* only) + style ---
        if ($tagName === 'div') {
            $class = $extractAttr($attrs, 'class');
            if ($class && in_array($class, $allowedDivClasses)) {
                $safeAttrs .= ' class="' . $class . '"';
            }

            $style = $sanitizeStyle($extractAttr($attrs, 'style'));
            if ($style) $safeAttrs .= ' style="' . $style . '"';

            return '<div' . $safeAttrs . '>';
        }

        // --- Tags som inte ska ha attribut ---
        if (in_array($tagName, $noAttrTags)) {
            // Men tillåt style på ul, ol, li för listor
            if (in_array($tagName, ['ul', 'ol', 'li'])) {
                $style = $sanitizeStyle($extractAttr($attrs, 'style'));
                if ($style) return '<' . $tagName . ' style="' . $style . '">';
            }
            return '<' . $tagName . '>';
        }

        // --- Övriga taggar (p, span, h2-h5, blockquote, table, tr): style + class ---
        $style = $sanitizeStyle($extractAttr($attrs, 'style'));
        if ($style) $safeAttrs .= ' style="' . $style . '"';

        $class = $extractAttr($attrs, 'class');
        if ($class) $safeAttrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';

        return '<' . $tagName . $safeAttrs . '>';
    }, $html);

    // Konvertera nakna div-taggar (utan attribut) till p-taggar
    // Div-taggar med class/style-attribut behålls som div (de saniterades korrekt ovan)
    $html = preg_replace('/<div>/', '<p>', $html);
    // Ersätt </div> som hör till nakna divs — vi placeholdar stilade divs först
    $html = preg_replace_callback('/<div\s+[^>]+>(.*?)<\/div>/si', function($match) {
        return $match[0]; // Behåll hela matchningen oförändrad
    }, $html);
    // Resterande </div> (de som inte matchades ovan, dvs. hör till konverterade <p>)
    // Vi kan inte enbart göra str_replace eftersom stilade </div> också finns
    // Lösning: temporärt markera stilade div-block, konvertera resten, sedan återställ
    $html = preg_replace_callback('/(<div\s+[^>]+>)(.*?)(<\/div>)/si', function($m) {
        return $m[1] . $m[2] . '%%CLOSEDIV%%';
    }, $html);
    $html = str_replace('</div>', '</p>', $html);
    $html = str_replace('%%CLOSEDIV%%', '</div>', $html);

    // Ta bort kapslade p-taggar
    $html = preg_replace('/<p>\s*<p>/i', '<p>', $html);
    $html = preg_replace('/<\/p>\s*<\/p>/i', '</p>', $html);

    // Ta bort p-taggar runt listobjekt
    $html = preg_replace('/<p>\s*<li>/i', '<li>', $html);
    $html = preg_replace('/<\/li>\s*<\/p>/i', '</li>', $html);

    // Ta bort tomma stycken och stycken som bara innehåller <br> eller whitespace
    $html = preg_replace('/<p>(\s|<br>)*<\/p>/i', '', $html);

    // Ta bort tomma listobjekt
    $html = preg_replace('/<li>\s*<\/li>/', '', $html);

    // Trimma whitespace mellan taggar
    $html = preg_replace('/>\s+</', '><', $html);

    // Ta bort extra mellanslag
    $html = preg_replace('/\s+/', ' ', $html);

    // Säkerställ att alla taggar är korrekt stängda
    $html = force_balance_tags($html);

    return trim($html);
}

/**
 * Hjälpfunktion för att säkerställa att HTML-taggar är korrekt stängda
 * @param string $html HTML-innehåll
 * @return string Balanserad HTML
 */
function force_balance_tags($html) {
    $html = preg_replace('#<([a-z][a-z0-9]*)\b[^>]*\/>#i', '<$1>', $html); // Ta bort själv-stängande slash
    
    // Matcha öppnande taggar
    preg_match_all('#<(?!meta|img|br|hr|input\b)\b([a-z][a-z0-9]*)(?: .*)?(?<![/|/ ])>#iU', $html, $result);
    $openedtags = $result[1];
    
    // Matcha stängande taggar
    preg_match_all('#</([a-z][a-z0-9]*)>#iU', $html, $result);
    $closedtags = $result[1];
    
    $len_opened = count($openedtags);
    
    if (count($closedtags) == $len_opened) {
        return $html;
    }
    
    $openedtags = array_reverse($openedtags);
    
    // Stäng alla öppna taggar
    for ($i = 0; $i < $len_opened; $i++) {
        if (!in_array($openedtags[$i], $closedtags)) {
            $html .= '</' . $openedtags[$i] . '>';
        } else {
            unset($closedtags[array_search($openedtags[$i], $closedtags)]);
        }
    }
    
    return $html;
}

/**
 * Kontrollera om en domän har PUB-avtal
 * 
 * @param string $domain Domännamnet att kontrollera
 * @return bool True om domänen har PUB-avtal, false annars
 */
function hasPubAgreement($domain) {
    $setting = queryOne("SELECT has_pub_agreement FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?", [$domain]);
    return $setting && $setting['has_pub_agreement'] == 1;
}

/**
 * Hämta PUB-avtalsinformation för en domän
 * 
 * @param string $domain Domännamnet
 * @return array|null Domäninställningar eller null om domänen inte finns
 */
function getDomainSettings($domain) {
    return queryOne("SELECT * FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?", [$domain]);
}

/**
 * Uppdatera PUB-avtalsstatus för en domän
 * 
 * @param string $domain Domännamnet
 * @param bool $hasPubAgreement Om domänen har PUB-avtal
 * @param string|null $agreementDate Datum för avtalstecknande (YYYY-MM-DD)
 * @param string|null $notes Anteckningar om avtalet
 * @return bool True om uppdateringen lyckades
 */
function updatePubAgreement($domain, $hasPubAgreement, $agreementDate = null, $notes = null) {
    $existing = getDomainSettings($domain);
    
    if ($existing) {
        return execute("UPDATE " . DB_DATABASE . ".domain_settings 
                        SET has_pub_agreement = ?, pub_agreement_date = ?, pub_agreement_notes = ? 
                        WHERE domain = ?", 
                        [$hasPubAgreement ? 1 : 0, $agreementDate, $notes, $domain]) !== null;
    } else {
        return execute("INSERT INTO " . DB_DATABASE . ".domain_settings 
                        (domain, has_pub_agreement, pub_agreement_date, pub_agreement_notes) 
                        VALUES (?, ?, ?, ?)", 
                        [$domain, $hasPubAgreement ? 1 : 0, $agreementDate, $notes]) !== null;
    }
}

/**
 * Hämta alla domäner med PUB-avtalsstatus
 * 
 * @return array Lista med domäner och deras PUB-status
 */
function getAllDomainSettings() {
    return query("SELECT * FROM " . DB_DATABASE . ".domain_settings ORDER BY domain");
}

/**
 * Hämta användarens domän från e-postadress
 *
 * @param string $email E-postadress
 * @return string Domännamnet
 */
function getUserDomain($email) {
    $parts = explode('@', $email);
    return isset($parts[1]) ? strtolower($parts[1]) : '';
}

/**
 * Avgör om inloggad användare får modifiera en kurs (edit/delete/manage editors).
 *
 * Behörighetsmodell (efter IDOR-fix 2026-05-13):
 *   - super_admin: alla kurser
 *   - is_admin:    bara kurser i sin egen org-scope (orgens domäner)
 *   - course_editor: bara den specifika kursen (cross-org-delegering)
 *   - annars: nej
 *
 * Använd istället för det gamla mönstret `if (!$isAdmin) { ... }` som
 * släppte igenom alla admins oavsett vilken org kursen tillhörde.
 *
 * @param array $course  Rad från courses-tabellen (måste innehålla 'id' och 'organization_domain')
 * @return bool
 */
function userCanModifyCourse(array $course) {
    $userEmail = $_SESSION['user_email'] ?? null;
    if (!$userEmail) return false;

    $u = queryOne(
        "SELECT is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE email = ?",
        [$userEmail]
    );
    if (!$u) return false;

    if (($u['role'] ?? '') === 'super_admin') return true;

    if (!empty($u['is_admin'])) {
        $org = $course['organization_domain'] ?? '';
        if ($org !== '') {
            $scope = getOrgScopeDomains($userEmail);
            if (in_array($org, $scope, true)) return true;
        }
    }

    if (!empty($course['id'])) {
        $isCE = queryOne(
            "SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?",
            [(int)$course['id'], $userEmail]
        );
        if ($isCE) return true;
    }

    return false;
}

// =============================================================================
// Organisationsgruppering (migration 023)
//
// Flera e-postdomäner kan tillhöra samma organisation. Domäner som inte
// tilldelats någon organisation behandlas som "implicit single-domain org" och
// fungerar som tidigare. Helpern getOrgScopeDomains() är central — den används
// av alla refaktorerade admin-/användar-queries för att expandera filterklausuler
// från en enskild domän till orgens samtliga domäner.
// =============================================================================

/**
 * Hämta organisation som en domän tillhör.
 *
 * @param string $domain Domännamn (utan @)
 * @return array|null Organisationsraden eller null om domänen inte är grupperad
 */
function getOrganizationByDomain($domain) {
    if (empty($domain)) {
        return null;
    }
    return queryOne(
        "SELECT o.* FROM " . DB_DATABASE . ".organizations o
         JOIN " . DB_DATABASE . ".organization_domains od ON od.organization_id = o.id
         WHERE od.domain = ?",
        [strtolower($domain)]
    );
}

/**
 * Hämta organisation via ID.
 *
 * @param int $orgId
 * @return array|null
 */
function getOrganizationById($orgId) {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".organizations WHERE id = ?",
        [(int)$orgId]
    );
}

/**
 * Hämta alla domäner som tillhör en organisation.
 *
 * @param int $orgId
 * @return array<string> Lista av domännamn
 */
function getOrganizationDomains($orgId) {
    $rows = query(
        "SELECT domain FROM " . DB_DATABASE . ".organization_domains
         WHERE organization_id = ? ORDER BY is_primary DESC, domain ASC",
        [(int)$orgId]
    );
    return array_column($rows ?: [], 'domain');
}

/**
 * Hämta organisationens primära domän (eller första domänen om ingen är markerad).
 *
 * @param int $orgId
 * @return string|null
 */
function getOrganizationPrimaryDomain($orgId) {
    $row = queryOne(
        "SELECT domain FROM " . DB_DATABASE . ".organization_domains
         WHERE organization_id = ? ORDER BY is_primary DESC, domain ASC LIMIT 1",
        [(int)$orgId]
    );
    return $row ? $row['domain'] : null;
}

/**
 * Returnera de domäner som en användares "organisationsscope" omfattar.
 *
 * Den centrala helpern för alla org-scope-queries: returnerar samtliga domäner
 * i användarens organisation om e-postdomänen är grupperad, annars en lista
 * med bara den egna domänen. Resultatet är alltid icke-tomt (så länge $email
 * har en giltig domändel) och kan användas direkt i en IN-klausul.
 *
 * @param string $email E-postadress
 * @return array<string> Lista av domännamn (alltid minst en post)
 */
function getOrgScopeDomains($email) {
    $domain = getUserDomain($email);
    if (empty($domain)) {
        return [];
    }
    $org = getOrganizationByDomain($domain);
    if (!$org) {
        return [$domain];
    }
    $domains = getOrganizationDomains($org['id']);
    return !empty($domains) ? $domains : [$domain];
}

/**
 * Är användarens domän satt som primär domän (huvuddomän) för en
 * organisation? Används för att avgöra om en admin/redaktör ska ha
 * org-omspännande rättigheter eller bara dom-omspännande.
 *
 * Returnerar false för användare som inte tillhör någon org.
 */
function isUserOnPrimaryOrgDomain($email) {
    $domain = getUserDomain($email);
    if (empty($domain)) return false;
    $row = queryOne(
        "SELECT 1 FROM " . DB_DATABASE . ".organization_domains
         WHERE domain = ? AND is_primary = 1 LIMIT 1",
        [$domain]
    );
    return !empty($row);
}

/**
 * Som getOrgScopeDomains() men respekterar huvuddomän-modellen:
 * - Användare på orgens primary-domän → alla orgens domäner (full org-scope)
 * - Användare på sub-domän → bara sin egen domän (begränsad scope)
 * - Användare utan org → bara sin egen domän
 *
 * Används för admin-sidor (kurslistor, taggar, statistik, användare) där
 * sub-domän-admins inte ska se andra sub-domäners resurser.
 */
function getEffectiveOrgScopeDomains($email) {
    $domain = getUserDomain($email);
    if (empty($domain)) return [];
    if (isUserOnPrimaryOrgDomain($email)) {
        return getOrgScopeDomains($email);
    }
    return [$domain];
}

/**
 * Bygg en parametriserad IN-klausul för en kolumn baserat på en lista av domäner.
 *
 * Returnerar ['fragment' => "col IN (?, ?, ?)", 'params' => [...]].
 *
 * @param array<string> $domains
 * @param string $column SQL-kolumnnamn (eller uttryck) att jämföra mot
 * @return array{fragment:string, params:array}
 */
function buildDomainInClause(array $domains, $column) {
    if (empty($domains)) {
        // Inga domäner → garantera att klausulen aldrig matchar
        return ['fragment' => "$column IN (NULL)", 'params' => []];
    }
    $placeholders = implode(',', array_fill(0, count($domains), '?'));
    return [
        'fragment' => "$column IN ($placeholders)",
        'params' => array_values($domains),
    ];
}

/**
 * Bygg klausulen för vilka kurser en organisation ska kunna följa upp.
 *
 * Används av admin- och uppföljningssidor (statistik) där kurslistan ska vara
 * avgränsad till den egna organisationen — en admin i en kommun ska inte se
 * vilka kurser andra kommuner har, ens som titlar. Tre fall räknas in:
 *
 *   1. Kurser organisationen själv äger (organization_domain i scopet)
 *   2. Globala kurser (is_global = 1), som är avsedda för alla
 *   3. Kurser som uttryckligen delats med någon av scopets domäner
 *      (course_shared_domains)
 *   4. Kurser som någon i scopet FAKTISKT har läst i
 *
 * Punkt 4 är inte en dubblett av 1–3. En sub-domänadmin har bara sin egen domän
 * i scopet, medan medarbetarna läser kurser som huvudorganisationen äger — utan
 * punkt 4 hade deras arbete försvunnit ur uppföljningen. Titeln på en främmande
 * kurs röjs alltså bara när de egna medarbetarna redan läser den, aldrig som en
 * öppen katalog över andra organisationers kurser.
 *
 * @param array<string> $domains Organisationens domänscope
 * @param string $alias Tabellalias för courses i den anropande frågan
 * @return array{fragment:string, params:array}
 */
function buildOrgCourseScopeClause(array $domains, $alias = 'c') {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 'c';
    }

    if (empty($domains)) {
        // Utan domänscope återstår bara det som är globalt för alla.
        return ['fragment' => "($a.is_global = 1)", 'params' => []];
    }

    $own = buildDomainInClause($domains, "$a.organization_domain");
    $placeholders = implode(',', array_fill(0, count($domains), '?'));
    $lowered = array_values(array_map('strtolower', $domains));

    return [
        'fragment' => "({$own['fragment']}
                        OR $a.is_global = 1
                        OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd
                                   WHERE csd.course_id = $a.id AND csd.domain IN ($placeholders))
                        OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".lessons lscope
                                   JOIN " . DB_DATABASE . ".progress pscope ON pscope.lesson_id = lscope.id
                                   JOIN " . DB_DATABASE . ".users uscope ON uscope.id = pscope.user_id
                                   WHERE lscope.course_id = $a.id
                                     AND LOWER(SUBSTRING_INDEX(uscope.email, '@', -1)) IN ($placeholders)))",
        'params' => array_merge($own['params'], array_values($domains), $lowered),
    ];
}

/**
 * Bygg en parametriserad IN-klausul för en e-postkolumn där vi vill matcha
 * användare vars e-postdomän finns i listan.
 *
 * @param array<string> $domains
 * @param string $emailColumn Tabell.kolumn för e-postfältet (t.ex. "u.email")
 * @return array{fragment:string, params:array}
 */
function buildEmailDomainInClause(array $domains, $emailColumn) {
    if (empty($domains)) {
        return ['fragment' => "LOWER(SUBSTRING_INDEX($emailColumn, '@', -1)) IN (NULL)", 'params' => []];
    }
    $placeholders = implode(',', array_fill(0, count($domains), '?'));
    return [
        'fragment' => "LOWER(SUBSTRING_INDEX($emailColumn, '@', -1)) IN ($placeholders)",
        'params' => array_values(array_map('strtolower', $domains)),
    ];
}

/**
 * Läs ut ett valfritt domän-/organisationsfilter för statistiksidorna.
 *
 * Huvuddomän-admins (och superadmins) har flera domäner i sitt scope. Med detta
 * filter kan de begränsa statistiken till en eller flera valda domäner
 * (medlemskommuner) via GET-parametern domains[]. Av säkerhetsskäl skärs valet
 * alltid mot det egna scopet — en användare kan aldrig filtrera fram (eller på
 * annat sätt nå) domäner utanför sin behörighet.
 *
 * @param string $email Inloggad användares e-post
 * @return array{scope:array,selected:array,active:array,filtered:bool}
 *   scope    = alla domäner användaren har behörighet till (för filter-UI)
 *   selected = de domäner användaren valt (alltid en delmängd av scope)
 *   active   = den mängd som queries ska filtreras på (selected om något valts,
 *              annars hela scope)
 *   filtered = true om användaren aktivt valt en delmängd
 */
function getStatsDomainScope($email) {
    $scope = getEffectiveOrgScopeDomains($email);
    $raw = isset($_GET['domains']) ? (array)$_GET['domains'] : [];
    $raw = array_map('strval', $raw);
    // Skär valet mot scopet (skydd mot manipulerade/utanför-scope-domäner)
    $selected = array_values(array_intersect($raw, $scope));
    $active = !empty($selected) ? $selected : $scope;
    return [
        'scope'    => $scope,
        'selected' => $selected,
        'active'   => $active,
        'filtered' => !empty($selected),
    ];
}

/**
 * Bygg en querystring-fragment (&domains[]=...) för att föra vidare ett aktivt
 * domänfilter i länkar (t.ex. export). Returnerar tom sträng om inget valts.
 *
 * @param array $selectedDomains
 * @return string
 */
function buildDomainFilterQuery(array $selectedDomains) {
    $qs = '';
    foreach ($selectedDomains as $d) {
        $qs .= '&domains%5B%5D=' . urlencode($d);
    }
    return $qs;
}

/**
 * Läs ut ett valfritt filter på organisationens organisationstaggar.
 *
 * Filtret erbjuder ALLA taggar som förekommer inom användarens domänscope, inte
 * bara de taggar personen själv bär. Den första versionen (2026-08-24) begränsade
 * listan till de egna taggarna, men det visade sig fel i praktiken: en admin eller
 * läsbehörig som ska följa upp hela organisationen bär sällan själv alla
 * avdelningars taggar, och kunde därför inte filtrera fram dem. Ändrat 2026-08-28.
 *
 * Avgränsningen ligger kvar där den hör hemma — på domänscopet. En sub-domänadmin
 * ser bara sin egen domäns taggar, och ingen ser någonsin en annan organisations.
 *
 * Filtret är VALFRITT. Utan val visas allt inom domänscopet.
 *
 * Valet skärs alltid mot de tillgängliga taggarna, så en manipulerad GET-parameter
 * kan inte smyga in en tagg utanför scopet.
 *
 * OBS om datamodellen: taggar lagras platt. splitOrgTags() delar
 * "Kommun/Förvaltning/Avdelning" på "/" och sparar tre fristående rader —
 * user_org_tags har bara (user_id, tag), ingen förälder och ingen ordning.
 *
 * "Allt under Förvaltningen" fungerar ändå i praktiken, eftersom varje användare
 * bär ALLA nivåer i sin egen väg. Det som inte går är att skilja två grenar som
 * delar namn på understa nivån: Skolförvaltningen/IT och Vårdförvaltningen/IT ger
 * båda taggen IT, och ett filter på IT träffar båda. Ett äkta hierarkiskt filter
 * kräver att datamodellen bär vägen.
 *
 * @param int $userId Inloggad användares id
 * @return array{available:array,selected:array,filtered:bool}
 *   available = alla taggar inom användarens domänscope (för filter-UI)
 *   selected  = de taggar som valts (alltid en delmängd av available)
 *   filtered  = om ett filter är aktivt
 */
function getOrgTagFilter($userId) {
    $user = queryOne("SELECT email FROM " . DB_DATABASE . ".users WHERE id = ?", [(int)$userId]);
    $scopeDomains = $user ? getEffectiveOrgScopeDomains($user['email']) : [];

    // Tomt scope ger "IN (NULL)" och därmed inga taggar — rätt utfall för en
    // användare som inte tillhör någon organisation.
    $scopeClause = buildEmailDomainInClause($scopeDomains, 'u.email');
    $rows = query(
        "SELECT DISTINCT t.tag
         FROM " . DB_DATABASE . ".user_org_tags t
         JOIN " . DB_DATABASE . ".users u ON u.id = t.user_id
         WHERE {$scopeClause['fragment']}
         ORDER BY t.tag ASC",
        $scopeClause['params']
    );
    $available = array_column($rows ?: [], 'tag');

    $raw = isset($_GET['org_tags']) ? (array)$_GET['org_tags'] : [];
    $raw = array_map('strval', $raw);
    $selected = array_values(array_intersect($raw, $available));

    return [
        'available' => $available,
        'selected'  => $selected,
        'filtered'  => !empty($selected),
    ];
}

/**
 * Bygg en parametriserad klausul som begränsar till användare som bär någon av
 * de valda org-taggarna.
 *
 * Returnerar en klausul som alltid matchar när inget filter är aktivt, så att
 * anroparen kan väva in den villkorslöst utan att specialfallshantera.
 *
 * @param array $selectedTags
 * @param string $userIdColumn Tabell.kolumn för användarens id (t.ex. "u.id")
 * @return array{fragment:string, params:array}
 */
function buildOrgTagFilterClause(array $selectedTags, $userIdColumn) {
    if (empty($selectedTags)) {
        return ['fragment' => '1=1', 'params' => []];
    }
    $placeholders = implode(',', array_fill(0, count($selectedTags), '?'));
    return [
        'fragment' => "EXISTS (SELECT 1 FROM " . DB_DATABASE . ".user_org_tags uotf
                               WHERE uotf.user_id = $userIdColumn AND uotf.tag IN ($placeholders))",
        'params' => array_values($selectedTags),
    ];
}

/**
 * Väv ihop två klausuler från build*Clause() till en.
 *
 * Poängen är att anropande sidor ska kunna lägga på ett extra villkor på ETT
 * ställe i stället för att röra varje enskild query. Klausulen används både i
 * WHERE och i JOIN ... ON, därför parentesen runt varje del.
 *
 * @param array{fragment:string,params:array} $a
 * @param array{fragment:string,params:array} $b
 * @return array{fragment:string,params:array}
 */
function combineSqlClauses(array $a, array $b) {
    return [
        'fragment' => '(' . $a['fragment'] . ') AND (' . $b['fragment'] . ')',
        'params'   => array_merge($a['params'], $b['params']),
    ];
}

/**
 * Bygg querystring-fragment (&org_tags[]=...) för att föra vidare ett aktivt
 * taggfilter i länkar (t.ex. export).
 *
 * @param array $selectedTags
 * @return string
 */
function buildOrgTagFilterQuery(array $selectedTags) {
    $qs = '';
    foreach ($selectedTags as $t) {
        $qs .= '&org_tags%5B%5D=' . urlencode($t);
    }
    return $qs;
}

/**
 * Kontrollera om en användares organisation (eller domän, om ingen org finns)
 * har tecknat PUB-avtal.
 *
 * Logiken hanterar tre fall:
 * 1. Domänen är inte grupperad → kolla domain_settings för domänen (legacy).
 * 2. Domänen är grupperad och orgen har egen PUB-flagga → returnera true.
 * 3. Domänen är grupperad men orgen har inte egen PUB-flagga ännu → faller
 *    tillbaka på att kolla domain_settings för någon av orgens domäner. Detta
 *    täcker fallet där en domän tecknade PUB innan den grupperades in i orgen
 *    (t.ex. sater.se hade PUB innan den lades till i en organisation).
 *
 * @param string $email Användarens e-post
 * @return bool
 */
function userHasPubAgreement($email) {
    $domain = getUserDomain($email);
    if (empty($domain)) {
        return false;
    }
    $org = getOrganizationByDomain($domain);
    if ($org) {
        // Fall 2: orgen har egen PUB
        if ((int)$org['has_pub_agreement'] === 1) {
            return true;
        }
        // Fall 3: legacy-fallback — någon av orgens domäner har PUB sen tidigare
        $orgDomains = getOrganizationDomains($org['id']);
        foreach ($orgDomains as $od) {
            if (hasPubAgreement($od)) {
                return true;
            }
        }
        return false;
    }
    // Fall 1: ingen org, kolla bara domänens egen PUB
    return hasPubAgreement($domain);
}

/**
 * Hämta senaste PUB-avtalsartefakt för en organisation.
 *
 * @param int $orgId
 * @return array|null
 */
function getOrgPubAgreementArtifact($orgId) {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".pub_agreement_artifacts
         WHERE organization_id = ? ORDER BY signed_at DESC LIMIT 1",
        [(int)$orgId]
    );
}

/**
 * Skapa en organisation.
 *
 * @param string $name
 * @param string|null $orgNumber
 * @param string|null $contactEmail
 * @return int|null Nytt organisations-ID eller null vid fel
 */
function createOrganization($name, $orgNumber = null, $contactEmail = null) {
    $newId = execute(
        "INSERT INTO " . DB_DATABASE . ".organizations (name, org_number, contact_email)
         VALUES (?, ?, ?)",
        [trim($name), $orgNumber ? trim($orgNumber) : null, $contactEmail ? trim($contactEmail) : null]
    );
    if ($newId) {
        require_once __DIR__ . '/ai_quota.php';
        ensureAiQuotaRow((int)$newId, null);
    }
    return $newId;
}

/**
 * Uppdatera organisationsdetaljer (namn, org-nummer, kontakt-e-post).
 *
 * @param int $orgId
 * @param string $name
 * @param string|null $orgNumber
 * @param string|null $contactEmail
 * @return bool
 */
function updateOrganization($orgId, $name, $orgNumber = null, $contactEmail = null) {
    return execute(
        "UPDATE " . DB_DATABASE . ".organizations
         SET name = ?, org_number = ?, contact_email = ?
         WHERE id = ?",
        [
            trim($name),
            $orgNumber ? trim($orgNumber) : null,
            $contactEmail ? trim($contactEmail) : null,
            (int)$orgId
        ]
    ) !== null;
}

/**
 * Radera en organisation. organization_domains-rader raderas via CASCADE,
 * pub_agreement_artifacts.organization_id sätts till NULL via SET NULL.
 *
 * @param int $orgId
 * @return bool
 */
function deleteOrganization($orgId) {
    return execute(
        "DELETE FROM " . DB_DATABASE . ".organizations WHERE id = ?",
        [(int)$orgId]
    ) !== null;
}

/**
 * Tilldela en domän till en organisation. Om domänen redan tillhör en annan
 * organisation flyttas den. Om domänen redan tillhör samma org händer ingenting.
 *
 * @param int $orgId
 * @param string $domain
 * @return bool
 */
function assignDomainToOrg($orgId, $domain) {
    $domain = strtolower(trim($domain));
    if (empty($domain)) {
        return false;
    }
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".organization_domains WHERE domain = ?",
        [$domain]
    );
    if ($existing) {
        return execute(
            "UPDATE " . DB_DATABASE . ".organization_domains SET organization_id = ? WHERE id = ?",
            [(int)$orgId, $existing['id']]
        ) !== null;
    }
    return execute(
        "INSERT INTO " . DB_DATABASE . ".organization_domains (organization_id, domain) VALUES (?, ?)",
        [(int)$orgId, $domain]
    ) !== null;
}

/**
 * Ta bort en domän från sin organisation.
 *
 * @param string $domain
 * @return bool
 */
function removeDomainFromOrg($domain) {
    return execute(
        "DELETE FROM " . DB_DATABASE . ".organization_domains WHERE domain = ?",
        [strtolower(trim($domain))]
    ) !== null;
}

/**
 * Sätt en domän som organisationens primära domän (rensar tidigare primary).
 *
 * @param int $orgId
 * @param string $domain
 * @return bool
 */
function setPrimaryDomainForOrg($orgId, $domain) {
    execute(
        "UPDATE " . DB_DATABASE . ".organization_domains SET is_primary = 0 WHERE organization_id = ?",
        [(int)$orgId]
    );
    return execute(
        "UPDATE " . DB_DATABASE . ".organization_domains SET is_primary = 1
         WHERE organization_id = ? AND domain = ?",
        [(int)$orgId, strtolower(trim($domain))]
    ) !== null;
}

/**
 * Uppdatera PUB-avtalsstatus på organisationsnivå.
 *
 * @param int $orgId
 * @param bool $hasPubAgreement
 * @param string|null $agreementDate (YYYY-MM-DD)
 * @param string|null $notes
 * @return bool
 */
function updateOrgPubAgreement($orgId, $hasPubAgreement, $agreementDate = null, $notes = null) {
    return execute(
        "UPDATE " . DB_DATABASE . ".organizations
         SET has_pub_agreement = ?, pub_agreement_date = ?, pub_agreement_notes = ?
         WHERE id = ?",
        [$hasPubAgreement ? 1 : 0, $agreementDate, $notes, (int)$orgId]
    ) !== null;
}

/**
 * Hämta alla organisationer med antal domäner.
 *
 * @return array
 */
function getAllOrganizations() {
    return query(
        "SELECT o.*,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".organization_domains od
                 WHERE od.organization_id = o.id) AS domain_count
         FROM " . DB_DATABASE . ".organizations o
         ORDER BY o.name ASC"
    );
}

// =============================================================================
// Slut på organisationsgruppering
// =============================================================================

// =============================================================================
// Underhållsläge
//
// Superadmin kan aktivera ett globalt underhållsläge som blockerar icke-superadmins
// från att använda systemet. När flaggan är aktiv visas maintenance.php och alla
// requests svarar med HTTP 503. Superadmin har bypass så de kan stänga av läget.
// Flaggan lagras som JSON-fil i data/ (utanför webbservermount via .htaccess deny).
// =============================================================================

/**
 * Returnera den absoluta sökvägen till maintenance-flaggfilen.
 *
 * @return string
 */
function getMaintenanceFlagPath() {
    return realpath(__DIR__ . '/..') . '/data/maintenance.json';
}

/**
 * Är underhållsläge aktivt?
 *
 * @return bool
 */
function isMaintenanceModeActive() {
    return file_exists(getMaintenanceFlagPath());
}

/**
 * Hämta detaljer om underhållsläget (eller null om inaktivt).
 *
 * @return array|null Array med keys: active, since, by_email, message
 */
function getMaintenanceMode() {
    $path = getMaintenanceFlagPath();
    if (!file_exists($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['active' => true, 'since' => null, 'by_email' => null, 'message' => null];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['active' => true, 'since' => null, 'by_email' => null, 'message' => null];
    }
    return [
        'active' => true,
        'since' => $data['since'] ?? null,
        'by_email' => $data['by_email'] ?? null,
        'message' => $data['message'] ?? null,
    ];
}

/**
 * Aktivera underhållsläge.
 *
 * @param string $byEmail E-post för superadmin som aktiverar
 * @param string|null $message Valfritt meddelande som visas för slutanvändare
 * @return bool
 */
function enableMaintenanceMode($byEmail, $message = null) {
    $path = getMaintenanceFlagPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $payload = json_encode([
        'since' => date('Y-m-d H:i:s'),
        'by_email' => $byEmail,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return @file_put_contents($path, $payload) !== false;
}

/**
 * Avaktivera underhållsläge.
 *
 * @return bool
 */
function disableMaintenanceMode() {
    $path = getMaintenanceFlagPath();
    if (!file_exists($path)) {
        return true;
    }
    return @unlink($path);
}

// =============================================================================
// Slut på underhållsläge
// =============================================================================

/**
 * Skicka e-postnotifikation när en användares rättigheter ändras
 *
 * @param string $userEmail E-postadressen till användaren vars rättigheter ändras
 * @param string $changeType Typ av ändring ('admin' eller 'editor')
 * @param bool $newStatus Den nya statusen (true = tilldelad, false = borttagen)
 * @param string $changedByEmail E-postadressen till den som gjorde ändringen
 * @return bool True om e-posten skickades, false vid fel
 */
/**
 * Generera UUID v4
 *
 * @return string UUID i format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 */
function generateUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Spara en PUB-avtalssigneringsartefakt.
 *
 * Slår automatiskt upp organization_id via domänen om det inte angavs explicit
 * i $data, så att signeringen kopplas till orgen om domänen är grupperad.
 *
 * @param array $data Artefaktdata
 * @return int|null ID för den sparade artefakten eller null vid fel
 */
function savePubAgreementArtifact($data) {
    // Slå upp organisation om den inte angavs (automatkoppling vid signering)
    $orgId = $data['organization_id'] ?? null;
    if ($orgId === null && !empty($data['domain'])) {
        $org = getOrganizationByDomain($data['domain']);
        if ($org) {
            $orgId = (int)$org['id'];
        }
    }

    return execute(
        "INSERT INTO " . DB_DATABASE . ".pub_agreement_artifacts
         (agreement_id, version, pdf_filename, pdf_hash, signed_at, ip_address,
          user_id, user_email, user_name, user_title, user_phone,
          domain, organization_id, org_name, org_number, agreement_email, certification_text)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $data['agreement_id'],
            $data['version'] ?? '1.0',
            $data['pdf_filename'] ?? null,
            $data['pdf_hash'] ?? null,
            $data['ip_address'],
            $data['user_id'],
            $data['user_email'],
            $data['user_name'],
            $data['user_title'] ?? null,
            $data['user_phone'] ?? null,
            $data['domain'],
            $orgId,
            $data['org_name'],
            $data['org_number'],
            $data['agreement_email'],
            $data['certification_text']
        ]
    );
}

/**
 * Hämta det aktiva PUB-avtalsdokumentet
 *
 * @return array|null Dokumentdata eller null om inget aktivt dokument finns
 */
function getActivePubDocument() {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".pub_agreement_documents WHERE is_active = 1 LIMIT 1"
    );
}

/**
 * Hämta PUB-avtalssigneringsartefakt för en domän
 *
 * @param string $domain Domännamnet
 * @return array|null Artefaktdata eller null
 */
function getPubAgreementArtifact($domain) {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".pub_agreement_artifacts WHERE domain = ? ORDER BY signed_at DESC LIMIT 1",
        [$domain]
    );
}

/**
 * Kontrollera om ett PUB-avtalsdokument har Sambruks kontrasignering
 *
 * @param int $docId Dokument-ID i pub_agreement_documents
 * @return bool True om dokumentet är kontrasignerat av Sambruk
 */
function isSambrukSigned($docId) {
    $doc = queryOne(
        "SELECT sambruk_signed_at FROM " . DB_DATABASE . ".pub_agreement_documents WHERE id = ?",
        [$docId]
    );
    return $doc && !empty($doc['sambruk_signed_at']);
}

/**
 * Hämta Sambruks signeringsdata för ett PUB-avtalsdokument
 *
 * @param int $docId Dokument-ID i pub_agreement_documents
 * @return array|null Sambruk-signeringsdata eller null
 */
function getSambrukSignatureData($docId) {
    return queryOne(
        "SELECT sambruk_signed_at, sambruk_signer_name, sambruk_signer_email,
                sambruk_signer_title, sambruk_signer_phone, sambruk_signer_user_id,
                sambruk_ip_address, sambruk_signature_hash, sambruk_certification_text
         FROM " . DB_DATABASE . ".pub_agreement_documents WHERE id = ?",
        [$docId]
    );
}

/**
 * Spara Sambruks kontrasignering för ett PUB-avtalsdokument
 *
 * @param int $docId Dokument-ID i pub_agreement_documents
 * @param array $data Signeringsdata med nycklar: signer_name, signer_email, signer_title, signer_phone, user_id, ip_address, signature_hash, certification_text
 * @return int|null Antal uppdaterade rader eller null vid fel
 */
function saveSambrukSignature($docId, $data) {
    return execute(
        "UPDATE " . DB_DATABASE . ".pub_agreement_documents
         SET sambruk_signed_at = NOW(),
             sambruk_signer_name = ?,
             sambruk_signer_email = ?,
             sambruk_signer_title = ?,
             sambruk_signer_phone = ?,
             sambruk_signer_user_id = ?,
             sambruk_ip_address = ?,
             sambruk_signature_hash = ?,
             sambruk_certification_text = ?
         WHERE id = ?",
        [
            $data['signer_name'],
            $data['signer_email'],
            $data['signer_title'] ?? null,
            $data['signer_phone'] ?? null,
            $data['user_id'],
            $data['ip_address'],
            $data['signature_hash'],
            $data['certification_text'],
            $docId
        ]
    );
}

/**
 * Generera signatur-hash för Sambruks kontrasignering
 *
 * @param string $fileHash PDF-filens SHA-256-hash
 * @param string $name Undertecknarens namn
 * @param string $email Undertecknarens e-post
 * @param string $signedAt Signeringstidpunkt (Y-m-d H:i:s)
 * @param string $ip IP-adress
 * @return string SHA-256-hash
 */
function generateSambrukSignatureHash($fileHash, $name, $email, $signedAt, $ip) {
    return hash('sha256', implode('|', [$fileHash, $name, $email, $signedAt, $ip]));
}

/**
 * Hämta alla unika organisationstaggar för en domän
 *
 * @param string $domain Domännamnet
 * @return array Lista med unika org-taggar
 */
function getOrgTagsForDomain($domain) {
    return query(
        "SELECT DISTINCT uot.tag
         FROM " . DB_DATABASE . ".user_org_tags uot
         JOIN " . DB_DATABASE . ".users u ON uot.user_id = u.id
         WHERE u.email LIKE ?
         ORDER BY uot.tag ASC",
        ['%@' . $domain]
    );
}

/**
 * Hämta en användares organisationstaggar
 *
 * @param int $userId Användarens ID
 * @return array Lista med org-taggar
 */
function getUserOrgTags($userId) {
    return query(
        "SELECT tag FROM " . DB_DATABASE . ".user_org_tags WHERE user_id = ? ORDER BY tag ASC",
        [$userId]
    );
}

/**
 * Registrera en användare i en stegvis kurs.
 * Skapar schedule-rader för alla lektioner (lektion 1 = tillgänglig, resten NULL).
 * Skapar även en course_enrollment om den inte redan finns.
 *
 * @param int $userId Användarens ID
 * @param int $courseId Kursens ID
 * @param string|null $startDate Valfritt startdatum (Y-m-d H:i:s eller Y-m-d). Default: nu.
 * @return bool True om det lyckades
 */
function enrollUserInSequentialCourse($userId, $courseId, $startDate = null) {
    // Kontrollera om redan inskriven
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".sequential_lesson_schedule WHERE user_id = ? AND course_id = ? LIMIT 1",
        [$userId, $courseId]
    );
    if ($existing) {
        return true; // Redan inskriven
    }

    // Hämta bara lektioner (inte infosidor) sorterade — infosidor ingår i
    // innehållet men har inga egna schedule-rader; deras tillgänglighet
    // härleds från den lektion de tillhör (eller är alltid tillgängliga om
    // fristående, typ en välkomstsida).
    $lessons = query(
        "SELECT id FROM " . DB_DATABASE . ".lessons
         WHERE course_id = ? AND lesson_type = 'lesson'
         ORDER BY sort_order ASC",
        [$courseId]
    );

    if (empty($lessons)) {
        return false;
    }

    // Skapa schedule-rader
    $effectiveStart = $startDate ? date('Y-m-d H:i:s', strtotime($startDate)) : date('Y-m-d H:i:s');
    foreach ($lessons as $index => $lesson) {
        $availableAt = ($index === 0) ? $effectiveStart : null;
        execute(
            "INSERT IGNORE INTO " . DB_DATABASE . ".sequential_lesson_schedule
             (user_id, course_id, lesson_id, available_at)
             VALUES (?, ?, ?, ?)",
            [$userId, $courseId, $lesson['id'], $availableAt]
        );
    }

    // Skapa course_enrollment om den inte finns
    $enrollment = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".course_enrollments WHERE user_id = ? AND course_id = ?",
        [$userId, $courseId]
    );
    if (!$enrollment) {
        execute(
            "INSERT INTO " . DB_DATABASE . ".course_enrollments (user_id, course_id, status, started_at) VALUES (?, ?, 'active', ?)",
            [$userId, $courseId, $effectiveStart]
        );
    }

    return true;
}

// =============================================================================
// Publika kurser (migration 025)
//
// En organisation kan publicera en kurs så att externa användare kan registrera
// sig via en unik länk. Registrerade användare får åtkomst ENDAST till den kurs
// de registrerat sig för. Samma e-post kan kopplas till flera publika kurser
// över olika organisationer via rader i public_course_access.
// =============================================================================

/**
 * Generera och spara ett nytt publikt registreringstoken för en kurs.
 * Skriver över ev. tidigare token — den gamla länken slutar omedelbart fungera.
 *
 * @param int $courseId
 * @return string Det nya token-värdet (64 hex-tecken)
 */
function generatePublicRegistrationToken($courseId) {
    $token = bin2hex(random_bytes(32));
    execute(
        "UPDATE " . DB_DATABASE . ".courses SET public_registration_token = ? WHERE id = ?",
        [$token, (int)$courseId]
    );
    return $token;
}

/**
 * Validera ett publikt registreringstoken mot en kurs.
 *
 * @param int $courseId
 * @param string $token
 * @return array|null Kursrad om allt är giltigt, annars null
 */
function validatePublicRegistrationToken($courseId, $token) {
    if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $course = queryOne(
        "SELECT * FROM " . DB_DATABASE . ".courses
         WHERE id = ? AND is_public = 1 AND public_registration_token = ? LIMIT 1",
        [(int)$courseId, $token]
    );
    return $course ?: null;
}

/**
 * Ge en användare tillgång till en publik kurs. INSERT IGNORE på
 * public_course_access + enroll enligt kursens typ (stegvis vs bulk_start).
 * Idempotent — om användaren redan har access händer inget skadligt.
 *
 * @param int $userId
 * @param int $courseId
 * @return bool True om allt gick vägen
 */
function grantPublicCourseAccess($userId, $courseId) {
    $course = queryOne(
        "SELECT id, organization_domain, sequential_mode, is_public FROM " . DB_DATABASE . ".courses WHERE id = ? LIMIT 1",
        [(int)$courseId]
    );
    if (!$course) {
        return false;
    }

    // Slå upp organization_id via kursens organization_domain om den är grupperad.
    $orgId = null;
    if (!empty($course['organization_domain'])) {
        $org = getOrganizationByDomain($course['organization_domain']);
        $orgId = $org ? (int)$org['id'] : null;
    }

    execute(
        "INSERT IGNORE INTO " . DB_DATABASE . ".public_course_access
         (user_id, course_id, organization_id) VALUES (?, ?, ?)",
        [(int)$userId, (int)$courseId, $orgId]
    );

    // Anmäl till kursen enligt dess typ
    if (!empty($course['sequential_mode'])) {
        enrollUserInSequentialCourse((int)$userId, (int)$courseId, date('Y-m-d H:i:s'));
    } else {
        // Bulk_start / icke-stegvis: en course_enrollments-rad räcker
        $existing = queryOne(
            "SELECT id FROM " . DB_DATABASE . ".course_enrollments WHERE user_id = ? AND course_id = ?",
            [(int)$userId, (int)$courseId]
        );
        if (!$existing) {
            execute(
                "INSERT INTO " . DB_DATABASE . ".course_enrollments (user_id, course_id, status, started_at) VALUES (?, ?, 'active', NOW())",
                [(int)$userId, (int)$courseId]
            );
        }
    }

    return true;
}

/**
 * Kontrollera om en användare har publik access till en viss kurs.
 *
 * @param int $userId
 * @param int $courseId
 * @return bool
 */
function hasPublicCourseAccess($userId, $courseId) {
    $row = queryOne(
        "SELECT 1 FROM " . DB_DATABASE . ".public_course_access WHERE user_id = ? AND course_id = ? LIMIT 1",
        [(int)$userId, (int)$courseId]
    );
    return $row !== null && $row !== false;
}

/**
 * Kontrollera om användaren har rättighet att se en specifik kurs —
 * samma logik som i course.php (domän- eller publik åtkomst, respekterar
 * course_shared_domains).
 *
 * @param int $userId
 * @param int $courseId
 * @return bool
 */
function userCanAccessCourse($userId, $courseId) {
    $user = queryOne(
        "SELECT email, access_mode FROM " . DB_DATABASE . ".users WHERE id = ? LIMIT 1",
        [(int)$userId]
    );
    if (!$user) return false;

    if (($user['access_mode'] ?? 'domain') === 'public_only') {
        return hasPublicCourseAccess($userId, $courseId);
    }

    $course = queryOne(
        "SELECT organization_domain FROM " . DB_DATABASE . ".courses WHERE id = ? LIMIT 1",
        [(int)$courseId]
    );
    if (!$course) return false;

    $orgScope = getOrgScopeDomains($user['email']);
    $inOwnOrg = in_array($course['organization_domain'], $orgScope, true);

    if ($inOwnOrg) {
        $sharedDoms = getCourseSharedDomains($courseId);
        if (!empty($sharedDoms)) {
            $userDomain = getUserDomain($user['email']);
            if (!in_array($userDomain, $sharedDoms, true)) {
                $inOwnOrg = false;
            }
        }
    }

    return $inOwnOrg || hasPublicCourseAccess($userId, $courseId);
}

/**
 * Returnera kurs-IDs som användaren har publik åtkomst till.
 *
 * @param int $userId
 * @return int[]
 */
function getPublicCourseIdsForUser($userId) {
    $rows = query(
        "SELECT course_id FROM " . DB_DATABASE . ".public_course_access WHERE user_id = ?",
        [(int)$userId]
    );
    return array_map('intval', array_column($rows ?: [], 'course_id'));
}

/**
 * Bygg det kompletta synlighetsfragmentet för kurser för en given användare.
 *
 * Detta är den enda platsen där kurssynligheten definieras. index.php byggde
 * förr samma regler inline; sedan 2026-08-25 anropar även den hit, så en ändring
 * av synligheten görs på ett ställe och slår igenom i både kurskatalogen och
 * lärvägarna.
 *
 * Regler:
 *   public_only-användare  → ENDAST kurser från public_course_access
 *   domain-användare       → ( kursens organization_domain i org-scope
 *                              OCH org-tagg-överlapp (otaggad kurs syns för alla)
 *                              OCH shared-domain-filter )
 *                            ELLER publik access ELLER is_global = 1
 *
 * @param int $userId
 * @param string $alias Tabellalias för courses i den anropande queryn
 * @return array{fragment:string, params:array, is_public_only:bool,
 *               org_scope_domains:array, public_course_ids:array}
 */
function buildCourseVisibilityClause($userId, $alias = 'c') {
    $userId = (int)$userId;
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 'c';
    }

    $user = queryOne(
        "SELECT email, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
        [$userId]
    );
    if (!$user) {
        return [
            'fragment' => "$a.id IN (NULL)",
            'params' => [],
            'is_public_only' => false,
            'org_scope_domains' => [],
            'public_course_ids' => [],
        ];
    }

    $publicCourseIds = getPublicCourseIdsForUser($userId);
    $isPublicOnly = ($user['access_mode'] ?? 'domain') === 'public_only';

    if ($isPublicOnly) {
        // public_only-användare ser ENDAST kurser de registrerat sig för.
        $fragment = empty($publicCourseIds)
            ? "$a.id IN (NULL)"
            : "$a.id IN (" . implode(',', array_fill(0, count($publicCourseIds), '?')) . ")";
        return [
            'fragment' => $fragment,
            'params' => $publicCourseIds,
            'is_public_only' => true,
            'org_scope_domains' => [],
            'public_course_ids' => $publicCourseIds,
        ];
    }

    $userDomain = getUserDomain($user['email']);
    $orgScopeDomains = getOrgScopeDomains($user['email']);
    $domainClause = buildDomainInClause($orgScopeDomains, "$a.organization_domain");

    // Org-taggar: otaggad kurs syns för alla i scopet, taggad kurs kräver
    // överlapp med användarens taggar. Saknar användaren taggar ser hen bara
    // otaggade kurser.
    $userOrgTagValues = array_column(getUserOrgTags($userId), 'tag');
    if (!empty($userOrgTagValues)) {
        $tagPlaceholders = implode(',', array_fill(0, count($userOrgTagValues), '?'));
        $orgTagFilter = "AND (
            NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot WHERE cot.course_id = $a.id)
            OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot WHERE cot.course_id = $a.id AND cot.tag IN ($tagPlaceholders))
        )";
        $orgTagParams = $userOrgTagValues;
    } else {
        $orgTagFilter = "AND NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot WHERE cot.course_id = $a.id)";
        $orgTagParams = [];
    }

    // Kurs utan rader i course_shared_domains delas med hela organisationen.
    $sharedDomainFilter = "AND (
        NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd WHERE csd.course_id = $a.id)
        OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd WHERE csd.course_id = $a.id AND csd.domain = ?)
    )";
    $sharedDomainParams = [$userDomain];

    $scoped = "({$domainClause['fragment']} $orgTagFilter $sharedDomainFilter)";
    $params = array_merge($domainClause['params'], $orgTagParams, $sharedDomainParams);

    if (!empty($publicCourseIds)) {
        $publicPlaceholders = implode(',', array_fill(0, count($publicCourseIds), '?'));
        $fragment = "($scoped OR $a.id IN ($publicPlaceholders) OR $a.is_global = 1)";
        $params = array_merge($params, $publicCourseIds);
    } else {
        $fragment = "($scoped OR $a.is_global = 1)";
    }

    return [
        'fragment' => $fragment,
        'params' => $params,
        'is_public_only' => false,
        'org_scope_domains' => $orgScopeDomains,
        'public_course_ids' => $publicCourseIds,
    ];
}

/**
 * Batch-beräkna kursprogress för M användare × N kurser. Två queries totalt,
 * oavsett hur många användare och kurser som efterfrågas.
 *
 * Nämnaren är lessons.status='active' oavsett lesson_type — exakt samma
 * definition som checkAndCompleteCourse() i gamification.php använder. Därmed
 * gäller att 100 % ⇔ kursen är klar (och diplom utfärdat om kriterierna
 * uppfylls). Räknar man bort infosidor kan en kurs visa 100 % utan diplom.
 *
 * @param int[] $userIds
 * @param int[] $courseIds
 * @return array [userId][courseId] => ['total'=>int,'done'=>int,'percent'=>int]
 *               Tät matris: alla efterfrågade kombinationer finns med.
 */
function getCourseProgressForUsers(array $userIds, array $courseIds) {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $courseIds = array_values(array_unique(array_map('intval', $courseIds)));

    $result = [];
    if (empty($userIds) || empty($courseIds)) {
        return $result;
    }

    $coursePlaceholders = implode(',', array_fill(0, count($courseIds), '?'));

    // 1) Antal aktiva lektioner per kurs
    $totals = [];
    $totalRows = query(
        "SELECT course_id, COUNT(*) AS n
         FROM " . DB_DATABASE . ".lessons
         WHERE course_id IN ($coursePlaceholders) AND status = 'active'
         GROUP BY course_id",
        $courseIds
    );
    foreach ($totalRows ?: [] as $row) {
        $totals[(int)$row['course_id']] = (int)$row['n'];
    }

    // 2) Antal avklarade lektioner per (användare, kurs)
    $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
    $doneRows = query(
        "SELECT l.course_id, p.user_id, COUNT(DISTINCT p.lesson_id) AS n
         FROM " . DB_DATABASE . ".progress p
         JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
         WHERE l.course_id IN ($coursePlaceholders)
           AND l.status = 'active'
           AND p.user_id IN ($userPlaceholders)
           AND p.status = 'completed'
         GROUP BY l.course_id, p.user_id",
        array_merge($courseIds, $userIds)
    );
    $done = [];
    foreach ($doneRows ?: [] as $row) {
        $done[(int)$row['user_id']][(int)$row['course_id']] = (int)$row['n'];
    }

    foreach ($userIds as $uid) {
        foreach ($courseIds as $cid) {
            $total = $totals[$cid] ?? 0;
            $completed = $done[$uid][$cid] ?? 0;
            if ($completed > $total) {
                // Kan inträffa om lektioner inaktiverats efter att de klarats.
                $completed = $total;
            }
            $result[$uid][$cid] = [
                'total' => $total,
                'done' => $completed,
                'percent' => $total > 0 ? (int)round($completed / $total * 100) : 0,
            ];
        }
    }

    return $result;
}

/**
 * Rensa ALL data för en användare i en specifik publik kurs. Gemensam för
 * självradering (leave_public_course.php) och admin-bulk-delete. Rör INTE
 * users-raden — det sköts av maybeDeleteOrphanPublicUser() efter sista access
 * är borta.
 *
 * Lärvägar berörs inte: de tilldelas implicit och äger ingen per-användardata
 * (se migrations/044_learning_paths.sql, designbeslut 2).
 *
 * Körs i transaktion.
 *
 * @param int $userId
 * @param int $courseId
 * @return void
 */
function purgePublicCourseUserData($userId, $courseId) {
    $userId = (int)$userId;
    $courseId = (int)$courseId;

    execute("START TRANSACTION");
    try {
        // Per-lektion progress för den här kursens lektioner
        execute(
            "DELETE p FROM " . DB_DATABASE . ".progress p
             JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
             WHERE p.user_id = ? AND l.course_id = ?",
            [$userId, $courseId]
        );
        execute(
            "DELETE FROM " . DB_DATABASE . ".sequential_lesson_schedule WHERE user_id = ? AND course_id = ?",
            [$userId, $courseId]
        );
        execute(
            "DELETE FROM " . DB_DATABASE . ".course_enrollments WHERE user_id = ? AND course_id = ?",
            [$userId, $courseId]
        );
        // sequential_reminder_log och reminder_log finns kanske inte alltid — try/catch
        try {
            execute(
                "DELETE FROM " . DB_DATABASE . ".sequential_reminder_log WHERE user_id = ? AND course_id = ?",
                [$userId, $courseId]
            );
        } catch (Exception $e) { /* tabell finns ev. inte */ }
        try {
            execute(
                "DELETE FROM " . DB_DATABASE . ".reminder_log WHERE user_id = ? AND course_id = ?",
                [$userId, $courseId]
            );
        } catch (Exception $e) { /* tabell finns ev. inte */ }
        execute(
            "DELETE FROM " . DB_DATABASE . ".public_course_access WHERE user_id = ? AND course_id = ?",
            [$userId, $courseId]
        );
        execute("COMMIT");
    } catch (Exception $e) {
        execute("ROLLBACK");
        throw $e;
    }
}

/**
 * Om en användare har access_mode='public_only' OCH saknar kvarvarande rader
 * i public_course_access, radera användaren helt (med full cascade via FK).
 *
 * @param int $userId
 * @return bool True om användaren raderades
 */
function maybeDeleteOrphanPublicUser($userId) {
    $user = queryOne(
        "SELECT id, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
        [(int)$userId]
    );
    if (!$user || $user['access_mode'] !== 'public_only') {
        return false;
    }
    $remaining = queryOne(
        "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".public_course_access WHERE user_id = ?",
        [(int)$userId]
    );
    if ((int)($remaining['c'] ?? 0) > 0) {
        return false;
    }
    // Städa upp rester som inte är FK-cascade-skyddade
    try { execute("DELETE FROM " . DB_DATABASE . ".remember_tokens WHERE user_id = ?", [(int)$userId]); } catch (Exception $e) {}
    try { execute("DELETE FROM " . DB_DATABASE . ".user_org_tags WHERE user_id = ?", [(int)$userId]); } catch (Exception $e) {}
    execute("DELETE FROM " . DB_DATABASE . ".users WHERE id = ?", [(int)$userId]);
    return true;
}

/**
 * Beräkna beräknat slutdatum för en användare i en stegvis kurs.
 *
 * @param string $startedAt Startdatum (Y-m-d eller datetime)
 * @param int $lessonCount Antal lektioner i kursen
 * @param int $intervalDays Dagar mellan lektioner
 * @return string|null Beräknat slutdatum (Y-m-d) eller null
 */
function getProjectedEndDate($startedAt, $lessonCount, $intervalDays) {
    if (!$startedAt || $lessonCount <= 0) return null;
    $totalDays = ($lessonCount - 1) * $intervalDays;
    return date('Y-m-d', strtotime($startedAt) + $totalDays * 86400);
}

/**
 * Hämta senaste tillgängliga lektionsdatum för en användare i en stegvis kurs.
 *
 * @param int $userId Användarens ID
 * @param int $courseId Kursens ID
 * @return string|null Senaste available_at (datetime) eller null
 */
function getLatestAvailableLessonDate($userId, $courseId) {
    $row = queryOne(
        "SELECT MAX(available_at) AS latest FROM " . DB_DATABASE . ".sequential_lesson_schedule
         WHERE user_id = ? AND course_id = ? AND available_at <= NOW()",
        [$userId, $courseId]
    );
    return $row ? $row['latest'] : null;
}

/**
 * Lås upp nästa lektion i en stegvis kurs efter att en lektion slutförts.
 * Sätter completed_at på den avklarade lektionen och available_at på nästa.
 *
 * @param int $userId Användarens ID
 * @param int $courseId Kursens ID
 * @param int $completedLessonId ID för den just avklarade lektionen
 * @return void
 */
function unlockNextSequentialLesson($userId, $courseId, $completedLessonId) {
    // Markera lektionen som avklarad
    execute(
        "UPDATE " . DB_DATABASE . ".sequential_lesson_schedule
         SET completed_at = NOW()
         WHERE user_id = ? AND course_id = ? AND lesson_id = ? AND completed_at IS NULL",
        [$userId, $courseId, $completedLessonId]
    );

    // Hämta kursens intervall
    $course = queryOne(
        "SELECT sequential_interval_days FROM " . DB_DATABASE . ".courses WHERE id = ?",
        [$courseId]
    );
    $intervalDays = $course ? (int)$course['sequential_interval_days'] : 7;

    // Hitta nästa lektion (via sort_order) — hoppa över infosidor; de har
    // inga schedule-rader och låses upp indirekt via sin parent-lektion.
    $nextLesson = queryOne(
        "SELECT l.id FROM " . DB_DATABASE . ".lessons l
         WHERE l.course_id = ? AND l.lesson_type = 'lesson' AND l.sort_order > (
             SELECT l2.sort_order FROM " . DB_DATABASE . ".lessons l2 WHERE l2.id = ?
         )
         ORDER BY l.sort_order ASC LIMIT 1",
        [$courseId, $completedLessonId]
    );

    if ($nextLesson) {
        // Sätt available_at = nu + interval dagar
        execute(
            "UPDATE " . DB_DATABASE . ".sequential_lesson_schedule
             SET available_at = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE user_id = ? AND course_id = ? AND lesson_id = ? AND available_at IS NULL",
            [$intervalDays, $userId, $courseId, $nextLesson['id']]
        );
    }
}

/**
 * Kontrollera om en lektion är tillgänglig för en användare.
 * Returnerar true om kursen inte är stegvis, eller om available_at <= NOW().
 *
 * @param int $userId Användarens ID
 * @param int $lessonId Lektionens ID
 * @param int $courseId Kursens ID
 * @return bool True om lektionen är tillgänglig
 */
function isLessonAvailableForUser($userId, $lessonId, $courseId) {
    // Kontrollera om kursen är stegvis
    $course = queryOne(
        "SELECT sequential_mode FROM " . DB_DATABASE . ".courses WHERE id = ?",
        [$courseId]
    );
    if (!$course || !$course['sequential_mode']) {
        return true; // Ej stegvis - alltid tillgänglig
    }

    // För infosidor: härled tillgängligheten från den lektion sidan tillhör.
    // Fristående infosidor (belongs_to_lesson_id = NULL) är alltid
    // tillgängliga så snart användaren har kursåtkomst.
    $lessonRow = queryOne(
        "SELECT lesson_type, belongs_to_lesson_id FROM " . DB_DATABASE . ".lessons WHERE id = ?",
        [$lessonId]
    );
    if ($lessonRow && ($lessonRow['lesson_type'] ?? 'lesson') === 'info_page') {
        if (empty($lessonRow['belongs_to_lesson_id'])) {
            return true;
        }
        return isLessonAvailableForUser($userId, (int)$lessonRow['belongs_to_lesson_id'], $courseId);
    }

    // Kontrollera schedule
    $schedule = queryOne(
        "SELECT available_at FROM " . DB_DATABASE . ".sequential_lesson_schedule
         WHERE user_id = ? AND lesson_id = ?",
        [$userId, $lessonId]
    );

    if (!$schedule) {
        return false; // Ingen schedule-rad = inte inskriven ännu
    }

    if ($schedule['available_at'] === null) {
        return false; // Ej upplåst ännu
    }

    return strtotime($schedule['available_at']) <= time();
}

/**
 * Returnerar ID:t för den "ingång" (sida) som hör till en given lektion — dvs
 * den första infosidan som tillhör lektionen och ligger före den i sort_order,
 * eller själva lektionens ID om inga sådana infosidor finns. Används för att
 * bygga länkar i stegvis-e-post så att användaren kommer in via sin ev.
 * intro-infosida istället för direkt på själva lektionen.
 *
 * @param int $courseId
 * @param int $lessonId
 * @return int Entry page-id
 */
function getSequentialEntryPageForLesson($courseId, $lessonId) {
    $lesson = queryOne(
        "SELECT sort_order FROM " . DB_DATABASE . ".lessons WHERE id = ?",
        [$lessonId]
    );
    if (!$lesson) return (int)$lessonId;

    $firstInfo = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".lessons
         WHERE course_id = ? AND lesson_type = 'info_page'
           AND belongs_to_lesson_id = ? AND sort_order < ?
           AND status = 'active'
         ORDER BY sort_order ASC LIMIT 1",
        [$courseId, $lessonId, $lesson['sort_order']]
    );
    return $firstInfo ? (int)$firstInfo['id'] : (int)$lessonId;
}

/**
 * Hämta status för alla lektioner i en stegvis kurs för en användare.
 *
 * @param int $userId Användarens ID
 * @param int $courseId Kursens ID
 * @return array Lista med schedule-rader JOINade med lektionsdata
 */
function getSequentialCourseStatusForUser($userId, $courseId) {
    return query(
        "SELECT sls.*, l.title as lesson_title, l.sort_order
         FROM " . DB_DATABASE . ".sequential_lesson_schedule sls
         JOIN " . DB_DATABASE . ".lessons l ON sls.lesson_id = l.id
         WHERE sls.user_id = ? AND sls.course_id = ?
         ORDER BY l.sort_order ASC",
        [$userId, $courseId]
    );
}

/**
 * Ersätt {{variabel}}-platshållare i en malltext med värden.
 *
 * @param string $template Malltext med {{variabel}}-platshållare
 * @param array $vars Associativ array med variabelnamn => värde
 * @return string Renderad text
 */
function renderSequentialEmailTemplate($template, $vars) {
    foreach ($vars as $key => $value) {
        $template = str_replace('{{' . $key . '}}', $value ?? '', $template);
    }
    return $template;
}

/**
 * Lägg till e-post i sequential_email_queue med dubblettkontroll.
 *
 * @param int $courseId Kursens ID
 * @param array $userLessonPairs Array av ['user_id' => int, 'lesson_id' => int]
 * @param string $emailType 'new_lesson' eller 'reminder'
 * @return int Antal tillagda rader
 */
function queueSequentialEmails($courseId, $userLessonPairs, $emailType) {
    $added = 0;
    foreach ($userLessonPairs as $pair) {
        // Dubblettkontroll
        $existing = queryOne(
            "SELECT id FROM " . DB_DATABASE . ".sequential_email_queue
             WHERE user_id = ? AND course_id = ? AND lesson_id = ? AND email_type = ? AND status IN ('queued','sending')",
            [$pair['user_id'], $courseId, $pair['lesson_id'], $emailType]
        );
        if ($existing) {
            continue;
        }
        execute(
            "INSERT INTO " . DB_DATABASE . ".sequential_email_queue
             (user_id, course_id, lesson_id, email_type, scheduled_at, status)
             VALUES (?, ?, ?, ?, NOW(), 'queued')",
            [$pair['user_id'], $courseId, $pair['lesson_id'], $emailType]
        );
        $added++;
    }
    return $added;
}

/**
 * Bearbeta e-postkön i batchar med throttling.
 *
 * @param int|null $courseId Begränsa till specifik kurs, null = alla
 * @param int $batchSize Antal e-post per batch
 * @param int $delaySeconds Sekunder mellan batchar
 * @param callable|null $logCallback Loggfunktion, t.ex. function($msg) { echo $msg; }
 * @return array ['sent' => int, 'failed' => int]
 */
function processEmailQueue($courseId, $batchSize = 10, $delaySeconds = 30, $logCallback = null) {
    require_once __DIR__ . '/mail.php';

    $systemUrl = rtrim(getenv('SYSTEM_URL') ?: 'https://stimma.sambruk.se', '/');
    $systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
    $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se';
    $mailFromName = trim(getenv('MAIL_FROM_NAME'), '"\'') ?: 'Stimma';

    $log = $logCallback ?: function($msg) {};

    $sent = 0;
    $failed = 0;
    $batchNum = 0;

    while (true) {
        // Hämta nästa batch
        $whereClause = "q.status = 'queued' AND q.scheduled_at <= NOW()";
        $params = [];
        if ($courseId !== null) {
            $whereClause .= " AND q.course_id = ?";
            $params[] = $courseId;
        }

        $items = query(
            "SELECT q.*, u.email, u.name AS user_name,
                    l.title AS lesson_title, l.sort_order,
                    c.title AS course_title, c.deadline,
                    c.seq_new_lesson_subject, c.seq_new_lesson_body,
                    c.seq_reminder_subject, c.seq_reminder_body,
                    (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons WHERE course_id = c.id) AS total_lessons
             FROM " . DB_DATABASE . ".sequential_email_queue q
             JOIN " . DB_DATABASE . ".users u ON q.user_id = u.id
             JOIN " . DB_DATABASE . ".lessons l ON q.lesson_id = l.id
             JOIN " . DB_DATABASE . ".courses c ON q.course_id = c.id
             WHERE $whereClause
             ORDER BY q.scheduled_at ASC
             LIMIT $batchSize",
            $params
        );

        if (empty($items)) {
            break;
        }

        $batchNum++;
        $log("Batch $batchNum: bearbetar " . count($items) . " e-post...");

        foreach ($items as $item) {
            // Markera som 'sending'
            execute(
                "UPDATE " . DB_DATABASE . ".sequential_email_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ?",
                [$item['id']]
            );

            // Länka till "entry page" för lektionen — om det finns en
            // infosida som tillhör lektionen och ligger före den i
            // sort_order, använd den istället (så användaren går via
            // intro-sidan). Annars direkt till lektionen.
            $entryLessonId = getSequentialEntryPageForLesson($item['course_id'], $item['lesson_id']);
            $lessonUrl = $systemUrl . '/lesson.php?id=' . $entryLessonId;
            $courseUrl = $systemUrl . '/course.php?id=' . $item['course_id'];
            $userName = $item['user_name'] ?: 'användare';
            $lessonNumber = $item['sort_order'] ?? 1;

            // Beräkna deadline-info
            $deadlineFormatted = '';
            $daysRemaining = '';
            if ($item['deadline']) {
                $months = ['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'];
                $dt = new DateTime($item['deadline']);
                $deadlineFormatted = $dt->format('j') . ' ' . $months[$dt->format('n') - 1] . ' ' . $dt->format('Y');
                $daysRemaining = (string)max(0, (int)((new DateTime($item['deadline']))->getTimestamp() - time()) / 86400);
            }

            // Mallvariabler
            $vars = [
                'user_name' => htmlspecialchars($userName),
                'user_email' => htmlspecialchars($item['email']),
                'course_title' => htmlspecialchars($item['course_title']),
                'lesson_title' => htmlspecialchars($item['lesson_title']),
                'lesson_url' => htmlspecialchars($lessonUrl),
                'lesson_number' => $lessonNumber,
                'total_lessons' => $item['total_lessons'],
                'course_url' => htmlspecialchars($courseUrl),
                'deadline' => $deadlineFormatted,
                'days_remaining' => $daysRemaining,
                'system_name' => htmlspecialchars($systemName),
            ];

            // Välj mall baserat på typ
            if ($item['email_type'] === 'new_lesson') {
                $templateSubject = $item['seq_new_lesson_subject'];
                $templateBody = $item['seq_new_lesson_body'];
            } else {
                $templateSubject = $item['seq_reminder_subject'];
                $templateBody = $item['seq_reminder_body'];
            }

            // Bygg e-post
            if ($templateSubject && $templateBody) {
                $subject = renderSequentialEmailTemplate($templateSubject, $vars);
                $bodyText = renderSequentialEmailTemplate($templateBody, $vars);
                $htmlMessage = buildSequentialEmailHtml($bodyText, $systemName);
            } else {
                // Fallback till standardmall
                $htmlMessage = buildDefaultSequentialEmailHtml($item, $vars, $systemName, $systemUrl);
                if ($item['email_type'] === 'new_lesson') {
                    $subject = "Ny lektion tillgänglig: " . $item['lesson_title'];
                } else {
                    $subject = "Påminnelse: " . $item['lesson_title'] . " väntar på dig";
                }
            }

            $mailSent = sendSmtpMail($item['email'], $subject, $htmlMessage, $mailFrom, $mailFromName);

            if ($mailSent) {
                execute(
                    "UPDATE " . DB_DATABASE . ".sequential_email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?",
                    [$item['id']]
                );
                $sent++;

                // Uppdatera schedule
                if ($item['email_type'] === 'new_lesson') {
                    execute(
                        "UPDATE " . DB_DATABASE . ".sequential_lesson_schedule SET notified_at = NOW()
                         WHERE user_id = ? AND course_id = ? AND lesson_id = ? AND notified_at IS NULL",
                        [$item['user_id'], $item['course_id'], $item['lesson_id']]
                    );
                } else {
                    execute(
                        "UPDATE " . DB_DATABASE . ".sequential_lesson_schedule SET reminded_at = NOW()
                         WHERE user_id = ? AND course_id = ? AND lesson_id = ? AND reminded_at IS NULL",
                        [$item['user_id'], $item['course_id'], $item['lesson_id']]
                    );
                }

                // Logga i sequential_reminder_log
                execute(
                    "INSERT INTO " . DB_DATABASE . ".sequential_reminder_log (user_id, course_id, lesson_id, type, email_status)
                     VALUES (?, ?, ?, ?, 'sent')",
                    [$item['user_id'], $item['course_id'], $item['lesson_id'], $item['email_type']]
                );

                $log("Skickat {$item['email_type']} till {$item['email']}: {$item['lesson_title']}");
            } else {
                $errorMsg = 'E-post kunde inte skickas';
                execute(
                    "UPDATE " . DB_DATABASE . ".sequential_email_queue SET status = 'failed', error_message = ? WHERE id = ?",
                    [$errorMsg, $item['id']]
                );
                $failed++;

                execute(
                    "INSERT INTO " . DB_DATABASE . ".sequential_reminder_log (user_id, course_id, lesson_id, type, email_status, error_message)
                     VALUES (?, ?, ?, ?, 'failed', ?)",
                    [$item['user_id'], $item['course_id'], $item['lesson_id'], $item['email_type'], $errorMsg]
                );

                $log("FEL: Kunde inte skicka {$item['email_type']} till {$item['email']}");
            }
        }

        // Delay mellan batchar om det finns fler
        $remaining = queryOne(
            "SELECT COUNT(*) AS cnt FROM " . DB_DATABASE . ".sequential_email_queue
             WHERE status = 'queued' AND scheduled_at <= NOW()" .
            ($courseId !== null ? " AND course_id = " . (int)$courseId : ""),
            []
        );
        if ($remaining && $remaining['cnt'] > 0 && $delaySeconds > 0) {
            $log("Väntar {$delaySeconds}s innan nästa batch ({$remaining['cnt']} kvar)...");
            sleep($delaySeconds);
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Bygg HTML-wrapper för e-post med anpassad brödtext.
 *
 * @param string $bodyText Brödtext (kan innehålla HTML)
 * @param string $systemName Systemets namn
 * @return string Fullständigt HTML-mail
 */
function buildSequentialEmailHtml($bodyText, $systemName) {
    // Konvertera newlines till <br> om texten inte redan innehåller HTML-taggar
    if (strip_tags($bodyText) === $bodyText) {
        $bodyText = nl2br($bodyText);
    }

    return "
    <!DOCTYPE html>
    <html lang='sv'>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
            <h1 style='color: #007bff; margin: 0 0 10px 0; font-size: 24px;'>" . htmlspecialchars($systemName) . "</h1>
        </div>
        <div style='padding: 20px 0;'>
            $bodyText
        </div>
        <div style='border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 20px; color: #6c757d; font-size: 12px;'>
            <p>Detta är ett automatiskt meddelande från " . htmlspecialchars($systemName) . ".</p>
        </div>
    </body>
    </html>";
}

/**
 * Bygg standard-HTML-mail (fallback när inga mallar konfigurerats).
 * Bevarar exakt samma HTML som det ursprungliga cron-skriptet.
 *
 * @param array $item Körad med e-post- och kursdata
 * @param array $vars Mallvariabler
 * @param string $systemName Systemets namn
 * @param string $systemUrl Systemets URL
 * @return string Fullständigt HTML-mail
 */
function buildDefaultSequentialEmailHtml($item, $vars, $systemName, $systemUrl) {
    $entryLessonId = getSequentialEntryPageForLesson($item['course_id'], $item['lesson_id']);
    $lessonUrl = $systemUrl . '/lesson.php?id=' . $entryLessonId;
    $userName = $item['user_name'] ?: 'användare';

    if ($item['email_type'] === 'new_lesson') {
        return "
        <!DOCTYPE html>
        <html lang='sv'>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
                <h1 style='color: #007bff; margin: 0 0 10px 0; font-size: 24px;'>$systemName</h1>
                <p style='margin: 0; color: #6c757d;'>Ny lektion tillgänglig</p>
            </div>
            <div style='padding: 20px 0;'>
                <p>Hej " . htmlspecialchars($userName) . "!</p>
                <p>En ny lektion i kursen <strong>" . htmlspecialchars($item['course_title']) . "</strong> är nu tillgänglig för dig:</p>
                <div style='background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                    <strong style='color: #155724;'>" . htmlspecialchars($item['lesson_title']) . "</strong>
                </div>
                <p>
                    <a href='" . htmlspecialchars($lessonUrl) . "' style='display: inline-block; background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold;'>Gå till lektionen</a>
                </p>
            </div>
            <div style='border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 20px; color: #6c757d; font-size: 12px;'>
                <p>Detta är ett automatiskt meddelande från $systemName.</p>
            </div>
        </body>
        </html>";
    } else {
        return "
        <!DOCTYPE html>
        <html lang='sv'>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
                <h1 style='color: #007bff; margin: 0 0 10px 0; font-size: 24px;'>$systemName</h1>
                <p style='margin: 0; color: #6c757d;'>Påminnelse</p>
            </div>
            <div style='padding: 20px 0;'>
                <p>Hej " . htmlspecialchars($userName) . "!</p>
                <p>Du har en lektion i kursen <strong>" . htmlspecialchars($item['course_title']) . "</strong> som väntar på dig:</p>
                <div style='background-color: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                    <strong style='color: #856404;'>" . htmlspecialchars($item['lesson_title']) . "</strong>
                </div>
                <p>
                    <a href='" . htmlspecialchars($lessonUrl) . "' style='display: inline-block; background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold;'>Gå till lektionen</a>
                </p>
            </div>
            <div style='border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 20px; color: #6c757d; font-size: 12px;'>
                <p>Detta är ett automatiskt meddelande från $systemName.</p>
            </div>
        </body>
        </html>";
    }
}

function sendPermissionChangeNotification($userEmail, $changeType, $newStatus, $changedByEmail) {
    require_once __DIR__ . '/mail.php';

    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Stimma';
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';

    // Bestäm rollnamn på svenska
    $roleNames = [
        'admin' => 'administratör',
        'editor' => 'redaktör',
        'viewer' => 'läsbehörig'
    ];
    $roleName = $roleNames[$changeType] ?? $changeType;

    // Skapa ämnesrad
    if ($newStatus) {
        $subject = "Du har tilldelats $roleName-behörighet i $siteName";
    } else {
        $subject = "Din $roleName-behörighet har tagits bort i $siteName";
    }

    // Skapa e-postmeddelande
    $message = "
    <!DOCTYPE html>
    <html lang='sv'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
            <h1 style='color: #007bff; margin: 0 0 10px 0; font-size: 24px;'>$siteName</h1>
            <p style='margin: 0; color: #6c757d;'>Meddelande om behörighetsändring</p>
        </div>

        <div style='padding: 20px 0;'>
            <p>Hej!</p>
            ";

    if ($newStatus) {
        $message .= "
            <p>Du har nu tilldelats <strong>$roleName-behörighet</strong> i $siteName.</p>

            <div style='background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <strong style='color: #155724;'>Vad innebär detta?</strong>
                <ul style='color: #155724; margin: 10px 0 0 0; padding-left: 20px;'>";

        if ($changeType === 'admin') {
            $message .= "
                    <li>Du kan nu hantera användare i din organisation</li>
                    <li>Du kan tilldela och ta bort redaktörsbehörigheter</li>
                    <li>Du har tillgång till administratörspanelen</li>
                    <li>Du kan konfigurera påminnelser och se utökad statistik</li>";
        } else {
            $message .= "
                    <li>Du kan nu skapa och redigera kurser</li>
                    <li>Du kan hantera lektioner och frågor</li>
                    <li>Du har tillgång till kursstatistik</li>
                    <li>Du kan använda AI-funktioner för kursgenerering</li>";
        }

        $message .= "
                </ul>
            </div>";
    } else {
        $message .= "
            <p>Din <strong>$roleName-behörighet</strong> har tagits bort i $siteName.</p>

            <div style='background-color: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <strong style='color: #856404;'>Vad innebär detta?</strong>
                <p style='color: #856404; margin: 10px 0 0 0;'>Du har inte längre tillgång till de funktioner som krävde $roleName-behörighet. Du kan fortfarande logga in och genomföra kurser som vanlig användare.</p>
            </div>";
    }

    $message .= "
            <p>Om du har frågor om denna ändring, kontakta din organisations administratör.</p>
        </div>

        <div style='border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 20px; color: #6c757d; font-size: 12px;'>
            <p>Detta är ett automatiskt meddelande från $siteName.</p>
            <p>Ändringen gjordes av: $changedByEmail</p>
        </div>
    </body>
    </html>";

    return sendSmtpMail($userEmail, $subject, $message);
}

// ---------------------------------------------------------------------------
// Synkens semantik. Ligger HÄR och inte i api_helpers.php därför att
// performUserSync() använder dem, och den anropas även av adminpanelens
// synkverktyg (admin/ajax/sync_users_direct.php) som inte laddar api_helpers.php.
// ---------------------------------------------------------------------------

/**
 * Tolka värdet i läsbehörighetskolumnen vid batch-uppdatering.
 *
 * Läsbehörighet är INTE en roll. users.role är en enum (student/teacher/admin/
 * super_admin) utan sådant värde — behörigheten bärs av den separata kolumnen
 * users.is_viewer, som i användarlistan är en egen på/av-knapp vid sidan av
 * rollen. Därför tolkas den som en ja/nej-flagga och inte via normalizeSyncRole().
 *
 * Tomt värde ger null, alltså fel. En ifylld fil med en blank cell är tvetydig,
 * och att gissa "ja" hade delat ut behörighet någon inte bett om.
 *
 * @param mixed $value Värdet från filen eller JSON-posten
 * @return int|null 1 = läsbehörig, 0 = inte läsbehörig, null = otolkbart
 */
function parseViewerFlagValue($value) {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value)) {
        return $value === 1 ? 1 : ($value === 0 ? 0 : null);
    }
    if (!is_string($value)) {
        return null;
    }

    $v = mb_strtolower(trim($value));
    if ($v === '') {
        return null;
    }

    $sant  = ['ja', 'j', 'true', '1', 'x', 'yes', 'y', 'läsbehörig', 'lasbehorig'];
    $falskt = ['nej', 'n', 'false', '0', 'no', 'ta bort', 'ingen'];

    if (in_array($v, $sant, true))  return 1;
    if (in_array($v, $falskt, true)) return 0;
    return null;
}

/**
 * Normalisera ett rollvärde från synk-API:et till det värde som lagras.
 *
 * Rollen heter "Användare" i hela gränssnittet och i dokumentationen. Internt
 * lagras den fortfarande som 'student' i users.role — enum-värdet är gammalt och
 * i praktiken dekorativt (behörighet bärs av is_admin/is_editor/is_viewer), och
 * att byta det hade krävt en migration som rör varje query som nämner rollen,
 * utan att någon användare eller API-anropare blir hjälpt.
 *
 * Därför sker översättningen här, vid systemgränsen. `användare` är det värde
 * dokumentationen anger. `student` accepteras fortfarande: kunder som redan
 * integrerat mot den äldre dokumentationen ska inte få sin synk sönder av ett
 * terminologibyte.
 *
 * @param string|null $role Rollvärde från payloaden
 * @return string|null Lagrat värde, eller null om rollen inte känns igen
 */
function normalizeSyncRole($role) {
    if ($role === null || $role === '') {
        return null;
    }

    $r = strtolower(trim((string)$role));

    $map = [
        // Användare — nya termen först, äldre kvar som alias
        'användare'      => 'student',
        'anvandare'      => 'student',
        'student'        => 'student',
        // Redaktör
        'redaktör'       => 'teacher',
        'redaktor'       => 'teacher',
        'teacher'        => 'teacher',
        // Administratör
        'administratör'  => 'admin',
        'administrator'  => 'admin',
        'admin'          => 'admin',
    ];

    return $map[$r] ?? null;
}

/**
 * Är posten en begäran om radering?
 *
 * Accepterar true, 1 och "true"/"1" som strängar, eftersom källsystem som
 * serialiserar från AD ofta skickar strängar. Allt annat — inklusive "false",
 * 0 och utelämnat fält — betyder att posten är en vanlig synkpost.
 *
 * Att tolkningen ligger i en egen funktion är avsiktligt: valideringen och
 * synken måste vara ense om vad flaggan betyder. Om de tolkade den olika kunde
 * en post passera valideringen som radering men behandlas som uppdatering.
 *
 * @param array $user En post ur users-listan
 * @return bool
 */
function isUserSyncDeleteRequest($user) {
    if (!isset($user['delete'])) {
        return false;
    }
    $v = $user['delete'];
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v)) {
        return $v === 1;
    }
    if (is_string($v)) {
        return in_array(strtolower(trim($v)), ['true', '1'], true);
    }
    return false;
}

/**
 * Vilka fältnamn i en synkpost som betyder organisationstaggar.
 *
 * Två namn därför att vi själva stavar det olika: API-dokumentationen anger
 * `organization` medan synkverktygets CSV-mall har rubriken `organisation`.
 * En integration byggd efter mallen skickade därför ett fält som ingen läste,
 * och användaren skapades utan taggar — utan felmeddelande. Alias är billigare
 * än att bryta de integrationer som redan gissat rätt eller fel.
 *
 * @return array
 */
function syncOrgFieldNames() {
    return ['organization', 'organisation'];
}

/**
 * Alla fältnamn en synkpost får innehålla.
 *
 * @return array
 */
function syncUserFieldNames() {
    return array_merge(['email', 'name', 'role', 'delete'], syncOrgFieldNames());
}

/**
 * Läs ut organisationstaggarna ur en synkpost.
 *
 * Returnerar både värdet OCH om fältet fanns med, för skillnaden är bärande:
 *
 *   fältet saknas  → "jag har ingen uppfattning om taggarna, rör dem inte"
 *   fältet är tomt → "personen ska inte ha några taggar"
 *
 * Innan skillnaden fanns raderade varje synk utan organisationskolumn tyst
 * allas taggar, eftersom taggarna alltid skrivs om från grunden. En AD-export
 * utan den kolumnen hade alltså nollställt ett arbete som gjorts för hand.
 *
 * En lista accepteras som alternativ till den snedstrecksseparerade strängen.
 * Källsystem som serialiserar från AD skickar ofta flervärdesattribut som array,
 * och trim() på en array är ett fatalt fel i PHP 8 — alltså skulle hela synken
 * ha havererat på något som rimligen ska fungera.
 *
 * @param array $userData En post ur users-listan
 * @return array{finns:bool, varde:string}
 */
function readSyncOrganization(array $userData) {
    foreach (syncOrgFieldNames() as $falt) {
        if (!array_key_exists($falt, $userData)) {
            continue;
        }
        $v = $userData[$falt];
        if (is_array($v)) {
            $delar = array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $v);
            $v = implode('/', $delar);
        } elseif (!is_scalar($v) && $v !== null) {
            $v = '';
        }
        return ['finns' => true, 'varde' => trim((string)($v ?? ''))];
    }
    return ['finns' => false, 'varde' => ''];
}

/**
 * Dela upp ett organisationsvärde i platta taggar.
 *
 * "Kommun/Förvaltning/Avdelning" blir tre fristående rader. Snedstrecket är alltså
 * en inmatningsform för flera taggar, inte ett träd: varken förälder eller ordning
 * lagras. Se getOrgTagFilter() för vad det betyder för filtreringen.
 *
 * @param string $organization
 * @return array
 */
function splitOrgTags($organization) {
    $segments = array_map('trim', explode('/', (string)$organization));
    $segments = array_filter($segments, function ($s) { return $s !== ''; });
    return array_values(array_unique($segments));
}

/**
 * Skriv om en användares organisationstaggar från grunden.
 *
 * Delad av synken och adminpanelen, så att båda vägarna tolkar
 * "Kommun/Förvaltning/Avdelning" likadant. Innan adminpanelen kunde sätta
 * taggar fanns bara synkvägen, och en admin som ville rätta en avdelning för
 * EN person var tvungen att köra om hela organisationens synk.
 *
 * Returnerar hur det gick, så att anroparen kan skilja "satte taggar" från
 * "rensade taggar som fanns". En oavsiktlig rensning ska gå att se i svaret.
 *
 * @param int $userId
 * @param string|array $organization
 * @return array{taggar:array, satta:int, rensade:int}
 */
function setUserOrgTags($userId, $organization) {
    $userId = (int)$userId;
    if (is_array($organization)) {
        $delar = array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $organization);
        $organization = implode('/', $delar);
    }
    $taggar = splitOrgTags($organization);

    // Raka prepared statements, INTE execute()/queryOne(): de hjälparna sväljer
    // PDOException och returnerar null. Funktionen anropas inifrån synkens
    // transaktion, där ett svalt fel hade betytt att taggarna tyst uteblev i
    // stället för att hela synken rullades tillbaka.
    $db = getDb();

    $stmt = $db->prepare("SELECT COUNT(*) FROM " . DB_DATABASE . ".user_org_tags WHERE user_id = ?");
    $stmt->execute([$userId]);
    $foreAntal = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("DELETE FROM " . DB_DATABASE . ".user_org_tags WHERE user_id = ?");
    $stmt->execute([$userId]);

    $stmt = $db->prepare(
        "INSERT IGNORE INTO " . DB_DATABASE . ".user_org_tags (user_id, tag) VALUES (?, ?)"
    );
    foreach ($taggar as $tag) {
        $stmt->execute([$userId, $tag]);
    }

    return [
        'taggar'  => $taggar,
        'satta'   => empty($taggar) ? 0 : 1,
        'rensade' => (empty($taggar) && $foreAntal > 0) ? 1 : 0,
    ];
}

/**
 * Fältnamn i en synkpost som Stimma inte känner igen.
 *
 * Poängen är att ett felstavat fält ska gå att upptäcka. Payloaden valideras
 * bara på e-post, namn och roll, så `organisation_namn` eller `department`
 * passerade tidigare med HTTP 200 och "success": true — och användaren skapades
 * utan taggar. Anroparen hade ingenting att felsöka på.
 *
 * Okända fält är INTE ett fel. Att avvisa payloaden hade brutit integrationer
 * som skickar med extrafält från källsystemet utan att mena något med dem.
 * De rapporteras som varningar.
 *
 * @param array $userData
 * @return array
 */
function unknownSyncUserFields(array $userData) {
    $kanda = syncUserFieldNames();
    $okanda = [];
    foreach (array_keys($userData) as $k) {
        if (!in_array(strtolower((string)$k), $kanda, true)) {
            $okanda[] = (string)$k;
        }
    }
    return $okanda;
}

/**
 * Samla varningar om en payload — sådant som inte gör synken ogiltig men som
 * nästan alltid betyder att anroparen menade något annat än det som händer.
 *
 * Delad av API:et och adminpanelens synkverktyg, så att båda vägarna säger
 * samma sak om samma payload.
 *
 * @param array $users
 * @return array Lista med varningstexter
 */
function collectSyncUserWarnings(array $users) {
    $varningar = [];
    $okandaFalt = [];
    $utanOrg = 0;
    $totalt = 0;

    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        if (isUserSyncDeleteRequest($u)) {
            continue;   // raderingsposter har bara e-post, resten ignoreras avsiktligt
        }
        $totalt++;
        foreach (unknownSyncUserFields($u) as $f) {
            $okandaFalt[$f] = ($okandaFalt[$f] ?? 0) + 1;
        }
        if (!readSyncOrganization($u)['finns']) {
            $utanOrg++;
        }
    }

    foreach ($okandaFalt as $falt => $antal) {
        $varningar[] = "Fältet \"{$falt}\" känns inte igen och ignorerades ({$antal} "
            . ($antal === 1 ? 'post' : 'poster') . '). Tillåtna fält: '
            . implode(', ', syncUserFieldNames()) . '.';
    }

    if ($totalt > 0 && $utanOrg === $totalt) {
        $varningar[] = 'Ingen post innehöll fältet "organization" — befintliga '
            . 'organisationstaggar lämnades därför orörda. Skicka fältet med tomt '
            . 'värde för att rensa taggar.';
    }

    return $varningar;
}

/**
 * Radera en användare fullständigt, med allt som hänger på kontot.
 *
 * Delad av adminpanelens raderingsknapp (admin/users.php) och synk-API:ets
 * per-användarflagga "delete". Att ha EN implementation är poängen: de två
 * vägarna hann glida isär, och den i adminpanelen missade quiz_answers.
 *
 * Vad som händer med data:
 *
 * - Tabeller med FK ON DELETE CASCADE mot users städas av databasen själv.
 *   Det gäller bland annat certificates, user_progress, daily_activity,
 *   user_badges, user_stats, public_course_access, announcement_dismissals,
 *   sequential_lesson_schedule, sequential_email_queue, ai_course_jobs,
 *   user_org_tags och remember_tokens.
 *
 *   OBS: certificates ligger i den listan. En radering tar alltså med sig
 *   personens diplom, och genomförd utbildning går inte att styrka i efterhand.
 *
 * - Tabeller med user_id UTAN främmandenyckel måste städas här. Missas någon
 *   blir personuppgifter kvar efter en "radering".
 *
 * - pub_agreement_artifacts raderas AVSIKTLIGT INTE. Det är ett signerat
 *   PUB-avtal med undertecknarens namn, e-post, IP och PDF-hash — organisationens
 *   handling med egen rättslig grund för bevarande, inte användarens
 *   inlärningsdata. Raden blir kvar med ett user_id som pekar på ett borttaget
 *   konto; artefakten är självbärande och innehåller alla uppgifter den behöver.
 *
 * - Skapat innehåll överlever sin skapare: courses.author_id, lessons.author_id,
 *   learning_paths.created_by, tags.created_by och resources.author_id har
 *   ON DELETE SET NULL.
 *
 * Anroparen ansvarar för behörighetskontrollen. Funktionen kör i egen
 * transaktion om ingen redan är igång, annars deltar den i anroparens.
 *
 * @param int $userId
 * @return array{success:bool, error:?string}
 */
function deleteUserCompletely($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Ogiltigt användar-id.'];
    }

    $db = getDb();
    $ownTransaction = !$db->inTransaction();

    // Tabeller med user_id men utan främmandenyckel — databasen städar dem inte.
    // Vissa saknas i äldre installationer, därför tolereras "okänd tabell".
    $manualTables = [
        'progress',
        'quiz_answers',
        'course_enrollments',
        'sequential_reminder_log',
        'reminder_log',
    ];

    try {
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        foreach ($manualTables as $table) {
            try {
                $stmt = $db->prepare("DELETE FROM " . DB_DATABASE . ".{$table} WHERE user_id = ?");
                $stmt->execute([$userId]);
            } catch (Exception $e) {
                // Tabellen finns inte i den här installationen — hoppa över.
            }
        }

        $stmt = $db->prepare("DELETE FROM " . DB_DATABASE . ".users WHERE id = ?");
        $stmt->execute([$userId]);

        if ($ownTransaction) {
            $db->commit();
        }

        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('deleteUserCompletely misslyckades för id ' . $userId . ': ' . $e->getMessage());
        return ['success' => false, 'error' => 'Radering misslyckades.'];
    }
}

/**
 * Utför användarsynkronisering mot databasen.
 * Delad logik som används av både API-endpointen (api/sync_users.php)
 * och admin-synkverktyget (admin/ajax/sync_users_direct.php).
 *
 * @param array $users Lista av användare [{email, name, role, organization}]
 * @param string $domain Domän att synka mot
 * @param bool $deactivateMissing Om true, markera saknade synkade användare som inaktiva
 * @param int|null $apiKeyId API-nyckel-ID (null vid admin-sessionssynk)
 * @param string $ipAddress IP-adress
 * @param bool $includeSubdomains Om true omfattar synken även underdomäner till $domain.
 *        API-synken sätter detta eftersom en nyckel utfärdas för primärdomänen och gäller
 *        hela organisationen. Admin-synkverktyget grupperar redan per exakt domän och
 *        anropar en gång per domän — där måste omfånget förbli exakt, annars skulle
 *        synken av sater.se avaktivera edu.sater.se-användare som ligger i en annan grupp.
 * @return array ['success' => bool, 'summary' => [...], 'sync_id' => int, 'error' => string|null]
 */
function performUserSync(array $users, string $domain, bool $deactivateMissing, ?int $apiKeyId, string $ipAddress, bool $includeSubdomains = false): array {
    $startTime = microtime(true);
    $userCount = count($users);
    $created = 0;
    $updated = 0;
    $deactivated = 0;
    $deleted = 0;
    $deletesRefused = 0;
    $reactivated = 0;
    $orgTagsSet = 0;
    $orgTagsCleared = 0;
    $syncLogId = null;

    $db = getDb();

    try {
        $db->beginTransaction();

        $processedEmails = [];

        foreach ($users as $userData) {
            $email = strtolower(trim($userData['email']));
            $name = trim($userData['name'] ?? '');
            // Rollen normaliseras vid systemgränsen: "Användare" är termen utåt,
            // 'student' är det som lagras. Se normalizeSyncRole() i api_helpers.php.
            $role = normalizeSyncRole($userData['role'] ?? null) ?? 'student';
            // Både värdet och OM fältet fanns med — se readSyncOrganization().
            $orgFalt = readSyncOrganization($userData);

            $existingUser = queryOne(
                "SELECT id, is_synced, sync_status, role FROM " . DB_DATABASE . ".users WHERE email = ?",
                [$email]
            );

            // Raderingsbegäran: "delete": true på posten.
            if (isUserSyncDeleteRequest($userData)) {
                // Adressen läggs medvetet INTE i $processedEmails. Listan används
                // bara för att avgöra vem som saknas och ska inaktiveras, och en
                // raderad rad finns ändå inte kvar att inaktivera.

                if (!$existingUser) {
                    // Redan borta. Ingen åtgärd, inget fel — en synk som körs om
                    // ska ge samma resultat som första gången.
                    continue;
                }

                // Superadmins kan inte raderas via API. En felaktig eller kapad
                // synk ska aldrig kunna låsa ute systemets sista administratör.
                // Vägran räknas och rapporteras — att tyst strunta i en begärd
                // radering vore värre än att neka den, eftersom anroparen då tror
                // att kontot är borta.
                if (($existingUser['role'] ?? '') === 'super_admin') {
                    $deletesRefused++;
                    continue;
                }

                $delResult = deleteUserCompletely($existingUser['id']);
                if ($delResult['success']) {
                    $deleted++;
                }
                continue;
            }

            $processedEmails[] = $email;

            $isAdmin = ($role === 'admin') ? 1 : 0;
            $isEditor = ($role === 'admin' || $role === 'teacher') ? 1 : 0;

            if (!$existingUser) {
                $stmt = $db->prepare(
                    "INSERT INTO " . DB_DATABASE . ".users (email, name, role, is_admin, is_editor, is_synced, sync_status, synced_at, verified_at, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, 'active', NOW(), NOW(), NOW())"
                );
                $stmt->execute([$email, $name, $role, $isAdmin, $isEditor]);
                $userId = $db->lastInsertId();
                $created++;
                // Säkerställ AI-kvotrad för domänen/orgen
                require_once __DIR__ . '/ai_quota.php';
                $syncScope = getAiScopeForEmail($email);
                ensureAiQuotaRow($syncScope['organization_id'], $syncScope['domain']);
            } else {
                $userId = $existingUser['id'];

                if ($existingUser['is_synced'] == 1 && $existingUser['sync_status'] === 'inactive') {
                    $reactivated++;
                }

                $newRole = $role;
                $newIsAdmin = $isAdmin;
                $newIsEditor = $isEditor;
                if ($existingUser['role'] === 'super_admin') {
                    $newRole = 'super_admin';
                    $newIsAdmin = 1;
                    $newIsEditor = 1;
                }

                $stmt = $db->prepare(
                    "UPDATE " . DB_DATABASE . ".users
                     SET name = ?, role = ?, is_admin = ?, is_editor = ?, is_synced = 1, sync_status = 'active', synced_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([$name, $newRole, $newIsAdmin, $newIsEditor, $userId]);
                $updated++;
            }

            // Organisationstaggar skrivs om från grunden, men BARA när posten
            // faktiskt bär fältet. Ett utelämnat fält betyder "jag har ingen
            // uppfattning" — annars hade en AD-synk utan organisationskolumn
            // tyst nollställt taggar som satts för hand.
            if ($orgFalt['finns']) {
                // Ett tomt fält ÄR ett besked: personen ska inte ha taggar.
                // Rensningen räknas separat så att den syns i svaret.
                $orgUtfall = setUserOrgTags($userId, $orgFalt['varde']);
                $orgTagsSet += $orgUtfall['satta'];
                $orgTagsCleared += $orgUtfall['rensade'];
            }
        }

        // Markera saknade användare som inaktiva.
        // Omfånget här MÅSTE vara detsamma som det omfång anroparen fick skicka in
        // användare för. Annars blir resultatet fel åt ena eller andra hållet: för
        // smalt omfång lämnar kvar borttagna underdomänanvändare som aktiva för
        // alltid, för brett omfång avaktiverar det användare anroparen inte råder över.
        // Suffixjämförelsen görs med RIGHT() i stället för LIKE eftersom '_' är ett
        // jokertecken i LIKE och förekommer i domännamn.
        // Villkoret !empty($processedEmails) är inte bara en optimering: en payload
        // som BARA innehåller raderingsposter ger en tom lista, och utan detta
        // villkor hade "alla som inte står i listan" träffat hela organisationen.
        if ($deactivateMissing && !empty($processedEmails)) {
            $placeholders = implode(',', array_fill(0, count($processedEmails), '?'));

            if ($includeSubdomains) {
                $domainCondition = "(SUBSTRING_INDEX(email, '@', -1) = ?
                                     OR RIGHT(SUBSTRING_INDEX(email, '@', -1), CHAR_LENGTH(?) + 1) = CONCAT('.', ?))";
                $domainParams = [$domain, $domain, $domain];
            } else {
                $domainCondition = "SUBSTRING_INDEX(email, '@', -1) = ?";
                $domainParams = [$domain];
            }

            $params = array_merge($processedEmails, $domainParams);

            $stmt = $db->prepare(
                "UPDATE " . DB_DATABASE . ".users
                 SET sync_status = 'inactive'
                 WHERE is_synced = 1
                   AND sync_status = 'active'
                   AND role != 'super_admin'
                   AND email NOT IN ($placeholders)
                   AND $domainCondition"
            );
            $stmt->execute($params);
            $deactivated = $stmt->rowCount();
        }

        // Logga synk
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        $stmt = $db->prepare(
            "INSERT INTO " . DB_DATABASE . ".sync_log
             (domain, api_key_id, users_in_payload, users_created, users_updated, users_deactivated, users_deleted, users_reactivated, status, ip_address, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'success', ?, ?)"
        );
        $stmt->execute([$domain, $apiKeyId, $userCount, $created, $updated, $deactivated, $deleted, $reactivated, $ipAddress, $durationMs]);
        $syncLogId = $db->lastInsertId();

        $db->commit();

        return [
            'success' => true,
            'summary' => [
                'total_in_payload' => $userCount,
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'deleted' => $deleted,
                'deletes_refused' => $deletesRefused,
                'reactivated' => $reactivated,
                'org_tags_satta' => $orgTagsSet,
                'org_tags_rensade' => $orgTagsCleared
            ],
            'sync_id' => (int)$syncLogId,
            'error' => null
        ];

    } catch (Exception $e) {
        $db->rollBack();

        // Logga fel
        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $errorMsg = $e->getMessage();

        execute(
            "INSERT INTO " . DB_DATABASE . ".sync_log
             (domain, api_key_id, users_in_payload, users_created, users_updated, users_deactivated, users_deleted, users_reactivated, status, error_message, ip_address, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?, ?, ?)",
            [$domain, $apiKeyId, $userCount, $created, $updated, $deactivated, $deleted, $reactivated, $errorMsg, $ipAddress, $durationMs]
        );

        error_log("Stimma sync error: " . $errorMsg);

        return [
            'success' => false,
            'summary' => [
                'total_in_payload' => $userCount,
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'deleted' => $deleted,
                'deletes_refused' => $deletesRefused,
                'reactivated' => $reactivated,
                'org_tags_satta' => $orgTagsSet,
                'org_tags_rensade' => $orgTagsCleared
            ],
            'sync_id' => null,
            'error' => 'Ett internt fel uppstod vid synkronisering.'
        ];
    }
}
