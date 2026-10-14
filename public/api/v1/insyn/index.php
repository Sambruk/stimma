<?php
/**
 * Stimma – insyns-API:t, version 1 (2026-10-14).
 *
 * Läs-API för Sambruks förvaltning och uppföljning. Bara GET, bara systemnycklar
 * (sts_...). Regler och organisationsmodell: include/insyn_api.php.
 * Dokumentation: docs/API-INSYN.md.
 */

require_once __DIR__ . '/../../../include/insyn_api.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$route = trim(preg_replace('#^.*?/api/v1/insyn#', '', $path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // Bara läsning. Metoden prövas före nyckeln, så att ett skrivförsök aldrig når
    // någon kod som tolkar innehållet. HEAD behandlas som GET av Apache.
    if ($method !== 'GET' && $method !== 'HEAD') {
        insynError(405, 'API:t är bara för läsning. Använd GET.', ['Allow: GET']);
    }

    $key = insynAuthenticate();

    switch (true) {
        case $route === 'health':
            insynAllowParams([]);
            insynHealth($key);
        case $route === 'organizations':
            insynAllowParams([]);
            insynOrganizations();
        case $route === 'admins':
            insynAllowParams(['organization_id']);
            insynAdmins();
        case $route === 'editors':
            insynAllowParams(['organization_id']);
            insynEditors();
        case $route === 'pub-agreements':
            insynAllowParams(['organization_id', 'status']);
            insynPubAgreements();
        case $route === 'courses':
            insynAllowParams(['organization_id', 'status', 'baskurs']);
            insynCourses();
        case (bool)preg_match('#^courses/(\d+)/stats$#', $route, $m):
            insynAllowParams([]);
            insynCourseStats((int)$m[1]);
        case $route === 'usage':
            insynAllowParams([]);
            insynUsage();
        case $route === 'learning-paths':
            insynAllowParams(['organization_id']);
            insynLearningPaths();
        default:
            insynError(404, "Okänd endpoint '/$route'. Se /api/v1/insyn/health och docs/API-INSYN.md.");
    }
} catch (Throwable $e) {
    // Tabellerna saknas tills migrations/051_system_api_keys.sql är körd.
    if ($e instanceof PDOException && $e->getCode() === '42S02') {
        $GLOBALS['INSYN']['logged'] = true; // loggtabellen finns inte heller
        insynError(503, 'Insyns-API:t är inte aktiverat ännu (migrering 051 saknas).');
    }
    error_log('insyn: ' . $route . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    insynError(500, 'Internt fel. Felet är loggat.');
}

// =================================================================== endpoints

function insynHealth(array $key): void {
    insynSend(200, [
        'status' => 'ok',
        'api' => 'stimma-insyn',
        'version' => INSYN_API_VERSION,
        'time' => date('c'),
        'organizations' => count(insynUnits()),
        'key' => ['name' => $key['name'], 'prefix' => $key['api_key_prefix']],
        'limits' => [
            'per_minute' => INSYN_RATE_PER_MINUTE,
            'per_hour' => INSYN_RATE_PER_HOUR,
            'max_per_page' => INSYN_MAX_PER_PAGE,
            'min_group' => INSYN_MIN_GROUP,
        ],
    ]);
}

/** GET /organizations */
function insynOrganizations(): void {
    $db = DB_DATABASE;
    $units = insynUnits();
    uasort($units, fn($a, $b) => strcoll(mb_strtolower($a['name']), mb_strtolower($b['name'])));

    // Användare per domän med senaste inloggning, en gång för alla organisationer.
    $logins = insynLastLogins();
    $perDomain = [];
    $t30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $t90 = date('Y-m-d H:i:s', strtotime('-90 days'));
    foreach (insynAll("SELECT LOWER(email) AS email FROM $db.users") as $r) {
        $d = substr(strrchr($r['email'], '@') ?: '', 1);
        $perDomain[$d] ??= ['total' => 0, 'active30' => 0, 'active90' => 0];
        $perDomain[$d]['total']++;
        $last = $logins[$r['email']] ?? null;
        if ($last !== null && $last >= $t30) $perDomain[$d]['active30']++;
        if ($last !== null && $last >= $t90) $perDomain[$d]['active90']++;
    }

    insynSendPage(array_values($units), insynPaging(), function (array $u) use ($db, $perDomain) {
        $in = insynIn($u['domains']);
        $users = ['total' => 0, 'active30' => 0, 'active90' => 0];
        foreach ($u['domains'] as $d) {
            foreach ($users as $k => $_) $users[$k] += $perDomain[$d][$k] ?? 0;
        }
        $courses = insynOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(c.status = 'active'), 0) AS active,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM $db.lessons l WHERE l.course_id = c.id
                                          AND l.status = 'active' AND l.ai_chat_enabled = 1)), 0) AS with_tutor
               FROM $db.courses c WHERE LOWER(c.organization_domain) IN ($in)",
            $u['domains']
        );

        // AI-pott: samma regler som adminvyn (include/ai_quota.php). Grupperade
        // organisationer har kvot och saldo på organisationen, ensamma domäner på domänen.
        $orgId = $u['organization_id'];
        $dom = $orgId ? null : $u['primary_domain'];
        $quota = getAiQuota($orgId, $dom);
        $used = getMonthlyAiUsage($orgId, $dom);
        $balance = $orgId ? insynOne("SELECT balance FROM $db.organization_token_balance WHERE organization_id = ?", [$orgId]) : null;

        $pub = insynOne(
            "SELECT version, signed_at FROM $db.pub_agreement_artifacts
              WHERE LOWER(domain) IN ($in) AND invalidated_at IS NULL ORDER BY signed_at DESC LIMIT 1",
            $u['domains']
        );

        return [
            'id' => $u['id'],
            'type' => $u['type'],
            'name' => $u['name'],
            'org_number' => $u['org_number'],
            'primary_domain' => $u['primary_domain'],
            'domains' => $u['domains'],
            'created_at' => insynTime($u['created_at']),
            'users' => [
                'total' => insynCount($users['total']),
                'active_30_days' => insynCount($users['active30']),
                'active_90_days' => insynCount($users['active90']),
            ],
            'courses' => ['total' => (int)$courses['total'], 'active' => (int)$courses['active']],
            'ai' => [
                'monthly_token_quota' => (int)$quota['monthly_token_quota'],
                'quota_is_default' => !empty($quota['is_default']),
                'tokens_used_this_month' => $used['total_tokens'],
                'cost_this_month_usd' => round($used['cost_cents'] / 100, 2),
                'token_balance' => $balance ? (int)$balance['balance'] : null,
                'behavior' => $quota['behavior'],
            ],
            'ai_tutor' => [
                'enabled' => (int)$courses['with_tutor'] > 0,
                'courses_with_tutor' => (int)$courses['with_tutor'],
            ],
            'pub_agreement' => $pub ? ['status' => 'gällande', 'version' => $pub['version'], 'date' => insynDate($pub['signed_at'])]
                                    : ['status' => 'saknas'],
        ];
    });
}

