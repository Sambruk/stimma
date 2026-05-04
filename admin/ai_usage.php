<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * AI-användning per organisation/domän/kurs (superadmin).
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/ai_quota.php';
require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

if (!$isSuperAdmin) {
    $_SESSION['message'] = 'Endast superadmin har tillgång till AI-användning.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// --- Filter ----------------------------------------------------------------
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year = max(2024, min(2099, $year));
$month = max(1, min(12, $month));
$start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$end = date('Y-m-d 00:00:00', strtotime("$start +1 month"));

$drillOrgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;
$drillDomain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
$drillFeature = isset($_GET['feature']) ? trim($_GET['feature']) : '';

// --- CSV-export ------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = query(
        "SELECT id, organization_id, domain, user_email, course_id, feature, model,
                prompt_tokens, completion_tokens, total_tokens, cost_cents, status, created_at
           FROM " . DB_DATABASE . ".ai_usage_log
          WHERE created_at >= ? AND created_at < ?
          ORDER BY created_at ASC",
        [$start, $end]
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai_usage_' . sprintf('%04d-%02d', $year, $month) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','org_id','domain','user_email','course_id','feature','model','prompt_tokens','completion_tokens','total_tokens','cost_cents','status','created_at'], ';');
    foreach ((array)$rows as $r) {
        fputcsv($out, [
            $r['id'], $r['organization_id'], $r['domain'], $r['user_email'], $r['course_id'],
            $r['feature'], $r['model'], $r['prompt_tokens'], $r['completion_tokens'],
            $r['total_tokens'], $r['cost_cents'], $r['status'], $r['created_at'],
        ], ';');
    }
    fclose($out);
    exit;
}

// --- Översikt per scope (org eller domän) ---------------------------------
$scopeRows = query(
    "SELECT
        COALESCE(CAST(organization_id AS CHAR), '') AS org_id,
        COALESCE(domain, '') AS domain,
        COUNT(*) AS requests,
        SUM(total_tokens) AS tokens,
        SUM(cost_cents) AS cents,
        SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) AS blocked
       FROM " . DB_DATABASE . ".ai_usage_log
      WHERE created_at >= ? AND created_at < ?
      GROUP BY org_id, domain
      ORDER BY tokens DESC",
    [$start, $end]
);

// Berika med kvot + namn
$scopes = [];
foreach ((array)$scopeRows as $r) {
    $orgId = $r['org_id'] !== '' ? (int)$r['org_id'] : null;
    $dom = $r['domain'] !== '' ? $r['domain'] : null;
    $label = '–';
    if ($orgId) {
        $org = queryOne("SELECT name FROM " . DB_DATABASE . ".organizations WHERE id = ?", [$orgId]);
        $label = $org['name'] ?? ('Org #' . $orgId);
    } elseif ($dom) {
        $label = $dom;
    }
    $quota = getAiQuota($orgId, $dom);
    $tokensQuota = max(1, (int)$quota['monthly_token_quota']);
    $tokensUsed = (int)$r['tokens'];
    $pct = (int)floor(($tokensUsed / $tokensQuota) * 100);
    $scopes[] = [
        'org_id' => $orgId,
        'domain' => $dom,
        'label' => $label,
        'requests' => (int)$r['requests'],
        'tokens' => $tokensUsed,
        'tokens_quota' => $tokensQuota,
        'cents' => (int)$r['cents'],
        'blocked' => (int)$r['blocked'],
        'pct' => $pct,
        'quota_is_default' => $quota['is_default'],
    ];
}

// --- Per feature ----------------------------------------------------------
$featureRows = query(
    "SELECT feature, COUNT(*) AS requests, SUM(total_tokens) AS tokens, SUM(cost_cents) AS cents
       FROM " . DB_DATABASE . ".ai_usage_log
      WHERE created_at >= ? AND created_at < ?
      GROUP BY feature
      ORDER BY tokens DESC",
    [$start, $end]
);

