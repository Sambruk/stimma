<?php
/**
 * Stimma - AJAX-endpoint för SMS-verifiering vid PUB-avtal
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Hanterar send_sms och verify_sms via JSON-responses.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/sms.php';

header('Content-Type: application/json; charset=UTF-8');

// Kräv inloggad användare
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad.']);
    exit;
}

// Kräv POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Ogiltig metod.']);
    exit;
}

// Validera CSRF-token
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ogiltig säkerhetstoken. Ladda om sidan.']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'send_sms':
        $phone = trim($_POST['phone'] ?? '');
        if (empty($phone)) {
            echo json_encode(['success' => false, 'error' => 'Telefonnummer måste anges.']);
            exit;
        }

        $result = sendVerificationSms($phone);
        echo json_encode($result);
        break;

    case 'verify_sms':
        $code = trim($_POST['code'] ?? '');
        if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
            echo json_encode(['success' => false, 'error' => 'Ange en 6-siffrig kod.']);
            exit;
        }

        $result = verifySmsCode($code);
        echo json_encode($result);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Okänd åtgärd.']);
        break;
}
