<?php
/**
 * Stimma - API-nyckelhantering
 *
 * Admin-sida för att hantera API-nycklar och aktivera/inaktivera synk per domän.
 * Åtkomst: admin + super_admin.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/api_helpers.php'; // domainCoversEmailDomain()

// Kontrollera att användaren är inloggad och är admin
require_once 'include/auth_check.php';

/**
 * Hitta den primärdomän i systemet som täcker $domain, om $domain är en underdomän.
 *
 * En API-nyckel utfärdas alltid för organisationens primärdomän och gäller då hela
 * organisationen inklusive underdomäner. Därför ska en underdomän aldrig få en egen
 * nyckel — den skulle vara överflödig, och två nycklar med överlappande omfång gör
 * det omöjligt att resonera om vem som får avaktivera vem vid synk.
 *
 * "Primärdomän" avgörs utifrån vad som faktiskt finns i systemet: om någon annan känd
 * domän är ett äkta suffix till $domain, är $domain en underdomän till den. Finns ingen
 * sådan domän behandlas $domain som primär, vilket är rätt även för en organisation som
 * bara använder t.ex. utb.kommun.se.
 *
 * @param string $domain Domänen som ska prövas
 * @return string|null Den täckande primärdomänen, eller null om $domain själv är primär
 */
function findCoveringPrimaryDomain($domain) {
    $domain = strtolower(trim((string)$domain));
    if ($domain === '') {
        return null;
    }

    $known = query(
        "SELECT DISTINCT SUBSTRING_INDEX(email, '@', -1) AS domain FROM " . DB_DATABASE . ".users
         UNION
         SELECT DISTINCT domain FROM " . DB_DATABASE . ".domain_settings
         UNION
         SELECT DISTINCT domain FROM " . DB_DATABASE . ".api_keys"
    );

    $best = null;
    foreach ($known as $row) {
        $candidate = strtolower(trim($row['domain']));
        if ($candidate === '' || $candidate === $domain) {
            continue;
        }
        // Är $domain en underdomän till $candidate?
        if (domainCoversEmailDomain($candidate, $domain)) {
            // Välj den kortaste träffen — den ligger närmast toppen av namnrymden.
            if ($best === null || strlen($candidate) < strlen($best)) {
                $best = $candidate;
            }
        }
    }

    return $best;
}

