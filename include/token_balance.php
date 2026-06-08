<?php
/**
 * Stimma — Token-saldo och beställningar
 *
 * Helpers för att läsa/uppdatera saldot per organisation, hantera
 * paket-katalogen, lägga beställningar och köra månadspåfyllning.
 *
 * Datamodell finns i migrations/040_token_economy.sql:
 *   - token_packages              (paketkatalog, seedad)
 *   - organization_token_balance  (saldo per org)
 *   - organization_billing        (senaste faktureringsuppgifter per org)
 *   - token_orders                (beställningar med billing-snapshot)
 *   - token_transactions          (append-only ledger)
 *
 * Saldot uppdateras alltid via addTokensToBalance / consumeTokensFromBalance
 * så att en transaktionsrad alltid skrivs.
 */

if (!defined('TOKEN_RECURRING_CAP_MULTIPLIER')) {
    // Tak för auto-påfyllning: saldo får aldrig växa till mer än N × paketstorlek
    // av den 1:a varje månad. Förhindrar obegränsad ackumulering vid låg
    // förbrukning.
    define('TOKEN_RECURRING_CAP_MULTIPLIER', 3);
}

if (!defined('TOKEN_LOW_BALANCE_THRESHOLD_PCT')) {
    // Procent av senaste månadens-tilldelning som triggar "lågt saldo"-banner.
    // Vi har inget separat threshold-fält i token-systemet — använder samma
    // logik som ai_quotas.alert_threshold_pct.
    define('TOKEN_LOW_BALANCE_THRESHOLD_PCT', 20);
}

// ---------------------------------------------------------------------------
// Paketkatalog
// ---------------------------------------------------------------------------

/**
 * Hämta alla aktiva paket sorterade efter sort_order.
 */
function getActiveTokenPackages() {
    return query(
        "SELECT id, code, name, tokens, price_sek_cents, estimated_lessons,
                description, sort_order
           FROM " . DB_DATABASE . ".token_packages
          WHERE is_active = 1
          ORDER BY sort_order ASC, id ASC"
    );
}

/**
 * Hämta ett paket via id.
 */
function getTokenPackage($packageId) {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".token_packages WHERE id = ? LIMIT 1",
        [(int)$packageId]
    );
}

// ---------------------------------------------------------------------------
// Saldo
// ---------------------------------------------------------------------------

/**
 * Hämta aktuellt saldo för en organisation. Returnerar 0 om ingen rad finns.
 */
function getOrgTokenBalance($organizationId) {
    if (!$organizationId) return 0;
    $row = queryOne(
        "SELECT balance FROM " . DB_DATABASE . ".organization_token_balance WHERE organization_id = ?",
        [(int)$organizationId]
    );
    return $row ? (int)$row['balance'] : 0;
}

/**
 * Avgör om en organisation har en saldorad alls. Används för att skilja
 * mellan "ej initialiserad" (ingen rad) och "förbrukat" (rad med balance=0).
 */
function hasOrgTokenBalance($organizationId) {
    if (!$organizationId) return false;
    $row = queryOne(
        "SELECT 1 AS x FROM " . DB_DATABASE . ".organization_token_balance WHERE organization_id = ?",
        [(int)$organizationId]
    );
    return $row !== null;
}

/**
 * Initialisera saldot för en organisation som ännu inte har en rad. Lägger
 * in månadens gratisbas och skriver en monthly_refill-transaktion så att
 * cron-jobbet inte ger dubbel gratisbas samma månad.
 *
 * Idempotent: gör inget om saldoraden redan finns.
 */
function bootstrapOrgTokenBalance($organizationId, $initialTokens) {
    $orgId = (int)$organizationId;
    $tokens = (int)$initialTokens;
    if ($orgId <= 0 || $tokens <= 0) return false;
    if (hasOrgTokenBalance($orgId)) return false;

    addTokensToBalance(
        $orgId,
        $tokens,
        'monthly_refill',
        ['note' => 'Initial gratisbas (lazy bootstrap)']
    );
    return true;
}

/**
 * Lägg till tokens på saldot. Skriver också en token_transactions-rad.
 *
 * @param int    $organizationId
 * @param int    $tokens          Antal tokens som ska adderas (måste vara > 0)
 * @param string $type            monthly_refill | purchase | recurring | adjustment
 * @param array  $context         ['related_order_id' => int|null, 'note' => string|null, 'cap' => int|null]
 * @return int  Saldo efter operationen (eller -1 vid fel)
 */