/** GET /admins?organization_id= */
function insynAdmins(): void {
    $db = DB_DATABASE;
    $unit = insynRequestedUnit();
    $where = "(u.is_admin = 1 OR u.role IN ('admin', 'super_admin'))";
    $params = [];
    if ($unit) {
        $where .= " AND LOWER(SUBSTRING_INDEX(u.email, '@', -1)) IN (" . insynIn($unit['domains']) . ")";
        $params = $unit['domains'];
    }
    $rows = insynAll(
        "SELECT u.name, u.email, u.role FROM $db.users u WHERE $where ORDER BY u.email",
        $params
    );
    insynSendPage($rows, insynPaging(), fn($r) => [
        'name' => $r['name'],
        'email' => $r['email'],
        'role' => $r['role'] === 'super_admin' ? 'superadmin' : 'admin',
        'organization' => insynUnitRef(insynUnitIdForEmail($r['email'])),
        'last_login' => insynDate(insynLastLogin($r['email'])),
    ]);
}

/** GET /editors?organization_id= */
function insynEditors(): void {
    $db = DB_DATABASE;
    $unit = insynRequestedUnit();

    // Redaktörer = användare med redaktörsrollen (is_editor) och alla som är
    // redaktörer för en enskild kurs (course_editors), även över organisationsgränsen.
    $emails = [];
    foreach (insynAll("SELECT LOWER(email) AS email, name, is_editor FROM $db.users WHERE is_editor = 1") as $r) {
        $emails[$r['email']] = ['name' => $r['name'], 'role_editor' => true];
    }
    $courses = [];
    foreach (insynAll(
        "SELECT LOWER(ce.email) AS email, c.id, c.title, c.organization_domain, u.name
           FROM $db.course_editors ce
           JOIN $db.courses c ON c.id = ce.course_id
           LEFT JOIN $db.users u ON LOWER(u.email) = LOWER(ce.email)
          ORDER BY c.id"
    ) as $r) {
        $emails[$r['email']] ??= ['name' => $r['name'], 'role_editor' => false];
        $courses[$r['email']][] = [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'organization' => insynUnitRef(insynUnitIdForDomain($r['organization_domain'])),
        ];
    }
    ksort($emails);

    $items = [];
    foreach ($emails as $email => $e) {
        $unitId = insynUnitIdForEmail($email);
        if ($unit && (string)$unitId !== (string)$unit['id']) continue;
        $items[] = [
            'name' => $e['name'],
            'email' => $email,
            'organization' => insynUnitRef($unitId),
            'editor_role' => $e['role_editor'],
            'courses' => $courses[$email] ?? [],
        ];
    }
    insynSendPage($items, insynPaging());
}

