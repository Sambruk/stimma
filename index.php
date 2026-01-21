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
                        <h1 class="display-4 mb-3"><img src="images/stimma-logo.png" height="80px" alt="<?= $systemName ?>"></h1>
                        <?php if ($systemDescription): ?>
                            <p class="lead text-muted mb-4"><?= $systemDescription ?></p>
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
                <!-- Info button -->
                <div class="text-center mt-3">
                    <a href="stimmainfo.html" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-info-circle me-1"></i>Information om Stimma och Kontakt
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php
    // Include footer
    require_once 'include/footer.php';
else: 
    // Get user ID from session
    $userId = $_SESSION['user_id'];

    // Get user's email and domain first (needed for filtering)
    $user = queryOne("SELECT email FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);
    $userDomain = substr(strrchr($user['email'], "@"), 1);

    // Fetch active lessons and courses - ONLY from user's organization
    $lessons = query("
        SELECT l.*, c.title as course_title, c.status as course_status, c.image_url as course_image_url, c.id as course_id
        FROM " . DB_DATABASE . ".lessons l
        JOIN " . DB_DATABASE . ".courses c ON l.course_id = c.id
        WHERE c.status = 'active'
        AND c.organization_domain = ?
        ORDER BY c.sort_order, l.sort_order
    ", [$userDomain]);

    // Get user's progress
    $progress = query("SELECT * FROM " . DB_DATABASE . ".progress WHERE user_id = ?", [$userId]);

    // Fetch organization courses (for the organization section with tags)
    $orgCourses = query("
        SELECT DISTINCT c.*, u.email as author_email
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".users u ON c.author_id = u.id
        WHERE c.status = 'active'
        AND c.organization_domain = ?
        ORDER BY c.sort_order
    ", [$userDomain]);

    // Fetch tags for the user's organization (for filtering)
    $orgTags = query("
        SELECT t.*, COUNT(DISTINCT ct.course_id) as course_count
        FROM " . DB_DATABASE . ".tags t
        LEFT JOIN " . DB_DATABASE . ".course_tags ct ON t.id = ct.tag_id
        LEFT JOIN " . DB_DATABASE . ".courses c ON ct.course_id = c.id AND c.status = 'active'
        WHERE t.organization_domain = ?
        GROUP BY t.id
        HAVING course_count > 0
        ORDER BY t.name ASC
    ", [$userDomain]);

    // Fetch course tags mapping for organization courses (only same domain tags)
    $courseTagsMap = [];
    if (!empty($orgCourses)) {
        $courseIds = array_column($orgCourses, 'id');
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $courseTags = query("
            SELECT ct.course_id, t.id as tag_id, t.name as tag_name
            FROM " . DB_DATABASE . ".course_tags ct
            JOIN " . DB_DATABASE . ".tags t ON ct.tag_id = t.id
            WHERE ct.course_id IN ($placeholders) AND t.organization_domain = ?
        ", array_merge($courseIds, [$userDomain]));

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
                'lessons' => []
            ];
        }
        $courseGroups[$lesson['course_id']]['lessons'][] = $lesson;
    }

    // Sort courses by sort_order
    uasort($courseGroups, function($a, $b) {
        return $a['sort_order'] <=> $b['sort_order'];
    });

    // Find first incomplete lesson in first incomplete course
    foreach ($courseGroups as $courseGroup) {
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
                    <!-- Next lesson card -->
                    <?php if ($nextLesson): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body px-3 py-3">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                                <div class="me-md-3">
                                    <span class="badge bg-secondary mb-2">Nästa lektion</span>
                                    <h2 class="card-title fs-5"><?= sanitize($nextLesson['title']) ?></h2>
                                    <p class="text-muted small mb-2">Kurs: <?= sanitize($nextCourse) ?></p>
                                    <?php if (!empty($nextLesson['description'])): ?>
                                        <p class="card-text small"><?= sanitize($nextLesson['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <a href="lesson.php?id=<?= $nextLesson['id'] ?>" class="btn btn-primary btn-sm mt-2 mt-md-0">
                                    Fortsätt lära
                                    <i class="bi bi-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="h2 fs-5 fs-md-3 mb-0">Mina kurser</h2>
                    </div>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mx-2 mx-md-0">
                        <?php
                        $hasStartedCourses = false;
                        // Hämta avslutade kurser för denna användare
                        $abandonedCourses = query("SELECT course_id FROM " . DB_DATABASE . ".course_enrollments
                                                   WHERE user_id = ? AND status = 'abandoned'", [$userId]);
                        $abandonedCourseIds = array_column($abandonedCourses, 'course_id');

                        foreach ($groupedLessons as $courseTitle => $courseData):
                            $courseLessons = $courseData['lessons'];
                            // Calculate course progress
                            $courseTotal = count($courseLessons);
                            $courseCompleted = 0;
                            $courseStarted = false;

                            // Hämta kurs-ID från första lektionen
                            $firstLessonInGroup = reset($courseLessons);
                            $currentCourseId = $firstLessonInGroup['course_id'];

                            // Hoppa över avslutade kurser
                            if (in_array($currentCourseId, $abandonedCourseIds)) {
                                continue;
                            }

                            foreach ($courseLessons as $lesson) {
                                if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                    $courseCompleted++;
                                    $courseStarted = true;
                                }
                            }

                            if (!$courseStarted) {
                                continue; // Skip to next course if not started
                            }
                            $hasStartedCourses = true;
                            
                            // Get the next lesson in this course
                            $nextLessonInCourse = null;
                            foreach ($courseLessons as $lesson) {
                                if (!isset($userProgress[$lesson['id']]) || $userProgress[$lesson['id']]['status'] !== 'completed') {
                                    $nextLessonInCourse = $lesson;
                                    break;
                                }
                            }
                        ?>
                        <div class="col">
                            <div class="card shadow-sm h-100">
                                <?php 
                                    // Get first lesson of the course to access course data
                                    $firstLesson = reset($courseLessons);
                                    $courseImageUrl = $firstLesson['course_image_url'] ?? null;
                                ?>
                                <?php if ($courseImageUrl): ?>
                                    <img src="upload/<?= sanitize($courseImageUrl) ?>" class="card-img-top course-image" alt="<?= sanitize($courseTitle) ?>">
                                <?php else: ?>
                                    <img src="images/placeholder.png" class="card-img-top course-image" alt="<?= sanitize($courseTitle) ?>">
                                <?php endif; ?>
                                
                                <div class="card-body d-flex flex-column px-3 py-3">
                                    <h5 class="card-title text-truncate"><?= sanitize($courseTitle) ?></h5>
                                    <div class="d-flex align-items-center my-3">
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" 
                                                 style="width: <?= ($courseCompleted / $courseTotal) * 100 ?>%"></div>
                                        </div>
                                        <span class="ms-2 text-muted small"><?= $courseCompleted ?> av <?= $courseTotal ?> lektioner klarade</span>
                                    </div>
                                    
                                    <?php if ($nextLessonInCourse): ?>
                                        <p class="card-text text-muted small mb-3">
                                            Nästa: <?= sanitize($nextLessonInCourse['title']) ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <!-- Accordion for lessons -->
                                    <div class="accordion accordion-flush mt-2 mb-3" id="courseAccordion<?= $courseData['id'] ?>">
                                        <div class="accordion-item border-0">
                                            <h2 class="accordion-header" id="heading<?= $courseData['id'] ?>">
                                                <button class="accordion-button collapsed p-2 bg-light" type="button" 
                                                        data-bs-toggle="collapse" data-bs-target="#collapse<?= $courseData['id'] ?>" 
                                                        aria-expanded="false" aria-controls="collapse<?= $courseData['id'] ?>">
                                                    Visa lektioner
                                                </button>
                                            </h2>
                                            <div id="collapse<?= $courseData['id'] ?>" class="accordion-collapse collapse" 
                                                 aria-labelledby="heading<?= $courseData['id'] ?>" data-bs-parent="#courseAccordion<?= $courseData['id'] ?>">
                                                <div class="accordion-body p-0">
                                                    <ul class="list-group list-group-flush">
                                                        <?php foreach ($courseLessons as $index => $lesson): 
                                                            $isCompleted = isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed';
                                                            $isCurrent = $nextLessonInCourse && $lesson['id'] == $nextLessonInCourse['id'];
                                                        ?>
                                                            <li class="list-group-item py-2 px-3 <?= $isCurrent ? 'bg-light' : '' ?>">
                                                                <a href="lesson.php?id=<?= $lesson['id'] ?>" class="text-decoration-none text-dark d-flex align-items-center">
                                                                    <?php if ($isCompleted): ?>
                                                                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                                                                    <?php elseif ($isCurrent): ?>
                                                                        <i class="bi bi-arrow-right-circle text-primary me-2" aria-hidden="true"></i>
                                                                    <?php else: ?>
                                                                        <i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>
                                                                    <?php endif; ?>
                                                                    <span><?= sanitize($lesson['title']) ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-auto">
                                        <?php if ($nextLessonInCourse): ?>
                                            <a href="lesson.php?id=<?= $nextLessonInCourse['id'] ?>" class="btn btn-primary btn-sm d-block w-100 mb-2">
                                                Fortsätt lära
                                            </a>
                                            <a href="abandon_course.php?course_id=<?= $firstLesson['course_id'] ?>" class="btn btn-outline-secondary btn-sm d-block w-100">
                                                <i class="bi bi-x-circle me-1"></i>Avsluta kurs
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-primary btn-sm d-block w-100" disabled>Kursen är klar!</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!$hasStartedCourses): ?>
                        <div class="alert alert-info mt-4 mx-2 mx-md-0">
                            Du har inte påbörjat några kurser än.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($orgCourses)): ?>
                        <hr class="my-4 border-light">

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2 class="h2 fs-5 fs-md-3 mb-0">Kurser</h2>
                        </div>

                        <!-- Search and tag filter -->
                        <div class="mb-4 mx-2 mx-md-0">
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
                            <div class="mt-2" id="activeTagFilters">
                                <!-- Active tag badges will be shown here -->
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mx-2 mx-md-0" id="orgCourseGrid">
                            <?php foreach ($orgCourses as $course):
                                // Get lessons for this course
                                $courseLessons = query("
                                    SELECT * FROM " . DB_DATABASE . ".lessons
                                    WHERE course_id = ?
                                    ORDER BY sort_order
                                ", [$course['id']]);

                                // Check if course is started
                                $courseStarted = false;
                                $courseCompleted = 0;
                                foreach ($courseLessons as $lesson) {
                                    if (isset($userProgress[$lesson['id']]) && $userProgress[$lesson['id']]['status'] === 'completed') {
                                        $courseStarted = true;
                                        $courseCompleted++;
                                    }
                                }

                                // Skip if already shown in "Mina kurser"
                                if ($courseStarted) {
                                    continue;
                                }

                                // Get tags for this course
                                $thisCourseTagIds = [];
                                $thisCourseTags = $courseTagsMap[$course['id']] ?? [];
                                foreach ($thisCourseTags as $t) {
                                    $thisCourseTagIds[] = $t['id'];
                                }
                            ?>
                            <div class="col" data-tags="<?= htmlspecialchars(implode(',', $thisCourseTagIds)) ?>">
                                <div class="card shadow-sm h-100">
                                    <?php if ($course['image_url']): ?>
                                        <img src="upload/<?= sanitize($course['image_url']) ?>" class="card-img-top course-image" alt="<?= sanitize($course['title']) ?>">
                                    <?php else: ?>
                                        <img src="images/placeholder.png" class="card-img-top course-image" alt="<?= sanitize($course['title']) ?>">
                                    <?php endif; ?>
                                    
                                    <div class="card-body d-flex flex-column px-3 py-3">
                                        <h5 class="card-title text-truncate"><?= sanitize($course['title']) ?></h5>
                                        <?php if (!empty($thisCourseTags)): ?>
                                        <div class="mb-2">
                                            <?php foreach ($thisCourseTags as $tag): ?>
                                            <span class="badge bg-primary me-1"><?= htmlspecialchars($tag['name']) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        <p class="card-text text-muted small mb-3">
                                            <?= count($courseLessons) ?> lektioner
                                        </p>
                                        <p class="card-text text-muted small">
                                            Skapad av: <?= sanitize($course['author_email']) ?>
                                        </p>

                                        <!-- Accordion for lessons -->
                                        <?php $orgCourseId = 'org_course_' . $course['id']; ?>
                                        <div class="accordion accordion-flush mt-2 mb-3" id="orgCourseAccordion<?= $orgCourseId ?>">
                                            <div class="accordion-item border-0">
                                                <h2 class="accordion-header" id="orgHeading<?= $orgCourseId ?>">
                                                    <button class="accordion-button collapsed p-2 bg-light" type="button" 
                                                            data-bs-toggle="collapse" data-bs-target="#orgCollapse<?= $orgCourseId ?>" 
                                                            aria-expanded="false" aria-controls="orgCollapse<?= $orgCourseId ?>">
                                                        Visa lektioner
                                                    </button>
                                                </h2>
                                                <div id="orgCollapse<?= $orgCourseId ?>" class="accordion-collapse collapse" 
                                                     aria-labelledby="orgHeading<?= $orgCourseId ?>" data-bs-parent="#orgCourseAccordion<?= $orgCourseId ?>">
                                                    <div class="accordion-body p-0">
                                                        <ul class="list-group list-group-flush">
                                                            <?php foreach ($courseLessons as $index => $lesson): ?>
                                                                <li class="list-group-item py-2 px-3">
                                                                    <a href="lesson.php?id=<?= $lesson['id'] ?>" class="text-decoration-none text-dark d-flex align-items-center">
                                                                        <i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>
                                                                        <span><?= sanitize($lesson['title']) ?></span>
                                                                    </a>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-auto">
                                            <a href="lesson.php?id=<?= $courseLessons[0]['id'] ?>" class="btn btn-outline-primary btn-sm d-block w-100">
                                                Börja kursen
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
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
            // Search and tag filter for organization courses
            const orgSearchInput = document.getElementById('orgCourseSearch');
            const orgTagFilter = document.getElementById('orgTagFilter');
            const orgCourseGrid = document.getElementById('orgCourseGrid');

            if (orgCourseGrid) {
                const orgCourseCards = orgCourseGrid.getElementsByClassName('col');
                const orgNoResultsAlert = document.createElement('div');
                orgNoResultsAlert.className = 'alert alert-info mt-4';
                orgNoResultsAlert.textContent = 'Inga kurser matchar din sökning.';
                orgNoResultsAlert.style.display = 'none';
                orgCourseGrid.parentNode.insertBefore(orgNoResultsAlert, orgCourseGrid.nextSibling);

                // Combined filter function for search and tags
                function filterOrgCourses() {
                    const searchTerm = orgSearchInput ? orgSearchInput.value.toLowerCase() : '';
                    const selectedTag = orgTagFilter ? orgTagFilter.value : '';
                    let visibleCount = 0;

                    Array.from(orgCourseCards).forEach(card => {
                        const title = card.querySelector('.card-title').textContent.toLowerCase();
                        const description = card.querySelector('.card-text')?.textContent.toLowerCase() || '';
                        const tags = card.getAttribute('data-tags') || '';
                        const tagIds = tags.split(',').filter(t => t);

                        // Check search match
                        const searchMatches = !searchTerm || title.includes(searchTerm) || description.includes(searchTerm);

                        // Check tag match
                        const tagMatches = !selectedTag || tagIds.includes(selectedTag);

                        const matches = searchMatches && tagMatches;
                        card.style.display = matches ? '' : 'none';
                        if (matches) visibleCount++;
                    });

                    orgNoResultsAlert.style.display = visibleCount === 0 ? '' : 'none';

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
        });
    </script>
<?php 
    // Inkludera footer
    require_once 'include/footer.php';
endif; ?>
