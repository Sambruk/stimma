<?php
/**
 * Stimma — Lärvägar (learning paths)
 *
 * En lärväg är en namngiven, ordnad gruppering av kurser. Ordningen är en
 * REKOMMENDATION — inget låses, deltagaren får ta kurserna i valfri ordning.
 *
 * Datamodell finns i migrations/044_learning_paths.sql:
 *   - learning_paths                (lärvägen med eget org-scope)
 *   - learning_path_courses         (kurserna i lärvägen, sort_order = steg)
 *   - learning_path_shared_domains  (spegling av course_shared_domains)
 *
 * Tilldelning är IMPLICIT: det finns ingen enrollment-tabell för lärvägar.
 * Status per kurs härleds från befintliga signaler:
 *   - genomförd  = rad i certificates (enda signalen som respekterar
 *                  course_completion_criteria)
 *   - registrerad = 4-vägs-unionen course_enrollments ∪
 *                  sequential_lesson_schedule ∪ public_course_access ∪
 *                  (progress JOIN lessons) — samma definition som
 *                  admin/course_stats.php använder för "Inskrivna"
 *   - påbörjad   = progress-procent > 0
 *
 * Alla statusfunktioner är batchade (M användare × N kurser i ett konstant
 * antal queries) — vyerna får aldrig köra en query per användare eller kurs.
 */

require_once __DIR__ . '/functions.php';

// =============================================================================
// CRUD
// =============================================================================

/**
 * Hämta en lärväg.
 *
 * @param int $pathId
 * @return array|null
 */
function getLearningPath($pathId) {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".learning_paths WHERE id = ?",
        [(int)$pathId]
    );
}

/**
 * Lista lärvägar inom ett domän-scope, med antal kurser per lärväg.
 * En query — inget N+1.
 *
 * @param string[] $scopeDomains Domäner adminen får se (getEffectiveOrgScopeDomains)
 * @param bool $includeGlobal Ta även med globala lärvägar från andra org (superadmin)
 * @param int|null $onlyCreatedBy Begränsa till lärvägar skapade av denna user-id (redaktör)
 * @return array
 */
function getLearningPathsForScope(array $scopeDomains, $includeGlobal = false, $onlyCreatedBy = null) {
    $clause = buildDomainInClause($scopeDomains, 'lp.organization_domain');
    $where = $clause['fragment'];
    $params = $clause['params'];

    if ($includeGlobal) {
        $where = "($where OR lp.is_global = 1)";
    }
    if ($onlyCreatedBy !== null) {
        $where .= " AND lp.created_by = ?";
        $params[] = (int)$onlyCreatedBy;
    }

    return query(
        "SELECT lp.*,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".learning_path_courses lpc
                  WHERE lpc.learning_path_id = lp.id) AS course_count,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".learning_path_shared_domains lsd
                  WHERE lsd.learning_path_id = lp.id) AS shared_domain_count
         FROM " . DB_DATABASE . ".learning_paths lp
         WHERE $where
         ORDER BY lp.sort_order, lp.title",
        $params
    ) ?: [];
}

/**
 * Skapa en lärväg.
 *
 * @param array $data title, description, image_url, status, organization_domain,
 *                    is_global, created_by
 * @return int Nytt id
 */
function createLearningPath(array $data) {
    execute(
        "INSERT INTO " . DB_DATABASE . ".learning_paths
            (title, description, image_url, status, sort_order, organization_domain, is_global, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            trim($data['title']),
            $data['description'] ?? null,
            $data['image_url'] ?? null,
            ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            (int)($data['sort_order'] ?? 0),
            strtolower(trim($data['organization_domain'])),
            !empty($data['is_global']) ? 1 : 0,
            isset($data['created_by']) ? (int)$data['created_by'] : null,
        ]
    );
    return (int)getDb()->lastInsertId();
}

/**
 * Uppdatera en lärväg. Anroparen ansvarar för behörighetskontroll
 * (userCanModifyLearningPath) INNAN denna funktion anropas.
 *
 * @param int $pathId
 * @param array $data
 * @return bool
 */
function updateLearningPath($pathId, array $data) {
    execute(
        "UPDATE " . DB_DATABASE . ".learning_paths
            SET title = ?, description = ?, image_url = ?, status = ?, is_global = ?
          WHERE id = ?",
        [
            trim($data['title']),
            $data['description'] ?? null,
            $data['image_url'] ?? null,
            ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            !empty($data['is_global']) ? 1 : 0,
            (int)$pathId,
        ]
    );
    return true;
}