/** GET /pub-agreements?organization_id=&status= */
function insynPubAgreements(): void {
    $db = DB_DATABASE;
    $unit = insynRequestedUnit();
    $status = $_GET['status'] ?? '';
    if ($status !== '' && !in_array($status, ['gällande', 'saknas'], true)) {
        insynError(400, "status måste vara 'gällande' eller 'saknas'.");
    }

    $doc = insynOne("SELECT version, created_at, file_hash FROM $db.pub_agreement_documents WHERE is_active = 1 LIMIT 1");
    $byUnit = [];
    foreach (insynAll(
        "SELECT agreement_id, version, signed_at, user_name, user_title, user_email, pdf_hash,
                LOWER(domain) AS domain, org_name, org_number, invalidated_at, invalidation_comment
           FROM $db.pub_agreement_artifacts ORDER BY signed_at DESC"
    ) as $a) {
        $byUnit[insynUnitIdForDomain($a['domain']) ?? $a['domain']][] = $a;
    }
    $flags = [];
    foreach (insynAll("SELECT LOWER(domain) AS domain FROM $db.domain_settings WHERE has_pub_agreement = 1") as $r) {
        $flags[$r['domain']] = true;
    }
    $orgFlags = [];
    foreach (insynAll("SELECT id FROM $db.organizations WHERE has_pub_agreement = 1") as $r) {
        $orgFlags[(int)$r['id']] = true;
    }

    $items = [];
    foreach (insynUnits() as $id => $u) {
        if ($unit && (string)$id !== (string)$unit['id']) continue;
        $agreements = [];
        $current = null;
        foreach ($byUnit[$id] ?? [] as $a) {
            if ($a['invalidated_at'] !== null) {
                $st = 'ogiltigförklarat';
            } elseif ($current === null) {
                $st = 'gällande';
            } else {
                $st = 'ersatt';
            }
            $row = [
                'agreement_id' => $a['agreement_id'],
                'version' => $a['version'],
                'date' => insynDate($a['signed_at']),
                'signed_at' => insynTime($a['signed_at']),
                'status' => $st,
                'domain' => $a['domain'],
                'org_name' => $a['org_name'],
                'org_number' => $a['org_number'],
                'signer' => ['name' => $a['user_name'], 'title' => $a['user_title'], 'email' => $a['user_email']],
                'checksum_sha256' => $a['pdf_hash'],
            ];
            if ($st === 'ogiltigförklarat') {
                $row['invalidated_at'] = insynTime($a['invalidated_at']);
                $row['invalidation_comment'] = $a['invalidation_comment'];
            }
            if ($st === 'gällande') $current = $row;
            $agreements[] = $row;
        }
        $unitStatus = $current ? 'gällande' : 'saknas';
        if ($status !== '' && $status !== $unitStatus) continue;

        // Flaggan är det produkten faktiskt läser (userHasPubAgreement()). Den kan vara
        // satt för hand utan något tecknat avtal i Stimma, från tiden före e-signeringen.
        $flag = ($u['organization_id'] && isset($orgFlags[$u['organization_id']]))
            || (bool)array_intersect($u['domains'], array_keys($flags));

        $items[] = [
            'organization' => ['id' => $u['id'], 'name' => $u['name'], 'domains' => $u['domains']],
            'status' => $unitStatus,
            'current_version_signed' => $current && $doc ? $current['version'] === $doc['version'] : false,
            'pub_flag_in_stimma' => $flag,
            'current' => $current,
            'agreements' => $agreements,
        ];
    }
    insynSendPage($items, insynPaging(), null, [
        'active_document' => $doc ? ['version' => $doc['version'], 'published' => insynDate($doc['created_at']), 'checksum_sha256' => $doc['file_hash']] : null,
    ]);
}

