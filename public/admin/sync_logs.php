<?php
/**
 * Stimma - Synkloggar
 *
 * Visar loggar från API-användarsynkroniseringar.
 * Admin ser sin domän, super_admin ser alla.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isAdmin = $currentUser['is_admin'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';

if (!$isAdmin && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att se synkloggar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$page_title = 'Synkloggar';

// Paginering
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Hämta totalt antal och loggar
if ($isSuperAdmin) {
    $totalLogs = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".sync_log")['count'] ?? 0;
    $logs = query(
        "SELECT sl.*, ak.api_key_prefix
         FROM " . DB_DATABASE . ".sync_log sl
         LEFT JOIN " . DB_DATABASE . ".api_keys ak ON sl.api_key_id = ak.id
         ORDER BY sl.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
} else {
    $totalLogs = queryOne(
        "SELECT COUNT(*) as count FROM " . DB_DATABASE . ".sync_log WHERE domain = ?",
        [$userDomain]
    )['count'] ?? 0;
    $logs = query(
        "SELECT sl.*, ak.api_key_prefix
         FROM " . DB_DATABASE . ".sync_log sl
         LEFT JOIN " . DB_DATABASE . ".api_keys ak ON sl.api_key_id = ak.id
         WHERE sl.domain = ?
         ORDER BY sl.created_at DESC
         LIMIT $perPage OFFSET $offset",
        [$userDomain]
    );
}

$totalPages = max(1, ceil($totalLogs / $perPage));

require_once 'include/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-muted">
                        <i class="bi bi-arrow-repeat me-2"></i>Synkloggar
                        <span class="badge bg-secondary ms-2"><?= $totalLogs ?> poster</span>
                    </h6>
                    <a href="api_keys.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-key me-1"></i>API-nycklar
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($logs)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>Inga synkloggar finns ännu.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <?php if ($isSuperAdmin): ?><th>Domän</th><?php endif; ?>
                                    <th>Nyckel</th>
                                    <th>I payload</th>
                                    <th>Skapade</th>
                                    <th>Uppdaterade</th>
                                    <th>Inaktiverade</th>
                                    <th>Reaktiverade</th>
                                    <th>Status</th>
                                    <th>Tid</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <small><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></small>
                                    </td>
                                    <?php if ($isSuperAdmin): ?>
                                    <td><strong><?= htmlspecialchars($log['domain']) ?></strong></td>
                                    <?php endif; ?>
                                    <td><code><?= htmlspecialchars($log['api_key_prefix'] ?? '?') ?></code></td>
                                    <td><span class="badge bg-info"><?= (int)$log['users_in_payload'] ?></span></td>
                                    <td>
                                        <?php if ((int)$log['users_created'] > 0): ?>
                                            <span class="badge bg-success"><?= (int)$log['users_created'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$log['users_updated'] > 0): ?>
                                            <span class="badge bg-primary"><?= (int)$log['users_updated'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$log['users_deactivated'] > 0): ?>
                                            <span class="badge bg-warning text-dark"><?= (int)$log['users_deactivated'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$log['users_reactivated'] > 0): ?>
                                            <span class="badge bg-success"><?= (int)$log['users_reactivated'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusBadges = [
                                            'success' => 'bg-success',
                                            'partial' => 'bg-warning text-dark',
                                            'failed' => 'bg-danger'
                                        ];
                                        $statusLabels = [
                                            'success' => 'OK',
                                            'partial' => 'Delvis',
                                            'failed' => 'Fel'
                                        ];
                                        $badge = $statusBadges[$log['status']] ?? 'bg-secondary';
                                        $label = $statusLabels[$log['status']] ?? $log['status'];
                                        ?>
                                        <span class="badge <?= $badge ?>"><?= $label ?></span>
                                        <?php if (!empty($log['error_message'])): ?>
                                            <i class="bi bi-exclamation-circle text-danger ms-1" title="<?= htmlspecialchars($log['error_message']) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= $log['duration_ms'] !== null ? $log['duration_ms'] . ' ms' : '-' ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Synklogg-paginering">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page - 1 ?>">Föregående</a>
                            </li>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page + 1 ?>">Nästa</a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