/**
 * Radera en lärväg. FK CASCADE tar kopplade kurser och delade domäner.
 * Kurserna själva och deltagarnas resultat påverkas inte.
 *
 * @param int $pathId
 * @return bool
 */
function deleteLearningPath($pathId) {
    execute("DELETE FROM " . DB_DATABASE . ".learning_paths WHERE id = ?", [(int)$pathId]);
    return true;
}

// =============================================================================
// Kurskoppling
// =============================================================================

/**
 * Kurserna i en lärväg, i rekommenderad ordning, med lektionsantal.
 *
 * @param int $pathId
 * @return array
 */
function getLearningPathCourses($pathId) {
    return query(
        "SELECT c.id, c.title, c.description, c.image_url, c.status,
                c.organization_domain, c.is_global, c.sequential_mode,
                lpc.sort_order,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons l
                  WHERE l.course_id = c.id AND l.status = 'active') AS lesson_count,
                EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd
                         WHERE csd.course_id = c.id) AS has_shared_domains,
                EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot
                         WHERE cot.course_id = c.id) AS has_org_tags
         FROM " . DB_DATABASE . ".learning_path_courses lpc
         JOIN " . DB_DATABASE . ".courses c ON c.id = lpc.course_id
         WHERE lpc.learning_path_id = ?
         ORDER BY lpc.sort_order, c.title",
        [(int)$pathId]
    ) ?: [];
}

/**
 * Ersätt kurslistan för en lärväg. sort_order sätts från arrayens ordning.
 * Körs i transaktion. Anroparen ansvarar för att kurs-ID:na ligger inom
 * adminens scope.
 *
 * @param int $pathId
 * @param int[] $orderedCourseIds
 * @return void
 */
function setLearningPathCourses($pathId, array $orderedCourseIds) {
    $pathId = (int)$pathId;
    $clean = [];
    foreach ($orderedCourseIds as $cid) {
        $cid = (int)$cid;
        if ($cid > 0 && !in_array($cid, $clean, true)) {
            $clean[] = $cid;
        }
    }

    execute("START TRANSACTION");
    try {
        execute(
            "DELETE FROM " . DB_DATABASE . ".learning_path_courses WHERE learning_path_id = ?",
            [$pathId]
        );
        foreach ($clean as $i => $cid) {
            execute(
                "INSERT INTO " . DB_DATABASE . ".learning_path_courses
                    (learning_path_id, course_id, sort_order) VALUES (?, ?, ?)",
                [$pathId, $cid, $i]
            );
        }
        execute("COMMIT");
    } catch (Exception $e) {
        execute("ROLLBACK");
        error_log("setLearningPathCourses misslyckades för lärväg $pathId: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Uppdatera sorteringsordningen för flera lärvägar (drag-and-drop i listan).
 * Hoppar över lärvägar anroparen inte får ändra.
 *
 * @param array $pathIdToOrder [pathId => sortOrder]
 * @return int Antal uppdaterade rader
 */
function updateLearningPathSortOrder(array $pathIdToOrder) {
    $updated = 0;
    foreach ($pathIdToOrder as $pathId => $order) {
        $path = getLearningPath((int)$pathId);
        if (!$path || !userCanModifyLearningPath($path)) {
            continue;
        }
        execute(
            "UPDATE " . DB_DATABASE . ".learning_paths SET sort_order = ? WHERE id = ?",
            [(int)$order, (int)$pathId]
        );
        $updated++;
    }
    return $updated;
}

/**
 * Lärvägar som en viss kurs ingår i. Används för varningen i
 * admin/delete_course.php.
 *
 * @param int $courseId
 * @return array Rader med id + title
 */
function getLearningPathsContainingCourse($courseId) {
    return query(
        "SELECT lp.id, lp.title
         FROM " . DB_DATABASE . ".learning_path_courses lpc
         JOIN " . DB_DATABASE . ".learning_paths lp ON lp.id = lpc.learning_path_id
         WHERE lpc.course_id = ?
         ORDER BY lp.title",
        [(int)$courseId]
    ) ?: [];
}

// =============================================================================
// Delning och synlighet
// =============================================================================

/**
 * Domäner lärvägen är begränsad till. Tom array = hela organisationen.
 *
 * @param int $pathId
 * @return string[]
 */
function getLearningPathSharedDomains($pathId) {
    $rows = query(
        "SELECT domain FROM " . DB_DATABASE . ".learning_path_shared_domains
          WHERE learning_path_id = ? ORDER BY domain",
        [(int)$pathId]
    );
    return array_column($rows ?: [], 'domain');
}

/**
 * Spara delade domäner för en lärväg. Tom array rensar (= hela organisationen).
 *
 * @param int $pathId
 * @param string[] $domains
 * @return void
 */
function setLearningPathSharedDomains($pathId, array $domains) {
    execute(
        "DELETE FROM " . DB_DATABASE . ".learning_path_shared_domains WHERE learning_path_id = ?",
        [(int)$pathId]
    );
    $clean = array_filter(array_unique(array_map(function ($d) {
        return strtolower(trim($d));
    }, $domains)));
    foreach ($clean as $d) {
        execute(
            "INSERT IGNORE INTO " . DB_DATABASE . ".learning_path_shared_domains
                (learning_path_id, domain) VALUES (?, ?)",
            [(int)$pathId, $d]
        );
    }
}

/**
 * Synlighetsfragment för lärvägar, motsvarande buildCourseVisibilityClause()
 * fast för learning_paths. public_only-användare ser inga lärvägar alls (de
 * har bara access till enskilda publika kurser).
 *
 * @param int $userId
 * @param string $alias
 * @return array{fragment:string, params:array}
 */
function buildLearningPathVisibilityClause($userId, $alias = 'lp') {
    $userId = (int)$userId;
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 'lp';
    }

    $user = queryOne(
        "SELECT email, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
        [$userId]
    );
    if (!$user || ($user['access_mode'] ?? 'domain') === 'public_only') {
        return ['fragment' => "$a.id IN (NULL)", 'params' => []];
    }

    $userDomain = getUserDomain($user['email']);
    $orgScopeDomains = getOrgScopeDomains($user['email']);
    $domainClause = buildDomainInClause($orgScopeDomains, "$a.organization_domain");

    // Lärväg utan rader i learning_path_shared_domains delas med hela orgen.
    $sharedFilter = "AND (
        NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".learning_path_shared_domains lsd
                     WHERE lsd.learning_path_id = $a.id)
        OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".learning_path_shared_domains lsd
                     WHERE lsd.learning_path_id = $a.id AND lsd.domain = ?)
    )";

    return [
        'fragment' => "(({$domainClause['fragment']} $sharedFilter) OR $a.is_global = 1)",
        'params' => array_merge($domainClause['params'], [$userDomain]),
    ];
}

