<?php
/**
 * Stimma - Lärvägar (deltagarvy)
 *
 * Visar de lärvägar användaren har tillgång till, med status per ingående
 * kurs och en samlad procent. Ordningen är en rekommendation — inget låses.
 *
 * All data hämtas via getLearningPathOverviewForUser() i
 * include/learning_paths.php (sex queries totalt, oavsett antal lärvägar).
 * Kurser användaren saknar åtkomst till filtreras bort där och räknas inte i
 * procenten.
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/learning_paths.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userId = (int)$user['id'];

$learningPaths = getLearningPathOverviewForUser($userId);

$page_title = 'Lärvägar — Stimma';
$rdActive = 'paths';
require_once 'include/header.php';
?>

<div class="rd-page-head">
    <h1 class="rd-hero">Lärvägar</h1>
    <p class="rd-greeting">
        En lärväg samlar flera kurser som hör ihop. Ordningen är en rekommendation —
        du kan ta kurserna i vilken ordning du vill.
    </p>
</div>

<?php if (empty($learningPaths)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        Inga lärvägar är tillgängliga för dig just nu.
    </div>
<?php else: ?>
    <?php foreach ($learningPaths as $lpIdx => $lp): ?>
        <section class="rd-section <?= $lpIdx > 0 ? 'mt-5' : '' ?>" id="larvag-<?= (int)$lp['id'] ?>">
            <div class="rd-section-head">
                <h2><?= sanitize($lp['title']) ?></h2>
                <span class="rd-section-meta">
                    <?= (int)$lp['completed_count'] ?> av <?= (int)$lp['total_count'] ?> kurser klara
                    · <?= (int)$lp['path_percent'] ?> %
                </span>
            </div>

            <?php if (!empty($lp['description'])): ?>
                <p style="font-size:13px; color:var(--rd-text-secondary); margin-bottom:4px;">
                    <?= nl2br(sanitize($lp['description'])) ?>
                </p>
            <?php endif; ?>

            <div class="rd-progress" role="progressbar"
                 aria-valuenow="<?= (int)$lp['path_percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                 aria-label="Framsteg i lärvägen <?= sanitize($lp['title']) ?>">
                <div class="rd-progress-fill" style="width: <?= (int)$lp['path_percent'] ?>%;"></div>
            </div>

            <div class="row row-cols-1 g-3 mx-0 mt-1">
                <?php foreach ($lp['courses'] as $cIdx => $course): ?>
                    <?php
                        $rdIconColors = ['purple', 'teal', 'amber'];
                        $rdIconColor = $rdIconColors[$cIdx % 3];
                        $meta = learningPathStatusMeta($course['status']);
                    ?>
                    <div class="col">
                        <div class="rd-course">
                            <div class="rd-course-top">
                                <div class="rd-course-info">
                                    <div class="rd-course-icon <?= $rdIconColor ?>" aria-hidden="true">
                                        <?= (int)$course['step'] ?>
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="rd-course-meta">
                                            <?= (int)$course['lesson_count'] ?> lektioner<?= $course['sequential_mode'] ? ' · stegvis' : '' ?>
                                            <?php if ($course['status'] !== 'not_started'): ?>
                                                · <span class="<?= $meta['class'] ?>">
                                                    <i class="bi <?= $meta['icon'] ?>"></i> <?= $meta['label'] ?><?php
                                                        if ($course['status'] === 'completed' && !empty($course['completion_date'])) {
                                                            echo ' ' . sanitize($course['completion_date']);
                                                        }
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rd-course-title text-truncate" title="<?= sanitize($course['title']) ?>">
                                            <?= sanitize($course['title']) ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="rd-course-percent"><?= (int)$course['percent'] ?> %</span>
                            </div>

                            <div class="rd-progress" role="progressbar"
                                 aria-valuenow="<?= (int)$course['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                                 aria-label="Kursprogress">
                                <div class="rd-progress-fill" style="width: <?= (int)$course['percent'] ?>%;"></div>
                            </div>

                            <div class="rd-actions">
                                <?php if ($course['status'] === 'completed'): ?>
                                    <?php if (!empty($course['certificate_number'])): ?>
                                        <a href="certificate.php?id=<?= urlencode($course['certificate_number']) ?>" class="rd-btn rd-btn-primary">
                                            <i class="bi bi-award me-1"></i>Visa diplom
                                        </a>
                                    <?php endif; ?>
                                    <a href="course.php?id=<?= (int)$course['course_id'] ?>" class="rd-btn">Gå igenom igen</a>
                                <?php elseif ($course['status'] === 'in_progress'): ?>
                                    <a href="course.php?id=<?= (int)$course['course_id'] ?>" class="rd-btn rd-btn-primary">Fortsätt</a>
                                <?php else: ?>
                                    <a href="course.php?id=<?= (int)$course['course_id'] ?>" class="rd-btn rd-btn-primary">Börja kursen</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'include/footer.php'; ?>
