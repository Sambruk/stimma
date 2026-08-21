<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Hantera AI-kvoter per organisation/domän + modellval per funktion (superadmin).
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/ai_quota.php';
require_once '../include/token_balance.php';
require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

if (!$isSuperAdmin) {
    $_SESSION['message'] = 'Endast superadmin har tillgång till AI-kvoter.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// --- POST: spara kvot eller modellval --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ai_quotas.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_quota') {
        $orgId = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;
        $domain = trim($_POST['domain'] ?? '') ?: null;
        if ($orgId && $domain) $domain = null; // org tar precedens
        $tokenQuota = (int)($_POST['monthly_token_quota'] ?? 0);
        $threshold = (int)($_POST['alert_threshold_pct'] ?? 80);
        $behavior = ($_POST['behavior'] ?? 'block') === 'warn' ? 'warn' : 'block';
        $notes = trim($_POST['notes'] ?? '');

        if (!$orgId && !$domain) {
            $_SESSION['message'] = 'Välj antingen organisation eller domän.';
            $_SESSION['message_type'] = 'danger';
        } else {
            upsertAiQuota($orgId, $domain, $tokenQuota, $threshold, $behavior, $notes ?: null, $currentUser['email']);
            $_SESSION['message'] = 'Kvotinställning sparad.';
            $_SESSION['message_type'] = 'success';
        }
        header('Location: ai_quotas.php');
        exit;
    }

    if ($action === 'save_models') {
        foreach (['course_gen', 'lesson_gen', 'chat', 'image'] as $f) {
            $m = trim($_POST['model_' . $f] ?? '');
            if ($m !== '') setModelForFeature($f, $m, $currentUser['email']);
        }
        $_SESSION['message'] = 'Modellval sparade.';
        $_SESSION['message_type'] = 'success';
        header('Location: ai_quotas.php');
        exit;
    }

    if ($action === 'delete_quota') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            execute("DELETE FROM " . DB_DATABASE . ".ai_quotas WHERE id = ?", [$id]);
            $_SESSION['message'] = 'Kvotinställning borttagen (återgår till standardvärde).';
            $_SESSION['message_type'] = 'success';
        }
        header('Location: ai_quotas.php');
        exit;
    }
}

// --- Hämta data till sidan -------------------------------------------------
$orgs = query("SELECT id, name FROM " . DB_DATABASE . ".organizations ORDER BY name");

// Filter: visa alla scopes (default) eller bara de med anpassad kvota
$showFilter = ($_GET['filter'] ?? 'all') === 'custom' ? 'custom' : 'all';

// Indexera befintliga quotas
$quotaByOrg = [];
$quotaByDomain = [];
foreach (query("SELECT * FROM " . DB_DATABASE . ".ai_quotas") as $q) {
    if (!empty($q['organization_id'])) {
        $quotaByOrg[(int)$q['organization_id']] = $q;
    } elseif (!empty($q['domain'])) {
        $quotaByDomain[mb_strtolower($q['domain'])] = $q;
    }
}

// Organisationernas medlemsdomäner — används för att undvika dubbletter
// (en domän som tillhör en org listas inte separat i fri-domän-listan).
$orgMemberDomains = [];
$orgDomainCounts = [];
foreach (query("SELECT organization_id, domain FROM " . DB_DATABASE . ".organization_domains") as $od) {
    $orgMemberDomains[mb_strtolower($od['domain'])] = (int)$od['organization_id'];
    $oid = (int)$od['organization_id'];
    $orgDomainCounts[$oid] = ($orgDomainCounts[$oid] ?? 0) + 1;
}

// Läs in allowed_domains.txt (källan med 177 vitlistade domäner)
$allowedDomainsFile = __DIR__ . '/../allowed_domains.txt';
$allowedDomains = [];
if (file_exists($allowedDomainsFile)) {
    foreach (file($allowedDomainsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $allowedDomains[] = $line;
        }
    }
    $allowedDomains = array_values(array_unique($allowedDomains));
    sort($allowedDomains);
}

// Bygg en samlad scope-lista: alla organisationer + alla fria domäner.
// För scopes utan egen quota fylls default-värden i; de markeras med
// _is_default = true så UI:t kan skilja dem från anpassade rader.
$existingQuotas = [];