function addTokensToBalance($organizationId, $tokens, $type, array $context = []) {
    $orgId = (int)$organizationId;
    $tokens = (int)$tokens;
    if ($orgId <= 0 || $tokens <= 0) return -1;

    $allowedTypes = ['monthly_refill', 'purchase', 'recurring', 'adjustment'];
    if (!in_array($type, $allowedTypes, true)) return -1;

    $db = getDb();
    try {
        $db->beginTransaction();

        // Lås raden för uppdatering (eller skapa den med saldo 0).
        $stmt = $db->prepare(
            "INSERT INTO " . DB_DATABASE . ".organization_token_balance (organization_id, balance)
             VALUES (?, 0)
             ON DUPLICATE KEY UPDATE organization_id = organization_id"
        );
        $stmt->execute([$orgId]);

        $stmt = $db->prepare(
            "SELECT balance FROM " . DB_DATABASE . ".organization_token_balance
              WHERE organization_id = ? FOR UPDATE"
        );
        $stmt->execute([$orgId]);
        $current = (int)$stmt->fetchColumn();

        // Tillämpa tak om angivet (för recurring).
        $cap = isset($context['cap']) ? (int)$context['cap'] : 0;
        $delta = $tokens;
        if ($cap > 0 && ($current + $delta) > $cap) {
            $delta = max(0, $cap - $current);
        }

        $newBalance = $current + $delta;

        if ($delta > 0) {
            $stmt = $db->prepare(
                "UPDATE " . DB_DATABASE . ".organization_token_balance
                    SET balance = ? WHERE organization_id = ?"
            );
            $stmt->execute([$newBalance, $orgId]);
        }

        // Skriv alltid en transaktionsrad — även när delta=0 — så att vi ser
        // att en refill-cykel faktiskt körde och stoppades av taket.
        $stmt = $db->prepare(
            "INSERT INTO " . DB_DATABASE . ".token_transactions
             (organization_id, type, tokens_delta, balance_after, related_order_id, note)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $orgId,
            $type,
            $delta,
            $newBalance,
            $context['related_order_id'] ?? null,
            $context['note'] ?? null,
        ]);

        $db->commit();
        return $newBalance;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('addTokensToBalance misslyckades för org ' . $orgId . ': ' . $e->getMessage());
        return -1;
    }
}

/**
 * Dra tokens från saldot. Kan gå under noll (vi blockerar inte här — själva
 * blockeringen sker i enforceAiQuotaForEmail innan anropet). Detta gör att
 * vi inte missar att logga ett anrop som av någon anledning glider igenom.
 *
 * @param int    $organizationId
 * @param int    $tokens          Antal tokens att dra (positivt heltal)
 * @param array  $context         ['related_usage_log_id' => int|null, 'note' => string|null]
 * @return int Saldo efter operationen (-1 vid fel)
 */
function consumeTokensFromBalance($organizationId, $tokens, array $context = []) {
    $orgId = (int)$organizationId;
    $tokens = (int)$tokens;
    if ($orgId <= 0 || $tokens <= 0) return -1;

    $db = getDb();
    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            "INSERT INTO " . DB_DATABASE . ".organization_token_balance (organization_id, balance)
             VALUES (?, 0)
             ON DUPLICATE KEY UPDATE organization_id = organization_id"
        );
        $stmt->execute([$orgId]);

        $stmt = $db->prepare(
            "SELECT balance FROM " . DB_DATABASE . ".organization_token_balance
              WHERE organization_id = ? FOR UPDATE"
        );
        $stmt->execute([$orgId]);
        $current = (int)$stmt->fetchColumn();

        $newBalance = $current - $tokens;

        $stmt = $db->prepare(
            "UPDATE " . DB_DATABASE . ".organization_token_balance
                SET balance = ? WHERE organization_id = ?"
        );
        $stmt->execute([$newBalance, $orgId]);

        $stmt = $db->prepare(
            "INSERT INTO " . DB_DATABASE . ".token_transactions
             (organization_id, type, tokens_delta, balance_after, related_usage_log_id, note)
             VALUES (?, 'consume', ?, ?, ?, ?)"
        );
        $stmt->execute([
            $orgId,
            -$tokens,
            $newBalance,
            $context['related_usage_log_id'] ?? null,
            $context['note'] ?? null,
        ]);

        $db->commit();
        return $newBalance;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('consumeTokensFromBalance misslyckades för org ' . $orgId . ': ' . $e->getMessage());
        return -1;
    }
}

// ---------------------------------------------------------------------------
// Faktureringsuppgifter
// ---------------------------------------------------------------------------

function getOrgBilling($organizationId) {
    if (!$organizationId) return null;
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".organization_billing WHERE organization_id = ?",
        [(int)$organizationId]
    );
}

