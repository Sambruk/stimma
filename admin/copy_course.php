<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

// Kontrollera att användaren är inloggad och är admin/editor
require_once 'include/auth_check.php';

// Hämta användarens information
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = substr(strrchr($currentUser['email'], "@"), 1);
$isAdmin = $currentUser['is_admin'] == 1;
$isEditor = $currentUser['is_editor'] == 1;

// Kontrollera behörighet
if (!$isAdmin && !$isEditor) {
    $_SESSION['message'] = 'Du har inte behörighet att kopiera kurser.';
    $_SESSION['message_type'] = 'danger';
    header('Location: courses.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Kopiera kurs';

/**
 * Kopiera en kurs med alla dess lektioner till den egna organisationen
 */
function copyCourse($sourceCourseId, $targetDomain, $newAuthorId, $orgScopeDomains = null) {
    // Hämta källkursen
    $sourceCourse = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$sourceCourseId]);

    if (!$sourceCourse) {
        return ['success' => false, 'message' => 'Kursen kunde inte hittas.'];
    }

    // Skapa ny kurs med kopierad data
    $newTitle = $sourceCourse['title'] . ' (kopia)';

    // Kontrollera om en kurs med samma namn redan finns någonstans i orgens
    // samtliga domäner (annars kan vi få dubbletter mellan grupperade domäner)
    $scope = !empty($orgScopeDomains) ? $orgScopeDomains : [$targetDomain];
    $dupClause = buildDomainInClause($scope, 'organization_domain');
    $existingCourse = queryOne(
        "SELECT id FROM " . DB_DATABASE . ".courses WHERE title = ? AND {$dupClause['fragment']}",
        array_merge([$newTitle], $dupClause['params'])
    );

    if ($existingCourse) {
        $newTitle = $sourceCourse['title'] . ' (kopia ' . date('Y-m-d H:i') . ')';
    }

    // Bevara ursprungsorganisationen. Om källan redan är en kopia har den
    // ett original_organization_domain satt — det ska följa med vidare. Annars
    // är källans egen organisation den ursprungliga.
    $originalOrgDomain = !empty($sourceCourse['original_organization_domain'])
        ? $sourceCourse['original_organization_domain']
        : $sourceCourse['organization_domain'];

    // Infoga ny kurs
    $result = execute(
        "INSERT INTO " . DB_DATABASE . ".courses
        (category_id, title, description, completion_content, difficulty_level, duration_minutes, prerequisites, tags,
         image_url, status, sort_order, featured, author_id, organization_domain, original_organization_domain, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?, 0, ?, ?, ?, NOW())",
        [
            $sourceCourse['category_id'],
            $newTitle,
            $sourceCourse['description'],
            $sourceCourse['completion_content'] ?? null,
            $sourceCourse['difficulty_level'],
            $sourceCourse['duration_minutes'],
            $sourceCourse['prerequisites'],
            $sourceCourse['tags'],
            $sourceCourse['image_url'],
            $sourceCourse['sort_order'],
            $newAuthorId,
            $targetDomain,
            $originalOrgDomain
        ]
    );

    if (!$result) {
        return ['success' => false, 'message' => 'Kunde inte skapa kurskopian.'];
    }

    // Hämta det nya kurs-ID:t
    $newCourseId = queryOne("SELECT LAST_INSERT_ID() as id")['id'];

    // Kopiera alla lektioner
    $sourceLessons = query(
        "SELECT * FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC",
        [$sourceCourseId]
    );

    $lessonsCopied = 0;
    foreach ($sourceLessons as $lesson) {
        // Copy local video file with new random name if applicable
        $videoUrl = $lesson['video_url'];
        $videoType = $lesson['video_type'] ?? null;
        if ($videoType === 'local' && !empty($videoUrl)) {
            $srcVideoPath = realpath(__DIR__ . '/../upload/videos/') . '/' . basename($videoUrl);
            if (file_exists($srcVideoPath)) {
                $ext = strtolower(pathinfo($videoUrl, PATHINFO_EXTENSION));
                $newVideoFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                $destVideoPath = __DIR__ . '/../upload/videos/' . $newVideoFilename;
                if (copy($srcVideoPath, $destVideoPath)) {
                    chmod($destVideoPath, 0644);
                    $videoUrl = $newVideoFilename;
                }
            }
        }

        // Copy audio file with new random name if applicable
        $audioUrl = $lesson['audio_url'] ?? null;
        if (!empty($audioUrl)) {
            $srcAudioPath = realpath(__DIR__ . '/../upload/audio/') . '/' . basename($audioUrl);
            if (file_exists($srcAudioPath)) {
                $ext = strtolower(pathinfo($audioUrl, PATHINFO_EXTENSION));
                $newAudioFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                $destAudioPath = __DIR__ . '/../upload/audio/' . $newAudioFilename;
                if (copy($srcAudioPath, $destAudioPath)) {
                    chmod($destAudioPath, 0644);
                    $audioUrl = $newAudioFilename;
                }
            }
        }

        $lessonResult = execute(
            "INSERT INTO " . DB_DATABASE . ".lessons
            (course_id, title, estimated_duration, image_url, video_url, video_type, audio_url, content, resource_links, tags,
             status, sort_order, ai_instruction, ai_prompt, quiz_question, quiz_answer1, quiz_answer2,
             quiz_answer3, quiz_correct_answer, author_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $newCourseId,
                $lesson['title'],
                $lesson['estimated_duration'],
                $lesson['image_url'],
                $videoUrl,
                $videoType,
                $audioUrl,
                $lesson['content'],
                $lesson['resource_links'],
                $lesson['tags'],
                $lesson['status'],
                $lesson['sort_order'],
                $lesson['ai_instruction'],
                $lesson['ai_prompt'],
                $lesson['quiz_question'],
                $lesson['quiz_answer1'],
                $lesson['quiz_answer2'],
                $lesson['quiz_answer3'],
                $lesson['quiz_correct_answer'],
                $newAuthorId
            ]
        );

        if ($lessonResult) {
            $lessonsCopied++;
        }
    }

    // Lägg till användaren som redaktör på den nya kursen (om inte admin)
    $copierUser = queryOne("SELECT is_admin, email FROM " . DB_DATABASE . ".users WHERE id = ?", [$newAuthorId]);
    if ($copierUser && $copierUser['is_admin'] != 1) {
        execute(
            "INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE created_by = created_by",
            [$newCourseId, $copierUser['email'], $newAuthorId]
        );
    }

    return [
        'success' => true,
        'message' => "Kursen '{$sourceCourse['title']}' har kopierats med {$lessonsCopied} lektioner.",
        'new_course_id' => $newCourseId
    ];
}

