<?php
/**
 * Stimma — AI-kvot och användningsloggning
 *
 * Helpers för att avgöra vilken organisation/domän ett AI-anrop hör till,
 * läsa kvotinställning, summera månadsförbrukning, kontrollera kvot före
 * anrop, samt logga varje anrop med token- och kostnadsdata.
 *
 * Datamodell finns i migrations/035_ai_quota_and_usage.sql:
 *   - ai_pricing      (modellpris)
 *   - ai_quotas       (kvot per scope: organization_id ELLER domain)
 *   - ai_usage_log    (en rad per anrop)
 *
 * Scope-regel:
 *   1. Användarens domän slås upp.
 *   2. Om domänen tillhör en organization → scope = organization_id.
 *   3. Annars → scope = domain (organization_id IS NULL).
 */

if (!defined('AI_QUOTA_DEFAULT_TOKENS')) {
    define('AI_QUOTA_DEFAULT_TOKENS', 50000);
}
if (!defined('AI_QUOTA_DEFAULT_THRESHOLD_PCT')) {
    define('AI_QUOTA_DEFAULT_THRESHOLD_PCT', 80);
}
if (!defined('AI_QUOTA_DEFAULT_BEHAVIOR')) {
    define('AI_QUOTA_DEFAULT_BEHAVIOR', 'block');
}
// Bilder rapporterar inte token-användning från OpenAI. För att de ska
// belasta kvoten översätter vi varje bildanrop till en fast token-ekvivalent.
// 10 000 tokens motsvarar grovt 1 lektions textgenerering — så en bild
// upplevs lika "dyr" som en lektion.
if (!defined('AI_IMAGE_TOKEN_EQUIVALENT')) {
    define('AI_IMAGE_TOKEN_EQUIVALENT', 10000);
}

/**
 * Avgör scope (org eller domän) för ett givet e-postadress.
 *
 * @param string $email
 * @return array ['organization_id' => int|null, 'domain' => string|null, 'label' => string]
 */
function getAiScopeForEmail($email) {
    $userDomain = strtolower(trim(substr(strrchr((string)$email, '@'), 1) ?: ''));
    if ($userDomain === '') {
        return ['organization_id' => null, 'domain' => null, 'label' => 'okänd'];
    }
    $org = function_exists('getOrganizationByDomain') ? getOrganizationByDomain($userDomain) : null;
    if ($org && !empty($org['id'])) {
        return [
            'organization_id' => (int)$org['id'],
            'domain' => null,
            'label' => $org['name'] ?? ('Org #' . $org['id']),
        ];
    }
    return [
        'organization_id' => null,
        'domain' => $userDomain,
        'label' => $userDomain,
    ];
}

/**
 * Hämtar kvotinställning för ett scope. Returnerar default om ingen rad finns.
 *
 * @param int|null $organizationId
 * @param string|null $domain
 * @return array { id?, organization_id, domain, monthly_token_quota, alert_threshold_pct, behavior, notes, is_default }
 */
function getAiQuota($organizationId, $domain) {
    if ($organizationId) {
        $row = queryOne(
            "SELECT * FROM " . DB_DATABASE . ".ai_quotas WHERE organization_id = ? LIMIT 1",
            [$organizationId]
        );
    } elseif ($domain) {
        $row = queryOne(
            "SELECT * FROM " . DB_DATABASE . ".ai_quotas WHERE domain = ? LIMIT 1",
            [$domain]
        );
    } else {
        $row = null;
    }

    if ($row) {
        $row['is_default'] = false;
        return $row;
    }

    return [
        'organization_id' => $organizationId,
        'domain' => $domain,
        'monthly_token_quota' => AI_QUOTA_DEFAULT_TOKENS,
        'alert_threshold_pct' => AI_QUOTA_DEFAULT_THRESHOLD_PCT,
        'behavior' => AI_QUOTA_DEFAULT_BEHAVIOR,
        'notes' => null,
        'is_default' => true,
    ];
}

/**
 * Summerar förbrukning innevarande kalendermånad för ett scope.
 *
 * @return array ['total_tokens' => int, 'cost_cents' => int, 'requests' => int]
 */