function upsertOrgBilling($organizationId, array $data) {
    $orgId = (int)$organizationId;
    if ($orgId <= 0) return false;

    $contactName = trim($data['contact_name'] ?? '');
    $address = trim($data['invoice_address'] ?? '');
    $gln = trim($data['gln'] ?? '');
    $peppol = trim($data['peppol'] ?? '');
    $email = trim($data['contact_email'] ?? '');

    if ($contactName === '' || $address === '') return false;

    $existing = queryOne(
        "SELECT organization_id FROM " . DB_DATABASE . ".organization_billing WHERE organization_id = ?",
        [$orgId]
    );

    if ($existing) {
        execute(
            "UPDATE " . DB_DATABASE . ".organization_billing
                SET contact_name = ?, invoice_address = ?, gln = ?, peppol = ?, contact_email = ?
              WHERE organization_id = ?",
            [$contactName, $address, $gln ?: null, $peppol ?: null, $email ?: null, $orgId]
        );
    } else {
        execute(
            "INSERT INTO " . DB_DATABASE . ".organization_billing
             (organization_id, contact_name, invoice_address, gln, peppol, contact_email)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$orgId, $contactName, $address, $gln ?: null, $peppol ?: null, $email ?: null]
        );
    }
    return true;
}

// ---------------------------------------------------------------------------
// Beställningar
// ---------------------------------------------------------------------------

/**
 * Lägg en beställning. Snapshotar paket + billing, fyller på saldo direkt
 * och returnerar order-id.
 *
 * @param int   $organizationId
 * @param int   $packageId
 * @param array $billing       ['contact_name', 'invoice_address', 'gln', 'peppol', 'contact_email']
 * @param bool  $isRecurring   Aktivera månatlig auto-påfyllning
 * @param string $createdBy    E-post på admin som lägger ordern
 * @return int|false Order-ID eller false vid fel
 */
function createTokenOrder($organizationId, $packageId, array $billing, $isRecurring, $createdBy) {
    $orgId = (int)$organizationId;
    $pkg = getTokenPackage($packageId);
    if ($orgId <= 0 || !$pkg) return false;

    $contactName = trim($billing['contact_name'] ?? '');
    $address = trim($billing['invoice_address'] ?? '');
    $gln = trim($billing['gln'] ?? '');
    $peppol = trim($billing['peppol'] ?? '');
    $email = trim($billing['contact_email'] ?? '');
    if ($contactName === '' || $address === '') return false;

    $isRecurring = $isRecurring ? 1 : 0;

    $orderId = execute(
        "INSERT INTO " . DB_DATABASE . ".token_orders
         (organization_id, package_id, tokens, price_sek_cents,
          billing_contact_name, billing_address, billing_gln, billing_peppol, billing_email,
          is_recurring, recurring_active, last_refilled_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $orgId,
            (int)$pkg['id'],
            (int)$pkg['tokens'],
            (int)$pkg['price_sek_cents'],
            $contactName,
            $address,
            $gln ?: null,
            $peppol ?: null,
            $email ?: null,
            $isRecurring,
            $isRecurring,
            $isRecurring ? date('Y-m-d H:i:s') : null,
            $createdBy,
        ]
    );

    if (!$orderId) return false;

    // Fyll på saldo direkt — direkt aktivering enligt designvalet.
    addTokensToBalance(
        $orgId,
        (int)$pkg['tokens'],
        'purchase',
        [
            'related_order_id' => (int)$orderId,
            'note' => 'Beställning av paket ' . $pkg['name'],
        ]
    );

    // Spara faktureringsuppgifterna så de föranifylls vid nästa beställning.
    upsertOrgBilling($orgId, $billing);

    return (int)$orderId;
}

/**
 * Lista alla beställningar för en organisation, senaste först.
 */
function getOrgTokenOrders($organizationId, $limit = 50) {
    return query(
        "SELECT o.*, p.name AS package_name, p.code AS package_code
           FROM " . DB_DATABASE . ".token_orders o
           JOIN " . DB_DATABASE . ".token_packages p ON p.id = o.package_id
          WHERE o.organization_id = ?
          ORDER BY o.created_at DESC
          LIMIT " . (int)$limit,
        [(int)$organizationId]
    );
}

/**
 * Hämta senaste N transaktioner för en organisation.
 */
function getOrgTokenTransactions($organizationId, $limit = 20) {
    return query(
        "SELECT id, type, tokens_delta, balance_after, related_order_id, note, created_at
           FROM " . DB_DATABASE . ".token_transactions
          WHERE organization_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT " . (int)$limit,
        [(int)$organizationId]
    );
}

