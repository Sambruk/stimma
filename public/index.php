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
                                        Kom ihåg mig på den här enheten
                                    </label>
                                    <div class="form-text small">
                                        Du hålls inloggad så länge du besöker Stimma minst en gång i månaden.
                                        Utan detta loggas du ut efter en arbetsdag.
                                    </div>
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

    // Vilka kurser användaren får se avgörs på ETT ställe: domänscope, org-taggar,
    // shared-domains, publik registrering och globala kurser. Reglerna låg förr
    // utskrivna här också, vilket innebar att varje ändring av synligheten måste
    // göras på två ställen för att katalogen och lärvägarna skulle vara överens.
    $courseVisibility = buildCourseVisibilityClause($userId, 'c');
    $courseScopeFragment = $courseVisibility['fragment'];
    $courseScopeParams = $courseVisibility['params'];
    $isPublicOnly = $courseVisibility['is_public_only'];

    // Taggmolnet hämtas per domän. För public_only är scopet tomt, vilket ger
    // "IN (NULL)" — en sådan användare tillhör ingen organisation och ska inte
    // se några orgtaggar alls.
    $tagDomainClause = buildDomainInClause($courseVisibility['org_scope_domains'], 't.organization_domain');

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
    // Aktiv sidebar-länk
    $rdActive = 'overview';
    // Include header
    require_once 'include/header.php';

    // Hälsning baserat på tid på dygnet
    $rdHour = (int)date('G');
    $rdGreeting = $rdHour < 5  ? 'God natt'
                : ($rdHour < 10 ? 'God morgon'
                : ($rdHour < 13 ? 'Hej'
                : ($rdHour < 18 ? 'God eftermiddag'
                : 'God kväll')));
    $rdFirstName = '';
    if (!empty($headerUserName)) {
        $rdFirstName = strtok($headerUserName, ' ');
    } elseif (!empty($_SESSION['user_email'])) {
        $rdFirstName = strstr($_SESSION['user_email'], '@', true);
    }
    $rdOrgName = $headerOrganization['name'] ?? $userDomain;
    // Org-logo: ikoninitial från orgens namn
    $rdOrgInitials = '';
    foreach (preg_split('/\s+/', trim($rdOrgName), -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $rdOrgInitials .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($rdOrgInitials) >= 2) break;
    }
    if ($rdOrgInitials === '') $rdOrgInitials = mb_strtoupper(mb_substr($rdOrgName, 0, 2));