// Hantera kopieringsförfrågan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'copy_course') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: copy_course.php');
        exit;
    }

    $sourceCourseId = (int)($_POST['course_id'] ?? 0);

    if ($sourceCourseId > 0) {
        $orgScopeDomains = getOrgScopeDomains($_SESSION['user_email']);

        // Server-side skydd: inaktiva kurser får bara kopieras från egen org.
        $sourceCheck = queryOne(
            "SELECT organization_domain, status FROM " . DB_DATABASE . ".courses WHERE id = ?",
            [$sourceCourseId]
        );
        if ($sourceCheck
            && $sourceCheck['status'] !== 'active'
            && !in_array($sourceCheck['organization_domain'], $orgScopeDomains, true)
        ) {
            $_SESSION['message'] = 'Inaktiva kurser från andra organisationer kan inte kopieras.';
            $_SESSION['message_type'] = 'danger';
            header('Location: copy_course.php');
            exit;
        }

        $result = copyCourse($sourceCourseId, $userDomain, $currentUser['id'], $orgScopeDomains);

        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';

        if ($result['success']) {
            // Logga aktiviteten
            logActivity($_SESSION['user_email'], 'Kopierade kurs', [
                'action' => 'course_copied',
                'source_course_id' => $sourceCourseId,
                'new_course_id' => $result['new_course_id'],
                'target_domain' => $userDomain
            ]);

            header('Location: edit_course.php?id=' . $result['new_course_id']);
            exit;
        }
    } else {
        $_SESSION['message'] = 'Ingen kurs vald.';
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: copy_course.php');
    exit;
}

// Hämta filter
$filterDomain = $_GET['domain'] ?? '';
$filterSearch = $_GET['search'] ?? '';