function getMonthlyAiUsage($organizationId, $domain, $year = null, $month = null) {
    $y = $year ?: (int)date('Y');
    $m = $month ?: (int)date('n');
    $start = sprintf('%04d-%02d-01 00:00:00', $y, $m);
    $end = date('Y-m-d 00:00:00', strtotime("$start +1 month"));

    if ($organizationId) {
        $row = queryOne(
            "SELECT COALESCE(SUM(total_tokens),0) AS tokens,
                    COALESCE(SUM(cost_cents),0) AS cents,
                    COUNT(*) AS requests
               FROM " . DB_DATABASE . ".ai_usage_log
              WHERE organization_id = ?
                AND status IN ('ok','blocked')
                AND created_at >= ? AND created_at < ?",
            [$organizationId, $start, $end]
        );
    } elseif ($domain) {
        $row = queryOne(
            "SELECT COALESCE(SUM(total_tokens),0) AS tokens,
                    COALESCE(SUM(cost_cents),0) AS cents,
                    COUNT(*) AS requests
               FROM " . DB_DATABASE . ".ai_usage_log
              WHERE domain = ?
                AND status IN ('ok','blocked')
                AND created_at >= ? AND created_at < ?",
            [$domain, $start, $end]
        );
    } else {
        return ['total_tokens' => 0, 'cost_cents' => 0, 'requests' => 0];
    }

    return [
        'total_tokens' => (int)($row['tokens'] ?? 0),
        'cost_cents' => (int)($row['cents'] ?? 0),
        'requests' => (int)($row['requests'] ?? 0),
    ];
}

/**
 * Kontrollerar kvot för ett scope. Returnerar status för bannerlogik och
 * avgör om nästa anrop ska tillåtas.
 *
 * Saldo-baserat sedan migration 040: när scopet har en organization_id används
 * organization_token_balance.balance som primär budget. Saldot fylls på den
 * 1:a varje månad med monthly_token_quota (gratisbas) + ev. aktiva recurring
 * orders, och kan toppas upp via admin-beställning av paket.
 *
 * Domän-scopes (utan org) faller tillbaka på den gamla månadskvotlogiken
 * eftersom saldosystemet kräver organization_id.
 *
 * @return array {
 *   allowed: bool,
 *   pct: int,                 // 0..100+ — % av gratisbasen som är förbrukat
 *   level: 'ok'|'warn'|'block',
 *   mode: 'balance'|'monthly_quota',
 *   tokens_balance: int,      // -1 om mode=monthly_quota
 *   tokens_used: int,         // månadsförbrukning (statistik)
 *   tokens_quota: int,        // månatlig gratisbas
 *   behavior: string,
 *   organization_id: ?int,
 *   domain: ?string,
 *   reason: ?string
 * }
 */
function checkAiQuota($organizationId, $domain) {
    $quota = getAiQuota($organizationId, $domain);
    $usage = getMonthlyAiUsage($organizationId, $domain);

    $tokensQuota = max(1, (int)$quota['monthly_token_quota']);
    $tokensUsed = (int)$usage['total_tokens'];
    $threshold = (int)$quota['alert_threshold_pct'];
    $behavior = $quota['behavior'];

    $level = 'ok';
    $allowed = true;
    $reason = null;
    $mode = 'monthly_quota';
    $balance = -1;
    $pct = 0;

    if ($organizationId) {
        // Saldo-läge: ladda saldo-helpern lazyt (undviker cirkulär include).
        if (!function_exists('getOrgTokenBalance')) {
            require_once __DIR__ . '/token_balance.php';
        }
        $mode = 'balance';

        // Skilj på "ej initialiserad" (ingen rad → ge gratisbas direkt) och
        // "förbrukat" (rad med 0). Annars hade alla orgs blockerats första
        // gången de träffar systemet, trots att de inte förbrukat något.
        if (!hasOrgTokenBalance($organizationId)) {
            bootstrapOrgTokenBalance($organizationId, $tokensQuota);
        }

        $balance = getOrgTokenBalance($organizationId);
        // pct = hur mycket av gratisbasen som "saknas" i saldot — gör att
        // bannerlogiken (warn vid threshold) fortfarande fungerar utan att
        // ändra header.php-mallen.
        $consumedFromBase = max(0, $tokensQuota - $balance);
        $pct = (int)floor(($consumedFromBase / $tokensQuota) * 100);

        if ($balance <= 0) {
            if ($behavior === 'block') {
                $level = 'block';
                $allowed = false;
                $reason = 'token_balance_empty';
            } else {
                $level = 'warn';
            }
        } elseif ($pct >= $threshold) {
            $level = 'warn';
        }
    } else {
        // Fallback för domän-scopes utan org — gammal månadskvotlogik.
        $pct = (int)floor(($tokensUsed / $tokensQuota) * 100);
        if ($tokensUsed >= $tokensQuota) {
            if ($behavior === 'block') {
                $level = 'block';
                $allowed = false;
                $reason = 'monthly_quota_exceeded';
            } else {
                $level = 'warn';
            }
        } elseif ($pct >= $threshold) {
            $level = 'warn';
        }
    }

    return [
        'allowed' => $allowed,
        'pct' => $pct,
        'level' => $level,
        'mode' => $mode,
        'tokens_balance' => $balance,
        'tokens_used' => $tokensUsed,
        'tokens_quota' => $tokensQuota,
        'behavior' => $behavior,
        'organization_id' => $organizationId,
        'domain' => $domain,
        'reason' => $reason,
    ];
}