$defaultRowTemplate = [
    'id' => null,
    'monthly_token_quota' => AI_QUOTA_DEFAULT_TOKENS,
    'alert_threshold_pct' => AI_QUOTA_DEFAULT_THRESHOLD_PCT,
    'behavior' => AI_QUOTA_DEFAULT_BEHAVIOR,
    'notes' => null,
    'updated_at' => null,
    'updated_by' => null,
];

foreach ($orgs as $o) {
    $oid = (int)$o['id'];
    $hasQuota = isset($quotaByOrg[$oid]);
    $q = $hasQuota ? $quotaByOrg[$oid] : array_merge($defaultRowTemplate, [
        'organization_id' => $oid, 'domain' => null, 'org_name' => $o['name'],
    ]);
    $q['org_name'] = $o['name'];
    $q['_is_default'] = !$hasQuota;
    $q['_scope_type'] = 'org';
    $q['_member_count'] = $orgDomainCounts[$oid] ?? 0;
    $existingQuotas[] = $q;
}

foreach ($allowedDomains as $dom) {
    if (isset($orgMemberDomains[mb_strtolower($dom)])) continue;
    $hasQuota = isset($quotaByDomain[mb_strtolower($dom)]);
    $q = $hasQuota ? $quotaByDomain[mb_strtolower($dom)] : array_merge($defaultRowTemplate, [
        'organization_id' => null, 'domain' => $dom,
    ]);
    $q['org_name'] = null;
    $q['_is_default'] = !$hasQuota;
    $q['_scope_type'] = 'domain';
    $existingQuotas[] = $q;
}

// Sortera: org först (alfabetiskt), sedan domäner (alfabetiskt)
usort($existingQuotas, function($a, $b) {
    if ($a['_scope_type'] !== $b['_scope_type']) {
        return $a['_scope_type'] === 'org' ? -1 : 1;
    }
    $la = mb_strtolower($a['org_name'] ?? $a['domain'] ?? '');
    $lb = mb_strtolower($b['org_name'] ?? $b['domain'] ?? '');
    return strcmp($la, $lb);
});

// Filtrera om användaren bara vill se anpassade
if ($showFilter === 'custom') {
    $existingQuotas = array_values(array_filter($existingQuotas, function($q) {
        return empty($q['_is_default']);
    }));
}

// Berika med aktuell förbrukning + saldo + visningslabel
$year = (int)date('Y');
$month = (int)date('n');
foreach ($existingQuotas as $i => $q) {
    $usage = getMonthlyAiUsage(
        $q['organization_id'] ? (int)$q['organization_id'] : null,
        $q['domain'] ?: null,
        $year, $month
    );
    $existingQuotas[$i]['_used'] = $usage['total_tokens'];
    $existingQuotas[$i]['_pct'] = $q['monthly_token_quota'] > 0
        ? (int)floor(($usage['total_tokens'] / $q['monthly_token_quota']) * 100)
        : 0;
    $existingQuotas[$i]['_label'] = $q['org_name'] ?: $q['domain'];
    // Saldo finns bara per organisation (inte per ren domän).
    $existingQuotas[$i]['_balance'] = $q['organization_id']
        ? getOrgTokenBalance((int)$q['organization_id'])
        : null;
}

$totalScopeCount = count($orgs) + count(array_filter($allowedDomains, function($d) use ($orgMemberDomains) {
    return !isset($orgMemberDomains[mb_strtolower($d)]);
}));
$customCount = count($quotaByOrg) + count($quotaByDomain);

$availableModels = listAvailableAiModels();
$currentModels = [
    'course_gen' => getModelForFeature('course_gen', 'gpt-4o'),
    'lesson_gen' => getModelForFeature('lesson_gen', 'gpt-4o'),
    'chat'       => getModelForFeature('chat',       'gpt-4o-mini'),
    'image'      => getModelForFeature('image',      'gpt-image-1-mini'),
];

$page_title = 'AI-kvoter & modellval';
require_once 'include/header.php';
?>

