<?php
/**
 * Gör en textversion av ett HTML-mail.
 *
 * Används när anroparen inte skickar med en egen textversion. Länkar behåller
 * sin adress ("Logga in: https://…") — annars försvinner själva poängen med t.ex.
 * en inloggningsknapp i klienter som visar textdelen.
 *
 * @param string $html
 * @return string
 */
function mailHtmlToText($html) {
    // Kommentarer först: Outlooks villkorskommentarer (<!--[if mso]>) bär VML-kod.
    $text = preg_replace('/<!--.*?-->/s', '', $html);
    $text = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $text);

    $text = preg_replace_callback('#<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) {
        $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($url === '' || $url[0] === '#' || stripos($url, 'javascript:') === 0) {
            return $label;
        }
        if (stripos($url, 'mailto:') === 0) {
            $url = substr($url, 7);
        }
        if ($label === '' || $label === $url) {
            return $url;
        }
        return "$label: $url";
    }, $text);

    $text = preg_replace('#<br\s*/?>#i', "\n", $text);
    // Varje listpunkt på egen rad; nästa <li> ger radbrytningen, så </li> ska inte lägga till en.
    $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text);
    $text = preg_replace('#</li>#i', '', $text);
    $text = preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol|blockquote)>#i', "\n\n", $text);
    $text = preg_replace('#<(p|div|h[1-6]|tr|table|ul|ol|blockquote)\b[^>]*>#i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $lines = array_map(function ($line) {
        return trim(preg_replace('/[ \t]+/', ' ', $line));
    }, $lines);
    $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

    return trim($text) . "\n";
}

/**
 * Quoted-printable med CRLF-radbrytningar. Raderna blir högst 76 tecken, så
 * SMTP:s gräns på 1000 tecken per rad kan inte överskridas av långa HTML-rader.
 */
function mailQuotedPrintable($content) {
    $content = preg_replace('/\r\n|\r|\n/', "\r\n", $content);
    return quoted_printable_encode($content);
}

/**
 * Bygg en multipart/alternative-del med text först och HTML sist.
 *
 * Ordningen är standardens: klienten visar den SISTA del den kan rendera, alltså
 * HTML i vanliga klienter och texten i övriga. Ett mail med bara HTML väger
 * tyngre mot spam i bl.a. Microsofts filter.
 *
 * @param string $html
 * @param string|null $text Egen textversion, annars skapas en ur HTML:en
 * @return array{content_type:string, body:string}
 */
function mailBuildAlternative($html, $text = null) {
    if ($text === null || trim($text) === '') {
        $text = mailHtmlToText($html);
    }
    $boundary = 'StimmaAlt_' . bin2hex(random_bytes(12));

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= mailQuotedPrintable($text) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= mailQuotedPrintable($html) . "\r\n";
    $body .= "--$boundary--\r\n";

    return [
        'content_type' => "multipart/alternative; boundary=\"$boundary\"",
        'body' => $body,
    ];
}

/**
 * Förbered DATA-blocket för SMTP: en rad som börjar med punkt måste dubbleras
 * (RFC 5321, 4.5.2), annars kan servern tolka den som slut på meddelandet.
 */
function mailDotStuff($data) {
    return preg_replace('/(^|\r\n)\./', '$1..', $data);
}

/**
 * Skicka e-post via SMTP
 *
 * @param string $to Mottagarens e-postadress
 * @param string $subject Ämne
 * @param string $message Meddelande (HTML)
 * @param string $from Avsändarens e-postadress
 * @param string $fromName Avsändarens namn
 * @param string|null $textMessage Textversion. Utelämnad skapas den ur HTML:en.
 * @return bool True om det lyckades, false vid fel
 */