// =============================================================================
// Behörighet
// =============================================================================

/**
 * Kanonisk behörighetscheck för att ändra en lärväg. Samma mönster som
 * userCanModifyCourse() i functions.php.
 *
 *   super_admin → alla lärvägar
 *   is_admin    → lärvägar vars organization_domain ligger i egen org-scope
 *   is_editor   → endast lärvägar hen själv skapat (created_by)
 *   annars      → nej
 *
 * @param array $path Rad från learning_paths (kräver organization_domain + created_by)
 * @return bool
 */
function userCanModifyLearningPath(array $path) {
    $userEmail = $_SESSION['user_email'] ?? null;
    if (!$userEmail) {
        return false;
    }

    $u = queryOne(
        "SELECT id, is_admin, is_editor, role FROM " . DB_DATABASE . ".users WHERE email = ?",
        [$userEmail]
    );
    if (!$u) {
        return false;
    }

    if (($u['role'] ?? '') === 'super_admin') {
        return true;
    }

    if (!empty($u['is_admin'])) {
        $org = $path['organization_domain'] ?? '';
        if ($org !== '' && in_array($org, getOrgScopeDomains($userEmail), true)) {
            return true;
        }
    }

    if (!empty($u['is_editor']) && !empty($path['created_by'])) {
        if ((int)$path['created_by'] === (int)$u['id']) {
            return true;
        }
    }

    return false;
}

// =============================================================================
// Batchade statussignaler
// =============================================================================

/**
 * Diplom (= genomförda kurser) för M användare × N kurser. En query.
 *
 * @param int[] $userIds
 * @param int[] $courseIds
 * @return array [userId][courseId] => ['certificate_number'=>string,'completion_date'=>string]
 */
