<?php
/**
 * Stimma — Tokenbeställningar (superadmin-översikt)
 *
 * Superadmin ser här alla inkomna tokenbeställningar från samtliga
 * organisationer och kan markera varje beställning som debiterad kund
 * eller ej debiterad. Själva faktureringen sker manuellt utanför systemet
 * (se migration 040/041) — detta är spårningen av vad som fakturerats.
 *
 * Endast super_admin.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/token_balance.php';
require_once 'include/auth_check.php';

$currentUser = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?",
    [$_SESSION['user_email']]
);
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

if (!$isSuperAdmin) {
    $_SESSION['message'] = 'Endast superadministratörer har åtkomst till tokenbeställningar.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// POST: växla debiteringsstatus -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken.';
        $_SESSION['message_type'] = 'danger';
        header('Location: token_orders.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'toggle_billed') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $billed = ($_POST['billed'] ?? '') === '1';
        if ($orderId > 0) {
            setOrderBilled($orderId, $billed, $_SESSION['user_email']);
            logActivity(
                $_SESSION['user_email'],
                ($billed ? 'Markerade tokenbeställning som debiterad' : 'Avmarkerade debitering på tokenbeställning')
                    . ' (order #' . $orderId . ')'
            );
            $_SESSION['message'] = $billed
                ? 'Beställningen är markerad som debiterad.'
                : 'Beställningen är markerad som ej debiterad.';
            $_SESSION['message_type'] = 'success';
        }
    }

    // Behåll aktivt filter över redirect
    $qs = isset($_POST['filter']) && in_array($_POST['filter'], ['all', 'unbilled', 'billed'], true)
        ? '?filter=' . $_POST['filter']
        : '';
    header('Location: token_orders.php' . $qs);
    exit;
}

// Filter + data ------------------------------------------------------------
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unbilled', 'billed'], true)) {
    $filter = 'all';
}
$orders = getAllTokenOrders($filter, 1000);
$summary = getTokenOrdersBillingSummary();

$page_title = 'Tokenbeställningar';
require_once 'include/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-receipt me-2"></i>Tokenbeställningar</h1>
        <p class="text-muted mb-0">Alla inkomna tokenbeställningar från samtliga organisationer. Markera varje beställning som debiterad när den fakturerats.</p>
    </div>
</div>

<!-- Nyckeltal -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Ej debiterade</div>
                <div class="h4 mb-0"><?= number_format($summary['unbilled_count'], 0, ',', ' ') ?> st</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Ej debiterat belopp</div>
                <div class="h4 mb-0"><?= number_format($summary['unbilled_cents'] / 100, 0, ',', ' ') ?> kr</div>
                <div class="small text-muted">ex moms</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Debiterade</div>
                <div class="h4 mb-0"><?= number_format($summary['billed_count'], 0, ',', ' ') ?> st</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Debiterat belopp</div>
                <div class="h4 mb-0"><?= number_format($summary['billed_cents'] / 100, 0, ',', ' ') ?> kr</div>
                <div class="small text-muted">ex moms</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<ul class="nav nav-pills mb-3">
    <?php
    $tabs = ['all' => 'Alla', 'unbilled' => 'Ej debiterade', 'billed' => 'Debiterade'];
    foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $filter === $key ? 'active' : '' ?>" href="token_orders.php?filter=<?= $key ?>"><?= $label ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Datum</th>
                        <th>Organisation</th>
                        <th>Beställd av</th>
                        <th>Paket</th>
                        <th class="text-end">Tokens</th>
                        <th class="text-end">Belopp</th>
                        <th>Fakturauppgifter</th>
                        <th>Status</th>
                        <th class="text-end">Åtgärd</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Inga beställningar att visa.</td></tr>
                    <?php else: foreach ($orders as $o):
                        $isBilled = !empty($o['billed_at']);
                        $billingBits = array_filter([
                            $o['billing_contact_name'] ?? '',
                            $o['billing_email'] ?? '',
                            !empty($o['billing_gln']) ? 'GLN: ' . $o['billing_gln'] : '',
                            !empty($o['billing_peppol']) ? 'PEPPOL: ' . $o['billing_peppol'] : '',
                        ]);
                    ?>
                        <tr>
                            <td class="small text-nowrap"><?= htmlspecialchars(substr((string)$o['created_at'], 0, 16)) ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($o['organization_name'] ?? ('Org #' . $o['organization_id'])) ?></div>
                                <?php if (!empty($o['org_number'])): ?>
                                    <div class="small text-muted"><?= htmlspecialchars($o['org_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= htmlspecialchars($o['created_by'] ?? '—') ?></td>
                            <td>
                                <?= htmlspecialchars($o['package_name']) ?>
                                <?php if (!empty($o['is_recurring'])): ?>
                                    <span class="badge bg-primary ms-1" title="Återkommande beställning<?= !empty($o['recurring_active']) ? ' (aktiv)' : ' (avslutad)' ?>">
                                        <i class="bi bi-arrow-repeat"></i> <?= !empty($o['recurring_active']) ? 'Mån' : 'Mån (avsl.)' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format($o['tokens'], 0, ',', ' ') ?></td>
                            <td class="text-end text-nowrap"><?= number_format($o['price_sek_cents'] / 100, 0, ',', ' ') ?> kr</td>
                            <td class="small text-muted" style="max-width: 240px;">
                                <?= $billingBits ? htmlspecialchars(implode(' · ', $billingBits)) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?php if ($isBilled): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Debiterad</span>
                                    <div class="small text-muted mt-1">
                                        <?= htmlspecialchars(substr((string)$o['billed_at'], 0, 16)) ?><br>
                                        <?= htmlspecialchars($o['billed_by'] ?? '') ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Ej debiterad</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_billed">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                    <input type="hidden" name="billed" value="<?= $isBilled ? '0' : '1' ?>">
                                    <?php if ($isBilled): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Ångra
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check2 me-1"></i>Markera debiterad
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
