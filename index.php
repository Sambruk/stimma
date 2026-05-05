<?php
/**
 * Stimma - Learn in small steps
 * Copyright (C) 2025 Christian Alfredsson
 * 
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 * 
 * The name "Stimma" is a trademark and subject to restrictions.
 */

/**
 * Main index page
 * 
 * This file handles:
 * - User authentication and login
 * - New user registration
 * - Course and lesson progress tracking
 * - Display of next available lesson
 * - Progress statistics
 */

// Include required configuration and function files
require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

// Get system configuration from environment variables with fallbacks
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$systemDescription = trim(getenv('SYSTEM_DESCRIPTION'), '"\'') ?: '';

// Initialize error and success message variables
$error = '';
$success = '';

/**
 * SECURITY FIX: Rate limiting for login attempts
 * Prevents brute-force attacks and email enumeration
 */
function checkLoginRateLimit($email) {
    $key = 'login_attempts_' . md5(strtolower($email) . $_SERVER['REMOTE_ADDR']);
    $maxAttempts = 5;
    $lockoutMinutes = 15;

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }

    $attempts = &$_SESSION[$key];

    // Reset if lockout time has passed
    if (time() - $attempts['first_attempt'] > ($lockoutMinutes * 60)) {
        $attempts = ['count' => 0, 'first_attempt' => time()];
    }

    // Check if user is locked out
    if ($attempts['count'] >= $maxAttempts) {
        $remainingTime = ceil(($lockoutMinutes * 60 - (time() - $attempts['first_attempt'])) / 60);
        return "För många inloggningsförsök. Försök igen om {$remainingTime} minuter.";
    }

    $attempts['count']++;
    return null;
}

// Handle form submission for login/registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for security
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Ogiltig förfrågan. Vänligen försök igen.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

        // Spara remember_me valet i sessionen för användning i verify.php
        $_SESSION['pending_remember_me'] = $rememberMe;

        // SECURITY FIX: Check rate limit before processing
        $rateLimitError = checkLoginRateLimit($email);
        if ($rateLimitError) {
            $error = $rateLimitError;
            // Log the rate limit hit
            logActivity($email, "Rate limit exceeded for login attempt", ['ip' => $_SERVER['REMOTE_ADDR']]);
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Check if user already exists
            $user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$email]);
            
            if ($user) {
                // Existing user - send login link
                if (sendLoginToken($email)) {
                    $success = '<i class="bi bi-envelope-paper-fill me-2"></i>
                               <h4 class="mt-3">Inloggningslänk på väg!</h4>
                               <p class="mb-0">Vi har skickat en inloggningslänk till din e-postadress. 
                               Länken är giltig i ' . ((int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15) . ' minuter.
                               Om du inte ser e-postmeddelandet, kolla gärna i din skräppost.</p>';
                } else {
                    $error = 'Något gick fel vid utskick av inloggningslänk. Försök igen.';
                }
            } else {
                // New user - check domain
                $domain = substr(strrchr($email, "@"), 1);
                
                if (isDomainAllowed($domain)) {
                    // Create new user with only existing table columns
                    execute("INSERT INTO " . DB_DATABASE . ".users (email, created_at) 
                             VALUES (?, NOW())", 
                             [$email]);
                    
                    // Send login link
                    if (sendLoginToken($email)) {
                        $success = '<i class="bi bi-envelope-paper-fill me-2"></i>
                                   <h4 class="mt-3">Konto skapat och inloggningslänk på väg!</h4>
                                   <p class="mb-0">Vi har skapat ett konto för dig och skickat en inloggningslänk till din e-postadress. 
                                   Länken är giltig i ' . ((int)getenv('AUTH_TOKEN_EXPIRY_MINUTES') ?: 15) . ' minuter.
                                   Om du inte ser e-postmeddelandet, kolla gärna i din skräppost.</p>';
                    } else {
                        $error = 'Något gick fel vid utskick av inloggningslänk. Försök igen.';
                    }
                } else {
                    $error = 'Endast e-postadresser från godkända domäner är tillåtna för nya användare.';
                }
            }
        } else {
            $error = 'Ange en giltig e-postadress.';
        }
    }
}

// Check if user is logged in
$isLoggedIn = isLoggedIn();

// If not logged in, try auto-login via remember token
if (!$isLoggedIn) {
    $rememberedUser = validateRememberToken();
    if ($rememberedUser) {
        createLoginSession($rememberedUser);
        $isLoggedIn = true;
    }
}

// If not logged in - show email form
if (!$isLoggedIn): 
    // Set page title
    $page_title = $systemName . ' - en nanolearningsplattform';
    // Include header
    require_once 'include/header.php';
