<?php
/**
 * Stimma — AJAX-endpoint: markera meddelande som "visa inte igen"
 * för aktuell användare. Kräver inloggad admin/redaktör.
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/announcements.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$user = queryOne(
    "SELECT id, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?",
    [$_SESSION['user_email']]
);
if (!$user || (!$user['is_admin'] && !$user['is_editor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

$announcementId = (int)($_POST['announcement_id'] ?? 0);
if ($announcementId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

dismissAnnouncementForUser((int)$user['id'], $announcementId);
echo json_encode(['success' => true]);
