<?php
/**
 * Trigga ett cronjob manuellt från admin-UI:t.
 *
 * Behörighet: admin eller superadmin. CSRF krävs.
 * Input: POST job_id (motsvarar rad i cron_jobs-tabellen).
 *
 * Kör script_path med PHP-binary och returnerar stdout/stderr + exit code
 * samt uppdaterar cron_jobs.last_run_at / last_run_status.
 */
require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';
require_once '../include/ajax_auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken.']);
    exit;
}

$currentUser = queryOne("SELECT role, is_admin FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$isSuper = ($currentUser['role'] ?? '') === 'super_admin';
if (!$isSuper && empty($currentUser['is_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Otillräcklig behörighet.']);
    exit;
}

$jobId = (int)($_POST['job_id'] ?? 0);
$job = queryOne("SELECT * FROM " . DB_DATABASE . ".cron_jobs WHERE id = ?", [$jobId]);
if (!$job) {
    echo json_encode(['success' => false, 'message' => 'Cronjobbet hittades inte.']);
    exit;
}

// Bygg säker sökväg — script_path är relativ mot Stimma-rot
$scriptRoot = realpath(__DIR__ . '/../..');
$scriptPath = $scriptRoot . '/' . ltrim($job['script_path'], '/');
if (!file_exists($scriptPath) || strpos(realpath($scriptPath), $scriptRoot) !== 0) {
    echo json_encode(['success' => false, 'message' => 'Skriptfilen hittades inte eller är utanför Stimma-roten.']);
    exit;
}

$started = microtime(true);
$output = [];
$exitCode = 0;
exec('php ' . escapeshellarg($scriptPath) . ' 2>&1', $output, $exitCode);
$durationMs = (int)((microtime(true) - $started) * 1000);
$status = $exitCode === 0 ? 1 : 0;
$joined = implode("\n", array_slice($output, -80)); // sista 80 rader

execute(
    "UPDATE " . DB_DATABASE . ".cron_jobs
     SET last_run_at = NOW(), last_run_status = ?, last_run_message = ?, last_run_duration = ?
     WHERE id = ?",
    [$status, mb_substr($joined, 0, 4000), $durationMs, $jobId]
);

logActivity($_SESSION['user_email'], 'Körde cronjob manuellt: ' . $job['name'] . ' (exit ' . $exitCode . ')');

echo json_encode([
    'success' => $exitCode === 0,
    'exit_code' => $exitCode,
    'duration_ms' => $durationMs,
    'output' => $joined,
]);
