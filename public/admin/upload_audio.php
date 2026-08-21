<?php
/**
 * Stimma - Ljuduppladdning för lektioner
 *
 * Accepterar MP3, OGG, WAV och M4A. Max 50 MB (ljudfiler är betydligt mindre
 * än video, vi håller storleken tight). Validerar både extension och MIME.
 *
 * Returnerar JSON: {success, url|error}
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

require_once 'include/ajax_auth_check.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'Ingen fil uppladdad';
    if (isset($_FILES['audio'])) {
        switch ($_FILES['audio']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'Filen är för stor. Max storlek är 50 MB.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = 'Filen laddades bara upp delvis. Försök igen.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'Ingen fil vald.';
                break;
        }
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['audio'];
$allowedExtensions = ['mp3', 'ogg', 'wav', 'm4a'];
$maxFileSize = 50 * 1024 * 1024; // 50 MB

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions, true)) {
    echo json_encode(['success' => false, 'error' => 'Ogiltig filändelse. Tillåtna format: MP3, OGG, WAV, M4A.']);
    exit;
}

if ($file['size'] > $maxFileSize) {
    echo json_encode(['success' => false, 'error' => 'Filen är för stor. Max storlek är 50 MB.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedMimeTypes = [
    'audio/mpeg', 'audio/mp3',
    'audio/ogg', 'application/ogg',
    'audio/wav', 'audio/wave', 'audio/x-wav',
    'audio/mp4', 'audio/x-m4a', 'audio/aac',
];

if (!in_array($mimeType, $allowedMimeTypes, true)) {
    echo json_encode(['success' => false, 'error' => 'Ogiltig filtyp (' . $mimeType . '). Tillåtna: MP3, OGG, WAV, M4A.']);
    exit;
}

$uploadDir = __DIR__ . '/../upload/audio/';
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte skapa uppladdningsmapp']);
        exit;
    }
}

$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    chmod($targetPath, 0644);
    logActivity($_SESSION['user_email'], 'Laddade upp ljudfil: ' . $filename);
    echo json_encode(['success' => true, 'url' => $filename]);
} else {
    echo json_encode(['success' => false, 'error' => 'Kunde inte spara filen']);
}