?>
    <!-- Login/Registration container -->
    <div class="container-sm min-vh-100 d-flex align-items-center px-3">
        <div class="row justify-content-center w-100">
            <div class="col-12 col-md-5 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center p-4">
                        <!-- Logo and system description -->
                        <h1 class="display-4 mb-3"><img src="images/stimma-logo-transparent.png" height="80px" alt="<?= $systemName ?>"></h1>
                        <?php if ($systemDescription): ?>
                            <p class="lead text-muted mb-4"><?= htmlspecialchars($systemDescription) ?></p>
                        <?php endif; ?>
                        
                        <!-- Success message display -->
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <?= $success ?>
                            </div>
                        <?php else: ?>
                            <!-- Error message display -->
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <p class="mb-0"><?= $error ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Email input form -->
                            <form action="index.php" method="post" class="form">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <div class="form-floating mb-3">
                                    <input type="email" class="form-control" id="email" name="email" placeholder="namn@alingsas.se" required>
                                    <label for="email">E-postadress</label>
                                </div>
                                <div class="form-check mb-3 text-start">
                                    <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1" checked>
                                    <label class="form-check-label" for="remember_me">
                                        Kom ihåg mig i 7 dagar
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Skicka inloggningslänk</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>                   
				<?php
					if(getenv("SYSTEM_SHOW_CONTACT_INFO")):
				?>
                <!-- Info button -->
                <div class="text-center mt-3">
                    <a href="stimmainfo.html" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-info-circle me-1"></i>Information om Stimma och Kontakt
                    </a>
                </div>
				<?php endif; ?>
            </div>
        </div>
    </div>
<?php
    // Include footer
    require_once 'include/footer.php';
