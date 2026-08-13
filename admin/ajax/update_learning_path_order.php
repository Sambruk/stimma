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

/**
 * AJAX: uppdatera sorteringsordningen för lärvägar (drag-and-drop i listan).
 *
 * Behörighet kontrolleras per rad via userCanModifyLearningPath() —
 * lärvägar användaren inte äger hoppas över tyst (samma mönster som
 * admin/update_course_order.php).
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../../include/learning_paths.php';

// Include AJAX-compatible authentication check
require_once '../include/ajax_auth_check.php';

if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

if (!isset($_POST['paths'])) {
    echo json_encode(['success' => false, 'message' => 'Ingen data mottagen.']);
    exit;
}

try {
    $paths = json_decode($_POST['paths'], true);
    if (!is_array($paths)) {
        throw new Exception('Ogiltigt dataformat');
    }

    $map = [];
    foreach ($paths as $p) {
        $pid = (int)($p['id'] ?? 0);
        if ($pid > 0) {
            $map[$pid] = (int)($p['order'] ?? 0);
        }
    }

    $updated = updateLearningPathSortOrder($map);

    echo json_encode(['success' => true, 'message' => "$updated lärvägar uppdaterade."]);
} catch (Exception $e) {
    error_log('update_learning_path_order: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Kunde inte uppdatera ordningen.']);
}