function getCourseCompletionsForUsers(array $userIds, array $courseIds) {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
    if (empty($userIds) || empty($courseIds)) {
        return [];
    }

    $up = implode(',', array_fill(0, count($userIds), '?'));
    $cp = implode(',', array_fill(0, count($courseIds), '?'));
    $rows = query(
        "SELECT user_id, course_id, certificate_number, completion_date, issued_at
         FROM " . DB_DATABASE . ".certificates
         WHERE user_id IN ($up) AND course_id IN ($cp)",
        array_merge($userIds, $courseIds)
    );

    $out = [];
    foreach ($rows ?: [] as $r) {
        $out[(int)$r['user_id']][(int)$r['course_id']] = [
            'certificate_number' => $r['certificate_number'],
            'completion_date' => $r['completion_date'] ?: substr((string)$r['issued_at'], 0, 10),
        ];
    }
    return $out;
}

/**
 * Registreringar för M användare × N kurser. En query.
 *
 * Definitionen är exakt densamma 4-vägs-union som admin/course_stats.php
 * använder för "Inskrivna": en användare räknas som registrerad om hen har en
 * rad i course_enrollments, sequential_lesson_schedule eller
 * public_course_access, ELLER har progress på någon av kursens lektioner.
 * Att bara tillhöra organisationen räcker inte.
 *
 * @param int[] $userIds
 * @param int[] $courseIds
 * @return array [userId][courseId] => true
 */
function getCourseRegistrationsForUsers(array $userIds, array $courseIds) {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
    if (empty($userIds) || empty($courseIds)) {
        return [];
    }

    $up = implode(',', array_fill(0, count($userIds), '?'));
    $cp = implode(',', array_fill(0, count($courseIds), '?'));
    $db = DB_DATABASE;

    $rows = query(
        "SELECT DISTINCT user_id, course_id FROM (
            SELECT user_id, course_id FROM $db.course_enrollments
             WHERE user_id IN ($up) AND course_id IN ($cp)
            UNION
            SELECT user_id, course_id FROM $db.sequential_lesson_schedule
             WHERE user_id IN ($up) AND course_id IN ($cp)
            UNION
            SELECT user_id, course_id FROM $db.public_course_access
             WHERE user_id IN ($up) AND course_id IN ($cp)
            UNION
            SELECT p.user_id, l.course_id
              FROM $db.progress p
              JOIN $db.lessons l ON l.id = p.lesson_id
             WHERE p.user_id IN ($up) AND l.course_id IN ($cp)
         ) AS src",
        array_merge(
            $userIds, $courseIds,
            $userIds, $courseIds,
            $userIds, $courseIds,
            $userIds, $courseIds
        )
    );

    $out = [];
    foreach ($rows ?: [] as $r) {
        $out[(int)$r['user_id']][(int)$r['course_id']] = true;
    }
    return $out;
}

/**
 * Sätt samman status för en kurs utifrån de tre signalerna. Ren funktion.
 *
 * Prioritet: diplom → progress > 0 → registrerad → ej påbörjad.
 * Vid diplom tvingas procenten till 100 (skyddar mot att en lektion lagts till
 * efter att diplomet utfärdades).
 *
 * @param array $prog ['total'=>int,'done'=>int,'percent'=>int]
 * @param bool $hasCert
 * @param bool $isRegistered
 * @return array ['status'=>string,'percent'=>int]
 */
function computeCourseStatus(array $prog, $hasCert, $isRegistered) {
    $percent = (int)($prog['percent'] ?? 0);

    if ($hasCert) {
        return ['status' => 'completed', 'percent' => 100];
    }
    if ($percent > 0) {
        return ['status' => 'in_progress', 'percent' => $percent];
    }
    if ($isRegistered) {
        return ['status' => 'registered', 'percent' => 0];
    }
    return ['status' => 'not_started', 'percent' => 0];
}

/**
 * Etikett + ikon för en status. Håller studentvy och adminvy synkade.
 *
 * @param string $status
 * @return array ['label'=>string,'icon'=>string,'class'=>string]
 */
function learningPathStatusMeta($status) {
    switch ($status) {
        case 'completed':
            return ['label' => 'genomförd', 'icon' => 'bi-check-circle-fill', 'class' => 'text-success'];
        case 'in_progress':
            return ['label' => 'påbörjad', 'icon' => 'bi-play-circle-fill', 'class' => 'text-info'];
        case 'registered':
            return ['label' => 'registrerad', 'icon' => 'bi-person-check-fill', 'class' => 'text-warning'];
        default:
            return ['label' => 'ej påbörjad', 'icon' => 'bi-circle', 'class' => 'text-muted'];
    }
}

