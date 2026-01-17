<?php
/**
 * Stimma - Personlig Dashboard
 *
 * Visar användarens framsteg, badges, streaks och statistik
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/gamification.php';

// Kräv inloggning
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Hämta användardata
$user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userId = $user['id'];

// Hämta dashboard-data
$dashboardData = getDashboardData($userId);
$stats = $dashboardData['stats'];
$levelInfo = calculateLevel((int)$stats['total_xp']);

// Hämta alla badges med status
$allBadges = getAllBadgesWithStatus($userId);

// Hämta certifikat
$certificates = getUserCertificates($userId);

// Svenska månadsnamn
$monthNames = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
$today = date('j') . ' ' . $monthNames[date('n') - 1] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Min Dashboard - Stimma</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        body {
            background: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .dashboard-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            border: 3px solid rgba(255,255,255,0.5);
        }

        .level-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            height: 100%;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }

        .streak-card {
            background: linear-gradient(135deg, #ff9a56 0%, #ff6b6b 100%);
            color: white;
        }

        .streak-card .stat-icon {
            background: rgba(255,255,255,0.2);
        }

        .xp-card {
            background: var(--primary-gradient);
            color: white;
        }

        .xp-card .stat-icon {
            background: rgba(255,255,255,0.2);
        }

        .progress-bar-xp {
            height: 8px;
            border-radius: 4px;
            background: rgba(255,255,255,0.2);
        }

        .progress-bar-xp .progress-bar {
            background: rgba(255,255,255,0.8);
        }

        .badge-card {
            text-align: center;
            padding: 1rem;
            border-radius: 12px;
            background: white;
            border: 1px solid #eee;
            transition: all 0.2s;
        }

        .badge-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .badge-card.earned {
            border-color: #ffc107;
        }

        .badge-card.locked {
            opacity: 0.5;
            filter: grayscale(1);
        }

        .badge-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 0.5rem;
        }

        .activity-grid {
            display: grid;
            grid-template-columns: repeat(14, 1fr);
            gap: 4px;
        }

        .activity-day {
            aspect-ratio: 1;
            border-radius: 3px;
            background: #ebedf0;
        }

        .activity-day.level-1 { background: #9be9a8; }
        .activity-day.level-2 { background: #40c463; }
        .activity-day.level-3 { background: #30a14e; }
        .activity-day.level-4 { background: #216e39; }

        .course-progress-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border: 1px solid #eee;
        }

        .course-progress-bar {
            height: 6px;
            border-radius: 3px;
            background: #eee;
        }

        .certificate-mini {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-back {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1000;
        }

        @media (max-width: 768px) {
            .activity-grid {
                grid-template-columns: repeat(7, 1fr);
            }
        }
    </style>
</head>
<body>
    <a href="index.php" class="nav-back btn btn-light shadow-sm">
        <i class="bi bi-arrow-left me-1"></i>Tillbaka
    </a>

    <!-- Header -->
    <div class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-auto">
                    <div class="user-avatar">
                        <i class="bi bi-person-fill"></i>
                    </div>
                </div>
                <div class="col">
                    <h1 class="h3 mb-1"><?= htmlspecialchars($user['name'] ?: $user['email']) ?></h1>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="level-badge">
                            <i class="bi bi-star-fill"></i>
                            Nivå <?= $levelInfo['level'] ?>
                        </span>
                        <span class="opacity-75"><?= $today ?></span>
                    </div>
                </div>
                <div class="col-auto text-end d-none d-md-block">
                    <div class="h4 mb-0"><?= number_format($stats['total_xp']) ?> XP</div>
                    <small class="opacity-75">Totalt intjänat</small>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <!-- Stats Row -->
        <div class="row g-4 mb-4">
            <!-- Streak -->
            <div class="col-6 col-lg-3">
                <div class="stat-card streak-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon">
                            <i class="bi bi-fire"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= (int)$stats['current_streak'] ?></div>
                            <div class="small opacity-75">Dagar i rad</div>
                        </div>
                    </div>
                    <?php if ((int)$stats['longest_streak'] > 0): ?>
                    <div class="mt-2 small opacity-75">
                        <i class="bi bi-trophy me-1"></i>Rekord: <?= (int)$stats['longest_streak'] ?> dagar
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- XP & Level -->
            <div class="col-6 col-lg-3">
                <div class="stat-card xp-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $levelInfo['level'] ?></div>
                            <div class="small opacity-75">Nivå</div>
                        </div>
                    </div>
                    <div class="progress-bar-xp progress">
                        <div class="progress-bar" style="width: <?= $levelInfo['progress_percent'] ?>%"></div>
                    </div>
                    <div class="mt-1 small opacity-75">
                        <?= $levelInfo['xp_in_level'] ?> / <?= $levelInfo['xp_for_next_level'] ?> XP till nästa nivå
                    </div>
                </div>
            </div>

            <!-- Lessons -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--success-gradient);">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= (int)$stats['lessons_completed'] ?></div>
                            <div class="small text-muted">Lektioner klara</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Courses -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--info-gradient);">
                            <i class="bi bi-mortarboard-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= (int)$stats['courses_completed'] ?></div>
                            <div class="small text-muted">Kurser klara</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Activity Heatmap -->
                <div class="stat-card mb-4">
                    <h5 class="section-title">
                        <i class="bi bi-calendar3 text-primary"></i>
                        Aktivitet senaste 14 dagarna
                    </h5>
                    <div class="activity-grid">
                        <?php foreach ($dashboardData['activity_history'] as $day):
                            $level = 0;
                            if ($day['lessons'] > 0) {
                                if ($day['lessons'] >= 5) $level = 4;
                                elseif ($day['lessons'] >= 3) $level = 3;
                                elseif ($day['lessons'] >= 2) $level = 2;
                                else $level = 1;
                            }
                        ?>
                        <div class="activity-day level-<?= $level ?>"
                             title="<?= date('Y-m-d', strtotime($day['date'])) ?>: <?= $day['lessons'] ?> lektioner, <?= $day['xp'] ?> XP">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-end gap-1 mt-2 small text-muted">
                        <span>Mindre</span>
                        <div class="activity-day" style="width: 12px; height: 12px;"></div>
                        <div class="activity-day level-1" style="width: 12px; height: 12px;"></div>
                        <div class="activity-day level-2" style="width: 12px; height: 12px;"></div>
                        <div class="activity-day level-3" style="width: 12px; height: 12px;"></div>
                        <div class="activity-day level-4" style="width: 12px; height: 12px;"></div>
                        <span>Mer</span>
                    </div>
                </div>

                <!-- Course Progress -->
                <div class="stat-card mb-4">
                    <h5 class="section-title">
                        <i class="bi bi-journal-text text-success"></i>
                        Pågående kurser
                    </h5>
                    <?php if (empty($dashboardData['course_progress'])): ?>
                    <p class="text-muted mb-0">Du har inte börjat på någon kurs ännu.</p>
                    <?php else: ?>
                    <?php foreach ($dashboardData['course_progress'] as $course):
                        $progress = $course['total_lessons'] > 0
                            ? round(($course['completed_lessons'] / $course['total_lessons']) * 100)
                            : 0;
                    ?>
                    <div class="course-progress-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong><?= htmlspecialchars($course['title']) ?></strong>
                            <span class="badge bg-<?= $progress == 100 ? 'success' : 'primary' ?>">
                                <?= $course['completed_lessons'] ?>/<?= $course['total_lessons'] ?>
                            </span>
                        </div>
                        <div class="course-progress-bar progress">
                            <div class="progress-bar bg-<?= $progress == 100 ? 'success' : 'primary' ?>"
                                 style="width: <?= $progress ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Badges -->
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title mb-0">
                            <i class="bi bi-award text-warning"></i>
                            Utmärkelser
                        </h5>
                        <span class="badge bg-primary"><?= $dashboardData['total_badges'] ?> av <?= count($allBadges) ?></span>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($allBadges as $badge): ?>
                        <div class="col-4 col-md-3 col-lg-2">
                            <div class="badge-card <?= $badge['earned'] ? 'earned' : 'locked' ?>">
                                <div class="badge-icon bg-<?= $badge['color'] ?> bg-opacity-10 text-<?= $badge['color'] ?>">
                                    <i class="<?= $badge['icon'] ?>"></i>
                                </div>
                                <div class="small fw-semibold"><?= htmlspecialchars($badge['name']) ?></div>
                                <?php if ($badge['earned']): ?>
                                <div class="text-muted" style="font-size: 0.7rem;">
                                    <?= date('Y-m-d', strtotime($badge['earned_at'])) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Recent Badges -->
                <?php if (!empty($dashboardData['recent_badges'])): ?>
                <div class="stat-card mb-4">
                    <h5 class="section-title">
                        <i class="bi bi-clock-history text-info"></i>
                        Senaste utmärkelser
                    </h5>
                    <?php foreach ($dashboardData['recent_badges'] as $badge): ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="badge-icon bg-<?= $badge['color'] ?> bg-opacity-10 text-<?= $badge['color'] ?>"
                             style="width: 36px; height: 36px; font-size: 1rem;">
                            <i class="<?= $badge['icon'] ?>"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small"><?= htmlspecialchars($badge['name']) ?></div>
                            <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($badge['description']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Certificates -->
                <div class="stat-card mb-4">
                    <h5 class="section-title">
                        <i class="bi bi-patch-check text-success"></i>
                        Certifikat
                    </h5>
                    <?php if (empty($certificates)): ?>
                    <p class="text-muted small mb-0">
                        Slutför en kurs för att få ditt första certifikat!
                    </p>
                    <?php else: ?>
                    <?php foreach (array_slice($certificates, 0, 3) as $cert): ?>
                    <div class="certificate-mini mb-2">
                        <i class="bi bi-award text-warning"></i>
                        <div class="fw-semibold small"><?= htmlspecialchars($cert['course_title']) ?></div>
                        <a href="certificate.php?id=<?= urlencode($cert['certificate_number']) ?>"
                           class="btn btn-sm btn-light mt-1" target="_blank">
                            <i class="bi bi-eye me-1"></i>Visa
                        </a>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($certificates) > 3): ?>
                    <a href="certificate.php" class="btn btn-outline-primary btn-sm w-100 mt-2">
                        Visa alla <?= count($certificates) ?> certifikat
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Quick Stats -->
                <div class="stat-card">
                    <h5 class="section-title">
                        <i class="bi bi-bar-chart text-primary"></i>
                        Snabbstatistik
                    </h5>
                    <ul class="list-unstyled mb-0">
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Quiz rätt</span>
                            <strong><?= (int)$stats['quizzes_passed'] ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Längsta streak</span>
                            <strong><?= (int)$stats['longest_streak'] ?> dagar</strong>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Totalt XP</span>
                            <strong><?= number_format($stats['total_xp']) ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-2">
                            <span class="text-muted">Medlem sedan</span>
                            <strong><?= date('Y-m-d', strtotime($user['created_at'])) ?></strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
        // Aktivera tooltips för aktivitetsrutor
        document.querySelectorAll('.activity-day').forEach(el => {
            el.style.cursor = 'pointer';
        });
    </script>
</body>
</html>