/** Kopieringar ur loggen: nytt kurs-id => käll-id. Kopian sparar bara källans domän. */
function insynCopySources(): array {
    $map = [];
    foreach (insynAll("SELECT message FROM " . DB_DATABASE . ".logs WHERE message LIKE 'Kopierade kurs | Kontext:%'") as $r) {
        if (preg_match('/"source_course_id":"?(\d+)"?.*?"new_course_id":"?(\d+)"?/', $r['message'], $m)) {
            $map[(int)$m[2]] = (int)$m[1];
        }
    }
    return $map;
}

/** GET /courses?organization_id=&status=&baskurs= */
function insynCourses(): void {
    $db = DB_DATABASE;
    $unit = insynRequestedUnit();
    $where = ['1=1'];
    $params = [];
    if ($unit) {
        $where[] = "LOWER(c.organization_domain) IN (" . insynIn($unit['domains']) . ")";
        $params = array_merge($params, $unit['domains']);
    }
    $status = $_GET['status'] ?? '';
    if ($status !== '') {
        if (!in_array($status, ['active', 'inactive'], true)) insynError(400, "status måste vara 'active' eller 'inactive'.");
        $where[] = 'c.status = ?';
        $params[] = $status;
    }
    $baskursSql = "EXISTS (SELECT 1 FROM $db.course_tags bct JOIN $db.tags bt ON bt.id = bct.tag_id
                            WHERE bct.course_id = c.id AND bt.name = 'Baskurs' AND bt.organization_domain = 'sambruk.se')";
    $bk = $_GET['baskurs'] ?? '';
    if ($bk !== '') {
        if (!in_array($bk, ['0', '1'], true)) insynError(400, 'baskurs måste vara 0 eller 1.');
        $where[] = ($bk === '1' ? '' : 'NOT ') . $baskursSql;
    }

    $rows = insynAll(
        "SELECT c.id, c.title, c.status, c.organization_domain, c.original_organization_domain,
                c.is_public, c.is_global, c.sequential_mode, c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM $db.lessons l WHERE l.course_id = c.id AND " . requiredLessonSqlInsyn('l') . ") AS lessons,
                (SELECT COUNT(*) FROM $db.lessons l WHERE l.course_id = c.id AND l.status = 'active' AND l.lesson_type = 'info_page') AS info_pages,
                EXISTS (SELECT 1 FROM $db.lessons l WHERE l.course_id = c.id AND l.status = 'active' AND l.ai_chat_enabled = 1) AS ai_tutor,
                $baskursSql AS baskurs
           FROM $db.courses c
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.id",
        $params
    );

    $tags = [];
    foreach (insynAll("SELECT ct.course_id, t.name FROM $db.course_tags ct JOIN $db.tags t ON t.id = ct.tag_id ORDER BY t.name") as $t) {
        $tags[(int)$t['course_id']][] = $t['name'];
    }
    $copies = insynCopySources();

    insynSendPage($rows, insynPaging(), function ($c) use ($tags, $copies) {
        $copied = null;
        // original_organization_domain är satt även på originalkurser (= egna domänen).
        // En kopia är en kurs vars ursprung är en annan domän.
        $orig = strtolower(trim((string)$c['original_organization_domain']));
        if ($orig !== '' && $orig !== strtolower(trim((string)$c['organization_domain']))) {
            $copied = [
                'organization' => insynUnitRef(insynUnitIdForDomain($c['original_organization_domain'])),
                'domain' => $c['original_organization_domain'],
                'course_id' => $copies[(int)$c['id']] ?? null,
            ];
        }
        return [
            'id' => (int)$c['id'],
            'title' => $c['title'],
            'organization' => insynUnitRef(insynUnitIdForDomain($c['organization_domain'])),
            'organization_domain' => $c['organization_domain'],
            'status' => $c['status'],
            'internal' => !$c['is_public'] && !$c['is_global'],
            'public' => (bool)$c['is_public'],
            'global' => (bool)$c['is_global'],
            'sequential' => (bool)$c['sequential_mode'],
            'copied_from' => $copied,
            'lessons' => (int)$c['lessons'],
            'info_pages' => (int)$c['info_pages'],
            'ai_tutor' => (bool)$c['ai_tutor'],
            'baskurs' => (bool)$c['baskurs'],
            'tags' => $tags[(int)$c['id']] ?? [],
            'created_at' => insynTime($c['created_at']),
            'updated_at' => insynTime($c['updated_at']),
        ];
    });
}