// =============================================================================
// Sammansättning — studentvy
// =============================================================================

/**
 * Studentvyns enda ingång. Returnerar användarens synliga lärvägar med status
 * per kurs och samlad procent.
 *
 * Sex queries totalt oavsett antal lärvägar och kurser:
 *   1. synliga lärvägar
 *   2. kurserna i dem (med kursernas synlighetsfilter + status='active' inbakat)
 *   3-4. progress (getCourseProgressForUsers)
 *   5. diplom
 *   6. registreringar
 *
 * Kurser användaren inte har åtkomst till, eller som är inaktiva, filtreras
 * bort redan i SQL. Stegen numreras 1..N över de SYNLIGA kurserna, och
 * procenten räknas bara på dem. Lärvägar utan synliga kurser utelämnas helt.
 *
 * @param int $userId
 * @return array Lista av lärvägar med nyckeln 'courses'
 */
function getLearningPathOverviewForUser($userId) {
    $userId = (int)$userId;

    $pathVis = buildLearningPathVisibilityClause($userId, 'lp');
    $paths = query(
        "SELECT lp.id, lp.title, lp.description, lp.image_url
         FROM " . DB_DATABASE . ".learning_paths lp
         WHERE lp.status = 'active' AND {$pathVis['fragment']}
         ORDER BY lp.sort_order, lp.title",
        $pathVis['params']
    ) ?: [];

    if (empty($paths)) {
        return [];
    }

    $pathIds = array_map(function ($p) { return (int)$p['id']; }, $paths);
    $pathPlaceholders = implode(',', array_fill(0, count($pathIds), '?'));

    // Kurserna i alla lärvägar på en gång, filtrerade på användarens åtkomst.
    $courseVis = buildCourseVisibilityClause($userId, 'c');
    $courseRows = query(
        "SELECT lpc.learning_path_id, lpc.sort_order,
                c.id, c.title, c.image_url, c.sequential_mode,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons l
                  WHERE l.course_id = c.id AND l.status = 'active') AS lesson_count
         FROM " . DB_DATABASE . ".learning_path_courses lpc
         JOIN " . DB_DATABASE . ".courses c ON c.id = lpc.course_id
         WHERE lpc.learning_path_id IN ($pathPlaceholders)
           AND c.status = 'active'
           AND {$courseVis['fragment']}
         ORDER BY lpc.learning_path_id, lpc.sort_order, c.title",
        array_merge($pathIds, $courseVis['params'])
    ) ?: [];

    if (empty($courseRows)) {
        return [];
    }

    $courseIds = array_values(array_unique(array_map(function ($r) {
        return (int)$r['id'];
    }, $courseRows)));

    $progress = getCourseProgressForUsers([$userId], $courseIds);
    $certs = getCourseCompletionsForUsers([$userId], $courseIds);
    $regs = getCourseRegistrationsForUsers([$userId], $courseIds);

    $byPath = [];
    foreach ($courseRows as $row) {
        $byPath[(int)$row['learning_path_id']][] = $row;
    }

    $out = [];
    foreach ($paths as $path) {
        $pid = (int)$path['id'];
        if (empty($byPath[$pid])) {
            continue; // inga synliga kurser → dölj hela lärvägen
        }

        $courses = [];
        $percentSum = 0;
        $completedCount = 0;
        $step = 0;

        foreach ($byPath[$pid] as $row) {
            $cid = (int)$row['id'];
            $cert = $certs[$userId][$cid] ?? null;
            $state = computeCourseStatus(
                $progress[$userId][$cid] ?? [],
                $cert !== null,
                !empty($regs[$userId][$cid])
            );
            $step++;
            $percentSum += $state['percent'];
            if ($state['status'] === 'completed') {
                $completedCount++;
            }

            $courses[] = [
                'step' => $step,
                'course_id' => $cid,
                'title' => $row['title'],
                'image_url' => $row['image_url'],
                'lesson_count' => (int)$row['lesson_count'],
                'sequential_mode' => (int)$row['sequential_mode'],
                'status' => $state['status'],
                'percent' => $state['percent'],
                'certificate_number' => $cert['certificate_number'] ?? null,
                'completion_date' => $cert['completion_date'] ?? null,
            ];
        }

        $total = count($courses);
        $out[] = [
            'id' => $pid,
            'title' => $path['title'],
            'description' => $path['description'],
            'image_url' => $path['image_url'],
            'courses' => $courses,
            'total_count' => $total,
            'completed_count' => $completedCount,
            'path_percent' => $total > 0 ? (int)round($percentSum / $total) : 0,
        ];
    }

    return $out;
}