/**
 * Hämta vilken modell som är konfigurerad för en given feature.
 * Faller tillbaka på $default om inget val är sparat.
 *
 * @param string $feature  course_gen | lesson_gen | chat | image
 * @param string $default  Modell att använda om ingen rad finns
 * @return string Modell-id (t.ex. 'gpt-4o', 'gpt-4o-mini', 'gpt-image-1-mini')
 */
function getModelForFeature($feature, $default) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = query("SELECT feature, model FROM " . DB_DATABASE . ".ai_feature_models");
        if (is_array($rows)) {
            foreach ($rows as $r) $cache[$r['feature']] = $r['model'];
        }
    }
    return $cache[$feature] ?? $default;
}

/**
 * Spara modellval för en feature (anropas från superadmin-UI).
 */
function setModelForFeature($feature, $model, $updatedBy = null) {
    $allowed = ['course_gen', 'lesson_gen', 'chat', 'image'];
    if (!in_array($feature, $allowed, true)) return false;
    $exists = queryOne(
        "SELECT 1 AS x FROM " . DB_DATABASE . ".ai_feature_models WHERE feature = ?",
        [$feature]
    );
    if ($exists) {
        execute(
            "UPDATE " . DB_DATABASE . ".ai_feature_models SET model = ?, updated_by = ? WHERE feature = ?",
            [$model, $updatedBy, $feature]
        );
    } else {
        execute(
            "INSERT INTO " . DB_DATABASE . ".ai_feature_models (feature, model, updated_by) VALUES (?, ?, ?)",
            [$feature, $model, $updatedBy]
        );
    }
    return true;
}

/**
 * Lista alla kända modeller från ai_pricing (för dropdown i admin-UI).
 * Skiljer på text- vs bildmodeller.
 */