// --- Drill-down ------------------------------------------------------------
$drillCourses = [];
$drillUsers = [];
$drillTitle = '';
if ($drillOrgId || $drillDomain !== '') {
    $whereScope = $drillOrgId
        ? '`organization_id` = ' . (int)$drillOrgId
        : '`domain` = ' . getDb()->quote($drillDomain);
    $featureFilter = $drillFeature !== '' ? ' AND feature = ' . getDb()->quote($drillFeature) : '';

    if ($drillOrgId) {
        $org = queryOne("SELECT name FROM " . DB_DATABASE . ".organizations WHERE id = ?", [$drillOrgId]);
        $drillTitle = $org['name'] ?? ('Org #' . $drillOrgId);
    } else {
        $drillTitle = $drillDomain;
    }

    $drillCourses = query(
        "SELECT u.course_id,
                c.title AS course_title,
                COUNT(*) AS requests,
                SUM(u.total_tokens) AS tokens,
                SUM(u.cost_cents) AS cents
           FROM " . DB_DATABASE . ".ai_usage_log u
           LEFT JOIN " . DB_DATABASE . ".courses c ON c.id = u.course_id
          WHERE $whereScope $featureFilter
            AND u.created_at >= ? AND u.created_at < ?
          GROUP BY u.course_id, c.title
          ORDER BY tokens DESC
          LIMIT 50",
        [$start, $end]
    );

    $drillUsers = query(
        "SELECT user_email, COUNT(*) AS requests, SUM(total_tokens) AS tokens, SUM(cost_cents) AS cents
           FROM " . DB_DATABASE . ".ai_usage_log
          WHERE $whereScope $featureFilter
            AND created_at >= ? AND created_at < ?
          GROUP BY user_email
          ORDER BY tokens DESC
          LIMIT 50",
        [$start, $end]
    );
}

$page_title = 'AI-användning';
require_once 'include/header.php';
?>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-auto">
                <label class="form-label small mb-0">År</label>
                <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2024" max="2099">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0">Månad</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                            <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php if ($drillOrgId || $drillDomain !== ''): ?>
                <input type="hidden" name="<?= $drillOrgId ? 'org_id' : 'domain' ?>" value="<?= htmlspecialchars($drillOrgId ?: $drillDomain) ?>">
                <div class="col-auto">
                    <label class="form-label small mb-0">Filtrera feature</label>
                    <select name="feature" class="form-select form-select-sm">
                        <option value="">Alla</option>
                        <?php foreach (['course_gen','lesson_gen','chat','image','other'] as $f): ?>
                            <option value="<?= $f ?>" <?= $drillFeature === $f ? 'selected' : '' ?>><?= $f ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Visa</button>
            </div>
            <div class="col-auto">
                <a href="?year=<?= $year ?>&month=<?= $month ?>&export=csv" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-download"></i> Exportera CSV
                </a>
            </div>
            <?php if ($drillOrgId || $drillDomain !== ''): ?>
                <div class="col-auto">
                    <a href="ai_usage.php?year=<?= $year ?>&month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Tillbaka till översikt
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$drillOrgId && $drillDomain === ''): ?>

