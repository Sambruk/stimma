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

function sendOpenAIRequest($messages) {
    // Hämta API-konfiguration från .env
    $provider = getenv('AI_PROVIDER') ?: 'openai';
    $apiServer = getenv('AI_SERVER') ?: '';
    if (empty($apiServer)) {
        $apiServer = getDefaultApiUrl($provider);
    }
    $apiKey = getenv('AI_API_KEY') ?: '';
    $model = getenv('AI_MODEL') ?: 'gpt-4';
    $maxTokens = (int)(getenv('AI_MAX_COMPLETION_TOKENS') ?: 4096);
    $temperature = (float)(getenv('AI_TEMPERATURE') ?: 0.7);
    $topP = (float)(getenv('AI_TOP_P') ?: 0.9);
    $maxRetries = 3;
    $timeout = 30; // sekunder

    if (empty($apiKey)) {
        throw new Exception('API-nyckel saknas i konfigurationen.');
    }

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

    // Hantera återförsök
    $attempts = 0;
    $lastError = '';
    
    while ($attempts < $maxRetries) {
        $attempts++;
        
        // Anropa API
        $ch = curl_init($apiServer);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        
        // Sätt headers baserat på API-typ
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];
        
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Om vi fick ett giltigt svar, returnera det
        if ($httpCode === 200 && empty($error)) {
            $responseData = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Extrahera svaret från API-svaret baserat på API-typ
                if ($isOpenRoute) {
                    if (isset($responseData['choices'][0]['message']['content'])) {
                        logActivity($userEmail, "AI-anrop lyckades efter $attempts försök");
                        return $responseData['choices'][0]['message']['content'];
                    } elseif (isset($responseData['choices'][0]['text'])) {
                        logActivity($userEmail, "AI-anrop lyckades efter $attempts försök");
                        return $responseData['choices'][0]['text'];
                    }
                } else {
                    if (isset($responseData['choices'][0]['message']['content'])) {
                        logActivity($userEmail, "AI-anrop lyckades efter $attempts försök");
                        return $responseData['choices'][0]['message']['content'];
                    } elseif (isset($responseData['content'])) {
                        logActivity($userEmail, "AI-anrop lyckades efter $attempts försök");
                        return $responseData['content'];
                    }
                }
            }
        }
        
        // Om vi inte fick ett giltigt svar, spara felet och försök igen
        $lastError = "HTTP $httpCode: " . ($error ?: $response);
        sleep(1); // Vänta en sekund innan nästa försök
    }
    
    // Om vi har nått max antal försök, kasta ett undantag
    throw new Exception("Kunde inte få svar från AI efter $maxRetries försök. Senaste fel: $lastError");
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

// Sökväg till upload-mappen
$uploadDir = __DIR__ . '/../upload/';

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

    // Lista över tillåtna HTML-taggar (no attributes allowed)
    $allowedTags = [
        'br',      // Radbrytning
        'strong',  // Fet stil
        'b',       // Fet stil (alternativ)
        'em',      // Kursiv stil
        'i',       // Kursiv stil (alternativ)
        'u',       // Understruken
        'ul',      // Punktlista
        'ol',      // Numrerad lista
        'li',      // Listobjekt
        'p',       // Stycke
        'div'      // Div (kommer att konverteras till p)
    ];

    // Ta bort alla HTML-taggar förutom de tillåtna
    $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

    // SECURITY FIX: Remove ALL attributes from remaining tags (more thorough)
    $html = preg_replace('/<([a-z][a-z0-9]*)\s+[^>]*>/i', '<$1>', $html);

    // Konvertera div-taggar till p-taggar
    $html = str_replace(['<div>', '</div>'], ['<p>', '</p>'], $html);

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