?>

    <!-- Org-bar -->
    <div class="rd-org-bar">
        <div class="rd-org-info">
            <div class="rd-org-logo" aria-hidden="true">
                <?php if ($headerOrgIcon && !empty($headerOrgIcon['url'])): ?>
                    <img src="upload/org_icons/<?= htmlspecialchars($headerOrgIcon['url']) ?>"
                         alt="<?= htmlspecialchars($headerOrgIcon['name'] ?? $rdOrgName) ?>">
                <?php else: ?>
                    <?= htmlspecialchars($rdOrgInitials) ?>
                <?php endif; ?>
            </div>
            <div class="rd-org-text">
                <span class="rd-org-label">Inloggad i</span>
                <span class="rd-org-name"><?= htmlspecialchars($rdOrgName) ?></span>
            </div>
        </div>
    </div>

    <!-- Page-head (greeting + serif h1) -->
    <header class="rd-page-head">
        <div>
            <div class="rd-greeting"><?= htmlspecialchars($rdGreeting . ($rdFirstName !== '' ? ', ' . $rdFirstName : '')) ?></div>
            <h1 class="rd-hero">Fortsätt där du slutade</h1>
        </div>
    </header>

    <?php
    // ====== Stats-rutor (Pågående / Avslutade / Studietimmar) ======
    $rdLessonsCompleted = (int)(queryOne(
        "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".progress p
         JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
         JOIN " . DB_DATABASE . ".courses c ON c.id = l.course_id
         WHERE p.user_id = ? AND p.status = 'completed'", [$userId]
    )['c'] ?? 0);
    $rdCoursesCompleted = (int)(queryOne(
        "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".certificates WHERE user_id = ?", [$userId]
    )['c'] ?? 0);
    // Pågående: kurser där användaren klarat minst en lektion men inte alla
    $rdInProgress = (int)(queryOne(
        "SELECT COUNT(*) AS c FROM (
            SELECT c.id, COUNT(DISTINCT l.id) AS total_l,
                   COUNT(DISTINCT CASE WHEN p.status='completed' THEN l.id END) AS done_l
              FROM " . DB_DATABASE . ".courses c
              JOIN " . DB_DATABASE . ".lessons l ON l.course_id = c.id
              LEFT JOIN " . DB_DATABASE . ".progress p ON p.lesson_id = l.id AND p.user_id = ?
             WHERE c.status='active'
             GROUP BY c.id
            HAVING done_l > 0 AND done_l < total_l
        ) t", [$userId]
    )['c'] ?? 0);
    // Studietimmar: schablon 5 min per lektion (samma som dashboardens estimering)
    $rdStudyMinutes = $rdLessonsCompleted * 5;
    $rdStudyHoursLabel = $rdStudyMinutes >= 60
        ? round($rdStudyMinutes / 60) . 'h'
        : $rdStudyMinutes . 'min';
    ?>

    <section class="rd-stats">
        <div class="rd-stat">
            <div class="rd-stat-label">Pågående</div>
            <div class="rd-stat-value"><?= $rdInProgress ?></div>
        </div>
        <div class="rd-stat">
            <div class="rd-stat-label">Avslutade</div>
            <div class="rd-stat-value"><?= $rdCoursesCompleted ?></div>
        </div>
        <div class="rd-stat">
            <div class="rd-stat-label">Studietimmar</div>
            <div class="rd-stat-value"><?= $rdStudyHoursLabel ?></div>
        </div>
    </section>

    <!-- Main content container -->
    <div class="container-fluid p-0" id="mina-kurser">
        <div class="row g-0">
            <!-- Main content area (full bredd nu — sidebar finns redan i app-shell) -->
            <div class="col-12">
                <main>
                    <?php
                    // Pre-bucket: räkna pågående vs avslutade så flikarna kan visa antal.
                    // Avslutad = alla aktiva lektioner i kursen är progress.status='completed'
                    //            ELLER kursen är avbruten (course_enrollments.status='abandoned').
                    // Pågående = användaren har klickat "Börja kursen" (aktiv enrollment)
                    //            ELLER har minst en klar lektion. Det räcker alltså
                    //            med att ha öppnat kursen — man behöver inte ha
                    //            slutfört en lektion för att den ska räknas.
                    $enrollRows = query(
                        "SELECT course_id, status FROM " . DB_DATABASE . ".course_enrollments WHERE user_id = ?",
                        [$userId]
                    );
                    $abandonedCourseIds = [];
                    $activeEnrolledCourseIds = [];
                    foreach ($enrollRows as $er) {
                        if ($er['status'] === 'abandoned') {
                            $abandonedCourseIds[] = (int)$er['course_id'];
                        } elseif ($er['status'] === 'active') {
                            $activeEnrolledCourseIds[] = (int)$er['course_id'];
                        }
                    }

                    $countOngoing = 0;
                    $countCompleted = 0;
                    foreach ($groupedLessons as $courseTitle => $courseData) {
                        $courseLessons = $courseData['lessons'];
                        $firstLessonInGroup = reset($courseLessons);
                        $currentCourseId = (int)$firstLessonInGroup['course_id'];
                        $isAbandoned = in_array($currentCourseId, $abandonedCourseIds, true);
                        $isEnrolled = in_array($currentCourseId, $activeEnrolledCourseIds, true);
                        $cTotal = count($courseLessons);
                        $cDone = 0;
                        foreach ($courseLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $cDone++;
                            }
                        }
                        if ($isAbandoned) {
                            $countCompleted++;          // Avbrutna räknas under "Avslutade"
                            continue;
                        }
                        if ($cDone === 0 && !$isEnrolled) continue;  // ej påbörjad — visas inte
                        if ($cTotal > 0 && $cDone >= $cTotal) $countCompleted++;
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
                    // - Pågående: cDone>0, ej klar, ej avbruten
                    // - Avslutade: helt klar ELLER avbruten (även med cDone=0)
                    $tabCourses = [];
                    foreach ($groupedLessons as $courseTitle2 => $courseData2):
                        $cLessons = $courseData2['lessons'];
                        $firstLG = reset($cLessons);
                        $cCourseId = (int)$firstLG['course_id'];
                        $isAbandoned = in_array($cCourseId, $abandonedCourseIds, true);
                        $isEnrolled = in_array($cCourseId, $activeEnrolledCourseIds, true);

                        $cTotal2 = count($cLessons);
                        $cDone2 = 0;
                        foreach ($cLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $cDone2++;
                            }
                        }
                        $isCDone = ($cTotal2 > 0 && $cDone2 >= $cTotal2);

                        if ($isOngoing) {
                            if ($isAbandoned) continue;
                            if ($cDone2 === 0 && !$isEnrolled) continue;  // ej påbörjad
                            if ($isCDone) continue;
                        } else {
                            if (!$isCDone && !$isAbandoned) continue;
                        }

                        $nextLes = null;
                        foreach ($cLessons as $lesson) {
                            if (!isset($userProgress[$lesson['id']]) || $userProgress[$lesson['id']]['status'] !== 'completed') {
                                $nextLes = $lesson;
                                break;
                            }
                        }

                        // För stegvisa kurser: nästa lektion kan vara låst (väntar
                        // på sequential_lesson_schedule.available_at). Då vill vi inte
                        // skicka användaren till en sida som bara redirectar tillbaka.
                        $isSequential = !empty($firstLG['sequential_mode']);
                        $isNextAvailable = true;
                        if ($nextLes && $isSequential) {
                            $isNextAvailable = isLessonAvailableForUser(
                                (int)$userId, (int)$nextLes['id'], $cCourseId
                            );
                        }

                        // Hitta senaste klara lektion som fallback-länkmål när
                        // nästa lektion är låst — då hamnar användaren ändå inne
                        // i kursen och kan bläddra via lektionsmenyn.
                        $lastDoneLessonId = 0;
                        foreach ($cLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $lastDoneLessonId = (int)$lesson['id'];
                            }
                        }

                        $tabCourses[] = [
                            'title'        => $courseTitle2,
                            'course_id'    => $cCourseId,
                            'image_url'    => $firstLG['course_image_url'] ?? null,
                            'completed'    => $cDone2,
                            'total'        => $cTotal2,
                            'pct'          => $cTotal2 > 0 ? (int)round($cDone2 / $cTotal2 * 100) : 0,
                            'next_lesson'  => $nextLes,
                            'is_next_available' => $isNextAvailable,
                            'is_sequential'     => $isSequential,
                            'last_done_lesson_id' => $lastDoneLessonId,
                            'is_done'      => $isCDone,
                            'is_abandoned' => $isAbandoned,
                            'first_lesson_id' => isset($cLessons[0]) ? (int)$cLessons[0]['id'] : 0,
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
                        <!-- Kortvy (redesign v3 — ikon-platta i stället för bild) -->
                        <div class="courses-cards-grid row row-cols-1 row-cols-md-2 g-3 mx-0">
                            <?php foreach ($tabCourses as $tcIdx => $tc): ?>
                                <?php
                                    // Alternera färgad ikon-platta så cards får visuell variation
                                    $rdIconColors = ['purple', 'teal', 'amber'];
                                    $rdIconColor = $rdIconColors[$tcIdx % 3];
                                ?>
                                <div class="col">
                                    <div class="rd-course">
                                        <div class="rd-course-top">
                                            <div class="rd-course-info">
                                                <div class="rd-course-icon <?= $rdIconColor ?>" aria-hidden="true">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                                                </div>
                                                <div style="min-width:0;">
                                                    <div class="rd-course-meta">
                                                        <?= $tc['total'] ?> lektioner
                                                        <?php if ($tc['is_done']): ?>
                                                            · <span class="text-success"><i class="bi bi-check-circle-fill"></i> slutförd</span>
                                                        <?php elseif ($tc['is_abandoned']): ?>
                                                            · <span class="text-warning"><i class="bi bi-x-circle-fill"></i> avbruten</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="rd-course-title text-truncate" title="<?= sanitize($tc['title']) ?>"><?= sanitize($tc['title']) ?></div>
                                                    <?php if ($tc['next_lesson'] && !$tc['is_abandoned'] && !$tc['is_done']): ?>
                                                        <div class="rd-course-next text-truncate">Nästa: <?= sanitize($tc['next_lesson']['title']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="rd-course-percent"><?= $tc['pct'] ?>%</span>
                                        </div>
                                        <div class="rd-progress" role="progressbar" aria-valuenow="<?= $tc['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Kursprogress">
                                            <div class="rd-progress-fill" style="width: <?= $tc['pct'] ?>%;"></div>
                                        </div>
                                        <div class="rd-actions">
                                            <?php if ($tc['is_done']): ?>
                                                <span class="rd-btn rd-btn-primary" style="cursor: default; opacity: 0.7;"><i class="bi bi-check-circle me-1"></i>Klar!</span>
                                            <?php elseif ($tc['is_abandoned']): ?>
                                                <form method="post" action="resume_course.php" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                    <input type="hidden" name="course_id" value="<?= (int)$tc['course_id'] ?>">
                                                    <button type="submit" class="rd-btn rd-btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Återuppta kurs</button>
                                                </form>
                                            <?php elseif ($tc['next_lesson'] && $tc['is_next_available']): ?>
                                                <a href="lesson.php?id=<?= (int)$tc['next_lesson']['id'] ?>" class="rd-btn rd-btn-primary">Fortsätt lektion</a>
                                            <?php elseif ($tc['next_lesson'] && !$tc['is_next_available']): ?>
                                                <?php $fallbackId = $tc['last_done_lesson_id'] ?: $tc['first_lesson_id']; ?>
                                                <a href="lesson.php?id=<?= (int)$fallbackId ?>" class="rd-btn rd-btn-primary" title="Nästa lektion är inte upplåst ännu — visa det du redan har läst">Se kursinnehåll</a>
                                            <?php else: ?>
                                                <span class="rd-btn rd-btn-primary" style="cursor: default; opacity: 0.7;"><i class="bi bi-check-circle me-1"></i>Klar!</span>
                                            <?php endif; ?>
                                            <?php if (!$tc['is_abandoned'] && !$tc['is_done']): ?>
                                                <div class="dropdown">
                                                    <button class="rd-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mer alternativ">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item text-danger" href="abandon_course.php?course_id=<?= (int)$tc['course_id'] ?>">
                                                            <i class="bi bi-x-circle me-2"></i>Avbryt kursen
                                                        </a></li>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($tc['next_lesson'] && !$tc['is_next_available'] && !$tc['is_done'] && !$tc['is_abandoned']): ?>
                                            <div class="small text-muted mt-2">
                                                <i class="bi bi-clock-history me-1"></i>
                                                Stegvis kurs — nästa lektion är ännu inte upplåst.
                                            </div>
                                        <?php endif; ?>
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
                                    <?php if ($tc['is_done']): ?>
                                        <img src="<?= $imgSrc ?>" class="thumb" alt="">
                                        <div class="meta">
                                            <div class="li-title"><?= sanitize($tc['title']) ?></div>
                                            <div class="progress-row">
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Slutförd</span>
                                                <span class="pct"><?= $tc['total'] ?>/<?= $tc['total'] ?> lektioner</span>
                                            </div>
                                        </div>
                                    <?php elseif ($tc['is_abandoned']): ?>
                                        <img src="<?= $imgSrc ?>" class="thumb" alt="">
                                        <div class="meta">
                                            <div class="li-title"><?= sanitize($tc['title']) ?></div>
                                            <div class="progress-row">
                                                <span class="badge bg-warning text-dark"><i class="bi bi-x-circle me-1"></i>Avbruten</span>
                                                <?php if ($tc['completed'] > 0): ?>
                                                    <span class="pct"><?= $tc['completed'] ?>/<?= $tc['total'] ?> lektioner</span>
                                                <?php else: ?>
                                                    <span class="pct"><?= $tc['total'] ?> lektioner</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="li-actions">
                                            <form method="post" action="resume_course.php" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                <input type="hidden" name="course_id" value="<?= (int)$tc['course_id'] ?>">
                                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Återuppta</button>
                                            </form>
                                        </div>
                                    <?php elseif ($tc['next_lesson']):
                                        $linkTargetId = $tc['is_next_available']
                                            ? (int)$tc['next_lesson']['id']
                                            : ($tc['last_done_lesson_id'] ?: $tc['first_lesson_id']);
                                        $listBtnLabel = $tc['is_next_available'] ? 'Fortsätt' : 'Se innehåll';
                                    ?>
                                        <a href="lesson.php?id=<?= (int)$linkTargetId ?>" class="d-flex align-items-center gap-3 flex-grow-1 text-decoration-none text-dark" style="min-width: 0;">
                                            <img src="<?= $imgSrc ?>" class="thumb" alt="">
                                            <div class="meta">
                                                <div class="li-title"><?= sanitize($tc['title']) ?></div>
                                                <div class="progress-row">
                                                    <div class="progress">
                                                        <div class="progress-bar bg-success" style="width: <?= $tc['pct'] ?>%"></div>
                                                    </div>
                                                    <span class="pct"><?= $tc['completed'] ?>/<?= $tc['total'] ?> · <?= $tc['pct'] ?>%</span>
                                                    <?php if (!$tc['is_next_available']): ?>
                                                        <span class="badge bg-info text-dark" title="Stegvis kurs — nästa lektion är inte upplåst"><i class="bi bi-clock-history me-1"></i>Väntar på upplåsning</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </a>
                                        <div class="li-actions">
                                            <a href="lesson.php?id=<?= (int)$linkTargetId ?>" class="btn btn-primary btn-sm"><?= $listBtnLabel ?></a>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-label="Mer">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item text-danger" href="abandon_course.php?course_id=<?= (int)$tc['course_id'] ?>">
                                                        <i class="bi bi-x-circle me-2"></i>Avbryt kursen
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
                    // Mina lärvägar: kompakt sektion med de tre första lärvägarna.
                    // Full status finns på learning_paths.php. Renderas inte alls
                    // om användaren saknar synliga lärvägar.
                    require_once 'include/learning_paths.php';
                    $rdLearningPaths = getLearningPathOverviewForUser($userId);
                    ?>
                    <?php if (!empty($rdLearningPaths)): ?>
                    <section class="rd-section mt-5" id="mina-larvagar">
                        <div class="rd-section-head">
                            <h2>Mina lärvägar</h2>
                            <a href="learning_paths.php" class="rd-section-meta" style="text-decoration:none;">Se alla &rarr;</a>
                        </div>
                        <div class="row row-cols-1 row-cols-md-2 g-3 mx-0">
                            <?php foreach (array_slice($rdLearningPaths, 0, 3) as $lpIdx => $rdLp): ?>
                                <?php
                                    $lpIconColors = ['purple', 'teal', 'amber'];
                                    $lpIconColor = $lpIconColors[$lpIdx % 3];
                                ?>
                                <div class="col">
                                    <div class="rd-course">
                                        <div class="rd-course-top">
                                            <div class="rd-course-info">
                                                <div class="rd-course-icon <?= $lpIconColor ?>" aria-hidden="true">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h9l3 3-3 3H9"/><path d="M15 12H6l-3 3 3 3h9"/><path d="M12 3v3"/><path d="M12 18v3"/></svg>
                                                </div>
                                                <div style="min-width:0;">
                                                    <div class="rd-course-meta">
                                                        <?= (int)$rdLp['total_count'] ?> kurser · <?= (int)$rdLp['completed_count'] ?> klar<?= (int)$rdLp['completed_count'] != 1 ? 'a' : '' ?>
                                                    </div>
                                                    <div class="rd-course-title text-truncate" title="<?= sanitize($rdLp['title']) ?>">
                                                        <?= sanitize($rdLp['title']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <span class="rd-course-percent"><?= (int)$rdLp['path_percent'] ?> %</span>
                                        </div>
                                        <div class="rd-progress" role="progressbar"
                                             aria-valuenow="<?= (int)$rdLp['path_percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                                             aria-label="Framsteg i lärvägen">
                                            <div class="rd-progress-fill" style="width: <?= (int)$rdLp['path_percent'] ?>%;"></div>
                                        </div>
                                        <div class="rd-actions">
                                            <a href="learning_paths.php#larvag-<?= (int)$rdLp['id'] ?>" class="rd-btn rd-btn-primary">Visa lärvägen</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php
                    // Bygg kurskatalog: ALLA kurser i orgens scope (oavsett progress).
                    // Användaren ser även påbörjade/klara kurser här så de kan
                    // återbesöka eller starta om.
                    $catalogCourses = [];
                    foreach ($orgCourses as $course) {
                        $catalogLessons = query(
                            "SELECT * FROM " . DB_DATABASE . ".lessons
                              WHERE course_id = ? ORDER BY sort_order",
                            [$course['id']]
                        );
                        if (empty($catalogLessons)) continue;

                        // Räkna progress för att avgöra knapptext och länkmål
                        $catCompleted = 0;
                        $catNextLessonId = null;
                        foreach ($catalogLessons as $lesson) {
                            if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                $catCompleted++;
                            } elseif ($catNextLessonId === null) {
                                $catNextLessonId = (int)$lesson['id'];
                            }
                        }
                        $catTotal = count($catalogLessons);
                        $catIsDone = ($catCompleted >= $catTotal);
                        $catIsStarted = ($catCompleted > 0);
                        $catIsAbandoned = in_array((int)$course['id'], $abandonedCourseIds);

                        // Länkmål: nästa ej-klara lektion om sådan finns, annars första
                        $catLinkLessonId = $catNextLessonId ?? (int)$catalogLessons[0]['id'];

                        $tagsForCourse = $courseTagsMap[$course['id']] ?? [];
                        $tagIds = array_column($tagsForCourse, 'id');

                        $catalogCourses[] = [
                            'id'              => (int)$course['id'],
                            'title'           => $course['title'],
                            'image_url'       => $course['image_url'] ?? null,
                            'lesson_count'    => $catTotal,
                            'first_lesson_id' => $catLinkLessonId,
                            'sequential'      => !empty($course['sequential_mode']),
                            'author_email'    => $course['author_email'] ?? '',
                            'tags'            => $tagsForCourse,
                            'tag_ids'         => $tagIds,
                            'is_started'      => $catIsStarted,
                            'is_done'         => $catIsDone,
                            'is_abandoned'    => $catIsAbandoned,
                        ];
                    }
                    ?>

                    <?php if (!empty($catalogCourses)): ?>
                        <hr class="my-4 border-light">

                        <div class="d-flex justify-content-between align-items-center mb-3" id="kurskatalog">
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
                            <!-- Kortvy (redesign v3) -->
                            <div class="courses-cards-grid row row-cols-1 row-cols-md-2 g-3 mx-0" id="orgCourseGrid">
                                <?php foreach ($catalogCourses as $ccIdx => $cc): ?>
                                    <?php
                                        $rdIconColor = ['purple', 'teal', 'amber'][$ccIdx % 3];
                                    ?>
                                    <div class="col catalog-item"
                                         data-tags="<?= htmlspecialchars(implode(',', $cc['tag_ids'])) ?>"
                                         data-title="<?= htmlspecialchars(mb_strtolower($cc['title'])) ?>">
                                        <div class="rd-course">
                                            <div class="rd-course-top">
                                                <div class="rd-course-info">
                                                    <div class="rd-course-icon <?= $rdIconColor ?>" aria-hidden="true">
                                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                                                    </div>
                                                    <div style="min-width:0;">
                                                        <div class="rd-course-meta">
                                                            <?= $cc['lesson_count'] ?> lektioner<?= $cc['sequential'] ? ' · stegvis' : '' ?>
                                                            <?php if ($cc['is_done']): ?>
                                                                · <span class="text-success"><i class="bi bi-check-circle-fill"></i> klar</span>
                                                            <?php elseif ($cc['is_abandoned']): ?>
                                                                · <span class="text-warning"><i class="bi bi-x-circle-fill"></i> avbruten</span>
                                                            <?php elseif ($cc['is_started']): ?>
                                                                · <span class="text-info"><i class="bi bi-play-circle-fill"></i> påbörjad</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="rd-course-title text-truncate" title="<?= sanitize($cc['title']) ?>"><?= sanitize($cc['title']) ?></div>
                                                        <?php if (!empty($cc['tags'])): ?>
                                                            <div class="rd-course-next">
                                                                <?php foreach ($cc['tags'] as $tag): ?>
                                                                    <span style="display: inline-block; background: var(--rd-bg-muted); color: var(--rd-text-secondary); padding: 1px 8px; border-radius: 4px; font-size: 11px; margin-right: 4px;"><?= htmlspecialchars($tag['name']) ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="rd-actions">
                                                <?php
                                                    if ($cc['is_done']) {
                                                        $catBtnLabel = 'Gå igenom igen';
                                                    } elseif ($cc['is_started']) {
                                                        $catBtnLabel = 'Fortsätt';
                                                    } else {
                                                        $catBtnLabel = 'Börja kursen';
                                                    }
                                                ?>
                                                <a href="lesson.php?id=<?= $cc['first_lesson_id'] ?>" class="rd-btn rd-btn-primary"><?= $catBtnLabel ?></a>
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
                                                        <?php if ($cc['is_done']): ?>
                                                            · <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Klar</span>
                                                        <?php elseif ($cc['is_abandoned']): ?>
                                                            · <span class="badge bg-warning text-dark"><i class="bi bi-x-circle me-1"></i>Avbruten</span>
                                                        <?php elseif ($cc['is_started']): ?>
                                                            · <span class="badge bg-info text-dark"><i class="bi bi-play-circle me-1"></i>Påbörjad</span>
                                                        <?php endif; ?>
                                                        <?php foreach ($cc['tags'] as $tag): ?>
                                                            · <span class="badge bg-primary"><?= htmlspecialchars($tag['name']) ?></span>
                                                        <?php endforeach; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                        <div class="li-actions">
                                            <?php
                                                if ($cc['is_done']) {
                                                    $catListBtnLabel = 'Gå igen';
                                                } elseif ($cc['is_started']) {
                                                    $catListBtnLabel = 'Fortsätt';
                                                } else {
                                                    $catListBtnLabel = 'Börja';
                                                }
                                            ?>
                                            <a href="lesson.php?id=<?= $cc['first_lesson_id'] ?>" class="btn btn-outline-primary btn-sm"><?= $catListBtnLabel ?></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                    <!-- Achievements-preview (länkar till Min dashboard) -->
                    <?php
                    // Beräkna enkla achievements:
                    // 1. Första kursen klar (har minst 1 diplom)
                    // 2. Snabbstartare (≥5 lektioner senaste 7 dgr)
                    // 3. Streak (≥3 dagar i rad nyligen)
                    // 4. Locked: Expert — kräver 5 kurser klara
                    $rd7DayLessons = (int)(queryOne(
                        "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".progress
                         WHERE user_id = ? AND status='completed'
                           AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$userId]
                    )['c'] ?? 0);
                    $rdLastCertDate = queryOne(
                        "SELECT completion_date FROM " . DB_DATABASE . ".certificates
                         WHERE user_id = ? ORDER BY completion_date DESC LIMIT 1", [$userId]
                    );
                    $rdMonthNames = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
                    $rdCertLabel = $rdLastCertDate
                        ? $rdMonthNames[(int)date('n', strtotime($rdLastCertDate['completion_date'])) - 1]
                          . ' ' . date('Y', strtotime($rdLastCertDate['completion_date']))
                        : '';
                    ?>
                    <section class="rd-section mt-5">
                        <div class="rd-section-head">
                            <h2>Senaste utmärkelser</h2>
                            <a href="dashboard.php" style="font-size: 12px; color: var(--rd-text-secondary); text-decoration: none;">
                                Se alla →
                            </a>
                        </div>
                        <div class="rd-achievements">
                            <a href="dashboard.php" class="rd-badge-card <?= $rdCoursesCompleted < 1 ? 'locked' : '' ?>">
                                <div class="rd-badge-icon amber" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.5 13.5L17 21l-5-3-5 3 1.5-7.5"/></svg>
                                </div>
                                <div class="rd-badge-name">Första diplom</div>
                                <div class="rd-badge-sub"><?= $rdCertLabel ?: 'Slutför en kurs' ?></div>
                            </a>
                            <a href="dashboard.php" class="rd-badge-card <?= $rd7DayLessons < 5 ? 'locked' : '' ?>">
                                <div class="rd-badge-icon teal" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
                                </div>
                                <div class="rd-badge-name">Snabbstartare</div>
                                <div class="rd-badge-sub"><?= $rd7DayLessons ?> lekt./vecka</div>
                            </a>
                            <a href="dashboard.php" class="rd-badge-card <?= $rdLessonsCompleted < 10 ? 'locked' : '' ?>">
                                <div class="rd-badge-icon purple" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg>
                                </div>
                                <div class="rd-badge-name">10 lektioner</div>
                                <div class="rd-badge-sub"><?= $rdLessonsCompleted ?>/10 klara</div>
                            </a>
                            <a href="dashboard.php" class="rd-badge-card locked">
                                <div class="rd-badge-icon muted" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                </div>
                                <div class="rd-badge-name">Expert</div>
                                <div class="rd-badge-sub"><?= $rdCoursesCompleted ?>/5 kurser</div>
                            </a>
                        </div>
                    </section>

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
