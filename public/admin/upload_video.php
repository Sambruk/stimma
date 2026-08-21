<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

// Suppress display errors for clean JSON output
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

// Include AJAX-compatible authentication check
require_once 'include/ajax_auth_check.php';

// Kontrollera CSRF-token
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Kontrollera om en fil har laddats upp
if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'Ingen fil uppladdad';
    if (isset($_FILES['video'])) {
        switch ($_FILES['video']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'Filen är för stor. Max storlek är 100 MB.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = 'Filen laddades bara upp delvis. Försök igen.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'Ingen fil vald.';
                break;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['video'];
$allowedExtensions = ['mp4', 'webm'];
$maxFileSize = 100 * 1024 * 1024; // 100MB

// Validera filändelse
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ogiltig filändelse. Endast MP4 och WebM är tillåtna.']);
    exit;
}

// Validera filstorlek
if ($file['size'] > $maxFileSize) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Filen är för stor. Max storlek är 100 MB.']);
    exit;
}

// Validera MIME-typ med finfo (server-side detection)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedMimeTypes = ['video/mp4', 'video/webm'];

if (!in_array($mimeType, $allowedMimeTypes)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ogiltig filtyp. Endast MP4 och WebM-videor är tillåtna.']);
    exit;
}

// Skapa mapp för videouppladdningar om den inte finns
$uploadDir = '../upload/videos/';
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Kunde inte skapa uppladdningsmapp']);
        exit;
    }
}

// Generera ett säkert filnamn
$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$targetPath = $uploadDir . $filename;

// Flytta den uppladdade filen
if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Sätt rätt filrättigheter
    chmod($targetPath, 0644);

    // Logga uppladdningen
    logActivity($_SESSION['user_email'], "Laddade upp video: " . $filename);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'url' => $filename]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Kunde inte spara filen']);
}