/**
 * Slå av recurring på en order.
 */
function cancelRecurringOrder($orderId, $organizationId) {
    $orderId = (int)$orderId;
    $orgId = (int)$organizationId;
    if ($orderId <= 0 || $orgId <= 0) return false;
    execute(
        "UPDATE " . DB_DATABASE . ".token_orders
            SET recurring_active = 0
          WHERE id = ? AND organization_id = ?",
        [$orderId, $orgId]
    );
    return true;
}

/**
 * Hämta aktiva recurring orders (för cron).
 */
function getActiveRecurringOrders() {
    return query(
        "SELECT o.*, p.tokens AS package_tokens, p.name AS package_name
           FROM " . DB_DATABASE . ".token_orders o
           JOIN " . DB_DATABASE . ".token_packages p ON p.id = o.package_id
          WHERE o.is_recurring = 1 AND o.recurring_active = 1"
    );
}

/**
 * Markera att en recurring order har körts denna månad.
 */
function markRecurringRefilled($orderId) {
    execute(
        "UPDATE " . DB_DATABASE . ".token_orders SET last_refilled_at = NOW() WHERE id = ?",
        [(int)$orderId]
    );
}

/**
 * Lista ALLA tokenbeställningar i systemet (superadmin-översikt), senaste först.
 * Joinar med organisationsnamn och paket. Kan filtreras på debiteringsstatus.
 *
 * @param string $billingFilter 'all' | 'unbilled' | 'billed'
 * @param int    $limit
 * @return array
 */
function getAllTokenOrders($billingFilter = 'all', $limit = 500) {
    $where = '';
    if ($billingFilter === 'unbilled') {
        $where = 'WHERE o.billed_at IS NULL';
    } elseif ($billingFilter === 'billed') {
        $where = 'WHERE o.billed_at IS NOT NULL';
    }
    return query(
        "SELECT o.*, p.name AS package_name, p.code AS package_code,
                org.name AS organization_name, org.org_number
           FROM " . DB_DATABASE . ".token_orders o
           JOIN " . DB_DATABASE . ".token_packages p ON p.id = o.package_id
           LEFT JOIN " . DB_DATABASE . ".organizations org ON org.id = o.organization_id
           $where
          ORDER BY o.created_at DESC
          LIMIT " . (int)$limit
    );
}

/**
 * Sammanfattning av debiteringsläget för översiktens nyckeltal.
 * Returnerar antal och summerat belopp (öre) för ej debiterade resp. debiterade.
 *
 * @return array{unbilled_count:int,unbilled_cents:int,billed_count:int,billed_cents:int}
 */
function getTokenOrdersBillingSummary() {
    $row = queryOne(
        "SELECT
            SUM(CASE WHEN billed_at IS NULL THEN 1 ELSE 0 END) AS unbilled_count,
            COALESCE(SUM(CASE WHEN billed_at IS NULL THEN price_sek_cents ELSE 0 END), 0) AS unbilled_cents,
            SUM(CASE WHEN billed_at IS NOT NULL THEN 1 ELSE 0 END) AS billed_count,
            COALESCE(SUM(CASE WHEN billed_at IS NOT NULL THEN price_sek_cents ELSE 0 END), 0) AS billed_cents
         FROM " . DB_DATABASE . ".token_orders"
    );
    return [
        'unbilled_count' => (int)($row['unbilled_count'] ?? 0),
        'unbilled_cents' => (int)($row['unbilled_cents'] ?? 0),
        'billed_count'   => (int)($row['billed_count'] ?? 0),
        'billed_cents'   => (int)($row['billed_cents'] ?? 0),
    ];
}

/**
 * Sätt eller nollställ debiteringsstatus på en beställning (superadmin).
 *
 * @param int    $orderId
 * @param bool   $billed     true = markera debiterad (sätter billed_at=NOW),
 *                           false = markera ej debiterad (nollar billed_at)
 * @param string $adminEmail Superadminens e-post (sparas i billed_by)
 * @return bool
 */
function setOrderBilled($orderId, $billed, $adminEmail) {
    $orderId = (int)$orderId;
    if ($orderId <= 0) return false;
    if ($billed) {
        execute(
            "UPDATE " . DB_DATABASE . ".token_orders
                SET billed_at = NOW(), billed_by = ?
              WHERE id = ?",
            [$adminEmail, $orderId]
        );
    } else {
        execute(
            "UPDATE " . DB_DATABASE . ".token_orders
                SET billed_at = NULL, billed_by = NULL
              WHERE id = ?",
            [$orderId]
        );
    }
    return true;
}