function sendSmtpMail($to, $subject, $message, $from = null, $fromName = null, $textMessage = null) {
    // Hämta inställningar från .env
    $host = getenv('MAIL_HOST') ?: 'localhost';
    $port = getenv('MAIL_PORT') ?: 25;
    $username = getenv('MAIL_USERNAME') ?: '';
    $password = getenv('MAIL_PASSWORD') ?: '';
    $encryption = getenv('MAIL_ENCRYPTION') ?: 'ssl';
    
    // Använd standardvärden om inget anges
    $from = $from ?: (getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se');
    $fromName = $fromName ?: (getenv('MAIL_FROM_NAME') ?: 'Stimma');
    
    // Logga e-postförsök
    $logMessage = "E-post skickas till: $to | Ämne: $subject | Från: $fromName <$from>";
    logActivity($to, $logMessage, [
        'action' => 'mail_send_attempt',
        'to_email' => $to,
        'from_email' => $from,
        'from_name' => $fromName,
        'subject' => $subject,
        'message_length' => strlen($message)
    ]);
    
    // För felsökning
    $debug = [];
    $debug[] = "Ansluter till $host:$port med $encryption...";
    
    // Anslut till SMTP-server
    if ($encryption == 'ssl' && $port != 25) {
        $socket = fsockopen("ssl://$host", $port, $errno, $errstr, 30);
    } else {
        $socket = fsockopen($host, $port, $errno, $errstr, 30);
    }
    
    if (!$socket) {
        // Logga misslyckad anslutning
        logActivity($to, "E-post misslyckades: Kunde inte ansluta till SMTP-server ($host:$port)", [
            'action' => 'mail_send_failed',
            'error' => 'connection_failed',
            'to_email' => $to,
            'subject' => $subject
        ]);
        return false;
    }
    
    // Läs serverns hälsning
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    if (substr($response, 0, 3) != '220') {
        fclose($socket);
        // Logga misslyckad serverhälsning
        logActivity($to, "E-post misslyckades: Ogiltig serverhälsning - $response", [
            'action' => 'mail_send_failed',
            'error' => 'invalid_server_response',
            'to_email' => $to,
            'subject' => $subject,
            'server_response' => $response
        ]);
        return false;
    }
    
    // Skicka EHLO
    fputs($socket, "EHLO " . parse_url(SITE_URL, PHP_URL_HOST) . "\r\n");
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    // Läs alla serveralternativ
    while (substr($response, 3, 1) == '-') {
        $response = fgets($socket, 515);
        $debug[] = "SERVER: $response";
    }
    
    // Logga in om användarnamn och lösenord anges
    if (!empty($username) && !empty($password)) {
        // AUTH LOGIN
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        $debug[] = "SERVER: $response";
        
        if (substr($response, 0, 3) != '334') {
            fclose($socket);
            return false;
        }
        
        // Skicka användarnamn (base64-kodat)
        fputs($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 515);
        $debug[] = "SERVER: $response";
        
        if (substr($response, 0, 3) != '334') {
            fclose($socket);
            return false;
        }
        
        // Skicka lösenord (base64-kodat)
        $encodedPassword = base64_encode($password);
        $debug[] = "Skickar lösenord (base64): [längd: " . strlen($encodedPassword) . "]";
        fputs($socket, $encodedPassword . "\r\n");
        $response = fgets($socket, 515);
        $debug[] = "SERVER: $response";
        
        if (substr($response, 0, 3) != '235') {
            fclose($socket);
            // Logga misslyckad autentisering
            logActivity($to, "E-post misslyckades: Autentisering misslyckades - $response", [
                'action' => 'mail_send_failed',
                'error' => 'authentication_failed',
                'to_email' => $to,
                'subject' => $subject,
                'server_response' => $response
            ]);
            return false;
        }
    }
    
    // FROM
    fputs($socket, "MAIL FROM:<$from>\r\n");
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        // Logga misslyckad FROM-kommando
        logActivity($to, "E-post misslyckades: FROM-kommando misslyckades - $response", [
            'action' => 'mail_send_failed',
            'error' => 'from_command_failed',
            'to_email' => $to,
            'subject' => $subject,
            'server_response' => $response
        ]);
        return false;
    }
    
    // TO
    fputs($socket, "RCPT TO:<$to>\r\n");
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        // Logga misslyckad TO-kommando
        logActivity($to, "E-post misslyckades: TO-kommando misslyckades - $response", [
            'action' => 'mail_send_failed',
            'error' => 'to_command_failed',
            'to_email' => $to,
            'subject' => $subject,
            'server_response' => $response
        ]);
        return false;
    }
    
    // DATA
    fputs($socket, "DATA\r\n");
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    if (substr($response, 0, 3) != '354') {
        fclose($socket);
        // Logga misslyckad DATA-kommando
        logActivity($to, "E-post misslyckades: DATA-kommando misslyckades - $response", [
            'action' => 'mail_send_failed',
            'error' => 'data_command_failed',
            'to_email' => $to,
            'subject' => $subject,
            'server_response' => $response
        ]);
        return false;
    }
    
    // Förbered rubriker. Kroppen skickas som multipart/alternative med text och HTML.
    $mime = mailBuildAlternative($message, $textMessage);
    $messageId = '<' . bin2hex(random_bytes(16)) . '@sambruk.se>';
    $headers = "From: $fromName <$from>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: $messageId\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: {$mime['content_type']}\r\n";
    $headers .= "X-Mailer: Stimma Mailer\r\n";
    $headers .= "\r\n";

    // Skicka e-postinnehåll
    fputs($socket, mailDotStuff($headers . $mime['body']) . "\r\n.\r\n");
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        // Logga misslyckad e-postleverans
        logActivity($to, "E-post misslyckades: Leverans misslyckades - $response", [
            'action' => 'mail_send_failed',
            'error' => 'delivery_failed',
            'to_email' => $to,
            'subject' => $subject,
            'server_response' => $response
        ]);
        return false;
    }
    
    // QUIT
    fputs($socket, "QUIT\r\n");
    $response = fgets($socket, 515);
    $debug[] = "SERVER: $response";
    
    // Stäng anslutningen
    fclose($socket);
    
    // Logga lyckad e-postleverans
    logActivity($to, "E-post skickat framgångsrikt till: $to | Ämne: $subject", [
        'action' => 'mail_send_success',
        'to_email' => $to,
        'from_email' => $from,
        'from_name' => $fromName,
        'subject' => $subject,
        'message_length' => strlen($message)
    ]);

    return true;
}