<div class="card mb-4">
    <div class="card-header"><strong>Modellval per funktion</strong></div>
    <div class="card-body">
        <p class="text-muted small mb-3">Dyrare modeller (gpt-4o, gpt-4-turbo) ger högre kvalitet men förbrukar kvoten snabbare. För chat räcker oftast gpt-4o-mini.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_models">
            <div class="row g-3">
                <?php foreach (['course_gen' => 'Kursgenerering (bakgrundsjobb)',
                                'lesson_gen' => 'Enstaka lektion (admin)',
                                'chat'       => 'AI-chat per lektion',
                                'image'      => 'Bildgenerering'] as $f => $label): ?>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label"><?= htmlspecialchars($label) ?></label>
                        <select name="model_<?= $f ?>" class="form-select form-select-sm">
                            <?php
                                $models = $f === 'image' ? $availableModels['image'] : $availableModels['text'];
                                foreach ($models as $m):
                                    $price = $f === 'image'
                                        ? '$' . number_format($m['image_per_call_cents'] / 100, 2) . '/bild'
                                        : 'in $' . number_format($m['input_per_1m_cents'] / 100, 2) . ', ut $' . number_format($m['output_per_1m_cents'] / 100, 2) . ' /1M';
                            ?>
                                <option value="<?= htmlspecialchars($m['model']) ?>" <?= $currentModels[$f] === $m['model'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['model']) ?> — <?= $price ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary mt-3">Spara modellval</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <strong>Kvoter</strong>
            <span class="text-muted small ms-2">
                Saldo-läge: månatlig gratisbas fylls på 1:a varje månad och adderas till organisationens saldo.
                AI-anrop blockeras först när <em>saldot</em> går till 0.
            </span>
        </div>
        <div class="btn-group btn-group-sm" role="group">
            <a href="ai_quotas.php" class="btn <?= $showFilter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Alla (<?= (int)$totalScopeCount ?>)
            </a>
            <a href="ai_quotas.php?filter=custom" class="btn <?= $showFilter === 'custom' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Endast anpassade (<?= (int)$customCount ?>)
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>Scope</th>
                <th class="text-end">Aktuellt saldo</th>
                <th class="text-end">Månatlig gratisbas</th>
                <th class="text-end">Förbrukat (denna månad)</th>
                <th>Larmtröskel</th>
                <th>Vid tomt saldo</th>
                <th>Senast ändrad</th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ((array)$existingQuotas as $q): ?>
                    <tr<?= !empty($q['_is_default']) ? ' class="table-light text-muted"' : '' ?>>
                        <td>
                            <strong><?= htmlspecialchars($q['_label']) ?></strong>
                            <?php if (!$q['organization_id']): ?>
                                <span class="badge bg-light text-muted ms-1">domän</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted ms-1">org · <?= (int)($q['_member_count'] ?? 0) ?> domäner</span>
                            <?php endif; ?>
                            <?php if (!empty($q['_is_default'])): ?>
                                <span class="badge bg-warning text-dark ms-1">default</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($q['_balance'] === null): ?>
                                <span class="text-muted small">— (domän)</span>
                            <?php else: ?>
                                <?php
                                    $bal = (int)$q['_balance'];
                                    $low = (int)$q['monthly_token_quota'] > 0
                                        && $bal < ((int)$q['monthly_token_quota'] * (int)$q['alert_threshold_pct'] / 100);
                                    $cls = $bal <= 0 ? 'text-danger fw-bold' : ($low ? 'text-warning fw-semibold' : '');
                                ?>
                                <span class="<?= $cls ?>"><?= number_format($bal, 0, ',', ' ') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($q['monthly_token_quota'], 0, ',', ' ') ?></td>
                        <td class="text-end"><?= number_format($q['_used'], 0, ',', ' ') ?></td>
                        <td><?= (int)$q['alert_threshold_pct'] ?>%</td>
                        <td>
                            <?php if ($q['behavior'] === 'block'): ?>
                                <span class="badge bg-secondary" title="Inställning: AI-anrop blockeras när saldot når 0">Blockera anrop</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border" title="Inställning: bara varning vid 0 saldo, anrop tillåts">Endast varna</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small">
                            <?= htmlspecialchars($q['updated_at']) ?>
                            <?php if ($q['updated_by']): ?>
                                <br><?= htmlspecialchars($q['updated_by']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm <?= !empty($q['_is_default']) ? 'btn-outline-success' : 'btn-outline-primary' ?>"
                                    data-bs-toggle="modal" data-bs-target="#quotaModal"
                                    data-org-id="<?= (int)$q['organization_id'] ?>"
                                    data-domain="<?= htmlspecialchars($q['domain'] ?? '') ?>"
                                    data-token-quota="<?= (int)$q['monthly_token_quota'] ?>"
                                    data-threshold="<?= (int)$q['alert_threshold_pct'] ?>"
                                    data-behavior="<?= htmlspecialchars($q['behavior']) ?>"
                                    data-notes="<?= htmlspecialchars($q['notes'] ?? '') ?>"
                                    data-label="<?= htmlspecialchars($q['_label']) ?>">
                                <?= !empty($q['_is_default']) ? 'Sätt egen quota' : 'Redigera' ?>
                            </button>
                            <?php if (empty($q['_is_default'])): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Ta bort kvotinställningen för <?= htmlspecialchars($q['_label']) ?>? (Återgår till standardvärde.)');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete_quota">
                                <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Ta bort</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($existingQuotas)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">Inga anpassade kvoter — alla scopes använder standardvärdet (<?= number_format(AI_QUOTA_DEFAULT_TOKENS, 0, ',', ' ') ?> tokens/månad gratisbas).</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#quotaModal" data-action="new">
            <i class="bi bi-plus-lg"></i> Lägg till kvot
        </button>
        <span class="text-muted small ms-3">Standardvärde för icke-konfigurerade scopes: <?= number_format(AI_QUOTA_DEFAULT_TOKENS, 0, ',', ' ') ?> tokens/månad, larm vid <?= AI_QUOTA_DEFAULT_THRESHOLD_PCT ?>%, beteende <code><?= AI_QUOTA_DEFAULT_BEHAVIOR ?></code>. Bilder räknas som <?= number_format(AI_IMAGE_TOKEN_EQUIVALENT, 0, ',', ' ') ?> tokens/styck.</span>
    </div>
</div>

<!-- Modal: redigera/lägg till kvot -->
<div class="modal fade" id="quotaModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_quota">
            <div class="modal-header">
                <h5 class="modal-title" id="quotaModalTitle">Kvotinställning</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Scope</label>
                    <div class="row g-2">
                        <div class="col">
                            <select name="organization_id" id="qm_org" class="form-select form-select-sm">
                                <option value="">— Ingen org (välj domän) —</option>
                                <?php foreach ((array)$orgs as $o): ?>
                                    <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <input type="text" name="domain" id="qm_domain" class="form-control form-control-sm" placeholder="domain.se">
                        </div>
                    </div>
                    <small class="text-muted">Välj antingen organisation ELLER fri domän (för domäner som inte tillhör en org).</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Månatlig gratisbas (tokens)</label>
                    <input type="number" name="monthly_token_quota" id="qm_tokens" class="form-control" min="0" step="10000" value="<?= (int)AI_QUOTA_DEFAULT_TOKENS ?>" required>
                    <small class="text-muted">Detta antal tokens läggs till saldot den 1:a varje månad. Adderas till ev. köpta paket.</small>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Larmtröskel (%)</label>
                        <input type="number" name="alert_threshold_pct" id="qm_threshold" class="form-control" min="1" max="99" value="80">
                        <small class="text-muted">Banner visas när saldot understiger denna andel av gratisbasen.</small>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Vid tomt saldo</label>
                        <select name="behavior" id="qm_behavior" class="form-select">
                            <option value="block">Blockera anrop när saldo = 0</option>
                            <option value="warn">Endast varna (anrop tillåts ändå)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Anteckningar</label>
                    <textarea name="notes" id="qm_notes" class="form-control" rows="2" placeholder="(valfri) skäl, kontaktperson, etc."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                <button type="submit" class="btn btn-primary">Spara</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('quotaModal').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    var isNew = btn.getAttribute('data-action') === 'new';
    document.getElementById('quotaModalTitle').textContent = isNew ? 'Lägg till kvot' : ('Redigera: ' + (btn.getAttribute('data-label') || ''));
    document.getElementById('qm_org').value = isNew ? '' : (btn.getAttribute('data-org-id') || '');
    document.getElementById('qm_domain').value = isNew ? '' : (btn.getAttribute('data-domain') || '');
    document.getElementById('qm_tokens').value = isNew ? '<?= (int)AI_QUOTA_DEFAULT_TOKENS ?>' : (btn.getAttribute('data-token-quota') || '<?= (int)AI_QUOTA_DEFAULT_TOKENS ?>');
    document.getElementById('qm_threshold').value = isNew ? '80' : (btn.getAttribute('data-threshold') || '80');
    document.getElementById('qm_behavior').value = isNew ? 'block' : (btn.getAttribute('data-behavior') || 'block');
    document.getElementById('qm_notes').value = isNew ? '' : (btn.getAttribute('data-notes') || '');
});
</script>

<?php require_once 'include/footer.php'; ?>