else:
    // Get user ID from session
    $userId = $_SESSION['user_id'];

    // Get user's email, access_mode och domain
    $user = queryOne("SELECT email, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);
    $userDomain = substr(strrchr($user['email'], "@"), 1);
    $isPublicOnly = ($user['access_mode'] ?? 'domain') === 'public_only';

    // Publika kurs-IDs som användaren har access till (både för public_only och
    // vanliga domain-användare som registrerat sig för externa publika kurser)
    $publicCourseIds = getPublicCourseIdsForUser($userId);

    if ($isPublicOnly) {
        // public_only-användare ser ENDAST kurser från public_course_access.
        // Ingen domänscope, inga org-taggar, ingen kurskatalog.
        $courseScopeFragment = empty($publicCourseIds)
            ? "c.id IN (NULL)"
            : "c.id IN (" . implode(',', array_fill(0, count($publicCourseIds), '?')) . ")";
        $courseScopeParams = $publicCourseIds;
        $orgTagFilter = "";
        $orgTagExtraParams = [];
        $orgScopeDomains = [];
        $tagDomainClause = ['fragment' => "t.organization_domain IN (NULL)", 'params' => []];
    } else {
        // Domain-användare: domänscope UNION ev. publika kurser.
        $orgScopeDomains = getOrgScopeDomains($user['email']);
        $courseDomainClause = buildDomainInClause($orgScopeDomains, 'c.organization_domain');
        $tagDomainClause = buildDomainInClause($orgScopeDomains, 't.organization_domain');

        // Hämta användarens organisationstaggar
        $userOrgTags = getUserOrgTags($userId);
        $userOrgTagValues = array_column($userOrgTags, 'tag');

        if (!empty($userOrgTagValues)) {
            $placeholders = implode(',', array_fill(0, count($userOrgTagValues), '?'));
            $orgTagFilter = "AND (
                NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot WHERE cot.course_id = c.id)
                OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot WHERE cot.course_id = c.id AND cot.tag IN ($placeholders))
            )";
            $orgTagExtraParams = $userOrgTagValues;
        } else {
            $orgTagFilter = "AND NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot WHERE cot.course_id = c.id)";
            $orgTagExtraParams = [];
        }

        // Shared-domain-filter: kurs syns om den antingen delas med hela orgen
        // (inga rader i course_shared_domains) ELLER användarens domän finns i
        // listan av valda domäner.
        $sharedDomainFilter = "AND (
            NOT EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd WHERE csd.course_id = c.id)
            OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd WHERE csd.course_id = c.id AND csd.domain = ?)
        )";
        $sharedDomainExtraParams = [$userDomain];

        // Bygg scope som "(domänmatch OCH org-tag-filter OCH shared-domain-filter)
        // ELLER publik-access ELLER global kurs (is_global=1, satt av superadmin)"
        $globalFragment = "c.is_global = 1";
        if (!empty($publicCourseIds)) {
            $publicPlaceholders = implode(',', array_fill(0, count($publicCourseIds), '?'));
            $courseScopeFragment = "(({$courseDomainClause['fragment']} $orgTagFilter $sharedDomainFilter) OR c.id IN ($publicPlaceholders) OR $globalFragment)";
            $courseScopeParams = array_merge($courseDomainClause['params'], $orgTagExtraParams, $sharedDomainExtraParams, $publicCourseIds);
        } else {
            $courseScopeFragment = "(({$courseDomainClause['fragment']} $orgTagFilter $sharedDomainFilter) OR $globalFragment)";
            $courseScopeParams = array_merge($courseDomainClause['params'], $orgTagExtraParams, $sharedDomainExtraParams);
        }
    }

    // Fetch active lessons and courses
    $lessons = query("
        SELECT l.*, c.title as course_title, c.status as course_status, c.image_url as course_image_url, c.id as course_id, c.sequential_mode
        FROM " . DB_DATABASE . ".lessons l
        JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
        WHERE c.status = 'active'
        AND $courseScopeFragment
        ORDER BY c.sort_order, l.sort_order
    ", $courseScopeParams);

    // Get user's progress
    $progress = query("SELECT * FROM " . DB_DATABASE . ".progress WHERE user_id = ?", [$userId]);

    // Fetch active courses (for the organization section with tags)
    $orgCourses = query("
        SELECT DISTINCT c.*, c.sequential_mode, u.email as author_email
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".users u ON c.author_id = u.id
        WHERE c.status = 'active'
        AND $courseScopeFragment
        ORDER BY c.sort_order
    ", $courseScopeParams);

    // Fetch tags for the user's organization (for filtering) — skippas för public_only
    if ($isPublicOnly) {
        $orgTags = [];
    } else {
        $orgTags = query("
            SELECT t.*, COUNT(DISTINCT ct.course_id) as course_count
            FROM " . DB_DATABASE . ".tags t
            LEFT JOIN " . DB_DATABASE . ".course_tags ct ON t.id = ct.tag_id
            LEFT JOIN " . DB_DATABASE . ".courses c ON ct.course_id = c.id AND c.status = 'active'
            WHERE {$tagDomainClause['fragment']}
            GROUP BY t.id
            HAVING course_count > 0
            ORDER BY t.name ASC
        ", $tagDomainClause['params']);
    }

    // Fetch course tags mapping for organization courses (only same domain tags)
    $courseTagsMap = [];
    if (!empty($orgCourses)) {
        $courseIds = array_column($orgCourses, 'id');
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $courseTags = query("
            SELECT ct.course_id, t.id as tag_id, t.name as tag_name
            FROM " . DB_DATABASE . ".course_tags ct
            JOIN " . DB_DATABASE . ".tags t ON ct.tag_id = t.id
            WHERE ct.course_id IN ($placeholders) AND {$tagDomainClause['fragment']}
        ", array_merge($courseIds, $tagDomainClause['params']));

        foreach ($courseTags as $ct) {
            if (!isset($courseTagsMap[$ct['course_id']])) {
                $courseTagsMap[$ct['course_id']] = [];
            }
            $courseTagsMap[$ct['course_id']][] = ['id' => $ct['tag_id'], 'name' => $ct['tag_name']];
        }
    }
    
    // Create array for easy access to user progress
    $userProgress = [];
    foreach ($progress as $item) {
        $userProgress[$item['lesson_id']] = $item;
    }

    // Hämta stegvis kurs-schedule för användaren
    $sequentialSchedules = query(
        "SELECT sls.* FROM " . DB_DATABASE . ".sequential_lesson_schedule sls WHERE sls.user_id = ?",
        [$userId]
    );
    $userSchedule = [];
    foreach ($sequentialSchedules as $s) {
        $userSchedule[$s['lesson_id']] = $s;
    }
    
    // Find next available lesson
    $nextLesson = null;
    $nextCourse = null;
    
    // Group lessons by course and sort by course order
    $courseGroups = [];
    foreach ($lessons as $lesson) {
        if (!isset($courseGroups[$lesson['course_id']])) {
            $courseGroups[$lesson['course_id']] = [
                'title' => $lesson['course_title'],
                'sort_order' => $lesson['sort_order'],
                'sequential_mode' => $lesson['sequential_mode'] ?? 0,
                'lessons' => []
            ];
        }
        $courseGroups[$lesson['course_id']]['lessons'][] = $lesson;
    }

    // Sort courses by sort_order
    uasort($courseGroups, function($a, $b) {
        return $a['sort_order'] <=> $b['sort_order'];
    });

    // Find first incomplete lesson in first PÅGÅENDE kurs.
    // En kurs räknas som pågående endast om användaren faktiskt har minst
    // en klar lektion i den — annars dyker kurser de aldrig startat upp
    // som "Nästa lektion" (vilket är förvirrande och inkonsekvent med
    // "Pågående kurser"-fliken nedan).
    foreach ($courseGroups as $courseGroup) {
        $courseHasCompletedLesson = false;
        foreach ($courseGroup['lessons'] as $lesson) {
            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                $courseHasCompletedLesson = true;
                break;
            }
        }
        if (!$courseHasCompletedLesson) continue;

        $hasIncomplete = false;
        foreach ($courseGroup['lessons'] as $lesson) {
            if (!isset($userProgress[$lesson['id']]) || $userProgress[$lesson['id']]['status'] !== 'completed') {
                $nextLesson = $lesson;
                $nextCourse = $courseGroup['title'];
                $hasIncomplete = true;
                break;
            }
        }
        if ($hasIncomplete) {
            break;
        }
    }
    
    // Group lessons by course for display
    $groupedLessons = [];
    foreach ($lessons as $lesson) {
        if (!isset($groupedLessons[$lesson['course_title']])) {
            $groupedLessons[$lesson['course_title']] = [
                'lessons' => [],
                'sort_order' => $lesson['sort_order'],
                'id' => 'course_' . md5($lesson['course_title'] . $lesson['course_id'])
            ];
        }
        $groupedLessons[$lesson['course_title']]['lessons'][] = $lesson;
    }
    
    // Calculate progress statistics
    $lessonCount = 0;
    $completedCount = 0;
    foreach ($groupedLessons as $courseData) {
        foreach ($courseData['lessons'] as $lesson) {
            $lessonCount++;
            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                $completedCount++;
            }
        }
    }
    $progressPercent = $lessonCount > 0 ? round(($completedCount / $lessonCount) * 100) : 0;

    // Check if user is admin
    $isAdmin = false;
    if (isLoggedIn()) {
        $user = queryOne("SELECT is_admin FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
        $isAdmin = $user ? (bool)$user['is_admin'] : false;
    }

    // Set page title
    $page_title = $systemName . ' - en nanolearningsplattform';
    // Include header
    require_once 'include/header.php';
?>
    <!-- Main content container -->
    <div class="container-fluid px-3 px-md-4 py-4 px-md-5">
        <div class="row">
            <!-- Left sidebar (empty on desktop) -->
            <div class="col-lg-2 d-none d-lg-block"></div>
            <!-- Main content area -->
            <div class="col-12 col-lg-8">
                <main>
                    <?php
                    // Pre-bucket: räkna pågående vs avslutade så flikarna kan visa antal.
                    // Avslutad = alla aktiva lektioner i kursen är progress.status='completed'.
                    $abandonedCourses = query("SELECT course_id FROM " . DB_DATABASE . ".course_enrollments
                                               WHERE user_id = ? AND status = 'abandoned'", [$userId]);
                    $abandonedCourseIds = array_column($abandonedCourses, 'course_id');

                    $countOngoing = 0;
                    $countCompleted = 0;
                    foreach ($groupedLessons as $courseTitle => $courseData) {
                        $courseLessons = $courseData['lessons'];
                        $firstLessonInGroup = reset($courseLessons);
                        $currentCourseId = $firstLessonInGroup['course_id'];
                        if (in_array($currentCourseId, $abandonedCourseIds)) continue;
                        $cTotal = count($courseLessons);
                        $cDone = 0;
                        foreach ($courseLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $cDone++;
                            }
                        }
                        if ($cDone === 0) continue;        // ej påbörjad — visas inte
                        if ($cDone >= $cTotal) $countCompleted++;
                        else $countOngoing++;
                    }
                    ?>

                    <style>
                        /* Modern kursdesign — kort + listvy. Använder Bootstrap CSS-variabler
                           så att dark mode (data-bs-theme="dark") slår igenom automatiskt. */
                        .courses-view-wrapper.view-cards .courses-list-grid { display: none; }
                        .courses-view-wrapper.view-list .courses-cards-grid { display: none; }

                        .modern-course-card {
                            border: 1px solid var(--bs-border-color, rgba(0,0,0,.06));
                            border-radius: 16px;
                            box-shadow: 0 2px 6px rgba(0,0,0,.04);
                            transition: transform .15s ease, box-shadow .15s ease;
                            overflow: hidden;
                            background: var(--bs-body-bg, #fff);
                            color: var(--bs-body-color);
                        }
                        .modern-course-card:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 8px 24px rgba(0,0,0,.10);
                        }
                        [data-bs-theme="dark"] .modern-course-card {
                            background: var(--bs-tertiary-bg, #2b3035);
                        }
                        [data-bs-theme="dark"] .modern-course-card:hover {
                            box-shadow: 0 8px 24px rgba(0,0,0,.45);
                        }
                        .modern-course-card .course-banner {
                            height: 110px;
                            width: 100%;
                            object-fit: cover;
                            display: block;
                        }
                        .modern-course-card .card-body { padding: 14px 16px; }
                        .modern-course-card .card-title {
                            font-size: 1rem; font-weight: 600;
                            line-height: 1.3; margin-bottom: 10px;
                        }
                        .modern-course-card .next-lesson {
                            font-size: 0.8125rem;
                            color: var(--bs-secondary-color, #6c757d);
                            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                            margin: 0 0 12px 0;
                        }

                        .course-list-item {
                            display: flex; align-items: center; gap: 14px;
                            padding: 12px 14px;
                            background: var(--bs-body-bg, #fff);
                            color: var(--bs-body-color);
                            border: 1px solid var(--bs-border-color, rgba(0,0,0,.06));
                            border-radius: 12px;
                            margin-bottom: 8px; transition: box-shadow .15s ease;
                        }
                        [data-bs-theme="dark"] .course-list-item {
                            background: var(--bs-tertiary-bg, #2b3035);
                        }
                        .course-list-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
                        [data-bs-theme="dark"] .course-list-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,.45); }
                        .course-list-item .thumb {
                            width: 56px; height: 56px; border-radius: 10px;
                            object-fit: cover; flex-shrink: 0;
                        }
                        .course-list-item .meta { flex: 1; min-width: 0; }
                        .course-list-item .li-title {
                            font-weight: 600; font-size: 0.95rem;
                            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                            margin-bottom: 4px; color: var(--bs-body-color);
                        }
                        .course-list-item a.text-dark { color: var(--bs-body-color) !important; }
                        .course-list-item .progress-row {
                            display: flex; align-items: center; gap: 10px;
                        }
                        .course-list-item .progress-row .progress { flex: 1; height: 6px; }
                        .course-list-item .progress-row .pct {
                            font-size: 0.75rem;
                            color: var(--bs-secondary-color, #6c757d);
                            white-space: nowrap;
                        }
                        .course-list-item .li-actions { flex-shrink: 0; display: flex; gap: 6px; }

                        .view-toggle .btn { border-radius: 8px; padding: 4px 10px; }
                        .view-toggle .btn.active { background: var(--bs-primary); color: #fff; border-color: var(--bs-primary); }

                        /* Tab-flikar — säkerställ läsbara färger oavsett tema */
                        .nav-tabs .nav-link {
                            color: var(--bs-secondary-color);
                        }
                        .nav-tabs .nav-link:hover {
                            color: var(--bs-emphasis-color);
                        }
                        .nav-tabs .nav-link.active {
                            color: var(--bs-emphasis-color);
                            font-weight: 600;
                        }

                        /* Mörk navbar i dark mode (vit bg → mörk bg) */
                        [data-bs-theme="dark"] .navbar.bg-white {
                            background: var(--bs-body-bg) !important;
                        }
                        [data-bs-theme="dark"] .navbar .text-dark { color: var(--bs-body-color) !important; }
                    </style>

                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h2 class="h2 fs-5 fs-md-3 mb-0">Mina kurser</h2>
                        <div class="view-toggle btn-group btn-group-sm" role="group" aria-label="Visningsläge">
                            <button type="button" class="btn btn-outline-primary active" data-view="cards" title="Kortvy">
                                <i class="bi bi-grid"></i> Kort
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-view="list" title="Listvy">
                                <i class="bi bi-list-ul"></i> Lista
                            </button>
                        </div>
                    </div>

                    <ul class="nav nav-tabs mb-3" id="myCoursesTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="ongoing-tab" data-bs-toggle="tab"
                                    data-bs-target="#ongoing-pane" type="button" role="tab"
                                    aria-controls="ongoing-pane" aria-selected="true">
                                <i class="bi bi-play-circle me-1"></i>Pågående kurser
                                <span class="badge bg-secondary ms-1"><?= $countOngoing ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="completed-tab" data-bs-toggle="tab"
                                    data-bs-target="#completed-pane" type="button" role="tab"
                                    aria-controls="completed-pane" aria-selected="false">
                                <i class="bi bi-check-circle me-1"></i>Avslutade kurser
                                <span class="badge bg-secondary ms-1"><?= $countCompleted ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="myCoursesTabContent">
                    <?php
                    // Rendera samma kort-template i två tab-paner. tabPaneStatus styr
                    // vilka kurser som tas med i varje pass.
                    foreach (['ongoing', 'completed'] as $tabPaneStatus):
                        $isOngoing = $tabPaneStatus === 'ongoing';
                    ?>
                    <div class="tab-pane fade <?= $isOngoing ? 'show active' : '' ?>"
                         id="<?= $tabPaneStatus ?>-pane" role="tabpanel"
                         aria-labelledby="<?= $tabPaneStatus ?>-tab">
                    <?php
                    // Bygg lista med kurser som hör till denna flik (pre-pass).
                    // Båda vy-renderingarna nedan itererar över samma lista.
                    $tabCourses = [];
                    foreach ($groupedLessons as $courseTitle2 => $courseData2):
                        $cLessons = $courseData2['lessons'];
                        $firstLG = reset($cLessons);
                        $cCourseId = $firstLG['course_id'];
                        if (in_array($cCourseId, $abandonedCourseIds)) continue;

                        $cTotal2 = count($cLessons);
                        $cDone2 = 0;
                        foreach ($cLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $cDone2++;
                            }
                        }
                        if ($cDone2 === 0) continue;
                        $isCDone = ($cDone2 >= $cTotal2);
                        if ($isOngoing && $isCDone) continue;
                        if (!$isOngoing && !$isCDone) continue;

                        $nextLes = null;
                        foreach ($cLessons as $lesson) {
                            if (!isset($userProgress[$lesson['id']]) || $userProgress[$lesson['id']]['status'] !== 'completed') {
                                $nextLes = $lesson;
                                break;
                            }
                        }

                        $tabCourses[] = [
                            'title'     => $courseTitle2,
                            'course_id' => $cCourseId,
                            'image_url' => $firstLG['course_image_url'] ?? null,
                            'completed' => $cDone2,
                            'total'     => $cTotal2,
                            'pct'       => $cTotal2 > 0 ? (int)round($cDone2 / $cTotal2 * 100) : 0,
                            'next_lesson' => $nextLes,
                            'is_done'   => $isCDone,
                        ];
                    endforeach;
                    ?>

                    <?php if (empty($tabCourses)): ?>
                        <div class="alert alert-info mt-3 mx-2 mx-md-0">
                            <?php if ($isOngoing): ?>
                                Du har inga pågående kurser just nu.
                            <?php else: ?>
                                Du har inga avslutade kurser än — fortsätt på dina pågående kurser för att slutföra dem.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>

                    <div class="courses-view-wrapper view-cards">
                        <!-- Kortvy -->
                        <div class="courses-cards-grid row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mx-2 mx-md-0">
                            <?php foreach ($tabCourses as $tc): ?>
                                <?php
                                    $imgSrc = $tc['image_url']
                                        ? 'upload/' . sanitize($tc['image_url'])
                                        : 'images/placeholder.png';
                                ?>
                                <div class="col">
                                    <div class="modern-course-card h-100 d-flex flex-column">
                                        <?php if ($tc['next_lesson']): ?>
                                            <a href="lesson.php?id=<?= (int)$tc['next_lesson']['id'] ?>" aria-label="Fortsätt med <?= sanitize($tc['title']) ?>">
                                                <img src="<?= $imgSrc ?>" class="course-banner" alt="<?= sanitize($tc['title']) ?>">
                                            </a>
                                        <?php else: ?>
                                            <img src="<?= $imgSrc ?>" class="course-banner" alt="<?= sanitize($tc['title']) ?>">
                                        <?php endif; ?>
                                        <div class="card-body d-flex flex-column">
                                            <h5 class="card-title text-truncate" title="<?= sanitize($tc['title']) ?>">
                                                <?= sanitize($tc['title']) ?>
                                            </h5>
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-success" role="progressbar"
                                                         style="width: <?= $tc['pct'] ?>%"></div>
                                                </div>
                                                <span class="ms-2 text-muted small"><?= $tc['completed'] ?>/<?= $tc['total'] ?></span>
                                            </div>
                                            <?php if ($tc['next_lesson']): ?>
                                                <p class="next-lesson"><i class="bi bi-arrow-right-circle me-1"></i><?= sanitize($tc['next_lesson']['title']) ?></p>
                                            <?php endif; ?>
                                            <div class="mt-auto d-flex gap-2 align-items-center">
                                                <?php if ($tc['next_lesson']): ?>
                                                    <a href="lesson.php?id=<?= (int)$tc['next_lesson']['id'] ?>" class="btn btn-primary btn-sm flex-grow-1">
                                                        Fortsätt
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-success btn-sm flex-grow-1" disabled>
                                                        <i class="bi bi-check-circle me-1"></i>Klar!
                                                    </button>
                                                <?php endif; ?>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mer">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item" href="abandon_course.php?course_id=<?= (int)$tc['course_id'] ?>">
                                                            <i class="bi bi-x-circle me-2"></i>Avsluta kurs
                                                        </a></li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Listvy -->
                        <div class="courses-list-grid mx-2 mx-md-0">
                            <?php foreach ($tabCourses as $tc): ?>
                                <?php
                                    $imgSrc = $tc['image_url']
                                        ? 'upload/' . sanitize($tc['image_url'])
                                        : 'images/placeholder.png';
                                ?>
                                <div class="course-list-item">
                                    <?php if ($tc['next_lesson']): ?>
                                        <a href="lesson.php?id=<?= (int)$tc['next_lesson']['id'] ?>" class="d-flex align-items-center gap-3 flex-grow-1 text-decoration-none text-dark" style="min-width: 0;">
                                            <img src="<?= $imgSrc ?>" class="thumb" alt="">
                                            <div class="meta">
                                                <div class="li-title"><?= sanitize($tc['title']) ?></div>
                                                <div class="progress-row">
                                                    <div class="progress">
                                                        <div class="progress-bar bg-success" style="width: <?= $tc['pct'] ?>%"></div>
                                                    </div>
                                                    <span class="pct"><?= $tc['completed'] ?>/<?= $tc['total'] ?> · <?= $tc['pct'] ?>%</span>
                                                </div>
                                            </div>
                                        </a>
                                        <div class="li-actions">
                                            <a href="lesson.php?id=<?= (int)$tc['next_lesson']['id'] ?>" class="btn btn-primary btn-sm">Fortsätt</a>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-label="Mer">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="abandon_course.php?course_id=<?= (int)$tc['course_id'] ?>">
                                                        <i class="bi bi-x-circle me-2"></i>Avsluta kurs
                                                    </a></li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?= $imgSrc ?>" class="thumb" alt="">
                                        <div class="meta">
                                            <div class="li-title"><?= sanitize($tc['title']) ?></div>
                                            <div class="progress-row">
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Slutförd</span>
                                                <span class="pct"><?= $tc['total'] ?>/<?= $tc['total'] ?> lektioner</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php endif; /* har kurser */ ?>
                    </div><!-- /.tab-pane -->
                    <?php endforeach; /* tab-paner */ ?>
                    </div><!-- /.tab-content -->

                    <?php
                    // Bygg kurskatalog: kurser i orgens scope som användaren INTE har startat.
                    $catalogCourses = [];
                    foreach ($orgCourses as $course) {
                        $catalogLessons = query(
                            "SELECT * FROM " . DB_DATABASE . ".lessons
                              WHERE course_id = ? ORDER BY sort_order",
                            [$course['id']]
                        );
                        $started = false;
                        foreach ($catalogLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $started = true; break;
                            }
                        }
                        if ($started || empty($catalogLessons)) continue;

                        $tagsForCourse = $courseTagsMap[$course['id']] ?? [];
                        $tagIds = array_column($tagsForCourse, 'id');

                        $catalogCourses[] = [
                            'id'           => (int)$course['id'],
                            'title'        => $course['title'],
                            'image_url'    => $course['image_url'] ?? null,
                            'lesson_count' => count($catalogLessons),
                            'first_lesson_id' => (int)$catalogLessons[0]['id'],
                            'sequential'   => !empty($course['sequential_mode']),
                            'author_email' => $course['author_email'] ?? '',
                            'tags'         => $tagsForCourse,
                            'tag_ids'      => $tagIds,
                        ];
                    }
                    ?>

                    <?php if (!empty($catalogCourses)): ?>
                        <hr class="my-4 border-light">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h2 fs-5 fs-md-3 mb-0">Kurskatalog</h2>
                        </div>

                        <!-- Sök + tagg-filter -->
                        <div class="mb-3 mx-2 mx-md-0">
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="orgCourseSearch" placeholder="Sök kurser...">
                                        <span class="input-group-text">
                                            <i class="bi bi-search"></i>
                                        </span>
                                    </div>
                                </div>
                                <?php if (!empty($orgTags)): ?>
                                <div class="col-12 col-md-6">
                                    <select class="form-select" id="orgTagFilter">
                                        <option value="">Alla taggar</option>
                                        <?php foreach ($orgTags as $tag): ?>
                                        <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?> (<?= $tag['course_count'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($orgTags)): ?>
                            <div class="mt-2" id="activeTagFilters"></div>
                            <?php endif; ?>
                        </div>

                        <div class="courses-view-wrapper view-cards" id="catalogViewWrapper">
                            <!-- Kortvy -->
                            <div class="courses-cards-grid row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mx-2 mx-md-0" id="orgCourseGrid">
                                <?php foreach ($catalogCourses as $cc): ?>
                                    <?php
                                        $imgSrc = $cc['image_url']
                                            ? 'upload/' . sanitize($cc['image_url'])
                                            : 'images/placeholder.png';
                                    ?>
                                    <div class="col catalog-item"
                                         data-tags="<?= htmlspecialchars(implode(',', $cc['tag_ids'])) ?>"
                                         data-title="<?= htmlspecialchars(mb_strtolower($cc['title'])) ?>">
                                        <div class="modern-course-card h-100 d-flex flex-column">
                                            <a href="lesson.php?id=<?= $cc['first_lesson_id'] ?>" aria-label="Börja kursen <?= sanitize($cc['title']) ?>">
                                                <img src="<?= $imgSrc ?>" class="course-banner" alt="<?= sanitize($cc['title']) ?>">
                                            </a>
                                            <div class="card-body d-flex flex-column">
                                                <h5 class="card-title text-truncate" title="<?= sanitize($cc['title']) ?>">
                                                    <?= sanitize($cc['title']) ?>
                                                </h5>
                                                <?php if ($cc['sequential']): ?>
                                                    <div class="mb-2">
                                                        <span class="badge bg-info"><i class="bi bi-signpost-split me-1"></i>Stegvis</span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($cc['tags'])): ?>
                                                    <div class="mb-2">
                                                        <?php foreach ($cc['tags'] as $tag): ?>
                                                            <span class="badge bg-primary me-1"><?= htmlspecialchars($tag['name']) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <p class="next-lesson mb-2">
                                                    <i class="bi bi-journal-text me-1"></i><?= $cc['lesson_count'] ?> lektioner
                                                </p>
                                                <div class="mt-auto">
                                                    <a href="lesson.php?id=<?= $cc['first_lesson_id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                                                        Börja kursen
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Listvy -->
                            <div class="courses-list-grid mx-2 mx-md-0">
                                <?php foreach ($catalogCourses as $cc): ?>
                                    <?php
                                        $imgSrc = $cc['image_url']
                                            ? 'upload/' . sanitize($cc['image_url'])
                                            : 'images/placeholder.png';
                                    ?>
                                    <div class="course-list-item catalog-item"
                                         data-tags="<?= htmlspecialchars(implode(',', $cc['tag_ids'])) ?>"
                                         data-title="<?= htmlspecialchars(mb_strtolower($cc['title'])) ?>">
                                        <a href="lesson.php?id=<?= $cc['first_lesson_id'] ?>" class="d-flex align-items-center gap-3 flex-grow-1 text-decoration-none text-dark" style="min-width: 0;">
                                            <img src="<?= $imgSrc ?>" class="thumb" alt="">
                                            <div class="meta">
                                                <div class="li-title"><?= sanitize($cc['title']) ?></div>
                                                <div class="progress-row">
                                                    <span class="pct">
                                                        <?= $cc['lesson_count'] ?> lektioner
                                                        <?php if ($cc['sequential']): ?>
                                                            · <span class="text-info"><i class="bi bi-signpost-split"></i> stegvis</span>
                                                        <?php endif; ?>
                                                        <?php foreach ($cc['tags'] as $tag): ?>
                                                            · <span class="badge bg-primary"><?= htmlspecialchars($tag['name']) ?></span>
                                                        <?php endforeach; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                        <div class="li-actions">
                                            <a href="lesson.php?id=<?= $cc['first_lesson_id'] ?>" class="btn btn-outline-primary btn-sm">Börja</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                    <!-- Information om behörigheter -->
                    <div class="card border-0 bg-light mt-5">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start">
                                <div class="me-3">
                                    <span class="badge bg-success rounded-circle p-2">
                                        <i class="bi bi-question-lg"></i>
                                    </span>
                                </div>
                                <div>
                                    <h6 class="mb-2">Vill du skapa egna kurser?</h6>
                                    <p class="text-muted small mb-2">
                                        Om du önskar få behörighet som <strong>Redaktör</strong> eller <strong>Admin</strong>
                                        för den organisation du tillhör, skicka en förfrågan till:
                                    </p>
                                    <a href="mailto:hjalp@sambruksupport.se" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-envelope me-1"></i>hjalp@sambruksupport.se
                                    </a>
                                    <a href="admin/user_guide.php" class="btn btn-sm btn-outline-secondary ms-2">
                                        <i class="bi bi-book me-1"></i>Användarhandbok
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
            <div class="col-lg-2 d-none d-lg-block"></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Search and tag filter for organization courses (kurskatalogen)
            // Filtrerar både kort- och listvyn via den gemensamma .catalog-item-klassen.
            const orgSearchInput = document.getElementById('orgCourseSearch');
            const orgTagFilter = document.getElementById('orgTagFilter');
            const catalogWrapper = document.getElementById('catalogViewWrapper');

            if (catalogWrapper) {
                const catalogItems = catalogWrapper.querySelectorAll('.catalog-item');
                const orgNoResultsAlert = document.createElement('div');
                orgNoResultsAlert.className = 'alert alert-info mt-4 mx-2 mx-md-0';
                orgNoResultsAlert.textContent = 'Inga kurser matchar din sökning.';
                orgNoResultsAlert.style.display = 'none';
                catalogWrapper.parentNode.insertBefore(orgNoResultsAlert, catalogWrapper.nextSibling);

                // Combined filter function for search and tags
                function filterOrgCourses() {
                    const searchTerm = orgSearchInput ? orgSearchInput.value.toLowerCase() : '';
                    const selectedTag = orgTagFilter ? orgTagFilter.value : '';
                    // Räkna unika kurser som matchar (en kurs har två element: ett kort + en listrad)
                    const matchedTitles = new Set();

                    catalogItems.forEach(item => {
                        const title = item.getAttribute('data-title') || '';
                        const tags = item.getAttribute('data-tags') || '';
                        const tagIds = tags.split(',').filter(t => t);

                        const searchMatches = !searchTerm || title.includes(searchTerm);
                        const tagMatches = !selectedTag || tagIds.includes(selectedTag);
                        const matches = searchMatches && tagMatches;

                        item.style.display = matches ? '' : 'none';
                        if (matches) matchedTitles.add(title);
                    });

                    orgNoResultsAlert.style.display = matchedTitles.size === 0 ? '' : 'none';

                    // Update active filter badge
                    const activeTagFilters = document.getElementById('activeTagFilters');
                    if (activeTagFilters && orgTagFilter) {
                        if (selectedTag) {
                            const selectedOption = orgTagFilter.options[orgTagFilter.selectedIndex];
                            activeTagFilters.innerHTML = `
                                <span class="badge bg-primary">
                                    ${selectedOption.text.split(' (')[0]}
                                    <button type="button" class="btn-close btn-close-white ms-1" style="font-size: 0.6rem;" onclick="document.getElementById('orgTagFilter').value=''; document.getElementById('orgTagFilter').dispatchEvent(new Event('change'));"></button>
                                </span>
                            `;
                        } else {
                            activeTagFilters.innerHTML = '';
                        }
                    }
                }

                if (orgSearchInput) {
                    orgSearchInput.addEventListener('input', filterOrgCourses);
                }

                if (orgTagFilter) {
                    orgTagFilter.addEventListener('change', filterOrgCourses);
                }
            }

            // Vy-växling för "Mina kurser" — kort/lista, persistent via localStorage
            (function() {
                var STORAGE_KEY = 'stimma_courses_view';
                var wrappers = document.querySelectorAll('.courses-view-wrapper');
                var buttons = document.querySelectorAll('.view-toggle [data-view]');
                if (!wrappers.length || !buttons.length) return;

                function applyView(mode) {
                    if (mode !== 'list') mode = 'cards';
                    wrappers.forEach(function(w) {
                        w.classList.remove('view-cards', 'view-list');
                        w.classList.add('view-' + mode);
                    });
                    buttons.forEach(function(b) {
                        b.classList.toggle('active', b.getAttribute('data-view') === mode);
                    });
                }

                var saved = null;
                try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
                applyView(saved || 'cards');

                buttons.forEach(function(b) {
                    b.addEventListener('click', function() {
                        var mode = b.getAttribute('data-view');
                        applyView(mode);
                        try { localStorage.setItem(STORAGE_KEY, mode); } catch (e) {}
                    });
                });
            })();
        });
    </script>
<?php
    // Inkludera footer
    require_once 'include/footer.php';
endif; ?>
