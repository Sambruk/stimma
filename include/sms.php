<?php
/**
 * Stimma - ClickSend SMS-integration
 * Copyright (C) 2025 Christian Alfredsson
 *
 * SMS-verifiering för PUB-avtalssignering.
 */

require_once __DIR__ . '/functions.php';

/**
 * Skicka SMS via ClickSend REST API
 *
 * @param string $to Telefonnummer (E.164-format, t.ex. +46701234567)
 * @param string $message SMS-text
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendClickSendSms($to, $message) {
    $username = getenv('CLICKSEND_USERNAME');
    $apiKey = getenv('CLICKSEND_API_KEY');

    if (empty($username) || empty($apiKey)) {
        logActivity('system', 'SMS misslyckades: ClickSend-uppgifter saknas i .env', [
            'action' => 'sms_config_error'
        ]);
        return ['success' => false, 'error' => 'SMS-tjänsten är inte konfigurerad.'];
    }

    $payload = json_encode([
        'messages' => [
            [
                'body' => $message,
                'to' => $to,
                'from' => 'SambrukPUB',
                'source' => 'php'
            ]
        ]
    ]);

    $ch = curl_init('https://rest.clicksend.com/v3/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_USERPWD => $username . ':' . $apiKey,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logActivity('system', "SMS misslyckades: cURL-fel - $curlError", [
            'action' => 'sms_send_failed',
            'to' => $to,
            'error' => $curlError
        ]);
        return ['success' => false, 'error' => 'Kunde inte nå SMS-tjänsten.'];
    }

    $data = json_decode($response, true);

    if ($httpCode === 200 && isset($data['data']['messages'][0]['status']) && $data['data']['messages'][0]['status'] === 'SUCCESS') {
        logActivity('system', "SMS skickat till: $to", [
            'action' => 'sms_send_success',
            'to' => $to
        ]);
        return ['success' => true, 'error' => null];
    }

    $errorMsg = $data['data']['messages'][0]['status'] ?? ($data['response_msg'] ?? "HTTP $httpCode");
    logActivity('system', "SMS misslyckades till: $to - $errorMsg", [
        'action' => 'sms_send_failed',
        'to' => $to,
        'http_code' => $httpCode,
        'response' => $errorMsg
    ]);
    return ['success' => false, 'error' => "SMS kunde inte skickas: $errorMsg"];
}

/**
 * Generera en 6-siffrig verifieringskod
 *
 * @return string
 */
function generateSmsCode() {
    return (string) random_int(100000, 999999);
}

/**
 * Normalisera telefonnummer till E.164-format
 * Hanterar svenska nummer: 070... → +4670...
 *
 * @param string $phone Telefonnummer
 * @return string|false Normaliserat nummer eller false
 */
function normalizePhoneNumber($phone) {
    // Ta bort mellanslag, bindestreck, parenteser
    $phone = preg_replace('/[\s\-\(\).]/', '', $phone);

    // Om det redan börjar med + och har minst 10 siffror
    if (preg_match('/^\+\d{10,15}$/', $phone)) {
        return $phone;
    }

    // Om det börjar med 00 (internationellt prefix)
    if (preg_match('/^00(\d{10,15})$/', $phone, $m)) {
        return '+' . $m[1];
    }

    // Svenskt nummer som börjar med 0
    if (preg_match('/^0(\d{6,12})$/', $phone, $m)) {
        return '+46' . $m[1];
    }

    return false;
}

/**
 * Skicka verifierings-SMS och spara kod i session
 *
 * @param string $phone Telefonnummer
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendVerificationSms($phone) {
    $normalized = normalizePhoneNumber($phone);
    if ($normalized === false) {
        return ['success' => false, 'error' => 'Ogiltigt telefonnummer. Ange t.ex. 0701234567 eller +46701234567.'];
    }

    // Begränsa antal SMS per session (max 5 försök)
    if (isset($_SESSION['sms_send_count']) && $_SESSION['sms_send_count'] >= 5) {
        return ['success' => false, 'error' => 'Maximalt antal SMS-försök har nåtts. Försök igen senare.'];
    }

    $code = generateSmsCode();
    $message = "Din verifieringskod för Stimma PUB-avtal: $code (giltig i 10 minuter)";

    $result = sendClickSendSms($normalized, $message);

    if ($result['success']) {
        $_SESSION['sms_code'] = $code;
        $_SESSION['sms_phone'] = $normalized;
        $_SESSION['sms_phone_raw'] = $phone;
        $_SESSION['sms_expires'] = time() + 600; // 10 minuter
        $_SESSION['sms_verified'] = false;
        $_SESSION['sms_send_count'] = ($_SESSION['sms_send_count'] ?? 0) + 1;
        $_SESSION['sms_verify_attempts'] = 0;
    }

    return $result;
}

/**
 * Verifiera SMS-kod mot sessionsdata
 *
 * @param string $code Den inskickade koden
 * @return array ['success' => bool, 'error' => string|null]
 */
function verifySmsCode($code) {
    if (!isset($_SESSION['sms_code']) || !isset($_SESSION['sms_expires'])) {
        return ['success' => false, 'error' => 'Ingen verifieringskod har skickats. Begär en ny kod.'];
    }

    // Begränsa verifieringsförsök (max 5)
    $attempts = ($_SESSION['sms_verify_attempts'] ?? 0) + 1;
    $_SESSION['sms_verify_attempts'] = $attempts;

    if ($attempts > 5) {
        unset($_SESSION['sms_code'], $_SESSION['sms_expires']);
        return ['success' => false, 'error' => 'För många felaktiga försök. Begär en ny kod.'];
    }

    // Kontrollera utgångstid
    if (time() > $_SESSION['sms_expires']) {
        unset($_SESSION['sms_code'], $_SESSION['sms_expires']);
        return ['success' => false, 'error' => 'Koden har gått ut. Begär en ny kod.'];
    }

    // Jämför kod (timing-safe)
    if (!hash_equals($_SESSION['sms_code'], trim($code))) {
        $remaining = 5 - $attempts;
        return ['success' => false, 'error' => "Felaktig kod. $remaining försök kvar."];
    }

    // Verifiering lyckades
    $_SESSION['sms_verified'] = true;
    $_SESSION['sms_verified_at'] = time();
    $_SESSION['sms_verified_phone'] = $_SESSION['sms_phone'];

    // Rensa kodinformation
    unset($_SESSION['sms_code'], $_SESSION['sms_expires'], $_SESSION['sms_verify_attempts']);

    logActivity($_SESSION['user_email'] ?? 'unknown', 'SMS-verifiering lyckades', [
        'action' => 'sms_verification_success',
        'phone' => $_SESSION['sms_phone']
    ]);

    return ['success' => true, 'error' => null];
}