/** Samma villkor som requiredLessonSql() i include/progress.php (som drar in gamification). */
function requiredLessonSqlInsyn(string $a): string {
    return "$a.status = 'active' AND $a.lesson_type = 'lesson'";
}

/** GET /courses/{id}/stats */
function insynCourseStats(int $courseId): void {
    $db = DB_DATABASE;
    $course = insynOne("SELECT id, title, status, organization_domain FROM $db.courses WHERE id = ?", [$courseId]);
    if (!$course) insynError(404, "Kursen $courseId finns inte.");

    $required = (int)insynOne("SELECT COUNT(*) AS n FROM $db.lessons l WHERE l.course_id = ? AND " . requiredLessonSqlInsyn('l'), [$courseId])['n'];

    // En rad per deltagare, men bara för att räkna: inga id, namn eller e-post lämnar funktionen.
    $rows = insynAll(
        "SELECT LOWER(SUBSTRING_INDEX(u.email, '@', -1)) AS domain,
                ce.status AS enrollment_status, ce.started_at AS enrolled_at,
                (SELECT COUNT(DISTINCT p.lesson_id) FROM $db.progress p JOIN $db.lessons l ON l.id = p.lesson_id
                  WHERE p.user_id = u.id AND l.course_id = ? AND " . requiredLessonSqlInsyn('l') . " AND p.status = 'completed') AS done,
                (SELECT MIN(COALESCE(p.started_at, p.updated_at)) FROM $db.progress p JOIN $db.lessons l ON l.id = p.lesson_id
                  WHERE p.user_id = u.id AND l.course_id = ?) AS first_at,
                (SELECT MAX(p.updated_at) FROM $db.progress p JOIN $db.lessons l ON l.id = p.lesson_id
                  WHERE p.user_id = u.id AND l.course_id = ? AND " . requiredLessonSqlInsyn('l') . " AND p.status = 'completed') AS last_at,
                EXISTS (SELECT 1 FROM $db.certificates ct WHERE ct.user_id = u.id AND ct.course_id = ?) AS certificate,
                (SELECT COUNT(*) FROM $db.quiz_answers qa JOIN $db.lessons l ON l.id = qa.lesson_id
                  WHERE qa.user_id = u.id AND l.course_id = ?) AS answered,
                (SELECT COALESCE(SUM(qa.is_correct), 0) FROM $db.quiz_answers qa JOIN $db.lessons l ON l.id = qa.lesson_id
                  WHERE qa.user_id = u.id AND l.course_id = ?) AS correct
           FROM $db.users u
           LEFT JOIN $db.course_enrollments ce ON ce.user_id = u.id AND ce.course_id = ?
          WHERE ce.id IS NOT NULL
             OR EXISTS (SELECT 1 FROM $db.progress p JOIN $db.lessons l ON l.id = p.lesson_id
                         WHERE p.user_id = u.id AND l.course_id = ?)",
        array_fill(0, 8, $courseId)
    );

    $agg = fn() => ['started' => 0, 'completed' => 0, 'certificates' => 0, 'abandoned' => 0];
    $total = $agg();
    $groups = [];
    $pcts = [];
    $days = [];
    foreach ($rows as $r) {
        $completed = $required > 0 && (int)$r['done'] >= $required;
        $unitId = insynUnitIdForDomain($r['domain']);
        $g = $unitId === null ? '' : (string)$unitId;
        $groups[$g] ??= $agg();
        foreach (['total', 'group'] as $which) {
            $t = $which === 'total' ? $total : $groups[$g];
            $t['started']++;
            if ($completed) $t['completed']++;
            if ((int)$r['certificate'] === 1) $t['certificates']++;
            if ($r['enrollment_status'] === 'abandoned') $t['abandoned']++;
            if ($which === 'total') $total = $t; else $groups[$g] = $t;
        }
        if ((int)$r['answered'] > 0) $pcts[] = (int)$r['correct'] / (int)$r['answered'];
        if ($completed && $r['last_at']) {
            $starts = array_filter([$r['enrolled_at'], $r['first_at']]);
            if ($starts) $days[] = max(0, (strtotime($r['last_at']) - strtotime(min($starts))) / 86400);
        }
    }

    $mask = fn(array $a) => array_map('insynCount', $a);
    $byOrg = [];
    foreach ($groups as $g => $a) {
        $byOrg[] = ['organization' => $g === '' ? null : insynUnitRef($g)] + $mask($a);
    }
    usort($byOrg, fn($x, $y) => strcoll($x['organization']['name'] ?? 'ö', $y['organization']['name'] ?? 'ö'));

    insynSend(200, [
        'course' => [
            'id' => (int)$course['id'],
            'title' => $course['title'],
            'status' => $course['status'],
            'organization' => insynUnitRef(insynUnitIdForDomain($course['organization_domain'])),
            'required_lessons' => $required,
        ],
        'participants' => $mask($total),
        'quiz' => [
            'participants_with_answers' => insynCount(count($pcts)),
            'average_correct_pct' => count($pcts) >= INSYN_MIN_GROUP ? round(100 * array_sum($pcts) / count($pcts), 1) : null,
        ],
        'time' => [
            'completed_with_dates' => insynCount(count($days)),
            'average_days_to_complete' => count($days) >= INSYN_MIN_GROUP ? round(array_sum($days) / count($days), 1) : null,
            'median_days_to_complete' => count($days) >= INSYN_MIN_GROUP ? insynMedian($days) : null,
        ],
        'by_organization' => $byOrg,
        'notes' => [
            'Grupper med färre än ' . INSYN_MIN_GROUP . ' personer redovisas som "<' . INSYN_MIN_GROUP . '". Medelvärden över färre personer redovisas inte (null).',
            'Klar = alla aktiva lektioner klara (infosidor krävs inte), samma regel som i Stimma.',
            'Andel rätt bygger på det senast sparade svaret per fråga. Stimma mäter inte tidsåtgång; tiden är kalenderdagar från första till sista avklarade lektion.',
            'organization null = deltagare utan organisation (t.ex. publika konton).',
        ],
    ]);
}

