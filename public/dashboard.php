<?php
/**
 * Stimma - Personlig Dashboard
 *
 * Visar användarens framsteg, aktivitet, pågående kurser och diplom.
 * All statistik räknas fram från verklig data (tabellerna progress och
 * certificates) istället för att förlita sig på cache-tabeller som inte
 * underhålls konsekvent.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userId = (int)$user['id'];

// --------- Räkna fram statistik från faktisk data ---------

// Lektioner klara (progress.status='completed' för aktiva lektioner i aktiva kurser)
$lessonsCompleted = (int)(queryOne(
    "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".progress p
     JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
     JOIN " . DB_DATABASE . ".courses c ON c.id = l.course_id
     WHERE p.user_id = ? AND p.status = 'completed'",
    [$userId]
)['c'] ?? 0);

// Kurser slutförda (finns ett diplom)
$coursesCompleted = (int)(queryOne(
    "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".certificates WHERE user_id = ?",
    [$userId]
)['c'] ?? 0);

// Pågående kurser: kurser där användaren klarat minst en lektion men inte alla
$courseProgress = query("
    SELECT c.id, c.title, c.image_url,
           COUNT(DISTINCT l.id) AS total_lessons,
           COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN l.id END) AS completed_lessons,
           MAX(p.updated_at) AS last_activity
    FROM " . DB_DATABASE . ".courses c
    JOIN " . DB_DATABASE . ".lessons l ON l.course_id = c.id
    LEFT JOIN " . DB_DATABASE . ".progress p ON p.lesson_id = l.id AND p.user_id = ?
    WHERE c.status = 'active'
    GROUP BY c.id, c.title, c.image_url
    HAVING completed_lessons > 0
    ORDER BY last_activity DESC
    LIMIT 6
", [$userId]);

$coursesInProgress = 0;
foreach ($courseProgress as $cp) {
    if ((int)$cp['completed_lessons'] > 0 && (int)$cp['completed_lessons'] < (int)$cp['total_lessons']) {
        $coursesInProgress++;
    }
}

// Aktivitet senaste 30 dagarna (en rad per datum)
$activityRaw = query(
    "SELECT DATE(p.updated_at) AS d, COUNT(*) AS n
     FROM " . DB_DATABASE . ".progress p
     WHERE p.user_id = ? AND p.status = 'completed'
       AND p.updated_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(p.updated_at)",
    [$userId]
);
$activityMap = [];
foreach ($activityRaw as $r) $activityMap[$r['d']] = (int)$r['n'];

// Bygg 30-dagars-array (senaste till vänster)
$activityHistory = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $activityHistory[] = ['date' => $date, 'lessons' => $activityMap[$date] ?? 0];
}

// Streak: hur många dagar i rad bakåt från idag (eller igår om inget idag)
$streak = 0;
$cur = new DateTime('today');
// Om inget idag men något igår → börja streak från igår
if (!isset($activityMap[$cur->format('Y-m-d')]) && isset($activityMap[(new DateTime('yesterday'))->format('Y-m-d')])) {
    $cur->modify('-1 day');
}
while (!empty($activityMap[$cur->format('Y-m-d')])) {
    $streak++;
    $cur->modify('-1 day');
}

// Längsta streak någonsin (från full historik, inte bara 30 dagar)
$allActivityDays = query(
    "SELECT DATE(p.updated_at) AS d FROM " . DB_DATABASE . ".progress p
     WHERE p.user_id = ? AND p.status = 'completed'
     GROUP BY DATE(p.updated_at) ORDER BY d ASC",
    [$userId]
);
$longestStreak = 0;
$currentRun = 0;
$prevDate = null;
foreach ($allActivityDays as $row) {
    $d = new DateTime($row['d']);
    if ($prevDate && (int)$prevDate->diff($d)->days === 1) {
        $currentRun++;
    } else {
        $currentRun = 1;
    }
    if ($currentRun > $longestStreak) $longestStreak = $currentRun;
    $prevDate = $d;
}

// XP-formel: 10 XP per slutförd lektion (enkel och förutsägbar)
$xp = $lessonsCompleted * 10;
$level = 1;
$xpForNext = 100;
$xpInLevel = $xp;
while ($xpInLevel >= $xpForNext) {
    $xpInLevel -= $xpForNext;
    $level++;
    $xpForNext = 100 + ($level - 1) * 50;
}
$xpPercent = $xpForNext > 0 ? round(($xpInLevel / $xpForNext) * 100) : 0;

// Diplom — visa bara de där användaren fortfarande har tillgång till kursen
$certificates = query(
    "SELECT * FROM " . DB_DATABASE . ".certificates WHERE user_id = ? ORDER BY completion_date DESC",
    [$userId]
);
$certificates = array_values(array_filter($certificates, function ($c) use ($userId) {
    return userCanAccessCourse((int)$userId, (int)$c['course_id']);
}));

// Datum idag (svensk formatering)
$monthNames = ['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'];
$today = date('j') . ' ' . $monthNames[date('n') - 1] . ' ' . date('Y');

// Peak för heatmap-skalning
$maxLessonsInDay = max(array_column($activityHistory, 'lessons') + [0]);

// Konfiguration för app-shell (sidebar/header)
$page_title = 'Min dashboard — Stimma';
$rdActive = 'dashboard';
require_once 'include/header.php';

// Hälsning + förnamn (samma logik som index.php)
$rdHour = (int)date('G');
$rdGreeting = $rdHour < 5  ? 'God natt'
            : ($rdHour < 10 ? 'God morgon'
            : ($rdHour < 13 ? 'Hej'
            : ($rdHour < 18 ? 'God eftermiddag'
            : 'God kväll')));
$rdEmail = $user['email'] ?? $_SESSION['user_email'] ?? '';
$rdFirstName = !empty($user['name']) ? strtok($user['name'], ' ')
             : (!empty($rdEmail) ? strstr($rdEmail, '@', true) : 'användare');

$rdOrgName = $headerOrganization['name'] ?? $userDomain;
$rdOrgInitials = '';
foreach (preg_split('/\s+/', trim($rdOrgName), -1, PREG_SPLIT_NO_EMPTY) as $part) {
    $rdOrgInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($rdOrgInitials) >= 2) break;
}
if ($rdOrgInitials === '') $rdOrgInitials = mb_strtoupper(mb_substr($rdOrgName, 0, 2));
?>

<!-- Dashboard-specifika stilar (panel, heatmap, course-row m.m.) — kvar
     inline efter att header.php redan satt <head> på sidan. -->
<style>
        body { background: #f6f7fb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .dash-header { background: #fff; border-bottom: 1px solid #e9ecef; padding: 0.85rem 0; }
        .dash-header h1 { color: #212529; }
        .dash-header small { color: #6c757d !important; }
        .dash-header .avatar { width: 40px; height: 40px; border-radius: 50%; background: #f1f3f5; color: #6c757d; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; border: 1px solid #e9ecef; }
        .stat-card { background: white; border: 1px solid #e9ecef; border-radius: 10px; padding: 0.85rem 1rem; height: 100%; transition: box-shadow .15s; }
        .stat-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,.06); }
        .stat-icon { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; color: white; flex-shrink: 0; }
        .stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: 0.78rem; color: #6c757d; line-height: 1.2; }
        .stat-card.hero { color: white; }
        .stat-card.hero .stat-label { color: rgba(255,255,255,.8); }
        .stat-card.hero .stat-icon { background: rgba(255,255,255,.2); }
        .panel { background: white; border: 1px solid #e9ecef; border-radius: 10px; padding: 1rem 1.1rem; margin-bottom: 1rem; }
        .panel h2 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: #343a40; display: flex; align-items: center; gap: 0.4rem; }
        .heatmap { display: grid; grid-template-columns: repeat(30, 1fr); gap: 3px; }
        .heatmap .cell { aspect-ratio: 1; border-radius: 3px; background: #edf0f4; }
        .heatmap .cell.lv1 { background: #cfe3c4; }
        .heatmap .cell.lv2 { background: #9ecf83; }
        .heatmap .cell.lv3 { background: #68b450; }
        .heatmap .cell.lv4 { background: #2e7d32; }
        .heatmap-legend { display: flex; align-items: center; gap: 3px; font-size: 0.72rem; color: #6c757d; justify-content: flex-end; margin-top: 0.35rem; }
        .heatmap-legend .cell { width: 10px; height: 10px; border-radius: 2px; }
        .course-row { display: flex; align-items: center; gap: 0.65rem; padding: 0.5rem 0; border-bottom: 1px solid #f1f3f5; }
        .course-row:last-child { border-bottom: none; }
        .course-thumb { width: 40px; height: 40px; border-radius: 6px; background: #f1f3f5 center/cover no-repeat; flex-shrink: 0; }
        .course-row .title { font-size: 0.88rem; font-weight: 500; margin: 0; }
        .course-row .bar { height: 5px; background: #e9ecef; border-radius: 3px; overflow: hidden; margin-top: 3px; }
        .course-row .bar > div { height: 100%; background: #0d6efd; border-radius: 3px; }
        .course-row .bar > div.done { background: #198754; }
        .course-row .pct { font-size: 0.74rem; color: #6c757d; min-width: 52px; text-align: right; }
        .cert-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0; font-size: 0.85rem; border-bottom: 1px solid #f1f3f5; }
        .cert-row:last-child { border-bottom: none; }
        .cert-row i { color: #ffc107; }
        .kv { display: flex; justify-content: space-between; padding: 0.35rem 0; font-size: 0.85rem; border-bottom: 1px solid #f1f3f5; }
        .kv:last-child { border-bottom: none; }
        .kv .k { color: #6c757d; }
        .kv .v { font-weight: 600; }
        .back-btn { position: fixed; top: 0.75rem; left: 0.75rem; z-index: 1040; }
        @media (max-width: 768px) {
            .heatmap { grid-template-columns: repeat(15, 1fr); }
        }
    </style>

<!-- Org-bar -->
<div class="rd-org-bar">
    <div class="rd-org-info">
        <div class="rd-org-logo" aria-hidden="true">
            <?php if (!empty($headerOrgIcon['url'])): ?>
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

<!-- Page-head -->
<header class="rd-page-head">
    <div>
        <div class="rd-greeting"><?= htmlspecialchars($rdGreeting . ($rdFirstName !== '' ? ', ' . $rdFirstName : '')) ?></div>
        <h1 class="rd-hero">Min dashboard</h1>
        <small class="text-muted"><?= $today ?></small>
    </div>
</header>

<main class="container py-3" style="padding-left: 0; padding-right: 0;">

    <!-- Top stats row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-card hero" style="background: linear-gradient(135deg, #ff9a56, #ff6b6b);">
                <div class="d-flex align-items-center gap-2">
                    <div class="stat-icon"><i class="bi bi-fire"></i></div>
                    <div>
                        <div class="stat-value"><?= $streak ?></div>
                        <div class="stat-label">Dagar i rad</div>
                    </div>
                </div>
                <?php if ($longestStreak > $streak): ?>
                <div class="stat-label mt-1" style="color: rgba(255,255,255,.85);"><i class="bi bi-trophy"></i> Rekord: <?= $longestStreak ?> d</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card hero" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <div class="stat-icon"><i class="bi bi-star-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= $level ?></div>
                        <div class="stat-label">Nivå</div>
                    </div>
                </div>
                <div class="progress" style="height: 5px; background: rgba(255,255,255,.2);">
                    <div class="progress-bar" role="progressbar" style="width: <?= $xpPercent ?>%; background: rgba(255,255,255,.85);"></div>
                </div>
                <div class="stat-label" style="color: rgba(255,255,255,.85); margin-top: 3px;"><?= $xpInLevel ?>/<?= $xpForNext ?> XP</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-2">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #11998e, #38ef7d);"><i class="bi bi-check-circle-fill"></i></div>
                    <div>
                        <div class="stat-value text-dark"><?= $lessonsCompleted ?></div>
                        <div class="stat-label">Lektioner klara</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-2">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);"><i class="bi bi-mortarboard-fill"></i></div>
                    <div>
                        <div class="stat-value text-dark"><?= $coursesCompleted ?></div>
                        <div class="stat-label">Kurser klara</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Vänster: aktivitet + kurser -->
        <div class="col-lg-8">
            <div class="panel">
                <h2><i class="bi bi-calendar3 text-primary"></i>Aktivitet senaste 30 dagarna</h2>
                <div class="heatmap">
                    <?php foreach ($activityHistory as $day):
                        $n = $day['lessons'];
                        $cls = '';
                        if ($n > 0) {
                            if ($maxLessonsInDay >= 5 && $n >= 5) $cls = 'lv4';
                            elseif ($n >= 3) $cls = 'lv3';
                            elseif ($n >= 2) $cls = 'lv2';
                            else $cls = 'lv1';
                        }
                    ?>
                    <div class="cell <?= $cls ?>" title="<?= htmlspecialchars($day['date']) ?>: <?= $n ?> lektion<?= $n === 1 ? '' : 'er' ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="heatmap-legend">
                    <span>Mindre</span>
                    <div class="cell"></div><div class="cell lv1"></div><div class="cell lv2"></div><div class="cell lv3"></div><div class="cell lv4"></div>
                    <span>Mer</span>
                </div>
            </div>

            <div class="panel">
                <h2><i class="bi bi-journal-text text-success"></i>Pågående kurser</h2>
                <?php if (empty($courseProgress)): ?>
                    <p class="text-muted small mb-0">Du har inte börjat på någon kurs ännu. <a href="index.php">Utforska kurskatalogen →</a></p>
                <?php else: ?>
                    <?php foreach ($courseProgress as $c):
                        $pct = (int)$c['total_lessons'] > 0 ? round((int)$c['completed_lessons'] / (int)$c['total_lessons'] * 100) : 0;
                        $done = $pct >= 100;
                    ?>
                    <div class="course-row">
                        <?php if (!empty($c['image_url'])): ?>
                        <div class="course-thumb" style="background-image: url('upload/<?= htmlspecialchars($c['image_url']) ?>');"></div>
                        <?php else: ?>
                        <div class="course-thumb d-flex align-items-center justify-content-center"><i class="bi bi-book text-muted"></i></div>
                        <?php endif; ?>
                        <div class="flex-grow-1 min-width-0">
                            <p class="title text-truncate"><?= htmlspecialchars($c['title']) ?></p>
                            <div class="bar"><div class="<?= $done ? 'done' : '' ?>" style="width: <?= $pct ?>%;"></div></div>
                        </div>
                        <div class="pct"><?= (int)$c['completed_lessons'] ?>/<?= (int)$c['total_lessons'] ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Höger: diplom + snabbstats -->
        <div class="col-lg-4">
            <div class="panel">
                <h2><i class="bi bi-patch-check text-success"></i>Diplom</h2>
                <?php if (empty($certificates)): ?>
                    <p class="text-muted small mb-0">Slutför en kurs för att få ditt första diplom!</p>
                <?php else: ?>
                    <?php foreach (array_slice($certificates, 0, 5) as $cert): ?>
                    <div class="cert-row">
                        <i class="bi bi-award"></i>
                        <div class="flex-grow-1 text-truncate">
                            <?= htmlspecialchars($cert['course_title']) ?>
                            <span class="text-muted small ms-1">#<?= (int)$cert['course_id'] ?></span>
                        </div>
                        <a href="certificate.php?id=<?= urlencode($cert['certificate_number']) ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank" title="Visa diplom">
                            <i class="bi bi-eye"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($certificates) > 5): ?>
                    <a href="certificate.php" class="btn btn-outline-primary btn-sm w-100 mt-2">Visa alla <?= count($certificates) ?> diplom</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2><i class="bi bi-bar-chart text-primary"></i>Översikt</h2>
                <div class="kv"><span class="k">Pågående kurser</span><span class="v"><?= $coursesInProgress ?></span></div>
                <div class="kv"><span class="k">Avklarade kurser</span><span class="v"><?= $coursesCompleted ?></span></div>
                <div class="kv"><span class="k">Lektioner klara</span><span class="v"><?= $lessonsCompleted ?></span></div>
                <div class="kv"><span class="k">Längsta streak</span><span class="v"><?= $longestStreak ?> dagar</span></div>
                <div class="kv"><span class="k">Totalt XP</span><span class="v"><?= number_format($xp) ?></span></div>
                <?php
                    // Äldre konton (skapade innan created_at-kolumnen lades till)
                    // kan ha NULL — visa "—" istället för att krascha eller skriva 1970-01-01.
                    $memberSince = !empty($user['created_at'])
                        ? date('Y-m-d', strtotime($user['created_at']))
                        : '—';
                ?>
                <div class="kv"><span class="k">Medlem sedan</span><span class="v"><?= htmlspecialchars($memberSince) ?></span></div>
            </div>
        </div>
    </div>

</main>

<?php require_once 'include/footer.php'; ?>