// Hämta användarens information
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isAdmin = $currentUser['is_admin'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';

// Kontrollera behörighet - endast admin och superadmin
if (!$isAdmin && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att hantera API-nycklar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$page_title = 'API-nycklar';
$generatedKey = null; // Visas bara vid skapande

// Hantera formulärinlämning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: api_keys.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Toggle sync_enabled
    if ($action === 'toggle_sync') {
        $targetDomain = trim($_POST['domain'] ?? '');
        $enabled = (int)($_POST['sync_enabled'] ?? 0);

        // Behörighetskontroll: admin kan bara ändra sin domän
        if (!$isSuperAdmin && $targetDomain !== $userDomain) {
            $_SESSION['message'] = 'Du kan bara ändra synkinställningar för din egen domän.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        // Synkgrinden prövas mot API-nyckelns domän, alltså primärdomänen. Att sätta
        // sync_enabled på en underdomän skulle se ut att göra något utan att ha effekt.
        $coveringDomain = findCoveringPrimaryDomain($targetDomain);
        if ($coveringDomain !== null) {
            $_SESSION['message'] = "Synk styrs på primärdomänen. Ändra inställningen för {$coveringDomain} "
                . "— den gäller även {$targetDomain}.";
            $_SESSION['message_type'] = 'warning';
            header('Location: api_keys.php');
            exit;
        }

        // Uppdatera eller skapa domain_settings
        $existing = queryOne("SELECT id FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?", [$targetDomain]);
        if ($existing) {
            execute("UPDATE " . DB_DATABASE . ".domain_settings SET sync_enabled = ? WHERE domain = ?", [$enabled, $targetDomain]);
        } else {
            execute("INSERT INTO " . DB_DATABASE . ".domain_settings (domain, sync_enabled) VALUES (?, ?)", [$targetDomain, $enabled]);
        }

        $statusText = $enabled ? 'aktiverad' : 'inaktiverad';
        $_SESSION['message'] = "Synkronisering {$statusText} för {$targetDomain}.";
        $_SESSION['message_type'] = 'success';

        logActivity($_SESSION['user_email'], "Ändrade synk-status för {$targetDomain} till {$statusText}");
        header('Location: api_keys.php');
        exit;
    }

    // Generera ny API-nyckel
    if ($action === 'generate_key') {
        $targetDomain = trim($_POST['domain'] ?? '');

        if (empty($targetDomain)) {
            $_SESSION['message'] = 'Domän måste anges.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        // Behörighetskontroll
        if (!$isSuperAdmin && $targetDomain !== $userDomain) {
            $_SESSION['message'] = 'Du kan bara skapa API-nycklar för din egen domän.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        // Endast primärdomäner får ha nycklar. En underdomän täcks redan av
        // primärdomänens nyckel och ska därför inte kunna få en egen.
        $coveringDomain = findCoveringPrimaryDomain($targetDomain);
        if ($coveringDomain !== null) {
            $_SESSION['message'] = "{$targetDomain} är en underdomän till {$coveringDomain}. "
                . "API-nycklar utfärdas bara för primärdomänen, och nyckeln för {$coveringDomain} "
                . "gäller redan för {$targetDomain}.";
            $_SESSION['message_type'] = 'warning';
            header('Location: api_keys.php');
            exit;
        }

        // Kontrollera om det redan finns en aktiv nyckel för domänen
        $existingKey = queryOne(
            "SELECT id FROM " . DB_DATABASE . ".api_keys WHERE domain = ?",
            [$targetDomain]
        );

        if ($existingKey) {
            $_SESSION['message'] = "Det finns redan en API-nyckel för {$targetDomain}. Inaktivera eller regenerera den befintliga.";
            $_SESSION['message_type'] = 'warning';
            header('Location: api_keys.php');
            exit;
        }

        // Generera nyckel: stm_ + 60 hex-tecken
        $rawKey = 'stm_' . bin2hex(random_bytes(30));
        $hash = hash('sha256', $rawKey);
        $prefix = substr($rawKey, 0, 12); // "stm_" + 8 hex

        $description = trim($_POST['description'] ?? '');

        execute(
            "INSERT INTO " . DB_DATABASE . ".api_keys (domain, api_key_hash, api_key_prefix, description, is_active, created_by)
             VALUES (?, ?, ?, ?, 1, ?)",
            [$targetDomain, $hash, $prefix, $description, $currentUser['id']]
        );

        logActivity($_SESSION['user_email'], 'Skapade API-nyckel', [
            'action' => 'api_key_created',
            'domain' => $targetDomain,
            'prefix' => $prefix
        ]);

        // Spara nyckeln för att visa i modal (visas bara denna gång)
        $generatedKey = $rawKey;
        $_SESSION['message'] = "API-nyckel skapad för {$targetDomain}. Kopiera nyckeln nu - den visas aldrig igen!";
        $_SESSION['message_type'] = 'success';
        // Omdirigera INTE - visa nyckeln i modal
    }

    // Inaktivera/aktivera nyckel
    if ($action === 'toggle_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);

        $key = queryOne("SELECT * FROM " . DB_DATABASE . ".api_keys WHERE id = ?", [$keyId]);
        if (!$key) {
            $_SESSION['message'] = 'API-nyckeln hittades inte.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        if (!$isSuperAdmin && $key['domain'] !== $userDomain) {
            $_SESSION['message'] = 'Du kan bara hantera nycklar för din egen domän.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        execute("UPDATE " . DB_DATABASE . ".api_keys SET is_active = ? WHERE id = ?", [$isActive, $keyId]);

        $statusText = $isActive ? 'aktiverad' : 'inaktiverad';
        $_SESSION['message'] = "API-nyckeln har {$statusText}.";
        $_SESSION['message_type'] = 'success';

        logActivity($_SESSION['user_email'], "API-nyckel {$statusText}", [
            'action' => 'api_key_toggled',
            'key_id' => $keyId,
            'domain' => $key['domain'],
            'new_status' => $isActive
        ]);
        header('Location: api_keys.php');
        exit;
    }

    // Regenerera nyckel
    if ($action === 'regenerate_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);

        $key = queryOne("SELECT * FROM " . DB_DATABASE . ".api_keys WHERE id = ?", [$keyId]);
        if (!$key) {
            $_SESSION['message'] = 'API-nyckeln hittades inte.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        if (!$isSuperAdmin && $key['domain'] !== $userDomain) {
            $_SESSION['message'] = 'Du kan bara hantera nycklar för din egen domän.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        $rawKey = 'stm_' . bin2hex(random_bytes(30));
        $hash = hash('sha256', $rawKey);
        $prefix = substr($rawKey, 0, 12);

        execute(
            "UPDATE " . DB_DATABASE . ".api_keys SET api_key_hash = ?, api_key_prefix = ?, is_active = 1 WHERE id = ?",
            [$hash, $prefix, $keyId]
        );

        logActivity($_SESSION['user_email'], 'Regenererade API-nyckel', [
            'action' => 'api_key_regenerated',
            'key_id' => $keyId,
            'domain' => $key['domain']
        ]);

        $generatedKey = $rawKey;
        $_SESSION['message'] = "API-nyckel regenererad för {$key['domain']}. Kopiera nyckeln nu - den visas aldrig igen!";
        $_SESSION['message_type'] = 'success';
    }

    // Radera nyckel
    if ($action === 'delete_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);

        $key = queryOne("SELECT * FROM " . DB_DATABASE . ".api_keys WHERE id = ?", [$keyId]);
        if (!$key) {
            $_SESSION['message'] = 'API-nyckeln hittades inte.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        if (!$isSuperAdmin && $key['domain'] !== $userDomain) {
            $_SESSION['message'] = 'Du kan bara hantera nycklar för din egen domän.';
            $_SESSION['message_type'] = 'danger';
            header('Location: api_keys.php');
            exit;
        }

        execute("DELETE FROM " . DB_DATABASE . ".api_keys WHERE id = ?", [$keyId]);

        $_SESSION['message'] = "API-nyckeln för {$key['domain']} har raderats.";
        $_SESSION['message_type'] = 'success';

        logActivity($_SESSION['user_email'], 'Raderade API-nyckel', [
            'action' => 'api_key_deleted',
            'key_id' => $keyId,
            'domain' => $key['domain']
        ]);
        header('Location: api_keys.php');
        exit;
    }
}

// Hämta data för visning
if ($isSuperAdmin) {
    $apiKeys = query(
        "SELECT ak.*, u.email as creator_email,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".sync_log sl WHERE sl.api_key_id = ak.id) as sync_count,
                (SELECT MAX(sl.created_at) FROM " . DB_DATABASE . ".sync_log sl WHERE sl.api_key_id = ak.id) as last_sync_at
         FROM " . DB_DATABASE . ".api_keys ak
         LEFT JOIN " . DB_DATABASE . ".users u ON ak.created_by = u.id
         ORDER BY ak.domain ASC"
    );

    // Hämta alla domäner med sync_enabled-status
    $allDomains = query(
        "SELECT DISTINCT SUBSTRING_INDEX(email, '@', -1) as domain FROM " . DB_DATABASE . ".users ORDER BY domain"
    );
} else {
    $apiKeys = query(
        "SELECT ak.*, u.email as creator_email,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".sync_log sl WHERE sl.api_key_id = ak.id) as sync_count,
                (SELECT MAX(sl.created_at) FROM " . DB_DATABASE . ".sync_log sl WHERE sl.api_key_id = ak.id) as last_sync_at
         FROM " . DB_DATABASE . ".api_keys ak
         LEFT JOIN " . DB_DATABASE . ".users u ON ak.created_by = u.id
         WHERE ak.domain = ?
         ORDER BY ak.created_at DESC",
        [$userDomain]
    );

    $allDomains = [['domain' => $userDomain]];
}

// Hämta sync_enabled status och nyckelinfo för domänerna
$domainSyncStatus = [];
$domainKeyInfo = [];
$domainCoveredBy = []; // underdomän => primärdomän vars nyckel gäller
foreach ($allDomains as $d) {
    $ds = queryOne("SELECT sync_enabled FROM " . DB_DATABASE . ".domain_settings WHERE domain = ?", [$d['domain']]);
    $domainSyncStatus[$d['domain']] = $ds ? (int)$ds['sync_enabled'] : 0;

    $dk = queryOne("SELECT id, api_key_prefix, is_active FROM " . DB_DATABASE . ".api_keys WHERE domain = ?", [$d['domain']]);
    $domainKeyInfo[$d['domain']] = $dk ?: null;

    $domainCoveredBy[$d['domain']] = findCoveringPrimaryDomain($d['domain']);
}

require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-arrow-repeat me-2"></i>Synkronisering per domän
                </h6>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Aktivera eller inaktivera API-synkronisering per domän. När synk är aktiverad kan externa system pusha användare via API.</p>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Domän</th>
                                <th>Synk-status</th>
                                <th>API-nyckel</th>
                                <th>Åtgärd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allDomains as $d):
                                $dom = $d['domain'];
                                $syncEnabled = $domainSyncStatus[$dom] ?? 0;
                                $keyInfo = $domainKeyInfo[$dom] ?? null;
                                $coveredBy = $domainCoveredBy[$dom] ?? null;
                                $parentKey = $coveredBy ? ($domainKeyInfo[$coveredBy] ?? null) : null;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($dom) ?></strong>
                                    <?php if ($coveredBy): ?>
                                        <br><small class="text-muted"><i class="bi bi-diagram-2 me-1"></i>Underdomän till <?= htmlspecialchars($coveredBy) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // Synkgrinden i api/sync_users.php prövas mot API-nyckelns domän, alltså
                                    // primärdomänen. En underdomäns egen sync_enabled styr därför ingenting —
                                    // visa det ärvda läget i stället för ett värde som inte har någon effekt.
                                    $effectiveSync = $coveredBy ? ($domainSyncStatus[$coveredBy] ?? 0) : $syncEnabled;
                                    ?>
                                    <?php if ($effectiveSync): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktiverad</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inaktiverad</span>
                                    <?php endif; ?>
                                    <?php if ($coveredBy): ?>
                                        <br><small class="text-muted">Ärvd från <?= htmlspecialchars($coveredBy) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($keyInfo): ?>
                                        <code><?= htmlspecialchars($keyInfo['api_key_prefix']) ?>...</code>
                                        <?php if ($keyInfo['is_active']): ?>
                                            <span class="badge bg-success ms-1">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger ms-1">Inaktiv</span>
                                        <?php endif; ?>
                                    <?php elseif ($coveredBy): ?>
                                        <?php if ($parentKey): ?>
                                            <span class="text-muted">Täcks av <code><?= htmlspecialchars($parentKey['api_key_prefix']) ?>...</code> (<?= htmlspecialchars($coveredBy) ?>)</span>
                                        <?php else: ?>
                                            <span class="text-muted">Täcks av nyckeln för <?= htmlspecialchars($coveredBy) ?> — ingen skapad än</span>
                                        <?php endif; ?>
                                    <?php elseif ($syncEnabled): ?>
                                        <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>Saknas!</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <?php if (!$coveredBy): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_sync">
                                            <input type="hidden" name="domain" value="<?= htmlspecialchars($dom) ?>">
                                            <input type="hidden" name="sync_enabled" value="<?= $syncEnabled ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-sm <?= $syncEnabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                <?= $syncEnabled ? 'Inaktivera synk' : 'Aktivera synk' ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if (!$keyInfo && $coveredBy): ?>
                                        <span class="text-muted small align-self-center">
                                            Nyckel hanteras på <?= htmlspecialchars($coveredBy) ?>
                                        </span>
                                        <?php elseif (!$keyInfo): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="generate_key">
                                            <input type="hidden" name="domain" value="<?= htmlspecialchars($dom) ?>">
                                            <input type="hidden" name="description" value="Genererad från synk-tabell">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="bi bi-key me-1"></i>Skapa API-nyckel
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal" data-bs-target="#regenerateDomainModal<?= $keyInfo['id'] ?>"
                                                title="Regenerera API-nyckel">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Regenerera nyckel
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($keyInfo): ?>
                            <!-- Regenerera-modal (domänvy) -->
                            <div class="modal fade" id="regenerateDomainModal<?= $keyInfo['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="regenerate_key">
                                            <input type="hidden" name="key_id" value="<?= $keyInfo['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Regenerera API-nyckel</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-warning">
                                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                                    Den befintliga nyckeln (<code><?= htmlspecialchars($keyInfo['api_key_prefix']) ?>...</code>) slutar fungera omedelbart.
                                                    Det externa systemet måste uppdateras med den nya nyckeln.
                                                </div>
                                                <p>Vill du generera en ny nyckel för <strong><?= htmlspecialchars($dom) ?></strong>?</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                                <button type="submit" class="btn btn-warning">Regenerera</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
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
            <div class="card-header py-3 bg-primary text-white d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-key me-2"></i>API-nycklar
                </h6>
                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#generateKeyModal">
                    <i class="bi bi-plus-circle me-1"></i>Skapa ny nyckel
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($apiKeys)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>Inga API-nycklar har skapats ännu.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Domän</th>
                                <th>Prefix</th>
                                <th>Beskrivning</th>
                                <th>Status</th>
                                <th>Senast använd</th>
                                <th>Antal synkar</th>
                                <th>Skapad av</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $key): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($key['domain']) ?></strong></td>
                                <td><code><?= htmlspecialchars($key['api_key_prefix']) ?>...</code></td>
                                <td><?= htmlspecialchars($key['description'] ?? '-') ?></td>
                                <td>
                                    <?php if ($key['is_active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $key['last_used_at'] ? date('Y-m-d H:i', strtotime($key['last_used_at'])) : '<span class="text-muted">Aldrig</span>' ?>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= (int)$key['sync_count'] ?></span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($key['creator_email'] ?? 'Okänd') ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <!-- Toggle aktiv/inaktiv -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_key">
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $key['is_active'] ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-sm <?= $key['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                    title="<?= $key['is_active'] ? 'Inaktivera' : 'Aktivera' ?>">
                                                <i class="bi <?= $key['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                            </button>
                                        </form>
                                        <!-- Regenerera -->
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal" data-bs-target="#regenerateModal<?= $key['id'] ?>"
                                                title="Regenerera nyckel">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                        <!-- Radera -->
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal" data-bs-target="#deleteKeyModal<?= $key['id'] ?>"
                                                title="Radera nyckel">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Regenerera-modal -->
                            <div class="modal fade" id="regenerateModal<?= $key['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="regenerate_key">
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Regenerera API-nyckel</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-warning">
                                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                                    Den befintliga nyckeln (<code><?= htmlspecialchars($key['api_key_prefix']) ?>...</code>) slutar fungera omedelbart.
                                                    Det externa systemet måste uppdateras med den nya nyckeln.
                                                </div>
                                                <p>Vill du generera en ny nyckel för <strong><?= htmlspecialchars($key['domain']) ?></strong>?</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                                <button type="submit" class="btn btn-warning">Regenerera</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Radera-modal -->
                            <div class="modal fade" id="deleteKeyModal<?= $key['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete_key">
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Radera API-nyckel</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-danger">
                                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                                    Denna åtgärd kan inte ångras. Nyckeln för <strong><?= htmlspecialchars($key['domain']) ?></strong> raderas permanent.
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                                <button type="submit" class="btn btn-danger">Radera</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- API-dokumentation -->
<div class="row">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="bi bi-book me-2"></i>API-dokumentation
                </h6>
            </div>
            <div class="card-body">
                <h6>Endpoints</h6>
                <pre class="bg-light p-3 rounded"><code>POST <?= htmlspecialchars(SITE_URL) ?>/api/sync_users.php
GET  <?= htmlspecialchars(SITE_URL) ?>/api/course_status.php?email=...&amp;course_id=...</code></pre>

                <h6>Headers</h6>
                <pre class="bg-light p-3 rounded"><code>Content-Type: application/json
Authorization: Bearer stm_din_api_nyckel_har</code></pre>

                <h6>Request body</h6>
                <pre class="bg-light p-3 rounded"><code>{
  "users": [
    {
      "email": "anna.svensson@domän.se",
      "name": "Anna Svensson",
      "role": "redaktör",
      "organization": "Kommun/Förvaltning/Avdelning"
    }
  ]
}</code></pre>

                <h6>Fält</h6>
                <table class="table table-sm">
                    <thead><tr><th>Fält</th><th>Typ</th><th>Obligatoriskt</th><th>Beskrivning</th></tr></thead>
                    <tbody>
                        <tr><td><code>email</code></td><td>string</td><td>Ja</td><td>E-postadress på nyckelns domän eller någon av dess underdomäner</td></tr>
                        <tr><td><code>name</code></td><td>string</td><td>Ja</td><td>Användarens namn</td></tr>
                        <tr><td><code>role</code></td><td>string</td><td>Nej</td><td><code>användare</code> (standard), <code>redaktör</code> eller <code>admin</code>. De äldre värdena <code>student</code> och <code>teacher</code> fungerar fortfarande</td></tr>
                        <tr><td><code>organization</code></td><td>string<br>eller lista</td><td>Nej</td><td>Organisationstaggar separerade med / (t.ex. "Kommun/Förvaltning/Avdelning"). Den svenska stavningen <code>organisation</code> accepteras också. <strong>Utelämnas fältet lämnas befintliga taggar orörda</strong> — skicka tom sträng för att rensa dem</td></tr>
                        <tr><td><code>delete</code></td><td>bool</td><td>Nej</td><td><strong>Raderar användaren permanent.</strong> Endast <code>email</code> behöver anges på en sådan post</td></tr>
                    </tbody>
                </table>

                <h6>Domänomfång</h6>
                <div class="alert alert-info">
                    <i class="bi bi-diagram-2 me-2"></i>
                    En API-nyckel utfärdas för organisationens <strong>primärdomän</strong> och gäller då även alla
                    <strong>underdomäner</strong>. En nyckel för <code>kommun.se</code> får alltså synka både
                    <code>anna@kommun.se</code> och <code>bo@utb.kommun.se</code>. Underdomäner har inga egna nycklar
                    och ingen egen synkinställning.
                    <br><br>
                    Det innebär också att <strong>en synk måste innehålla hela organisationen</strong>. Skickas bara
                    <code>kommun.se</code>-användare markeras användarna på <code>utb.kommun.se</code> som inaktiva,
                    eftersom de saknas i listan. Sätt <code>"deactivate_missing": false</code> om ni behöver synka
                    en del i taget.
                </div>

                <h6>Radera en användare</h6>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Sätt <code>"delete": true</code> på en post för att <strong>radera kontot permanent</strong>
                    i stället för att inaktivera det:
                    <pre class="bg-light p-2 rounded mt-2 mb-2"><code>{"users": [{"email": "anna.svensson@domän.se", "delete": true}]}</code></pre>
                    <ul class="mb-0">
                        <li><strong>Raderingen går inte att ångra.</strong> Personens <strong>diplom raderas med kontot</strong> —
                            genomförd utbildning går inte att styrka i efterhand. Inaktivera i stället om utbildningsbeviset ska finnas kvar.</li>
                        <li>Framsteg, quizsvar, kursanmälningar, org-taggar och märken följer med.</li>
                        <li>Signerade PUB-avtal raderas <em>inte</em> — de är organisationens handling med egen rättslig grund.</li>
                        <li>Adressen måste tillhöra nyckelns domän eller en underdomän, precis som vid vanlig synk.</li>
                        <li>Superadmin-konton kan inte raderas via API. Sådana begäranden räknas i <code>deletes_refused</code>.</li>
                        <li>Att radera någon som redan är borta ger inget fel — synken kan köras om utan risk.</li>
                        <li>En payload som bara innehåller raderingsposter inaktiverar <em>inte</em> övriga användare,
                            trots att <code>deactivate_missing</code> är <code>true</code> som standard.</li>
                    </ul>
                </div>

                <h6>Beteende</h6>
                <ul>
                    <li>Användare som finns i listan men inte i systemet <strong>skapas</strong></li>
                    <li>Befintliga användare <strong>uppdateras</strong> (namn, roll, org-taggar)</li>
                    <li>Synkade användare som <strong>saknas</strong> i listan markeras som <code>sync_status='inactive'</code></li>
                    <li><strong>Inloggning påverkas INTE</strong> - inaktiva kan fortfarande logga in</li>
                    <li>Super_admin-användare och icke-synkade användare berörs aldrig</li>
                </ul>

                <h6>Organisationstaggar</h6>
                <div class="alert alert-info">
                    <i class="bi bi-diagram-3 me-2"></i>
                    Varje del av <code>"Kommun/Förvaltning/Avdelning"</code> blir en egen tagg. Taggarna är
                    <strong>platta</strong> — hierarkin lagras inte, så ett filter på "Förvaltningen" träffar
                    inte automatiskt avdelningarna under den.
                    <ul class="mb-0 mt-2">
                        <li><strong>Fältet saknas i posten</strong> → befintliga taggar lämnas orörda. En synk
                            utan organisationskolumn kan alltså inte råka nollställa taggar som satts för hand.</li>
                        <li><strong>Fältet finns men är tomt</strong> → taggarna tas bort. Det räknas som
                            <code>org_tags_rensade</code> i svaret.</li>
                        <li>Taggar kan också sättas för hand under <strong>Användare</strong> i adminpanelen.
                            En efterföljande synk som innehåller fältet skriver dock om dem.</li>
                    </ul>
                </div>

                <h6>Svar</h6>
                <pre class="bg-light p-3 rounded"><code>{
  "success": true,
  "summary": {
    "total_in_payload": 45,
    "created": 2,
    "updated": 43,
    "deactivated": 0,
    "deleted": 0,
    "deletes_refused": 0,
    "reactivated": 0,
    "org_tags_satta": 43,
    "org_tags_rensade": 0
  },
  "sync_id": 128,
  "warnings": [
    "Fältet \"department\" känns inte igen och ignorerades (45 poster). Tillåtna fält: email, name, role, delete, organization, organisation."
  ]
}</code></pre>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong><code>warnings</code> finns bara när något behöver uppmärksammas</strong> och gör inte
                    synken misslyckad. Fält som Stimma inte känner igen ignoreras tyst i själva synken — varningen
                    är enda sättet att upptäcka ett felstavat fältnamn. <strong>Kontrollera
                    <code>org_tags_satta</code></strong> om ni skickar taggar: står det 0 kom fältet aldrig fram.
                </div>

                <h6>Felkoder</h6>
                <table class="table table-sm">
                    <tbody>
                        <tr><td><code>400</code></td><td>Valideringsfel i request body</td></tr>
                        <tr><td><code>401</code></td><td>Ogiltig eller saknad API-nyckel</td></tr>
                        <tr><td><code>403</code></td><td>Synk ej aktiverad för domänen / domänmismatch</td></tr>
                        <tr><td><code>404</code></td><td>Kurs eller användare hittades inte</td></tr>
                        <tr><td><code>413</code></td><td>Mer än 10 000 användare</td></tr>
                        <tr><td><code>429</code></td><td>Rate limit (max 10 req/h)</td></tr>
                    </tbody>
                </table>

                <hr>

                <h6>Kursstatus-endpoint</h6>
                <p class="text-muted">Kontrollera om en användare har slutfört en kurs. Kurs-ID visas i kurslistan under Kurser.</p>
                <pre class="bg-light p-3 rounded"><code>GET /api/course_status.php?email=anna@domän.se&amp;course_id=25
Authorization: Bearer stm_din_api_nyckel_har</code></pre>

                <h6>Svar (slutförd)</h6>
                <pre class="bg-light p-3 rounded"><code>{
  "success": true,
  "status": 1,
  "email": "anna@domän.se",
  "course_id": 25,
  "course_title": "Grundkurs GDPR",
  "completed_at": "2026-03-04"
}</code></pre>

                <h6>Svar (pågående)</h6>
                <pre class="bg-light p-3 rounded"><code>{
  "success": true,
  "status": 0,
  "email": "anna@domän.se",
  "course_id": 25,
  "course_title": "Grundkurs GDPR",
  "progress": "3/5"
}</code></pre>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Skapa ny nyckel -->
<div class="modal fade" id="generateKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="generate_key">
                <div class="modal-header">
                    <h5 class="modal-title">Skapa ny API-nyckel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="domain" class="form-label">Domän</label>
                        <?php if ($isSuperAdmin): ?>
                        <select name="domain" id="domain" class="form-select" required>
                            <option value="">Välj domän...</option>
                            <?php foreach ($allDomains as $d): ?>
                            <option value="<?= htmlspecialchars($d['domain']) ?>"><?= htmlspecialchars($d['domain']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($userDomain) ?>" readonly>
                        <input type="hidden" name="domain" value="<?= htmlspecialchars($userDomain) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Beskrivning (valfritt)</label>
                        <input type="text" name="description" id="description" class="form-control" placeholder="T.ex. AD-synk, HR-system...">
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

<?php if ($generatedKey): ?>
<!-- Modal: Visa genererad nyckel -->
<div class="modal fade" id="showKeyModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Kopiera din API-nyckel</h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>Viktigt!</strong> Denna nyckel visas bara EN gång. Kopiera den och förvara den säkert.
                </div>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace" id="generatedKeyField" value="<?= htmlspecialchars($generatedKey) ?>" readonly>
                    <button class="btn btn-outline-primary" type="button" onclick="copyKey()">
                        <i class="bi bi-clipboard"></i> Kopiera
                    </button>
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

<?php require_once 'include/footer.php'; ?>