function insynMedian(array $values): float {
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
}

/** GET /usage – användning per funktion, 30 och 90 dagar. Inga personuppgifter. */
function insynUsage(): void {
    $db = DB_DATABASE;
    $out = [];

    // Händelser i loggen: räknas per avsändardomän, som sedan förs till organisation.
    $logFeatures = [
        'course_import' => ["Läste in kurspaket %", "Importerade SCORM-paket %", "Kopierade SCORM-paket %", "Importerade PowerPoint %"],
        'course_copy' => ["Kopierade kurs | %"],
        'learning_paths' => ["Skapade lärväg%", "Uppdaterade lärväg%"],
        'audio' => ["Laddade upp ljudfil%"],
    ];

    foreach ([30, 90] as $days) {
        $w = "created_at >= NOW() - INTERVAL $days DAY";
        $win = [];

        foreach ($logFeatures as $feature => $patterns) {
            $like = implode(' OR ', array_fill(0, count($patterns), 'message LIKE ?'));
            $rows = insynAll(
                "SELECT LOWER(SUBSTRING_INDEX(email, '@', -1)) AS domain, COUNT(*) AS n
                   FROM $db.logs WHERE $w AND ($like) GROUP BY domain",
                $patterns
            );
            $win[$feature] = insynUsageRow($rows);
        }

        // AI: ai_usage_log har organisation eller domän på varje anrop.
        foreach (['ai_generation' => ['course_gen', 'lesson_gen', 'image', 'other'], 'ai_tutor' => ['chat']] as $feature => $kinds) {
            $rows = insynAll(
                "SELECT organization_id, LOWER(domain) AS domain, COUNT(*) AS n, COALESCE(SUM(total_tokens), 0) AS tokens
                   FROM $db.ai_usage_log
                  WHERE $w AND status = 'ok' AND feature IN (" . insynIn($kinds) . ")
                  GROUP BY organization_id, domain",
                $kinds
            );
            $orgs = [];
            $events = 0;
            $tokens = 0;
            foreach ($rows as $r) {
                $events += (int)$r['n'];
                $tokens += (int)$r['tokens'];
                $id = $r['organization_id'] !== null ? (string)$r['organization_id'] : insynUnitIdForDomain($r['domain']);
                if ($id !== null) $orgs[$id] = true;
            }
            $win[$feature] = ['events' => $events, 'tokens' => $tokens, 'organizations' => count($orgs)];
        }

        // Användarsynk via API eller synkverktyget.
        $rows = insynAll("SELECT LOWER(domain) AS domain, COUNT(*) AS n FROM $db.sync_log WHERE $w GROUP BY domain");
        $win['user_sync'] = insynUsageRow($rows);

        // Frågetyper: nya frågor i perioden, per typ.
        $types = [];
        foreach (insynAll(
            "SELECT q.question_type, COUNT(*) AS n, COUNT(DISTINCT LOWER(c.organization_domain)) AS doms,
                    GROUP_CONCAT(DISTINCT LOWER(c.organization_domain)) AS domains
               FROM $db.quiz_questions q
               JOIN $db.lessons l ON l.id = q.lesson_id
               JOIN $db.courses c ON c.id = l.course_id
              WHERE q.created_at >= NOW() - INTERVAL $days DAY
              GROUP BY q.question_type ORDER BY n DESC"
        ) as $r) {
            $orgs = [];
            foreach (explode(',', (string)$r['domains']) as $d) {
                $id = insynUnitIdForDomain($d);
                if ($id !== null) $orgs[$id] = true;
            }
            $types[$r['question_type']] = ['questions' => (int)$r['n'], 'organizations' => count($orgs)];
        }
        $win['question_types'] = $types;

        $out["last_{$days}_days"] = $win;
    }

    // Läget nu (inte periodbundet): vad som finns i drift.
    $now = [];
    $lp = insynAll("SELECT LOWER(organization_domain) AS domain, COUNT(*) AS n FROM $db.learning_paths WHERE status = 'active' GROUP BY domain");
    $now['active_learning_paths'] = insynUsageRow($lp, 'count');
    $au = insynAll(
        "SELECT LOWER(c.organization_domain) AS domain, COUNT(*) AS n FROM $db.lessons l JOIN $db.courses c ON c.id = l.course_id
          WHERE l.status = 'active' AND l.audio_url IS NOT NULL AND l.audio_url <> '' GROUP BY domain"
    );
    $now['lessons_with_audio'] = insynUsageRow($au, 'count');
    $tu = insynAll(
        "SELECT LOWER(c.organization_domain) AS domain, COUNT(DISTINCT c.id) AS n FROM $db.lessons l JOIN $db.courses c ON c.id = l.course_id
          WHERE l.status = 'active' AND l.ai_chat_enabled = 1 AND c.status = 'active' GROUP BY domain"
    );
    $now['active_courses_with_ai_tutor'] = insynUsageRow($tu, 'count');
    $sy = insynAll("SELECT LOWER(domain) AS domain, 1 AS n FROM $db.domain_settings WHERE sync_enabled = 1");
    $now['user_sync_enabled'] = insynUsageRow($sy, 'count');
    $qt = [];
    foreach (insynAll("SELECT question_type, COUNT(*) AS n FROM $db.quiz_questions GROUP BY question_type ORDER BY n DESC") as $r) {
        $qt[$r['question_type']] = (int)$r['n'];
    }
    $now['question_types_total'] = $qt;
    $out['current'] = $now;

    $out['notes'] = [
        'events = antal händelser i perioden, organizations = antal organisationer med minst en händelse.',
        'Kursimport = kurspaket, SCORM och PowerPoint. ai_generation = kurs-, lektions- och bildgenerering. ai_tutor = AI-chatten i lektioner.',
        'Händelser från konton utan organisation (t.ex. gmail.com) räknas i events men inte i organizations.',
    ];
    insynSend(200, $out);
}

