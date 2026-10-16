<?php
/**
 * Enkel SMTP-mottagare för testmiljön (2026-10-16). Tar emot allt på
 * 127.0.0.1:2525 och sparar varje mejl som en fil i /tmp/stimma-mail/.
 * Startas av mailSinkStart() i lib.php; skickar aldrig något vidare.
 */
$dir = '/tmp/stimma-mail';
@mkdir($dir, 0777, true);
$srv = stream_socket_server('tcp://127.0.0.1:2525', $errno, $errstr);
if (!$srv) { fwrite(STDERR, "smtp_sink: $errstr\n"); exit(1); }
while ($c = @stream_socket_accept($srv, -1)) {
    $say = fn($s) => fwrite($c, $s . "\r\n");
    $say('220 stimma-test-sink');
    $to = []; $data = null;
    while (($line = fgets($c)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($data !== null) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents($dir . '/' . sprintf('%.6f', microtime(true)) . '.eml',
                    'X-Sink-To: ' . implode(',', $to) . "\r\n" . $data);
                $data = null; $to = [];
                $say('250 OK');
            } else {
                $data .= (strpos($line, '..') === 0 ? substr($line, 1) : $line);
            }
            continue;
        }
        if ($cmd === 'EHLO' || $cmd === 'HELO') { $say('250-stimma-test-sink'); $say('250 AUTH LOGIN'); }
        elseif ($cmd === 'AUTH') { $say('235 OK'); }
        elseif ($cmd === 'MAIL') { $say('250 OK'); }
        elseif ($cmd === 'RCPT') { preg_match('/<([^>]+)>/', $line, $m); $to[] = strtolower($m[1] ?? ''); $say('250 OK'); }
        elseif ($cmd === 'DATA') { $data = ''; $say('354 End with .'); }
        elseif ($cmd === 'QUIT') { $say('221 Bye'); break; }
        else { $say('250 OK'); }
    }
    fclose($c);
}
