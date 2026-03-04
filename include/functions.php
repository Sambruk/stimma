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

    // Lista över tillåtna HTML-taggar
    $allowedTags = [
        'br',      // Radbrytning
        'strong',  // Fet stil
        'b',       // Fet stil (alternativ)
        'em',      // Kursiv stil
        'i',       // Kursiv stil (alternativ)
        'u',       // Understruken
        'h3',      // Underrubrik (används i lektionsinnehåll)
        'h4',      // Underrubrik (används i sammanfattningar)
        'ul',      // Punktlista
        'ol',      // Numrerad lista
        'li',      // Listobjekt
        'p',       // Stycke
        'div',     // Div (konverteras till p, eller behålls med lesson-*-klass)
        'img'      // Bilder (med begränsade attribut)
    ];

    // Ta bort alla HTML-taggar förutom de tillåtna
    $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

    // Sanitera <img>-taggar: behåll bara src, alt, class med säkra värden
    $html = preg_replace_callback('/<img\b[^>]*>/i', function($match) {
        $tag = $match[0];

        // Extrahera src
        $src = '';
        if (preg_match('/\bsrc\s*=\s*"([^"]*)"/', $tag, $m) ||
            preg_match("/\bsrc\s*=\s*'([^']*)'/", $tag, $m)) {
            $src = $m[1];
        }

        // Normalisera ../upload/ till upload/
        $src = preg_replace('#^(\.\./)+upload/#', 'upload/', $src);

        // Validera src: tillåt bara relativa upload-sökvägar
        if (empty($src) || !preg_match('#^upload/[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$#', $src)) {
            return ''; // Ta bort bilder med ogiltiga sökvägar
        }

        // Extrahera alt
        $alt = '';
        if (preg_match('/\balt\s*=\s*"([^"]*)"/', $tag, $m) ||
            preg_match("/\balt\s*=\s*'([^']*)'/", $tag, $m)) {
            $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        }

        // Extrahera och filtrera class
        $class = '';
        if (preg_match('/\bclass\s*=\s*"([^"]*)"/', $tag, $m) ||
            preg_match("/\bclass\s*=\s*'([^']*)'/", $tag, $m)) {
            $allowedClasses = ['img-sm', 'img-md', 'img-lg', 'img-full', 'img-left', 'img-center', 'img-right'];
            $classes = array_filter(preg_split('/\s+/', $m[1]), function($c) use ($allowedClasses) {
                return in_array($c, $allowedClasses);
            });
            $class = implode(' ', $classes);
        }

        return '<img src="' . $src . '"' .
               ($alt ? ' alt="' . $alt . '"' : '') .
               ($class ? ' class="' . $class . '"' : '') .
               '>';
    }, $html);

    // Preserve styled div blocks (lesson content classes) before attribute stripping
    // These divs don't nest inside each other, so non-greedy match is safe
    $styledDivPlaceholders = [];
    $allowedDivClasses = ['lesson-intro', 'lesson-tip', 'lesson-info', 'lesson-example', 'lesson-warning', 'lesson-summary'];
    $classPattern = implode('|', array_map(function($c) { return preg_quote($c, '/'); }, $allowedDivClasses));
    $html = preg_replace_callback('/<div\s+class\s*=\s*["\'](' . $classPattern . ')["\']\s*>(.*?)<\/div>/si', function($match) use (&$styledDivPlaceholders) {
        $key = '%%STYLEDIV_' . count($styledDivPlaceholders) . '%%';
        $styledDivPlaceholders[$key] = '<div class="' . $match[1] . '">' . $match[2] . '</div>';
        return $key;
    }, $html);

    // SECURITY FIX: Remove ALL attributes from non-img tags
    $html = preg_replace('/<((?!img\b)[a-z][a-z0-9]*)\s+[^>]*>/i', '<$1>', $html);

    // Konvertera div-taggar till p-taggar
    $html = str_replace(['<div>', '</div>'], ['<p>', '</p>'], $html);

    // Restore styled div blocks
    foreach ($styledDivPlaceholders as $key => $divBlock) {
        $html = str_replace($key, $divBlock, $html);
    }

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
 * Spara en PUB-avtalssigneringsartefakt
 *
 * @param array $data Artefaktdata
 * @return int|null ID för den sparade artefakten eller null vid fel
 */
function savePubAgreementArtifact($data) {
    return execute(
        "INSERT INTO " . DB_DATABASE . ".pub_agreement_artifacts
         (agreement_id, version, pdf_filename, pdf_hash, signed_at, ip_address,
          user_id, user_email, user_name, user_title, user_phone,
          domain, org_name, org_number, agreement_email, certification_text)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
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
 * @return bool True om det lyckades
 */
function enrollUserInSequentialCourse($userId, $courseId) {
    // Kontrollera om redan inskriven
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".sequential_lesson_schedule WHERE user_id = ? AND course_id = ? LIMIT 1",
        [$userId, $courseId]
    );
    if ($existing) {
        return true; // Redan inskriven
    }

    // Hämta alla lektioner sorterade
    $lessons = query(
        "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC",
        [$courseId]
    );

    if (empty($lessons)) {
        return false;
    }

    // Skapa schedule-rader
    foreach ($lessons as $index => $lesson) {
        $availableAt = ($index === 0) ? date('Y-m-d H:i:s') : null;
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
            "INSERT INTO " . DB_DATABASE . ".course_enrollments (user_id, course_id, status) VALUES (?, ?, 'active')",
            [$userId, $courseId]
        );
    }

    return true;
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

    // Hitta nästa lektion (via sort_order)
    $nextLesson = queryOne(
        "SELECT l.id FROM " . DB_DATABASE . ".lessons l
         WHERE l.course_id = ? AND l.sort_order > (
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

            $lessonUrl = $systemUrl . '/lesson.php?id=' . $item['lesson_id'];
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
    $lessonUrl = $systemUrl . '/lesson.php?id=' . $item['lesson_id'];
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
        'editor' => 'redaktör'
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
