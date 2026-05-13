<?php
/**
 * Stimma — Månatlig påfyllning av token-saldo
 *
 * Körs den 1:a varje månad. Två steg:
 *   1. Lägg på gratisbasen (ai_quotas.monthly_token_quota) för varje
 *      organisation som har en kvotrad.
 *   2. Kör alla aktiva recurring orders (token_orders.recurring_active=1) och
 *      lägg på paketstorleken, med tak TOKEN_RECURRING_CAP_MULTIPLIER × paket.
 *
 * Idempotent inom samma månad: en transaktion av typen 'monthly_refill' /
 * 'recurring' med samma kalendermånad körs bara en gång per org/order.
 *
 * Schemaläggning sker via Ofelia (label i docker-compose.yml):
 *   ofelia.job-exec.stimma-token-refill.schedule: "0 5 0 1 * *"
 *   ofelia.job-exec.stimma-token-refill.command:  "php /var/www/html/cron/monthly_token_refill.php"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

define('CLI_MODE', true);
chdir(dirname(__DIR__));

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/ai_quota.php';
require_once 'include/token_balance.php';

function logMsg($msg) {
    echo '[' . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

logMsg('Startar monthly_token_refill...');

$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-d 00:00:00', strtotime($monthStart . ' +1 month'));

// ---------------------------------------------------------------------------
// 1. Gratisbas till alla organisationer som har en ai_quotas-rad
// ---------------------------------------------------------------------------
$quotas = query(
    "SELECT organization_id, monthly_token_quota
       FROM " . DB_DATABASE . ".ai_quotas
      WHERE organization_id IS NOT NULL"
);

$refilledBase = 0;
$skippedBase = 0;
foreach ((array)$quotas as $q) {
    $orgId = (int)$q['organization_id'];
    $quotaTokens = (int)$q['monthly_token_quota'];
    if ($orgId <= 0 || $quotaTokens <= 0) continue;

    // Har vi redan kört monthly_refill för denna org denna månad?
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".token_transactions
          WHERE organization_id = ? AND type = 'monthly_refill'
            AND created_at >= ? AND created_at < ?
          LIMIT 1",
        [$orgId, $monthStart, $monthEnd]
    );
    if ($existing) {
        $skippedBase++;
        continue;
    }

    $newBalance = addTokensToBalance(
        $orgId,
        $quotaTokens,
        'monthly_refill',
        ['note' => 'Månatlig gratisbas']
    );
    if ($newBalance >= 0) {
        $refilledBase++;
        logMsg("  Gratisbas: org $orgId +$quotaTokens tokens → saldo $newBalance");
    } else {
        logMsg("  FEL: kunde inte fylla på gratisbas för org $orgId");
    }
}

logMsg("Gratisbas: $refilledBase fyllda på, $skippedBase redan körda denna månad.");

// ---------------------------------------------------------------------------
// 2. Recurring orders
// ---------------------------------------------------------------------------
$recurring = getActiveRecurringOrders();
$refilledRec = 0;
$skippedRec = 0;
foreach ((array)$recurring as $o) {
    $orderId = (int)$o['id'];
    $orgId = (int)$o['organization_id'];
    $tokens = (int)$o['tokens'];

    // Redan kört för denna order denna månad?
    $existing = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".token_transactions
          WHERE organization_id = ? AND type = 'recurring'
            AND related_order_id = ?
            AND created_at >= ? AND created_at < ?
          LIMIT 1",
        [$orgId, $orderId, $monthStart, $monthEnd]
    );
    if ($existing) {
        $skippedRec++;
        continue;
    }

    $cap = TOKEN_RECURRING_CAP_MULTIPLIER * $tokens;
    $newBalance = addTokensToBalance(
        $orgId,
        $tokens,
        'recurring',
        [
            'related_order_id' => $orderId,
            'note' => 'Auto-påfyllning ' . ($o['package_name'] ?? ''),
            'cap' => $cap,
        ]
    );

    if ($newBalance >= 0) {
        markRecurringRefilled($orderId);
        $refilledRec++;
        logMsg("  Recurring order $orderId: org $orgId paket {$o['package_name']} ($tokens) → saldo $newBalance (tak $cap)");
    } else {
        logMsg("  FEL: kunde inte köra recurring order $orderId för org $orgId");
    }
}

logMsg("Recurring: $refilledRec körda, $skippedRec redan körda denna månad.");
logMsg('Klart.');