<div class="card mb-4">
    <div class="card-header"><strong>Per feature — <?= sprintf('%04d-%02d', $year, $month) ?></strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Feature</th><th class="text-end">Antal anrop</th>
                <th class="text-end">Tokens</th><th class="text-end">Kostnad (USD)</th>
            </tr></thead>
            <tbody>
                <?php foreach ((array)$featureRows as $f): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($f['feature']) ?></code></td>
                        <td class="text-end"><?= number_format($f['requests'], 0, ',', ' ') ?></td>
                        <td class="text-end"><?= number_format($f['tokens'], 0, ',', ' ') ?></td>
                        <td class="text-end">$<?= number_format(((int)$f['cents']) / 100, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($featureRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Ingen användning denna månad.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Per organisation/domän — <?= sprintf('%04d-%02d', $year, $month) ?></strong></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>Scope</th>
                <th class="text-end">Anrop</th>
                <th class="text-end">Tokens</th>
                <th>Kvot</th>
                <th>%</th>
                <th class="text-end">Kostnad</th>
                <th class="text-end">Blockerade</th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($scopes as $s): ?>
                    <?php
                        $color = $s['pct'] >= 100 ? 'bg-danger' : ($s['pct'] >= 80 ? 'bg-warning' : 'bg-success');
                        $linkParam = $s['org_id'] ? 'org_id=' . $s['org_id'] : 'domain=' . urlencode($s['domain']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($s['label']) ?></strong>
                            <?php if (!$s['org_id']): ?>
                                <span class="badge bg-light text-muted ms-1">domän</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($s['requests'], 0, ',', ' ') ?></td>
                        <td class="text-end"><?= number_format($s['tokens'], 0, ',', ' ') ?></td>
                        <td>
                            <?= number_format($s['tokens_quota'], 0, ',', ' ') ?>
                            <?php if ($s['quota_is_default']): ?>
                                <span class="badge bg-light text-muted ms-1" title="Standardvärde, ej anpassat">default</span>
                            <?php endif; ?>
                        </td>
                        <td style="min-width:160px;">
                            <div class="progress" style="height: 16px;">
                                <div class="progress-bar <?= $color ?>" role="progressbar"
                                     style="width: <?= min(100, $s['pct']) ?>%;">
                                    <?= $s['pct'] ?>%
                                </div>
                            </div>
                        </td>
                        <td class="text-end">$<?= number_format($s['cents'] / 100, 2) ?></td>
                        <td class="text-end">
                            <?= $s['blocked'] > 0 ? '<span class="badge bg-danger">' . $s['blocked'] . '</span>' : '0' ?>
                        </td>
                        <td>
                            <a href="?year=<?= $year ?>&month=<?= $month ?>&<?= $linkParam ?>" class="btn btn-sm btn-outline-primary">
                                Detaljer <i class="bi bi-chevron-right"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($scopes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">Ingen användning denna månad.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>

<div class="card mb-4">
    <div class="card-header">
        <strong>Detaljer — <?= htmlspecialchars($drillTitle) ?></strong>
        <span class="text-muted ms-2"><?= sprintf('%04d-%02d', $year, $month) ?></span>
        <?php if ($drillFeature !== ''): ?>
            <span class="badge bg-info ms-2"><?= htmlspecialchars($drillFeature) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>Per kurs</strong> (top 50)</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Kurs</th><th class="text-end">Anrop</th><th class="text-end">Tokens</th><th class="text-end">Kostnad</th></tr></thead>
                    <tbody>
                        <?php foreach ((array)$drillCourses as $c): ?>
                            <tr>
                                <td>
                                    <?php if ($c['course_id']): ?>
                                        <?= htmlspecialchars($c['course_title'] ?? ('Kurs #' . $c['course_id'])) ?>
                                        <small class="text-muted">#<?= (int)$c['course_id'] ?></small>
                                    <?php else: ?>
                                        <em class="text-muted">(ingen kurs)</em>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format($c['requests'], 0, ',', ' ') ?></td>
                                <td class="text-end"><?= number_format($c['tokens'], 0, ',', ' ') ?></td>
                                <td class="text-end">$<?= number_format(((int)$c['cents']) / 100, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($drillCourses)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Inga rader.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>Per användare</strong> (top 50)</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>E-post</th><th class="text-end">Anrop</th><th class="text-end">Tokens</th><th class="text-end">Kostnad</th></tr></thead>
                    <tbody>
                        <?php foreach ((array)$drillUsers as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['user_email'] ?? '–') ?></td>
                                <td class="text-end"><?= number_format($u['requests'], 0, ',', ' ') ?></td>
                                <td class="text-end"><?= number_format($u['tokens'], 0, ',', ' ') ?></td>
                                <td class="text-end">$<?= number_format(((int)$u['cents']) / 100, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($drillUsers)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Inga rader.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once 'include/footer.php'; ?>
