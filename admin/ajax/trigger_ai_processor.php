<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Trigger AI processor - starts background process using pcntl_fork or fallback
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json');

// Kontrollera att användaren är inloggad
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Ej inloggad']);
    exit;
}

// Validera CSRF
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken']);
    exit;
}

// Check if there are pending jobs
$pendingJob = queryOne("SELECT id FROM " . DB_DATABASE . ".ai_course_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");

if (!$pendingJob) {
    echo json_encode(['success' => false, 'message' => 'Inga väntande jobb hittades']);
    exit;
}

$processorPath = realpath(__DIR__ . '/../cron/process_ai_jobs.php');

if (!$processorPath || !file_exists($processorPath)) {
    echo json_encode(['success' => false, 'message' => 'Processor script not found']);
    exit;
}

$logFile = '/var/www/html/upload/ai_processor_' . date('Y-m-d_H-i-s') . '.log';

// Method 1: Try using exec with true background execution (using & and disown-like behavior)
// This is the most reliable method for Apache/PHP
$phpPath = '/usr/local/bin/php';
$command = sprintf(
    '(%s %s > %s 2>&1) &',
    escapeshellarg($phpPath),
    escapeshellarg($processorPath),
    escapeshellarg($logFile)
);

// Use shell_exec with explicit background shell
$result = shell_exec('/bin/sh -c ' . escapeshellarg($command) . ' 2>&1');

// Give the process time to start and create the log file
usleep(1000000); // 1 second

// Verify the process started by checking if job status changed or log exists
$jobStatus = queryOne("SELECT status FROM " . DB_DATABASE . ".ai_course_jobs WHERE id = ?", [$pendingJob['id']]);
$logExists = file_exists($logFile);

if ($jobStatus && $jobStatus['status'] === 'processing' || $logExists) {
    echo json_encode([
        'success' => true,
        'message' => 'Processor started successfully',
        'job_id' => $pendingJob['id'],
        'log' => $logFile
    ]);
} else {
    // Fallback: run synchronously if background didn't work
    // But first, send a response to the client
    echo json_encode([
        'success' => true,
        'message' => 'Processor starting (synchronous mode)',
        'job_id' => $pendingJob['id']
    ]);

    // Flush and close connection to client
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ignore_user_abort(true);
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    // Set longer timeout and run processor
    set_time_limit(600);
    ob_start();
    include $processorPath;
    $output = ob_get_clean();
    file_put_contents($logFile, $output);
}
