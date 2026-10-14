<?php
/**
 * Stimma - Systemnycklar (insyns-API:t), 2026-10-14
 *
 * Superadmin skapar och återkallar systemnycklar med läsbehörighet till
 * /api/v1/insyn/ (alla organisationer, bara GET). Se docs/API-INSYN.md.
 * Nyckeln visas en gång; bara SHA-256-hashen sparas.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/insyn_api.php';

require_once 'include/auth_check.php';

if (!isSuperAdminUser()) {
    $_SESSION['message'] = 'Bara superadmin kan hantera systemnycklar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$currentUser = queryOne("SELECT id, email FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$page_title = 'Systemnycklar';
$db = DB_DATABASE;

// Tabellerna skapas av migrations/051_system_api_keys.sql. Utan dem visas en
// tydlig uppmaning i stället för en tom lista (query() sväljer SQL-fel).
$tablesExist = (bool)queryOne("SHOW TABLES LIKE 'system_api_keys'") && (bool)queryOne("SHOW TABLES LIKE 'system_api_log'");

if ($tablesExist && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: system_api_keys.php');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $ips = trim(preg_replace('/[\s,]+/', ', ', $_POST['allowed_ips'] ?? ''), ', ');
        if ($name === '' || mb_strlen($name) > 100) {
            $_SESSION['message'] = 'Ange ett namn (högst 100 tecken), t.ex. "Magnus (AIPL)".';
            $_SESSION['message_type'] = 'danger';
        } elseif ($ips !== '' && !insynValidIpList($ips)) {
            $_SESSION['message'] = 'Ogiltig IP-lista. Ange adresser eller nät, t.ex. 203.0.113.10, 10.0.0.0/8.';
            $_SESSION['message_type'] = 'danger';
        } else {
            [$raw, $hash, $prefix] = insynGenerateKey();
            getDb()->prepare(
                "INSERT INTO $db.system_api_keys (name, scope, api_key_hash, api_key_prefix, allowed_ips, is_active, created_by)
                 VALUES (?, 'insyn', ?, ?, ?, 1, ?)"
            )->execute([$name, $hash, $prefix, $ips !== '' ? $ips : null, $currentUser['id']]);
            logActivity($_SESSION['user_email'], 'Skapade systemnyckel', [
                'action' => 'system_api_key_created', 'name' => $name, 'prefix' => $prefix, 'allowed_ips' => $ips,
            ]);
            // Visas en gång efter omdirigeringen, sedan aldrig mer.
            $_SESSION['new_system_key'] = $raw;
            $_SESSION['message'] = "Systemnyckeln \"$name\" är skapad. Kopiera den nu – den visas aldrig igen.";
            $_SESSION['message_type'] = 'success';
        }
        header('Location: system_api_keys.php');
        exit;
    }

    if ($action === 'revoke') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $key = queryOne("SELECT id, name, api_key_prefix, revoked_at FROM $db.system_api_keys WHERE id = ?", [$keyId]);
        if (!$key) {
            $_SESSION['message'] = 'Nyckeln finns inte.';
            $_SESSION['message_type'] = 'danger';
        } elseif ($key['revoked_at'] !== null) {
            $_SESSION['message'] = 'Nyckeln är redan återkallad.';
            $_SESSION['message_type'] = 'warning';
        } else {
            // Återkallad är slutgiltigt: nyckeln kan inte aktiveras igen. Behövs en ny, skapa en ny.
            getDb()->prepare("UPDATE $db.system_api_keys SET is_active = 0, revoked_at = NOW(), revoked_by = ? WHERE id = ?")
                ->execute([$currentUser['id'], $keyId]);
            logActivity($_SESSION['user_email'], 'Återkallade systemnyckel', [
                'action' => 'system_api_key_revoked', 'key_id' => $keyId, 'name' => $key['name'], 'prefix' => $key['api_key_prefix'],
            ]);
            $_SESSION['message'] = "Systemnyckeln \"{$key['name']}\" är återkallad. Den slutar fungera direkt.";
            $_SESSION['message_type'] = 'success';
        }
        header('Location: system_api_keys.php');
        exit;
    }
}

$newKey = $_SESSION['new_system_key'] ?? null;
unset($_SESSION['new_system_key']);

$keys = $tablesExist ? query(
    "SELECT k.*, cu.email AS created_by_email, ru.email AS revoked_by_email,
            (SELECT COUNT(*) FROM $db.system_api_log l WHERE l.key_id = k.id) AS calls,
            (SELECT COUNT(*) FROM $db.system_api_log l WHERE l.key_id = k.id AND l.created_at > NOW() - INTERVAL 1 DAY) AS calls_24h
       FROM $db.system_api_keys k
       LEFT JOIN $db.users cu ON cu.id = k.created_by
       LEFT JOIN $db.users ru ON ru.id = k.revoked_by
      ORDER BY k.revoked_at IS NOT NULL, k.created_at DESC"
) : [];
$log = $tablesExist ? query(
    "SELECT l.*, k.name, k.api_key_prefix FROM $db.system_api_log l
       LEFT JOIN $db.system_api_keys k ON k.id = l.key_id
      ORDER BY l.id DESC LIMIT 50"
) : [];

require_once 'include/header.php';
?>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <strong>Tabellerna för systemnycklar saknas.</strong> Kör migreringen
    <code>migrations/051_system_api_keys.sql</code> först. Se <code>docs/API-INSYN.md</code>.
</div>
<?php else: ?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3 bg-primary text-white d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold"><i class="bi bi-shield-lock me-2"></i>Systemnycklar med läsbehörighet</h6>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#createKeyModal">
                    <i class="bi bi-plus-lg me-1"></i>Skapa systemnyckel
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    En systemnyckel ger <strong>läsåtkomst till alla organisationer</strong> via
                    <code>/api/v1/insyn/</code>: administratörer, redaktörer, PUB-avtal, kurser och
                    aggregerad statistik. Den kan inte ändra något och fungerar inte mot synk-API:t.
                    Uppgifter om enskilda deltagare lämnas aldrig ut. Varje anrop loggas nedan.
                </p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Namn</th>
                                <th>Nyckel</th>
                                <th>IP-begränsning</th>
                                <th>Skapad</th>
                                <th>Senast använd</th>
                                <th>Anrop</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($keys)): ?>
                            <tr><td colspan="8" class="text-muted">Inga systemnycklar ännu.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($keys as $k): $revoked = $k['revoked_at'] !== null; ?>
                            <tr class="<?= $revoked ? 'text-muted' : '' ?>">
                                <td><strong><?= htmlspecialchars($k['name']) ?></strong></td>
                                <td><code><?= htmlspecialchars($k['api_key_prefix']) ?>…</code></td>
                                <td><?= $k['allowed_ips'] ? '<code>' . htmlspecialchars($k['allowed_ips']) . '</code>' : '<span class="text-muted">Alla adresser</span>' ?></td>
                                <td><?= htmlspecialchars(substr($k['created_at'], 0, 16)) ?><br><small class="text-muted"><?= htmlspecialchars($k['created_by_email'] ?? '') ?></small></td>
                                <td><?= $k['last_used_at'] ? htmlspecialchars(substr($k['last_used_at'], 0, 16)) . '<br><small class="text-muted">' . htmlspecialchars($k['last_used_ip'] ?? '') . '</small>' : '<span class="text-muted">Aldrig</span>' ?></td>
                                <td><?= (int)$k['calls'] ?> <small class="text-muted">(<?= (int)$k['calls_24h'] ?> senaste dygnet)</small></td>
                                <td>
                                    <?php if ($revoked): ?>
                                        <span class="badge bg-secondary">Återkallad</span><br>
                                        <small><?= htmlspecialchars(substr($k['revoked_at'], 0, 16)) ?> <?= htmlspecialchars($k['revoked_by_email'] ?? '') ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$revoked): ?>
                                    <form method="POST" onsubmit="return confirm('Återkalla nyckeln &quot;<?= htmlspecialchars($k['name'], ENT_QUOTES) ?>&quot;? Den slutar fungera direkt och kan inte aktiveras igen.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-octagon me-1"></i>Återkalla</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold"><i class="bi bi-list-ul me-2"></i>Senaste 50 anropen</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Tid</th><th>Nyckel</th><th>Anrop</th><th>Status</th><th>IP</th><th>ms</th></tr></thead>
                        <tbody>
                        <?php if (empty($log)): ?><tr><td colspan="6" class="text-muted">Inga anrop ännu.</td></tr><?php endif; ?>
                        <?php foreach ($log as $l): ?>
                            <tr>
                                <td><?= htmlspecialchars($l['created_at']) ?></td>
                                <td><?= $l['key_id'] ? htmlspecialchars($l['name']) . ' <code>' . htmlspecialchars($l['api_key_prefix']) . '…</code>' : '<span class="text-danger">ingen giltig nyckel</span>' ?></td>
                                <td><code><?= htmlspecialchars($l['method'] . ' ' . $l['endpoint']) ?></code></td>
                                <td><span class="badge <?= (int)$l['status'] < 300 ? 'bg-success' : ((int)$l['status'] < 500 ? 'bg-warning text-dark' : 'bg-danger') ?>"><?= (int)$l['status'] ?></span></td>
                                <td><?= htmlspecialchars($l['ip_address'] ?? '') ?></td>
                                <td><?= (int)$l['duration_ms'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="createKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Skapa systemnyckel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Stäng"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Namn</label>
                        <input type="text" name="name" id="name" class="form-control" maxlength="100" required placeholder="T.ex. Magnus (AIPL)">
                        <div class="form-text">Vem eller vilket system nyckeln är till. Syns i loggen.</div>
                    </div>
                    <div class="mb-3">
                        <label for="allowed_ips" class="form-label">Tillåtna IP-adresser (valfritt)</label>
                        <input type="text" name="allowed_ips" id="allowed_ips" class="form-control font-monospace" placeholder="203.0.113.10, 10.0.0.0/8">
                        <div class="form-text">Kommaseparerade adresser eller nät. Tomt = alla adresser.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="submit" class="btn btn-primary">Skapa nyckel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($newKey): ?>
<div class="modal fade" id="showKeyModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Kopiera systemnyckeln</h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>Viktigt!</strong> Nyckeln visas bara den här gången och ger läsåtkomst till alla
                    organisationer. Lägg den direkt i mottagarens <code>.env</code> som <code>STIMMA_API_KEY</code>.
                </div>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace" id="generatedKeyField" value="<?= htmlspecialchars($newKey) ?>" readonly>
                    <button class="btn btn-outline-primary" type="button" onclick="copyKey()"><i class="bi bi-clipboard"></i> Kopiera</button>
                </div>
                <div id="copyFeedback" class="text-success d-none"><i class="bi bi-check-circle me-1"></i>Kopierad!</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Jag har kopierat nyckeln</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('showKeyModal')).show();
});
function copyKey() {
    var field = document.getElementById('generatedKeyField');
    navigator.clipboard.writeText(field.value).then(function() {
        document.getElementById('copyFeedback').classList.remove('d-none');
    });
}
</script>
<?php endif; ?>

<?php endif; ?>

<?php require_once 'include/footer.php'; ?>
