<?php
/**
 * Skicka e-post via SMTP
 *
 * @param string $to Mottagarens e-postadress
 * @param string $subject Ämne
 * @param string $message Meddelande (HTML)
 * @param string $from Avsändarens e-postadress
 * @param string $fromName Avsändarens namn
 * @return bool True om det lyckades, false vid fel
 */
function sendSmtpMail($to, $subject, $message, $from = null, $fromName = null) {
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
    
    // Förbered rubriker
    $messageId = '<' . bin2hex(random_bytes(16)) . '@sambruk.se>';
    $headers = "From: $fromName <$from>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: $messageId\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: Stimma Mailer\r\n";
    $headers .= "\r\n";
    
    // Skicka e-postinnehåll
    fputs($socket, $headers . $message . "\r\n.\r\n");
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
 * @return bool True om det lyckades, false vid fel
 */
function sendSmtpMailWithAttachment($to, $subject, $message, $attachments = [], $from = null, $fromName = null) {
    // Om inga bilagor, använd vanlig funktion
    if (empty($attachments)) {
        return sendSmtpMail($to, $subject, $message, $from, $fromName);
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

    // HTML-del
    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "\r\n";
    $body .= chunk_split(base64_encode($message));
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
    fputs($socket, $headers . $body . "\r\n.\r\n");
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
