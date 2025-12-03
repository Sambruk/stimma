<?php
require_once '/var/www/html/include/config.php';
require_once '/var/www/html/include/database.php';

// Test startBackgroundProcessor
$processorPath = realpath('/var/www/html/admin/cron/process_ai_jobs.php');
$logFile = '/var/www/html/upload/test_processor_' . date('Y-m-d_H-i-s') . '.log';
$phpPath = '/usr/local/bin/php';

$command = sprintf(
    'nohup setsid %s %s > %s 2>&1 </dev/null &',
    escapeshellarg($phpPath),
    escapeshellarg($processorPath),
    escapeshellarg($logFile)
);

echo "Command: $command\n";
echo "Log file: $logFile\n";

exec($command);

echo "Exec returned\n";
echo "Waiting 2 seconds...\n";
sleep(2);

if (file_exists($logFile)) {
    echo "Log file exists!\n";
    echo "Contents:\n";
    echo file_get_contents($logFile);
} else {
    echo "Log file does NOT exist!\n";
}
