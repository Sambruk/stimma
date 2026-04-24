<?php
/**
 * Stimma - Kursavslutssida
 *
 * Visas för en användare som slutfört samtliga aktiva lektioner i en kurs.
 * Visar redaktörens anpassade avslutsinnehåll (eller standardtext) plus en
 * knapp till diplomet.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/gamification.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$userId = (int)$_SESSION['user_id'];

$course = queryOne(
    "SELECT id, title, completion_content, certificate_image_url, image_url, organization_domain
     FROM " . DB_DATABASE . ".courses WHERE id = ? LIMIT 1",
    [$courseId]
);
if (!$course) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="sv"><head><meta charset="UTF-8"><title>Kursen hittades inte</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>Kursen hittades inte</h1><a href="/">Till startsidan</a></body></html>';
    exit;
}

// Verifiera att användaren verkligen slutfört kursen innan vi visar sidan.
$totalLessons = (int)queryOne(
    "SELECT COUNT(*) AS n FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND status = 'active'",
    [$courseId]
)['n'];

$completedLessons = (int)queryOne(
    "SELECT COUNT(*) AS n FROM " . DB_DATABASE . ".lessons l
     JOIN " . DB_DATABASE . ".progress p ON p.lesson_id = l.id
     WHERE l.course_id = ? AND l.status = 'active'
       AND p.user_id = ? AND p.status = 'completed'",
    [$courseId, $userId]
)['n'];

$isComplete = ($totalLessons > 0 && $completedLessons >= $totalLessons);

if (!$isComplete) {
    $_SESSION['flash_message'] = 'Du har inte slutfört alla lektioner i kursen ännu.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: course.php?id=' . $courseId);
    exit;
}

// Säkerställ att diplom finns (backfill: vid gamla slutföranden kan det saknas).
$cert = queryOne(
    "SELECT * FROM " . DB_DATABASE . ".certificates WHERE user_id = ? AND course_id = ? LIMIT 1",
    [$userId, $courseId]
);
if (!$cert) {
    $res = recordCourseCompletion($userId, $courseId);
    if (!empty($res['certificate_number'])) {
        $cert = queryOne(
            "SELECT * FROM " . DB_DATABASE . ".certificates WHERE certificate_number = ?",
            [$res['certificate_number']]
        );
    }
}

// Default-innehåll om redaktören inte angett något.
$defaultCompletion = '<h2>Grattis!</h2>'
    . '<p>Du har slutfört kursen <strong>' . htmlspecialchars($course['title']) . '</strong>.</p>'
    . '<p>Ditt diplom har skapats och finns nedan att spara eller skriva ut.</p>';

$completionHtml = !empty($course['completion_content'])
    ? $course['completion_content']
    : $defaultCompletion;

$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';
$page_title = 'Kursen avslutad — ' . $course['title'];

$headerOrgIcon = getHeaderOrganizationIcon($userId);
$headerText = getHeaderText($userId);
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
    <link href="admin/include/css/editor-content.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .completion-hero {
            background: linear-gradient(135deg, #198754 0%, #0b5d34 100%);
            color: white;
            padding: 3rem 0 2rem 0;
            text-align: center;
        }
        .completion-hero .trophy {
            font-size: 4rem;
            margin-bottom: 0.5rem;
        }
        .completion-card {
            background: white;
            border-radius: 10px;
            padding: 2rem 2.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .completion-card img { max-width: 100%; height: auto; }
        .diploma-box {
            background: #fff9e6;
            border: 2px solid #c9a227;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
        }
        @media (max-width: 600px) {
            .completion-card { padding: 1.25rem; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-light bg-white shadow-sm py-2">
    <div class="container-fluid">
        <a href="/" class="navbar-brand d-flex align-items-center">
            <img src="images/stimma-logo.png" height="48" alt="Stimma">
        </a>
        <span class="d-none d-md-inline text-muted" style="font-size: 0.95rem;"><?= $headerText ?: 'Stimma - en utbildningsplattform från Sambruk' ?></span>
        <?php if ($headerOrgIcon): ?>
        <span class="d-inline-flex align-items-center" title="<?= htmlspecialchars($headerOrgIcon['name']) ?>">
            <img src="upload/org_icons/<?= htmlspecialchars($headerOrgIcon['url']) ?>" alt="<?= htmlspecialchars($headerOrgIcon['name']) ?>" style="max-height: 48px; max-width: 120px; object-fit: contain;">
        </span>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
    </div>
</nav>

<section class="completion-hero">
    <div class="container">
        <div class="trophy"><i class="bi bi-trophy-fill"></i></div>
        <h1 class="display-6 mb-0">Kursen är avslutad</h1>
        <p class="lead mb-0 mt-2"><?= htmlspecialchars($course['title']) ?></p>
    </div>
</section>

<main class="container py-4">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="completion-card">
                <div class="lesson-content"><?= $completionHtml ?></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="diploma-box mb-3">
                <i class="bi bi-award-fill text-warning" style="font-size: 3rem;"></i>
                <h5 class="mt-2 mb-1">Ditt diplom är klart</h5>
                <?php if ($cert): ?>
                <p class="text-muted small mb-3">
                    <code><?= htmlspecialchars($cert['certificate_number']) ?></code>
                </p>
                <a href="certificate.php?id=<?= urlencode($cert['certificate_number']) ?>"
                   class="btn btn-warning w-100" target="_blank">
                    <i class="bi bi-award me-1"></i>Visa och skriv ut diplom
                </a>
                <?php else: ?>
                <p class="text-muted small">Diplomet skapas inom kort.</p>
                <?php endif; ?>
            </div>
            <a href="course.php?id=<?= (int)$course['id'] ?>" class="btn btn-outline-secondary w-100 mb-2">
                <i class="bi bi-arrow-left me-1"></i>Tillbaka till kursen
            </a>
            <a href="index.php" class="btn btn-outline-primary w-100">
                <i class="bi bi-house-door me-1"></i>Till startsidan
            </a>
        </div>
    </div>
</main>

</body>
</html>