/**
 * Skicka e-post via SMTP med bilagor
 *
 * @param string $to Mottagarens e-postadress
 * @param string $subject Ämne
 * @param string $message Meddelande (HTML)
 * @param array $attachments Bilagor: [['filename' => 'fil.pdf', 'content' => $binaryData], ...]
 * @param string $from Avsändarens e-postadress
 * @param string $fromName Avsändarens namn
 * @param string|null $textMessage Textversion. Utelämnad skapas den ur HTML:en.
 * @return bool True om det lyckades, false vid fel
 */
function sendSmtpMailWithAttachment($to, $subject, $message, $attachments = [], $from = null, $fromName = null, $textMessage = null) {
    // Om inga bilagor, använd vanlig funktion
    if (empty($attachments)) {
        return sendSmtpMail($to, $subject, $message, $from, $fromName, $textMessage);
    }

    // Hämta inställningar från .env
    $host = getenv('MAIL_HOST') ?: 'localhost';
    $port = getenv('MAIL_PORT') ?: 25;
    $username = getenv('MAIL_USERNAME') ?: '';
    $password = getenv('MAIL_PASSWORD') ?: '';
    $encryption = getenv('MAIL_ENCRYPTION') ?: 'ssl';

    $from = $from ?: (getenv('MAIL_FROM_ADDRESS') ?: 'noreply@tropheus.se');
    $fromName = $fromName ?: (getenv('MAIL_FROM_NAME') ?: 'Stimma');

    logActivity($to, "E-post med bilaga skickas till: $to | Ämne: $subject", [
        'action' => 'mail_attachment_send_attempt',
        'to_email' => $to,
        'from_email' => $from,
        'subject' => $subject,
        'attachment_count' => count($attachments)
    ]);

    // Anslut till SMTP-server
    if ($encryption == 'ssl' && $port != 25) {
        $socket = fsockopen("ssl://$host", $port, $errno, $errstr, 30);
    } else {
        $socket = fsockopen($host, $port, $errno, $errstr, 30);
    }

    if (!$socket) {
        logActivity($to, "E-post med bilaga misslyckades: Anslutning misslyckades ($host:$port)", [
            'action' => 'mail_attachment_send_failed',
            'error' => 'connection_failed',
            'to_email' => $to
        ]);
        return false;
    }

    // Läs hälsning
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '220') {
        fclose($socket);
        return false;
    }

    // EHLO
    fputs($socket, "EHLO " . parse_url(SITE_URL, PHP_URL_HOST) . "\r\n");
    $response = fgets($socket, 515);
    while (substr($response, 3, 1) == '-') {
        $response = fgets($socket, 515);
    }

    // AUTH LOGIN
    if (!empty($username) && !empty($password)) {
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '334') { fclose($socket); return false; }

        fputs($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '334') { fclose($socket); return false; }

        fputs($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '235') { fclose($socket); return false; }
    }

    // FROM
    fputs($socket, "MAIL FROM:<$from>\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '250') { fclose($socket); return false; }

    // TO
    fputs($socket, "RCPT TO:<$to>\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '250') { fclose($socket); return false; }

    // DATA
    fputs($socket, "DATA\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '354') { fclose($socket); return false; }

    // Bygg MIME multipart/mixed meddelande
    $boundary = 'Stimma_' . bin2hex(random_bytes(16));
    $messageId = '<' . bin2hex(random_bytes(16)) . '@sambruk.se>';

    $headers = "From: $fromName <$from>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: $messageId\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    $headers .= "X-Mailer: Stimma Mailer\r\n";
    $headers .= "\r\n";

    // Text- och HTML-del som en multipart/alternative inuti multipart/mixed
    $mime = mailBuildAlternative($message, $textMessage);
    $body = "--$boundary\r\n";
    $body .= "Content-Type: {$mime['content_type']}\r\n";
    $body .= "\r\n";
    $body .= $mime['body'];
    $body .= "\r\n";

    // Bilagor
    foreach ($attachments as $attachment) {
        $filename = $attachment['filename'] ?? 'bilaga.pdf';
        $content = $attachment['content'] ?? '';
        $mimeType = $attachment['mime_type'] ?? 'application/pdf';

        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $mimeType; name=\"$filename\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
        $body .= "\r\n";
        $body .= chunk_split(base64_encode($content));
        $body .= "\r\n";
    }

    $body .= "--$boundary--\r\n";

    // Skicka
    fputs($socket, mailDotStuff($headers . $body) . "\r\n.\r\n");
    $response = fgets($socket, 515);

    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        logActivity($to, "E-post med bilaga misslyckades: Leverans misslyckades - $response", [
            'action' => 'mail_attachment_send_failed',
            'error' => 'delivery_failed',
            'to_email' => $to,
            'subject' => $subject
        ]);
        return false;
    }

    // QUIT
    fputs($socket, "QUIT\r\n");
    fgets($socket, 515);
    fclose($socket);

    logActivity($to, "E-post med bilaga skickat till: $to | Ämne: $subject", [
        'action' => 'mail_attachment_send_success',
        'to_email' => $to,
        'subject' => $subject,
        'attachment_count' => count($attachments)
    ]);

    return true;
}
