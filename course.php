<?php
/**
 * Stimma - Kursens landningssida
 *
 * Publik landningssida per kurs: visar titel, beskrivning, bild, lektionslista
 * och en "Börja/Fortsätt kursen"-knapp som tar användaren direkt till rätt
 * lektion beroende på hens progress.
 *
 * - Om användaren inte är inloggad och kursen är publik: "Anmäl dig"-knapp
 *   till public_register.php.
 * - Om användaren inte är inloggad och kursen är intern: "Logga in"-knapp
 *   till /index.php med redirect-state.
 * - Om användaren är inloggad och redan inskriven: "Fortsätt kursen" →
 *   första lektionen som inte är avklarad (respekterar sequential mode).
 * - Om användaren är inloggad men inte har någon progress: "Börja kursen" →
 *   första lektionen.
 *
 * Landningssidan räknas INTE som ett lektionssteg — den blockerar inte
 * stegvis progression.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

$courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$course = queryOne(
    "SELECT c.*, u.email AS author_email
     FROM " . DB_DATABASE . ".courses c
     LEFT JOIN " . DB_DATABASE . ".users u ON u.id = c.author_id
     WHERE c.id = ? LIMIT 1",
    [$courseId]
);
if (!$course) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="sv"><head><meta charset="UTF-8"><title>Kursen hittades inte</title></head><body><div style="font-family: sans-serif; padding: 40px; text-align: center;"><h1>Kursen hittades inte</h1><p>Länken kan vara felaktig eller kursen borttagen.</p><a href="/">Till Stimma</a></div></body></html>';
    exit;
}

$lessons = query(
    "SELECT id, title, sort_order, status FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND status = 'active' ORDER BY sort_order ASC, id ASC",
    [$courseId]
);

$loggedIn = isLoggedIn();
$userId = $loggedIn ? (int)$_SESSION['user_id'] : 0;
$userEmail = $loggedIn ? $_SESSION['user_email'] : '';

// Åtkomstbestämning
$canAccess = false;
$accessReason = '';
$isEnrolled = false;
if ($loggedIn) {
    $currentUser = queryOne(
        "SELECT access_mode FROM " . DB_DATABASE . ".users WHERE id = ?",
        [$userId]
    );
    $accessMode = $currentUser['access_mode'] ?? 'domain';

    if ($accessMode === 'public_only') {
        $canAccess = hasPublicCourseAccess($userId, $courseId);
        $accessReason = $canAccess ? 'public' : 'not_enrolled';
    } else {
        $orgScope = getOrgScopeDomains($userEmail);
        $inOwnOrg = in_array($course['organization_domain'], $orgScope, true);
        $hasPublic = hasPublicCourseAccess($userId, $courseId);

        // Respektera shared_domains-begränsningen
        if ($inOwnOrg) {
            $sharedDoms = getCourseSharedDomains($courseId);
            if (!empty($sharedDoms)) {
                $ud = getUserDomain($userEmail);
                if (!in_array($ud, $sharedDoms, true)) $inOwnOrg = false;
            }
        }
        $canAccess = $inOwnOrg || $hasPublic;
        $accessReason = $hasPublic ? 'public' : ($inOwnOrg ? 'org' : 'no_access');
    }

    // Kolla inskrivningsstatus
    if ($canAccess) {
        $enr = queryOne(
            "SELECT 1 FROM " . DB_DATABASE . ".course_enrollments WHERE user_id = ? AND course_id = ? LIMIT 1",
            [$userId, $courseId]
        );
        $isEnrolled = (bool)$enr;
    }
}

// Hitta nästa lektion baserat på progress
$nextLessonId = null;
$completedCount = 0;
if ($loggedIn && $canAccess && !empty($lessons)) {
    $progressRows = query(
        "SELECT lesson_id, status FROM " . DB_DATABASE . ".progress WHERE user_id = ?",
        [$userId]
    );
    $progressByLesson = [];
    foreach ($progressRows as $p) { $progressByLesson[(int)$p['lesson_id']] = $p['status']; }

    foreach ($lessons as $l) {
        if (($progressByLesson[(int)$l['id']] ?? '') === 'completed') {
            $completedCount++;
            continue;
        }
        $nextLessonId = (int)$l['id'];
        break;
    }
    // Om alla är klara, peka på sista lektionen (repetition/visa diplom)
    if ($nextLessonId === null && !empty($lessons)) {
        $nextLessonId = (int)$lessons[count($lessons) - 1]['id'];
    }
}

// Visa publik/stegvis-metadata
$totalLessons = count($lessons);
$isSequential = !empty($course['sequential_mode']);
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$page_title = $course['title'] . ' — ' . $systemName;

// Hämta ev. orgikon och headertext för den loggade användaren (eller
// kursens org som fallback för icke-inloggade)
$headerOrgIcon = null;
$headerText = null;
if ($loggedIn) {
    $headerOrgIcon = getHeaderOrganizationIcon($userId);
    $headerText = getHeaderText($userId);
} else {
    $courseOrg = !empty($course['organization_domain'])
        ? getOrganizationByDomain($course['organization_domain'])
        : null;
    if ($courseOrg) {
        if (!empty($courseOrg['icon_url'])) {
            $headerOrgIcon = ['url' => $courseOrg['icon_url'], 'name' => $courseOrg['name'] ?? ''];
        }
        if (!empty($courseOrg['header_text'])) {
            $headerText = str_replace(
                ['{{domain}}', '{{organization}}', '{{date}}'],
                ['', htmlspecialchars($courseOrg['name'] ?? ''), date('j') . ' ' . ['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'][(int)date('n')-1] . ' ' . date('Y')],
                htmlspecialchars($courseOrg['header_text'])
            );
        }
    }
    if (!$headerText) $headerText = 'Stimma - en utbildningsplattform från Sambruk';
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="include/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .course-hero { background: white; border-bottom: 1px solid #e9ecef; padding: 0 0 2rem 0; }
        .course-hero-image { width: 100%; max-height: 340px; object-fit: cover; }
        .lesson-list-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f3f5; display: flex; align-items: center; gap: 0.75rem; }
        .lesson-list-item:last-child { border-bottom: none; }
        .lesson-number { width: 32px; height: 32px; border-radius: 50%; background: #e9ecef; color: #495057; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; flex-shrink: 0; }
        .lesson-number.done { background: #198754; color: white; }
    </style>
</head>
<body>

<nav class="navbar navbar-light bg-white shadow-sm py-2">
    <div class="container-fluid">
        <a href="/" class="navbar-brand d-flex align-items-center">
            <img src="images/stimma-logo.png" height="48" alt="Stimma">
        </a>
        <span class="d-none d-md-inline text-muted" style="font-size: 0.95rem;"><?= $headerText ?></span>
        <?php if ($headerOrgIcon): ?>
        <span class="d-inline-flex align-items-center" title="<?= htmlspecialchars($headerOrgIcon['name']) ?>">
            <img src="upload/org_icons/<?= htmlspecialchars($headerOrgIcon['url']) ?>" alt="<?= htmlspecialchars($headerOrgIcon['name']) ?>" style="max-height: 48px; max-width: 120px; object-fit: contain;">
        </span>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
    </div>
</nav>

<header class="course-hero">
    <?php if (!empty($course['image_url'])): ?>
    <img src="upload/<?= htmlspecialchars($course['image_url']) ?>" alt="<?= htmlspecialchars($course['title']) ?>" class="course-hero-image">
    <?php endif; ?>
    <div class="container py-4">
        <div class="row g-4 align-items-start">
            <div class="col-lg-8">
                <h1 class="display-6 mb-2"><?= htmlspecialchars($course['title']) ?></h1>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if ($isSequential): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-list-ol me-1"></i>Stegvis kurs</span>
                    <?php endif; ?>
                    <?php if (!empty($course['is_public'])): ?>
                    <span class="badge bg-primary"><i class="bi bi-globe me-1"></i>Öppen kurs</span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark border"><i class="bi bi-journal-text me-1"></i><?= $totalLessons ?> lektion<?= $totalLessons === 1 ? '' : 'er' ?></span>
                </div>
                <?php if (!empty($course['description'])): ?>
                <p class="lead text-muted"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if (!$loggedIn): ?>
                            <?php if (!empty($course['is_public']) && !empty($course['public_registration_token'])): ?>
                                <h5 class="card-title">Anmäl dig till kursen</h5>
                                <p class="small text-muted">Öppen för alla — registrera dig med din e-post.</p>
                                <a href="public_register.php?course_id=<?= (int)$course['id'] ?>&token=<?= htmlspecialchars($course['public_registration_token']) ?>" class="btn btn-primary w-100">
                                    <i class="bi bi-envelope me-1"></i>Anmäl dig här
                                </a>
                            <?php else: ?>
                                <h5 class="card-title">Logga in för att börja</h5>
                                <p class="small text-muted">Du behöver vara inloggad för att gå kursen.</p>
                                <?php
                                $_SESSION['redirect_after_login'] = 'course.php?id=' . (int)$course['id'];
                                ?>
                                <a href="index.php" class="btn btn-primary w-100">
                                    <i class="bi bi-box-arrow-in-right me-1"></i>Logga in
                                </a>
                            <?php endif; ?>
                        <?php elseif (!$canAccess): ?>
                            <h5 class="card-title text-danger">Du har inte tillgång</h5>
                            <p class="small text-muted">Den här kursen är begränsad till andra användare.</p>
                            <?php if (!empty($course['is_public']) && !empty($course['public_registration_token'])): ?>
                            <a href="public_register.php?course_id=<?= (int)$course['id'] ?>&token=<?= htmlspecialchars($course['public_registration_token']) ?>" class="btn btn-primary w-100">
                                <i class="bi bi-envelope me-1"></i>Anmäl dig via öppen länk
                            </a>
                            <?php else: ?>
                            <a href="index.php" class="btn btn-outline-secondary w-100">Till startsidan</a>
                            <?php endif; ?>
                        <?php elseif ($nextLessonId && $completedCount > 0 && $completedCount < $totalLessons): ?>
                            <h5 class="card-title">Fortsätt kursen</h5>
                            <p class="small text-muted"><?= $completedCount ?> av <?= $totalLessons ?> lektioner klara.</p>
                            <div class="progress mb-3" style="height: 6px;">
                                <div class="progress-bar bg-success" style="width: <?= $totalLessons > 0 ? round($completedCount / $totalLessons * 100) : 0 ?>%"></div>
                            </div>
                            <a href="lesson.php?id=<?= $nextLessonId ?>" class="btn btn-success w-100">
                                <i class="bi bi-play-fill me-1"></i>Fortsätt
                            </a>
                        <?php elseif ($completedCount >= $totalLessons && $totalLessons > 0): ?>
                            <h5 class="card-title text-success"><i class="bi bi-trophy-fill me-1"></i>Kursen är klar!</h5>
                            <p class="small text-muted">Du har slutfört alla lektioner.</p>
                            <a href="certificate.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-award me-1"></i>Se dina diplom
                            </a>
                            <a href="lesson.php?id=<?= $nextLessonId ?>" class="btn btn-outline-secondary w-100 mt-2">
                                <i class="bi bi-arrow-repeat me-1"></i>Gå om en lektion
                            </a>
                        <?php else: ?>
                            <h5 class="card-title">Börja kursen</h5>
                            <p class="small text-muted"><?= $totalLessons ?> lektion<?= $totalLessons === 1 ? '' : 'er' ?>.</p>
                            <?php if (!empty($lessons)): ?>
                            <a href="lesson.php?id=<?= (int)$lessons[0]['id'] ?>" class="btn btn-primary w-100">
                                <i class="bi bi-play-fill me-1"></i>Börja kursen
                            </a>
                            <?php else: ?>
                            <p class="text-muted small mb-0">Kursen har inga publicerade lektioner ännu.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="container py-4">
    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0"><i class="bi bi-list-ol me-2 text-primary"></i>Innehåll</h2>
        </div>
        <div class="card-body p-0">
            <?php if (empty($lessons)): ?>
                <p class="p-3 text-muted mb-0">Kursen har inga publicerade lektioner ännu.</p>
            <?php else: ?>
                <?php foreach ($lessons as $idx => $l):
                    $isDone = false;
                    if ($loggedIn) {
                        $pr = queryOne(
                            "SELECT status FROM " . DB_DATABASE . ".progress WHERE user_id = ? AND lesson_id = ?",
                            [$userId, $l['id']]
                        );
                        $isDone = $pr && $pr['status'] === 'completed';
                    }
                ?>
                <div class="lesson-list-item">
                    <div class="lesson-number <?= $isDone ? 'done' : '' ?>">
                        <?php if ($isDone): ?>
                            <i class="bi bi-check"></i>
                        <?php else: ?>
                            <?= $idx + 1 ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <?php if ($loggedIn && $canAccess): ?>
                            <a href="lesson.php?id=<?= (int)$l['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($l['title']) ?></a>
                        <?php else: ?>
                            <span class="text-dark"><?= htmlspecialchars($l['title']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-center mt-4 mb-5">
        <a href="/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Till Stimma</a>
    </div>
</main>

<footer class="border-top bg-white py-3 mt-4">
    <div class="container small text-muted text-center">
        © <?= date('Y') ?> <a href="https://stimma.se" class="text-muted">Stimma.se</a> —
        <a href="tillganglighet.php" class="text-muted">Tillgänglighetsredogörelse</a>
    </div>
</footer>

</body>
</html>
