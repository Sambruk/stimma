<?php
/**
 * Admin — översikt och manuell körning av cronjobb.
 *
 * Visar cron_jobs-tabellen med senaste körning, status och en "Kör nu"-knapp
 * per jobb. Knappen triggar admin/ajax/run_cron_job.php som kör skriptet
 * synkront och uppdaterar last_run_at/last_run_status.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT role, is_admin FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
$isSuper = ($currentUser['role'] ?? '') === 'super_admin';
if (!$isSuper && empty($currentUser['is_admin'])) {
    $_SESSION['message'] = 'Endast admin/superadmin kan köra cronjobb.';
    $_SESSION['message_type'] = 'danger';
    redirect('index.php');
    exit;
}

$page_title = 'Cronjobb';
$jobs = query("SELECT * FROM " . DB_DATABASE . ".cron_jobs ORDER BY id ASC");

require_once 'include/header.php';
?>

<div class="container-fluid py-3">
    <h4 class="mb-3"><i class="bi bi-gear-fill me-2 text-primary"></i>Cronjobb</h4>

    <div class="alert alert-warning">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Observera:</strong>
        Stimma saknar för närvarande en automatisk schemaläggare — jobben körs
        <strong>inte</strong> automatiskt. Använd "Kör nu"-knapparna nedan för att
        trigga dem manuellt, eller sätt upp en host-crontab enligt instruktionen längst ner.
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Jobb</th>
                        <th>Beskrivning</th>
                        <th>Schema</th>
                        <th>Senaste körning</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($job['display_name']) ?></strong>
                            <div class="small text-muted font-monospace"><?= htmlspecialchars($job['script_path']) ?></div>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($job['description']) ?></td>
                        <td class="small">
                            <?php
                            $days = $job['run_on_days'] ?: '1,2,3,4,5,6,7';
                            $dayCount = count(explode(',', $days));
                            echo sprintf('%02d:%02d', (int)$job['run_at_hour'], (int)$job['run_at_minute']);
                            if ($dayCount === 7) echo ' varje dag';
                            elseif ($dayCount < 7) echo " ($dayCount dagar/vecka)";
                            ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($job['last_run_at'])): ?>
                                <?= htmlspecialchars(date('Y-m-d H:i', strtotime($job['last_run_at']))) ?>
                                <?php if (!empty($job['last_run_duration'])): ?>
                                <div class="text-muted"><?= (int)$job['last_run_duration'] ?> ms</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Aldrig kört</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($job['last_run_at'])): ?>
                                <span class="badge bg-secondary">–</span>
                            <?php elseif ((int)$job['last_run_status'] === 1): ?>
                                <span class="badge bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Fel</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($job['enabled'])): ?>
                                <span class="badge bg-secondary">Inaktiverat</span>
                            <?php else: ?>
                            <button class="btn btn-sm btn-primary run-now-btn" data-job-id="<?= (int)$job['id'] ?>">
                                <i class="bi bi-play-fill"></i> Kör nu
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($job['last_run_message'])): ?>
                    <tr id="job-output-<?= (int)$job['id'] ?>">
                        <td colspan="6" class="bg-light">
                            <details>
                                <summary class="small text-muted">Senaste utskrift (<?= strlen($job['last_run_message']) ?> tecken)</summary>
                                <pre class="small mb-0 mt-2" style="max-height: 200px; overflow-y: auto;"><?= htmlspecialchars($job['last_run_message']) ?></pre>
                            </details>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">Sätt upp automatisk körning</div>
        <div class="card-body">
            <p class="small">Lägg följande rader i host-crontab (som root):</p>
            <pre class="small bg-light p-3 rounded"># Stimma — stegvisa kurs-notifieringar varje timme
0 * * * * docker exec stimma-web-1 php /var/www/html/cron/send_sequential_notifications.php >> /var/log/stimma-cron.log 2>&1

# Stimma — daglig start av stegvisa kurser kl 08:00
0 8 * * * docker exec stimma-web-1 php /var/www/html/cron/process_sequential_starts.php >> /var/log/stimma-cron.log 2>&1

# Stimma — allmänna påminnelser för icke-stegvisa kurser kl 09:00
0 9 * * * docker exec stimma-web-1 php /var/www/html/cron/send_reminders.php >> /var/log/stimma-cron.log 2>&1</pre>
            <p class="small text-muted mb-0">Redigera via <code>sudo crontab -e</code> på servern. Loggen hamnar i <code>/var/log/stimma-cron.log</code>.</p>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.run-now-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var jobId = btn.dataset.jobId;
        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Kör...';

        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('job_id', jobId);

        fetch('ajax/run_cron_job.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                alert((data.success ? '✓ Klart (exit 0)' : '✗ Misslyckades (exit ' + data.exit_code + ')') +
                      '\nTid: ' + (data.duration_ms || 0) + ' ms\n\nSenaste rader:\n' +
                      (data.output || '(ingen output)'));
                location.reload();
            })
            .catch(function() {
                alert('Nätverksfel — kontrollera att PHP-scriptet har behörighet att köra.');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    });
});
</script>

<?php require_once 'include/footer.php'; ?>
