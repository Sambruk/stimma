<?php
/**
 * Stimma — Beställ AI-tokens
 *
 * Adminanvändare kan här köpa fler tokens till sin organisations token-konto.
 * Köp aktiveras direkt: saldot fylls på, faktura skickas separat av Sambruk.
 *
 * Tillgänglig för is_admin OCH super_admin.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/ai_quota.php';
require_once '../include/token_balance.php';
require_once 'include/auth_check.php';

$currentUser = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?",
    [$_SESSION['user_email']]
);
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';
$isAdmin = $currentUser && (int)$currentUser['is_admin'] === 1;

if (!$isAdmin && !$isSuperAdmin) {
    $_SESSION['message'] = 'Endast administratörer kan beställa tokens.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Avgör organisationstillhörighet
$myDomain = substr(strrchr($currentUser['email'], '@'), 1);
$myOrg = getOrganizationByDomain($myDomain);

// POST-hantering -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken.';
        $_SESSION['message_type'] = 'danger';
        header('Location: order_tokens.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'place_order') {
        if (!$myOrg) {
            $_SESSION['message'] = 'Din e-postdomän tillhör ingen organisation — kan inte beställa tokens.';
            $_SESSION['message_type'] = 'danger';
            header('Location: order_tokens.php');
            exit;
        }

        $packageId = (int)($_POST['package_id'] ?? 0);
        $billing = [
            'contact_name' => trim($_POST['contact_name'] ?? ''),
            'invoice_address' => trim($_POST['invoice_address'] ?? ''),
            'gln' => trim($_POST['gln'] ?? ''),
            'peppol' => trim($_POST['peppol'] ?? ''),
            'contact_email' => trim($_POST['contact_email'] ?? ''),
        ];
        $isRecurring = !empty($_POST['is_recurring']);
        $confirmed = !empty($_POST['confirm_billing']);

        $errors = [];
        if (!$packageId) $errors[] = 'Välj ett paket.';
        if ($billing['contact_name'] === '') $errors[] = 'Ange ett kontaktnamn.';
        if ($billing['invoice_address'] === '') $errors[] = 'Ange en fakturaadress.';
        if (!$confirmed) $errors[] = 'Du måste bekräfta att du godkänner faktureringen.';

        $pkg = $packageId ? getTokenPackage($packageId) : null;
        if ($packageId && !$pkg) $errors[] = 'Okänt paket.';

        if ($errors) {
            $_SESSION['message'] = implode(' ', $errors);
            $_SESSION['message_type'] = 'danger';
            // Behåll formulärvärden för POST-Redirect-GET — lägg i session.
            $_SESSION['order_form_state'] = $_POST;
            header('Location: order_tokens.php');
            exit;
        }

        $orderId = createTokenOrder(
            (int)$myOrg['id'],
            $packageId,
            $billing,
            $isRecurring,
            $currentUser['email']
        );

        if (!$orderId) {
            $_SESSION['message'] = 'Beställningen kunde inte registreras. Försök igen.';
            $_SESSION['message_type'] = 'danger';
            $_SESSION['order_form_state'] = $_POST;
            header('Location: order_tokens.php');
            exit;
        }

        unset($_SESSION['order_form_state']);
        $_SESSION['message'] = 'Beställning registrerad. Saldot har fyllts på med '
            . number_format($pkg['tokens'], 0, ',', ' ') . ' tokens. Faktura skickas separat.';
        $_SESSION['message_type'] = 'success';
        header('Location: order_tokens.php');
        exit;
    }

    if ($action === 'cancel_recurring') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId && $myOrg) {
            cancelRecurringOrder($orderId, (int)$myOrg['id']);
            $_SESSION['message'] = 'Auto-påfyllning avslutad. Saldot påverkas inte.';
            $_SESSION['message_type'] = 'success';
        }
        header('Location: order_tokens.php');
        exit;
    }
}

// Data till vyn -----------------------------------------------------------
$packages = getActiveTokenPackages();
$balance = $myOrg ? getOrgTokenBalance((int)$myOrg['id']) : 0;
$billing = $myOrg ? getOrgBilling((int)$myOrg['id']) : null;
$orders = $myOrg ? getOrgTokenOrders((int)$myOrg['id'], 20) : [];
$transactions = $myOrg ? getOrgTokenTransactions((int)$myOrg['id'], 10) : [];
$activeRecurring = array_values(array_filter($orders, fn($o) => (int)$o['is_recurring'] === 1 && (int)$o['recurring_active'] === 1));

// Formulärvärden (sticky vid valideringsfel)
$formState = $_SESSION['order_form_state'] ?? [];
unset($_SESSION['order_form_state']);
$prefill = [
    'package_id'      => $formState['package_id'] ?? '',
    'contact_name'    => $formState['contact_name'] ?? ($billing['contact_name'] ?? $currentUser['email']),
    'invoice_address' => $formState['invoice_address'] ?? ($billing['invoice_address'] ?? ''),
    'gln'             => $formState['gln'] ?? ($billing['gln'] ?? ''),
    'peppol'          => $formState['peppol'] ?? ($billing['peppol'] ?? ''),
    'contact_email'   => $formState['contact_email'] ?? ($billing['contact_email'] ?? $currentUser['email']),
    'is_recurring'    => !empty($formState['is_recurring']),
];

// Aktuell månads-gratisbas (för informativt syfte)
$aiQuota = $myOrg ? getAiQuota((int)$myOrg['id'], null) : null;
$monthlyBase = $aiQuota ? (int)$aiQuota['monthly_token_quota'] : AI_QUOTA_DEFAULT_TOKENS;

$page_title = 'Beställ AI-tokens';
require_once 'include/header.php';
?>

<?php if (!$myOrg): ?>
    <div class="alert alert-warning">
        <strong>Din e-postdomän (<?= htmlspecialchars($myDomain) ?>) tillhör ingen organisation.</strong>
        Token-beställning kräver att din domän är kopplad till en organisation.
        Kontakta <a href="mailto:hjalp@sambruksupport.se">hjalp@sambruksupport.se</a> för hjälp.
    </div>
<?php else: ?>

<!-- Saldo + status -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">Aktuellt saldo</h6>
                <div class="display-5 fw-bold">
                    <?= number_format($balance, 0, ',', ' ') ?>
                    <span class="fs-6 fw-normal text-muted">tokens</span>
                </div>
                <div class="small text-muted mt-2">
                    Organisation: <strong><?= htmlspecialchars($myOrg['name']) ?></strong>
                </div>
                <div class="small text-muted">
                    Månatlig gratisbas: <?= number_format($monthlyBase, 0, ',', ' ') ?> tokens
                    (läggs på den 1:a varje månad)
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">Aktiva prenumerationer</h6>
                <?php if (empty($activeRecurring)): ?>
                    <p class="text-muted mb-0">Inga aktiva auto-påfyllningar.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($activeRecurring as $r): ?>
                            <li class="d-flex justify-content-between align-items-center mb-1">
                                <span>
                                    <strong><?= htmlspecialchars($r['package_name']) ?></strong>
                                    — <?= number_format($r['tokens'], 0, ',', ' ') ?> tokens/mån
                                </span>
                                <form method="post" class="d-inline" onsubmit="return confirm('Avsluta auto-påfyllning av <?= htmlspecialchars($r['package_name']) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="cancel_recurring">
                                    <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0">Avsluta</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Paket-katalog -->
<div class="card mb-4">
    <div class="card-header"><strong>Välj paket</strong></div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Priserna utgår från OpenAI:s prisbild × 1,5. Antalet lektioner är en
            ungefärlig riktlinje baserad på normal modellanvändning — komplexa
            kurser med många bilder förbrukar mer.
        </p>
        <div class="row g-3" id="packageGrid">
            <?php foreach ($packages as $pkg): ?>
                <?php
                    $priceSek = $pkg['price_sek_cents'] / 100;
                    $perThousand = $pkg['tokens'] > 0
                        ? ($priceSek / ($pkg['tokens'] / 1000))
                        : 0;
                    $isSelected = (string)$pkg['id'] === (string)$prefill['package_id'];
                ?>
                <div class="col-md-6 col-lg-4">
                    <label class="card h-100 package-card <?= $isSelected ? 'border-primary' : '' ?>"
                           style="cursor:pointer">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?= htmlspecialchars($pkg['name']) ?></h5>
                                <input type="radio" name="package_id_radio" value="<?= (int)$pkg['id'] ?>"
                                       class="form-check-input package-radio"
                                       <?= $isSelected ? 'checked' : '' ?>>
                            </div>
                            <div class="display-6 fw-bold mb-1">
                                <?= number_format($priceSek, 0, ',', ' ') ?>
                                <span class="fs-6 fw-normal text-muted">kr/mån ex moms</span>
                            </div>
                            <div class="text-muted small mb-2">
                                ≈ <?= number_format($perThousand, 3, ',', ' ') ?> kr per 1 000 tokens
                            </div>
                            <ul class="list-unstyled small mb-0">
                                <li><i class="bi bi-coin me-1"></i>
                                    <strong><?= number_format($pkg['tokens'], 0, ',', ' ') ?></strong> tokens
                                </li>
                                <li><i class="bi bi-mortarboard me-1"></i>
                                    räcker till ca <strong><?= (int)$pkg['estimated_lessons'] ?></strong> AI-genererade lektioner
                                </li>
                                <?php if (!empty($pkg['description'])): ?>
                                    <li class="text-muted mt-2"><?= htmlspecialchars($pkg['description']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Beställningsformulär -->
<form method="post" id="orderForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="place_order">
    <input type="hidden" name="package_id" id="selectedPackageId" value="<?= htmlspecialchars($prefill['package_id']) ?>">

    <div class="card mb-4">
        <div class="card-header"><strong>Faktureringsuppgifter</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Kontaktnamn <span class="text-danger">*</span></label>
                    <input type="text" name="contact_name" class="form-control" required
                           value="<?= htmlspecialchars($prefill['contact_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Kontakt-e-post</label>
                    <input type="email" name="contact_email" class="form-control"
                           value="<?= htmlspecialchars($prefill['contact_email']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Fakturaadress <span class="text-danger">*</span></label>
                    <textarea name="invoice_address" class="form-control" rows="3" required
                              placeholder="Företagsnamn&#10;Box 123&#10;100 00 Stockholm"><?= htmlspecialchars($prefill['invoice_address']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">GLN <small class="text-muted">(om finns)</small></label>
                    <input type="text" name="gln" class="form-control"
                           placeholder="7350000000000"
                           value="<?= htmlspecialchars($prefill['gln']) ?>">
                    <div class="form-text small">Global Location Number — 13 siffror.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">PEPPOL-ID <small class="text-muted">(om finns)</small></label>
                    <input type="text" name="peppol" class="form-control"
                           placeholder="0007:5560000000"
                           value="<?= htmlspecialchars($prefill['peppol']) ?>">
                    <div class="form-text small">Format: scheme:identifierare.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Fakturering &amp; auto-påfyllning</strong></div>
        <div class="card-body">
            <div class="alert alert-info small mb-3">
                Fakturering sker månadsvis från Sambruk utifrån uppgifterna ovan.
                När du lägger denna beställning fylls saldot på direkt med
                paketets tokens. Faktura skickas separat enligt era ordinarie rutiner.
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="is_recurring" id="isRecurring" value="1"
                       class="form-check-input"
                       <?= $prefill['is_recurring'] ? 'checked' : '' ?>>
                <label for="isRecurring" class="form-check-label">
                    <strong>Fyll på saldot automatiskt den 1:a varje månad</strong>
                    med det valda paketet (tak: <?= TOKEN_RECURRING_CAP_MULTIPLIER ?>× paketstorlek).
                    Du kan avsluta auto-påfyllning när som helst.
                </label>
            </div>

            <div class="form-check border-top pt-3">
                <input type="checkbox" name="confirm_billing" id="confirmBilling" value="1"
                       class="form-check-input" required>
                <label for="confirmBilling" class="form-check-label">
                    <strong>Jag bekräftar</strong> att jag genom denna beställning godkänner
                    att organisationen <em><?= htmlspecialchars($myOrg['name']) ?></em> faktureras
                    <strong><span id="priceLabel">—</span> kr ex moms</strong> för det valda paketet
                    <?php if (true): ?>, samt motsvarande belopp varje månad om auto-påfyllning är ikryssad<?php endif; ?>.
                </label>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary" id="submitOrder" disabled>
                <i class="bi bi-cart-plus me-1"></i> Lägg beställning
            </button>
        </div>
    </div>
</form>

<?php if (!empty($transactions)): ?>
<div class="card mb-4">
    <div class="card-header"><strong>Senaste transaktioner</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Typ</th>
                    <th class="text-end">Tokens</th>
                    <th class="text-end">Saldo efter</th>
                    <th>Notering</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $typeLabels = [
                    'monthly_refill' => '<span class="badge bg-info text-dark">Gratisbas</span>',
                    'purchase'       => '<span class="badge bg-success">Köp</span>',
                    'recurring'      => '<span class="badge bg-primary">Auto-påfyllning</span>',
                    'consume'        => '<span class="badge bg-secondary">Förbrukning</span>',
                    'adjustment'     => '<span class="badge bg-warning text-dark">Justering</span>',
                ];
                foreach ($transactions as $t): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars($t['created_at']) ?></td>
                        <td><?= $typeLabels[$t['type']] ?? htmlspecialchars($t['type']) ?></td>
                        <td class="text-end <?= $t['tokens_delta'] < 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $t['tokens_delta'] > 0 ? '+' : '' ?><?= number_format($t['tokens_delta'], 0, ',', ' ') ?>
                        </td>
                        <td class="text-end"><?= number_format($t['balance_after'], 0, ',', ' ') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($t['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    const radios = document.querySelectorAll('.package-radio');
    const hidden = document.getElementById('selectedPackageId');
    const priceLabel = document.getElementById('priceLabel');
    const submitBtn = document.getElementById('submitOrder');
    const confirmBox = document.getElementById('confirmBilling');

    // Pris per paket från PHP, i kronor
    const packagePrices = <?= json_encode(array_reduce(
        $packages,
        function ($acc, $p) { $acc[(int)$p['id']] = (int)$p['price_sek_cents'] / 100; return $acc; },
        []
    )) ?>;

    function updateState() {
        const id = hidden.value ? parseInt(hidden.value, 10) : 0;
        const price = id && packagePrices[id] ? packagePrices[id] : 0;
        priceLabel.textContent = price
            ? new Intl.NumberFormat('sv-SE').format(price)
            : '—';
        submitBtn.disabled = !id || !confirmBox.checked;
    }

    radios.forEach(r => {
        r.addEventListener('change', () => {
            hidden.value = r.value;
            document.querySelectorAll('.package-card').forEach(c => c.classList.remove('border-primary'));
            r.closest('.package-card').classList.add('border-primary');
            updateState();
        });
    });
    confirmBox.addEventListener('change', updateState);
    updateState();
})();
</script>

<?php endif; // $myOrg ?>

<?php require_once 'include/footer.php'; ?>
