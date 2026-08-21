<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * AJAX endpoint: Manuell start av stegvis kurs (registrera användare + kö e-post)
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/mail.php';

require_once '../include/ajax_auth_check.php';

header('Content-Type: application/json');

// Verifiera CSRF
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

// Kontrollera behörighet
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $currentUser && $currentUser['is_admin'] == 1;
$isEditor = $currentUser && $currentUser['is_editor'] == 1;

if (!$isAdmin && !$isEditor) {
    echo json_encode(['success' => false, 'message' => 'Ingen behörighet.']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);

if (!$courseId) {
    echo json_encode(['success' => false, 'message' => 'Kurs-ID saknas.']);
    exit;
}

// Hämta kurs
$course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$course || !$course['sequential_mode']) {
    echo json_encode(['success' => false, 'message' => 'Kursen hittades inte eller är inte stegvis.']);
    exit;
}

// Kontrollera status
if ($course['sequential_status'] && !in_array($course['sequential_status'], ['pending', null])) {
    echo json_encode(['success' => false, 'message' => 'Kursen har redan startats (status: ' . $course['sequential_status'] . ').']);
    exit;
}

// Redaktörskontroll
if (!$isAdmin) {
    $hasAccess = queryOne(
        "SELECT c.id FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON c.id = ce.course_id
         WHERE c.id = ? AND (c.author_id = ? OR ce.email = ?)",
        [$courseId, $currentUser['id'], $_SESSION['user_email']]
    );
    if (!$hasAccess) {
        echo json_encode(['success' => false, 'message' => 'Du har inte behörighet till denna kurs.']);
        exit;
    }
}

// Hämta inställningar
$batchSize = 10;
$settingBatch = queryOne("SELECT setting_value FROM " . DB_DATABASE . ".ai_settings WHERE setting_key = 'sequential_batch_size'");
if ($settingBatch) $batchSize = max(1, (int)$settingBatch['setting_value']);

// Hitta berörda användare baserat på org-taggar
$courseOrgTags = query(
    "SELECT tag FROM " . DB_DATABASE . ".course_org_tags WHERE course_id = ?",
    [$courseId]
);

$users = [];
if (!empty($courseOrgTags)) {
    $tags = array_column($courseOrgTags, 'tag');
    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $users = query(
        "SELECT DISTINCT u.id FROM " . DB_DATABASE . ".users u
         JOIN " . DB_DATABASE . ".user_org_tags uot ON u.id = uot.user_id
         WHERE uot.tag IN ($placeholders)
           AND u.email LIKE ?",
        array_merge($tags, ['%@' . $course['organization_domain']])
    );
} else {
    $users = query(
        "SELECT id FROM " . DB_DATABASE . ".users WHERE email LIKE ?",
        ['%@' . $course['organization_domain']]
    );
}

// Sätt status till 'sending'
execute(
    "UPDATE " . DB_DATABASE . ".courses SET sequential_status = 'sending' WHERE id = ?",
    [$courseId]
);

// Hämta första lektionen
$firstLesson = queryOne(
    "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1",
    [$courseId]
);

$enrolledCount = 0;
$firstLessonPairs = [];

foreach ($users as $user) {
    $result = enrollUserInSequentialCourse($user['id'], $courseId);
    if ($result) {
        $enrolledCount++;
        if ($firstLesson) {
            $firstLessonPairs[] = ['user_id' => $user['id'], 'lesson_id' => $firstLesson['id']];
        }
    }
}

// Kö första-lektion-mail
$queuedCount = 0;
if (!empty($firstLessonPairs)) {
    $queuedCount = queueSequentialEmails($courseId, $firstLessonPairs, 'new_lesson');
}

// Bearbeta EN batch inline (snabb feedback)
$result = processEmailQueue($courseId, $batchSize, 0, null);

// Sätt status till 'active'
execute(
    "UPDATE " . DB_DATABASE . ".courses SET sequential_status = 'active' WHERE id = ?",
    [$courseId]
);

// Logga
logActivity($_SESSION['user_email'], 'Startade stegvis kurs manuellt', [
    'action' => 'trigger_sequential_start',
    'course_id' => $courseId,
    'enrolled' => $enrolledCount,
    'queued' => $queuedCount,
    'sent' => $result['sent'],
]);

$remainingQueue = queryOne(
    "SELECT COUNT(*) AS cnt FROM " . DB_DATABASE . ".sequential_email_queue WHERE course_id = ? AND status = 'queued'",
    [$courseId]
);
$remaining = $remainingQueue ? $remainingQueue['cnt'] : 0;

$message = "$enrolledCount användare registrerade, $queuedCount e-post köade.";
if ($result['sent'] > 0) {
    $message .= " {$result['sent']} skickade direkt.";
}
if ($remaining > 0) {
    $message .= " $remaining återstår och skickas via cron.";
}

echo json_encode(['success' => true, 'message' => $message]);