function listAvailableAiModels() {
    $rows = query("SELECT model, input_per_1m_cents, output_per_1m_cents, image_per_call_cents
                     FROM " . DB_DATABASE . ".ai_pricing ORDER BY model");
    $text = [];
    $image = [];
    foreach ((array)$rows as $r) {
        if ((int)$r['image_per_call_cents'] > 0) {
            $image[] = $r;
        } else {
            $text[] = $r;
        }
    }
    return ['text' => $text, 'image' => $image];
}

/**
 * Slå upp pris för en modell. Returnerar nollor om okänd modell.
 */
function getAiModelPricing($model) {
    static $cache = [];
    $key = strtolower((string)$model);
    if (isset($cache[$key])) return $cache[$key];

    $row = queryOne(
        "SELECT input_per_1m_cents, output_per_1m_cents, image_per_call_cents
           FROM " . DB_DATABASE . ".ai_pricing WHERE LOWER(model) = ? LIMIT 1",
        [$key]
    );
    $cache[$key] = $row ?: [
        'input_per_1m_cents' => 0,
        'output_per_1m_cents' => 0,
        'image_per_call_cents' => 0,
    ];
    return $cache[$key];
}

/**
 * Beräkna kostnad i USD-cent för ett anrop. För bildmodeller används
 * image_per_call_cents (token-fält ignoreras).
 */
function calculateAiCostCents($model, $promptTokens, $completionTokens, $isImage = false) {
    $p = getAiModelPricing($model);
    if ($isImage) {
        return (int)round((int)$p['image_per_call_cents']);
    }
    $cost = ((int)$promptTokens * (int)$p['input_per_1m_cents'] / 1000000)
          + ((int)$completionTokens * (int)$p['output_per_1m_cents'] / 1000000);
    return (int)round($cost);
}

/**
 * Skriv en rad i ai_usage_log. Ska aldrig kasta — loggning får inte
 * stoppa det egentliga anropet.
 *
 * @param array $context  ['feature' => 'course_gen'|..., 'course_id' => ?int, 'user_email' => ?string]
 * @param array $usage    ['prompt_tokens' => int, 'completion_tokens' => int, 'total_tokens' => int]
 * @param string $model
 * @param string $status  'ok' | 'blocked' | 'error'
 */
function logAiUsage(array $context, array $usage, $model, $status = 'ok') {
    try {
        $email = $context['user_email'] ?? ($_SESSION['user_email'] ?? null);
        $scope = getAiScopeForEmail($email);
        $feature = $context['feature'] ?? 'other';
        $courseId = isset($context['course_id']) && $context['course_id'] !== null
            ? (int)$context['course_id'] : null;
        $isImage = !empty($context['is_image']);

        $promptT = (int)($usage['prompt_tokens'] ?? 0);
        $completionT = (int)($usage['completion_tokens'] ?? 0);
        if ($isImage) {
            // Bild: drar fast token-ekvivalent mot kvoten, men cost_cents speglar
            // OpenAI:s faktiska bildpris (image_per_call_cents).
            $totalT = AI_IMAGE_TOKEN_EQUIVALENT;
        } else {
            $totalT = (int)($usage['total_tokens'] ?? ($promptT + $completionT));
        }
        $costCents = calculateAiCostCents($model, $promptT, $completionT, $isImage);

        $usageLogId = execute(
            "INSERT INTO " . DB_DATABASE . ".ai_usage_log
             (organization_id, domain, user_email, course_id, feature, model,
              prompt_tokens, completion_tokens, total_tokens, cost_cents, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $scope['organization_id'],
                $scope['domain'],
                $email,
                $courseId,
                $feature,
                (string)$model,
                $promptT,
                $completionT,
                $totalT,
                $costCents,
                $status,
            ]
        );

        // Dra från saldot om scopet har en organisation och anropet faktiskt
        // gick igenom (status='ok'). 'blocked'/'error' ska inte belasta saldot.
        if ($status === 'ok' && $scope['organization_id'] && $totalT > 0) {
            if (!function_exists('consumeTokensFromBalance')) {
                require_once __DIR__ . '/token_balance.php';
            }
            consumeTokensFromBalance(
                (int)$scope['organization_id'],
                (int)$totalT,
                [
                    'related_usage_log_id' => $usageLogId ? (int)$usageLogId : null,
                    'note' => $feature . ' / ' . $model,
                ]
            );
        }
    } catch (Throwable $e) {
        error_log('logAiUsage misslyckades: ' . $e->getMessage());
    }
}

/**
 * Kontrollerar kvot för aktuell session. Kastar Exception med tydligt
 * meddelande om kvoten är full och behavior=block.
 *
 * Anropas av sendOpenAIRequest före varje API-anrop.
 *
 * @return array Resultat från checkAiQuota — caller kan använda 'level'
 *               för att t.ex. visa varning i svaret.
 */
function enforceAiQuotaForCurrentSession() {
    return enforceAiQuotaForEmail($_SESSION['user_email'] ?? null);
}

/**
 * Variant som tar e-post explicit (cron, batch-jobb utan session).
 */
function enforceAiQuotaForEmail($email) {
    $scope = getAiScopeForEmail($email);
    // Lazy fallback: om ett scope saknar kvotrad (t.ex. self-register som
    // skapade användaren utan att gå via createOrganization/admin/sync) ska
    // den dyka upp i superadmin-UI:t direkt vid första AI-kontakt.
    if ($scope['organization_id'] || $scope['domain']) {
        ensureAiQuotaRow($scope['organization_id'], $scope['domain']);
    }
    $check = checkAiQuota($scope['organization_id'], $scope['domain']);
    if (!$check['allowed']) {
        if ($check['reason'] === 'token_balance_empty') {
            throw new Exception(
                'Token-saldot för ' . $scope['label'] . ' är slut. '
                . 'En administratör kan beställa fler tokens under '
                . 'Adminpanelen → AI-användning.'
            );
        }
        throw new Exception(
            'AI-kvoten för ' . $scope['label'] . ' är förbrukad denna månad. '
            . 'Kontakta hjalp@sambruksupport.se för att utöka kvoten.'
        );
    }
    return $check;
}

/**
 * Säkerställer att en kvotrad finns för ett scope. Idempotent — gör inget
 * om raden redan finns (UNIQUE-keys på organization_id resp. domain).
 *
 * Anropas från createOrganization(), från användarskapande, och som lazy
 * fallback i enforceAiQuotaForEmail() så att alla scopes blir synliga i
 * superadmin-UI:t även om något skapelseflöde missas.
 *
 * @param int|null $organizationId
 * @param string|null $domain
 * @return bool true om en ny rad skapades, false om den redan fanns
 */
function ensureAiQuotaRow($organizationId, $domain) {
    if (!$organizationId && !$domain) return false;

    if ($organizationId) {
        $exists = queryOne(
            "SELECT 1 AS x FROM " . DB_DATABASE . ".ai_quotas WHERE organization_id = ? LIMIT 1",
            [(int)$organizationId]
        );
    } else {
        $exists = queryOne(
            "SELECT 1 AS x FROM " . DB_DATABASE . ".ai_quotas WHERE domain = ? LIMIT 1",
            [$domain]
        );
    }
    if ($exists) return false;

    try {
        execute(
            "INSERT IGNORE INTO " . DB_DATABASE . ".ai_quotas
             (organization_id, domain, monthly_token_quota, alert_threshold_pct, behavior, notes, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $organizationId,
                $organizationId ? null : $domain,
                AI_QUOTA_DEFAULT_TOKENS,
                AI_QUOTA_DEFAULT_THRESHOLD_PCT,
                AI_QUOTA_DEFAULT_BEHAVIOR,
                'Auto-skapad vid första kontakt',
                'system',
            ]
        );
        return true;
    } catch (Throwable $e) {
        error_log('ensureAiQuotaRow misslyckades: ' . $e->getMessage());
        return false;
    }
}

/**
 * Spara/uppdatera kvotinställning för ett scope (anropas från admin/ai_quotas.php).
 */
function upsertAiQuota($organizationId, $domain, $tokenQuota, $thresholdPct, $behavior, $notes, $updatedBy) {
    $tokenQuota = max(0, (int)$tokenQuota);
    $thresholdPct = max(1, min(99, (int)$thresholdPct));
    $behavior = in_array($behavior, ['block', 'warn'], true) ? $behavior : 'block';

    $existing = ($organizationId
        ? queryOne("SELECT id FROM " . DB_DATABASE . ".ai_quotas WHERE organization_id = ?", [$organizationId])
        : queryOne("SELECT id FROM " . DB_DATABASE . ".ai_quotas WHERE domain = ?", [$domain])
    );

    if ($existing) {
        execute(
            "UPDATE " . DB_DATABASE . ".ai_quotas
                SET monthly_token_quota = ?, alert_threshold_pct = ?, behavior = ?,
                    notes = ?, updated_by = ?
              WHERE id = ?",
            [$tokenQuota, $thresholdPct, $behavior, $notes, $updatedBy, $existing['id']]
        );
        return (int)$existing['id'];
    }

    execute(
        "INSERT INTO " . DB_DATABASE . ".ai_quotas
         (organization_id, domain, monthly_token_quota, alert_threshold_pct, behavior, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$organizationId, $domain, $tokenQuota, $thresholdPct, $behavior, $notes, $updatedBy]
    );
    return (int)getDb()->lastInsertId();
}