/**
 * Har användaren minst en synlig lärväg? Används för att villkora
 * sidebar-länken. Cachas per request.
 *
 * @param int $userId
 * @return bool
 */
function hasVisibleLearningPaths($userId) {
    static $cache = [];
    $userId = (int)$userId;
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $vis = buildLearningPathVisibilityClause($userId, 'lp');
    $row = queryOne(
        "SELECT 1 AS found
         FROM " . DB_DATABASE . ".learning_paths lp
         WHERE lp.status = 'active' AND {$vis['fragment']}
           AND EXISTS (SELECT 1 FROM " . DB_DATABASE . ".learning_path_courses lpc
                        WHERE lpc.learning_path_id = lp.id)
         LIMIT 1",
        $vis['params']
    );

    $cache[$userId] = !empty($row);
    return $cache[$userId];
}

// =============================================================================
// Sammansättning — adminstatistik
// =============================================================================

/**
 * Användare inom ett domän-scope, för statistikvyns rader.
 *
 * @param string[] $domains
 * @param int $limit 0 = ingen gräns
 * @param int $offset
 * @return array Rader med id, name, email
 */
function getUserIdsForDomains(array $domains, $limit = 0, $offset = 0) {
    if (empty($domains)) {
        return [];
    }
    $clause = buildEmailDomainInClause($domains, 'u.email');
    $sql = "SELECT u.id, u.name, u.email
            FROM " . DB_DATABASE . ".users u
            WHERE {$clause['fragment']}
            ORDER BY u.email";
    if ((int)$limit > 0) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    }
    return query($sql, $clause['params']) ?: [];
}

/**
 * Antal användare inom ett domän-scope (för paginering).
 *
 * @param string[] $domains
 * @return int
 */
function countUsersForDomains(array $domains) {
    if (empty($domains)) {
        return 0;
    }
    $clause = buildEmailDomainInClause($domains, 'u.email');
    $row = queryOne(
        "SELECT COUNT(*) AS n FROM " . DB_DATABASE . ".users u WHERE {$clause['fragment']}",
        $clause['params']
    );
    return (int)($row['n'] ?? 0);
}

/**
 * Statusmatris för adminvyn: M användare × lärvägens kurser.
 *
 * Till skillnad från studentvyn filtreras INGA kurser bort — adminen ska se
 * hela lärvägen som den är definierad. Fyra queries oavsett antal användare.
 *
 * @param array $courses Rader från getLearningPathCourses()
 * @param int[] $userIds
 * @return array [userId] => ['courses'=>[courseId=>['status','percent','completion_date',
 *               'certificate_number']], 'done'=>int, 'total'=>int, 'percent'=>int,
 *               'started'=>bool]
 */
function getLearningPathStatsForUsers(array $courses, array $userIds) {
    $courseIds = array_map(function ($c) { return (int)$c['id']; }, $courses);
    $userIds = array_values(array_unique(array_map('intval', $userIds)));

    if (empty($courseIds) || empty($userIds)) {
        return [];
    }

    $progress = getCourseProgressForUsers($userIds, $courseIds);
    $certs = getCourseCompletionsForUsers($userIds, $courseIds);
    $regs = getCourseRegistrationsForUsers($userIds, $courseIds);

    $out = [];
    $total = count($courseIds);

    foreach ($userIds as $uid) {
        $cells = [];
        $done = 0;
        $percentSum = 0;
        $started = false;

        foreach ($courseIds as $cid) {
            $cert = $certs[$uid][$cid] ?? null;
            $isReg = !empty($regs[$uid][$cid]);
            $state = computeCourseStatus($progress[$uid][$cid] ?? [], $cert !== null, $isReg);

            if ($state['status'] === 'completed') {
                $done++;
            }
            if ($state['status'] !== 'not_started') {
                $started = true;
            }
            $percentSum += $state['percent'];

            $cells[$cid] = [
                'status' => $state['status'],
                'percent' => $state['percent'],
                'certificate_number' => $cert['certificate_number'] ?? null,
                'completion_date' => $cert['completion_date'] ?? null,
            ];
        }

        $out[$uid] = [
            'courses' => $cells,
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int)round($percentSum / $total) : 0,
            'started' => $started,
        ];
    }

    return $out;
}