// Hämta alla unika domäner
$domains = query("SELECT DISTINCT organization_domain FROM " . DB_DATABASE . ".courses
                  WHERE organization_domain IS NOT NULL AND organization_domain != ''
                  ORDER BY organization_domain ASC");

// Visa endast aktiva kurser i listan — även för egen organisation.
$sql = "SELECT c.*, u.email as author_email,
        (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons l WHERE l.course_id = c.id) as lesson_count
        FROM " . DB_DATABASE . ".courses c
        LEFT JOIN " . DB_DATABASE . ".users u ON c.author_id = u.id
        WHERE c.status = 'active'";
$params = [];

if ($filterDomain) {
    $sql .= " AND c.organization_domain = ?";
    $params[] = $filterDomain;
}

if ($filterSearch) {
    $sql .= " AND (c.title LIKE ? OR c.description LIKE ?)";
    $params[] = '%' . $filterSearch . '%';
    $params[] = '%' . $filterSearch . '%';
}

$sql .= " ORDER BY c.organization_domain ASC, c.title ASC";

$courses = query($sql, $params);

// Gruppera kurser per organisation
$coursesByDomain = [];
foreach ($courses as $course) {
    $domain = $course['organization_domain'] ?: 'Okänd organisation';
    if (!isset($coursesByDomain[$domain])) {
        $coursesByDomain[$domain] = [];
    }
    $coursesByDomain[$domain][] = $course;
}

// Inkludera header
require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-copy me-2"></i>Kopiera kurs till din organisation
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Information:</strong> Här kan du kopiera kurser från andra organisationer till din egen organisation (<?= htmlspecialchars($userDomain) ?>).
                    Kopian skapas som inaktiv så du kan granska och anpassa innehållet innan du publicerar den.
                </div>

                <!-- Filter -->
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label for="domain" class="form-label">Filtrera på organisation</label>
                        <select name="domain" id="domain" class="form-select">
                            <option value="">Alla organisationer</option>
                            <?php foreach ($domains as $d): ?>
                            <option value="<?= htmlspecialchars($d['organization_domain']) ?>"
                                <?= $filterDomain === $d['organization_domain'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['organization_domain']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="search" class="form-label">Sök kurs</label>
                        <input type="text" name="search" id="search" class="form-control"
                               placeholder="Sök på titel eller beskrivning..."
                               value="<?= htmlspecialchars($filterSearch) ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-secondary w-100">
                            <i class="bi bi-search me-2"></i>Filtrera
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Kurslista per organisation -->
<?php foreach ($coursesByDomain as $domain => $domainCourses): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header py-3 <?= $domain === $userDomain ? 'bg-success text-white' : '' ?>">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-building me-2"></i><?= htmlspecialchars($domain) ?>
                    <?php if ($domain === $userDomain): ?>
                    <span class="badge bg-light text-success ms-2">Din organisation</span>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-2"><?= count($domainCourses) ?> kurser</span>
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Titel</th>
                                <th>Beskrivning</th>
                                <th>Lektioner</th>
                                <th>Nivå</th>
                                <th>Status</th>
                                <th>Åtgärd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domainCourses as $course): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($course['title']) ?></strong>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= htmlspecialchars(mb_substr($course['description'] ?? '', 0, 100)) ?>
                                        <?= mb_strlen($course['description'] ?? '') > 100 ? '...' : '' ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= $course['lesson_count'] ?></span>
                                </td>
                                <td>
                                    <?php
                                    $levelBadge = [
                                        'beginner' => 'success',
                                        'intermediate' => 'warning',
                                        'advanced' => 'danger'
                                    ];
                                    $levelText = [
                                        'beginner' => 'Nybörjare',
                                        'intermediate' => 'Mellan',
                                        'advanced' => 'Avancerad'
                                    ];
                                    ?>
                                    <span class="badge bg-<?= $levelBadge[$course['difficulty_level']] ?? 'secondary' ?>">
                                        <?= $levelText[$course['difficulty_level']] ?? $course['difficulty_level'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $course['status'] === 'active' ? 'success' : 'secondary' ?>">
                                        <?= $course['status'] === 'active' ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($domain !== $userDomain): ?>
                                    <form method="POST" style="display: inline;"
                                          onsubmit="return confirm('Vill du kopiera kursen \'<?= htmlspecialchars(addslashes($course['title'])) ?>\' till din organisation?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="copy_course">
                                        <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-copy me-1"></i>Kopiera
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <a href="edit_course.php?id=<?= $course['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil me-1"></i>Redigera
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($coursesByDomain)): ?>
<div class="row">
    <div class="col-12">
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Inga kurser hittades med de valda filtren.
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Inkludera footer
require_once 'include/footer.php';
?>