/** Rader (domain, n) → händelser och antal organisationer. */
function insynUsageRow(array $rows, string $label = 'events'): array {
    $sum = 0;
    $orgs = [];
    foreach ($rows as $r) {
        $sum += (int)$r['n'];
        $id = insynUnitIdForDomain($r['domain']);
        if ($id !== null) $orgs[$id] = true;
    }
    return [$label => $sum, 'organizations' => count($orgs)];
}

/** GET /learning-paths?organization_id= */
function insynLearningPaths(): void {
    $db = DB_DATABASE;
    $unit = insynRequestedUnit();
    $where = '1=1';
    $params = [];
    if ($unit) {
        $where = "LOWER(lp.organization_domain) IN (" . insynIn($unit['domains']) . ")";
        $params = $unit['domains'];
    }
    $paths = insynAll(
        "SELECT lp.id, lp.title, lp.status, lp.organization_domain, lp.is_global, lp.created_at, lp.updated_at
           FROM $db.learning_paths lp WHERE $where ORDER BY lp.organization_domain, lp.sort_order, lp.id",
        $params
    );
    $courses = [];
    foreach (insynAll(
        "SELECT lpc.learning_path_id, c.id, c.title, c.status
           FROM $db.learning_path_courses lpc JOIN $db.courses c ON c.id = lpc.course_id
          ORDER BY lpc.learning_path_id, lpc.sort_order, c.id"
    ) as $r) {
        $courses[(int)$r['learning_path_id']][] = ['id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status']];
    }
    $shared = [];
    foreach (insynAll("SELECT learning_path_id, domain FROM $db.learning_path_shared_domains ORDER BY domain") as $r) {
        $shared[(int)$r['learning_path_id']][] = $r['domain'];
    }
    insynSendPage($paths, insynPaging(), fn($p) => [
        'id' => (int)$p['id'],
        'title' => $p['title'],
        'status' => $p['status'],
        'organization' => insynUnitRef(insynUnitIdForDomain($p['organization_domain'])),
        'organization_domain' => $p['organization_domain'],
        'global' => (bool)$p['is_global'],
        'shared_with_domains' => $shared[(int)$p['id']] ?? [],
        'courses' => $courses[(int)$p['id']] ?? [],
        'created_at' => insynTime($p['created_at']),
        'updated_at' => insynTime($p['updated_at']),
    ]);
}
