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

// Include centralized authentication and authorization check
require_once 'include/auth_check.php';

// Hämta användarens e-post för användning i kursbehörigheter
$userEmail = $_SESSION['user_email'];

// Hämta användarens domän för taggfiltrering
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$userEmail]);
$userDomain = $currentUser ? substr(strrchr($currentUser['email'], "@"), 1) : '';
$isSuperAdmin = $currentUser && ($currentUser['role'] ?? '') === 'super_admin';

// Scope: huvuddomän-admins ser hela orgens taggar/synlighet; sub-domän
// bara sin egen domäns. Sharing-controls (Delas med hela org / specifika
// domäner) är endast tillgängliga för huvuddomän — sub-domän kan bara
// publicera på sin egen domän.
$orgScopeDomains = getEffectiveOrgScopeDomains($userEmail);
$isOnPrimaryDomain = $isSuperAdmin || isUserOnPrimaryOrgDomain($userEmail);
$tagDomClause = buildDomainInClause($orgScopeDomains, 'organization_domain');

// Hämta kursdata om vi redigerar en befintlig kurs
$course = null;
$courseTags = [];
// Diplom-kriterium: min_quiz_percentage. Tomt = inget krav.
$courseMinQuizPct = '';
if (isset($_GET['id'])) {
    $courseId = (int)$_GET['id'];
    $course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
    $critRow = queryOne(
        "SELECT threshold_value FROM " . DB_DATABASE . ".course_completion_criteria
         WHERE course_id = ? AND criterion_type = 'min_quiz_percentage' LIMIT 1",
        [$courseId]
    );
    if ($critRow && $critRow['threshold_value'] !== null) {
        $courseMinQuizPct = (int)$critRow['threshold_value'];
    }

    if (!$course) {
        $_SESSION['message'] = 'Kursen hittades inte.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }

    // Behörighetskontroll via userCanModifyCourse — täcker super_admin,
    // org-scopade admins och kurs-specifika redaktörer. Fixar IDOR-buggen
    // där alla admins (oavsett org) kunde editera vilken kurs som helst
    // genom att manuellt ändra ?id= i URL:en.
    if (!userCanModifyCourse($course)) {
        $_SESSION['message'] = 'Du har inte behörighet att redigera denna kurs.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }

    // Hämta kursredaktörer
    $editors = queryAll("SELECT ce.email, u.name
                        FROM " . DB_DATABASE . ".course_editors ce
                        JOIN " . DB_DATABASE . ".users u ON ce.email = u.email
                        WHERE ce.course_id = ?", [$courseId]);

    // Hämta kursens taggar
    $courseTags = query(
        "SELECT t.id FROM " . DB_DATABASE . ".tags t
         INNER JOIN " . DB_DATABASE . ".course_tags ct ON t.id = ct.tag_id
         WHERE ct.course_id = ?",
        [$courseId]
    );
    $courseTags = array_column($courseTags, 'id');
}

// Hämta alla tillgängliga taggar för organisationen (alla orgens domäner)
$availableTags = query(
    "SELECT * FROM " . DB_DATABASE . ".tags WHERE {$tagDomClause['fragment']} ORDER BY name ASC",
    $tagDomClause['params']
);

// Hämta organisationstaggar — slå ihop tags från alla orgens domäner
$availableOrgTags = [];
$seenOrgTags = [];
foreach ($orgScopeDomains as $scopeDomain) {
    foreach (getOrgTagsForDomain($scopeDomain) as $tagRow) {
        $tagName = $tagRow['tag'] ?? null;
        if ($tagName !== null && !isset($seenOrgTags[$tagName])) {
            $availableOrgTags[] = $tagRow;
            $seenOrgTags[$tagName] = true;
        }
    }
}

// Hämta kursens nuvarande organisationstaggar
$courseOrgTags = [];
if (isset($_GET['id'])) {
    $courseOrgTagRows = query(
        "SELECT tag FROM " . DB_DATABASE . ".course_org_tags WHERE course_id = ?",
        [$courseId]
    );
    $courseOrgTags = array_column($courseOrgTagRows, 'tag');
}

// Hantera formulärskickning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $completionContentRaw = trim($_POST['completion_content'] ?? '');
    // Behandla tomt / bara whitespace / tom <p>-tagg som NULL → default-text används.
    $completionContent = $completionContentRaw;
    if ($completionContent === '' || preg_match('/^\s*(<p[^>]*>\s*(&nbsp;)?\s*<\/p>\s*)+$/i', $completionContent)) {
        $completionContent = null;
    }
    $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $sequentialMode = isset($_POST['sequential_mode']) ? 1 : 0;
    $sequentialIntervalDays = max(1, min(365, (int)($_POST['sequential_interval_days'] ?? 7)));
    $sequentialReminderDelayDays = max(1, min(90, (int)($_POST['sequential_reminder_delay_days'] ?? 3)));
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $seqNewLessonSubject = trim($_POST['seq_new_lesson_subject'] ?? '') ?: null;
    $seqNewLessonBody = trim($_POST['seq_new_lesson_body'] ?? '') ?: null;
    $seqReminderSubject = trim($_POST['seq_reminder_subject'] ?? '') ?: null;
    $seqReminderBody = trim($_POST['seq_reminder_body'] ?? '') ?: null;
    $enrollmentType = ($_POST['enrollment_type'] ?? 'bulk_start') === 'rolling' ? 'rolling' : 'bulk_start';

    // Diplom-kriterier + retry-flagga (nya 2026-06-05).
    // allow_quiz_retry: checkbox → 1 om markerad, annars 0
    $allowQuizRetry = isset($_POST['allow_quiz_retry']) ? 1 : 0;
    // min_quiz_percentage: tomt fält = inget krav (NULL i kriterie-tabellen)
    $minQuizPct = null;
    if (isset($_POST['min_quiz_percentage']) && $_POST['min_quiz_percentage'] !== '') {
        $minQuizPct = max(0, min(100, (int)$_POST['min_quiz_percentage']));
    }

    // Global synlighet — endast superadmin får sätta. För icke-superadmin
    // bevaras befintligt värde (eller 0 vid create).
    if ($isSuperAdmin) {
        $isGlobal = !empty($_POST['is_global']) ? 1 : 0;
    } else {
        $isGlobal = $course && !empty($course['is_global']) ? 1 : 0;
    }
    // Sätt sequential_status till 'pending' om stegvis läge + startdatum (bulk_start)
    // För rolling: sätt direkt till 'active'
    $sequentialStatus = null;
    if ($sequentialMode && $enrollmentType === 'rolling') {
        $sequentialStatus = 'active';
    } elseif ($sequentialMode && $startDate) {
        $existingStatus = $course['sequential_status'] ?? null;
        $sequentialStatus = ($existingStatus && $existingStatus !== 'pending') ? $existingStatus : 'pending';
    }
    $imageUrl = $course['image_url'] ?? null;
    
    if (empty($title)) {
        $error = 'Titel är obligatoriskt.';
    } else {
        // Hantera bilduppladdning
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Kontrollera om det finns ett uppladdningsfel
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'Bilden är för stor (överskrider serverns maxgräns).',
                    UPLOAD_ERR_FORM_SIZE => 'Bilden är för stor.',
                    UPLOAD_ERR_PARTIAL => 'Bilden laddades endast upp delvis.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Serverfel: Temporär mapp saknas.',
                    UPLOAD_ERR_CANT_WRITE => 'Serverfel: Kunde inte skriva filen.',
                    UPLOAD_ERR_EXTENSION => 'Uppladdningen stoppades av servern.',
                ];
                $error = $uploadErrors[$_FILES['image']['error']] ?? 'Okänt uppladdningsfel (kod: ' . $_FILES['image']['error'] . ')';
            } else {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $maxSize = 5 * 1024 * 1024; // 5MB

                if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                    $error = 'Endast JPG, PNG och GIF bilder är tillåtna. Filtyp: ' . $_FILES['image']['type'];
                } elseif ($_FILES['image']['size'] > $maxSize) {
                    $error = 'Bilden får inte vara större än 5MB. Storlek: ' . round($_FILES['image']['size'] / 1024 / 1024, 2) . ' MB';
                } else {
                    // Sökväg till upload-mappen
                    $uploadDir = __DIR__ . '/../upload/';
                    $fileName = uniqid() . '_' . basename($_FILES['image']['name']);
                    $targetPath = $uploadDir . $fileName;

                    if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                        $imageUrl = $fileName;

                        // Ta bort gammal bild om den finns
                        if (isset($course['image_url']) && !empty($course['image_url']) && $course['image_url'] !== $imageUrl) {
                            $oldImagePath = __DIR__ . '/../upload/' . $course['image_url'];
                            if (file_exists($oldImagePath)) {
                                unlink($oldImagePath);
                            }
                        }
                    } else {
                        $error = 'Kunde inte ladda upp bilden. Kontrollera filrättigheter på servern.';
                    }
                }
            }
        }
        
        if (!isset($error)) {
            // Hämta valda taggar
            $selectedTags = $_POST['tags'] ?? [];

            if (isset($_GET['id'])) {
                // Uppdatera befintlig kurs
                execute("UPDATE " . DB_DATABASE . ".courses SET
                        title = ?,
                        description = ?,
                        completion_content = ?,
                        status = ?,
                        deadline = ?,
                        start_date = ?,
                        sequential_mode = ?,
                        sequential_interval_days = ?,
                        sequential_reminder_delay_days = ?,
                        seq_new_lesson_subject = ?,
                        seq_new_lesson_body = ?,
                        seq_reminder_subject = ?,
                        seq_reminder_body = ?,
                        sequential_status = ?,
                        enrollment_type = ?,
                        image_url = ?,
                        is_global = ?,
                        updated_at = NOW()
                        WHERE id = ?",
                        [$title, $description, $completionContent, $status, $deadline, $startDate, $sequentialMode, $sequentialIntervalDays, $sequentialReminderDelayDays, $seqNewLessonSubject, $seqNewLessonBody, $seqReminderSubject, $seqReminderBody, $sequentialStatus, $enrollmentType, $imageUrl, $isGlobal, $_GET['id']]);

                // Diplom-kriterier + retry-flagga
                execute("UPDATE " . DB_DATABASE . ".courses SET allow_quiz_retry = ? WHERE id = ?",
                        [$allowQuizRetry, $_GET['id']]);
                execute("DELETE FROM " . DB_DATABASE . ".course_completion_criteria WHERE course_id = ? AND criterion_type = 'min_quiz_percentage'",
                        [$_GET['id']]);
                if ($minQuizPct !== null) {
                    execute("INSERT INTO " . DB_DATABASE . ".course_completion_criteria (course_id, criterion_type, threshold_value) VALUES (?, 'min_quiz_percentage', ?)",
                            [$_GET['id'], $minQuizPct]);
                }

                // Uppdatera kursens taggar
                // Ta bort befintliga taggar
                execute("DELETE FROM " . DB_DATABASE . ".course_tags WHERE course_id = ?", [$_GET['id']]);

                // Lägg till nya taggar (endast taggar från användarens organisation)
                foreach ($selectedTags as $tagId) {
                    $tagId = (int)$tagId;
                    // Verifiera att taggen tillhör någon av adminens orgs domäner
                    $validTag = queryOne(
                        "SELECT id FROM " . DB_DATABASE . ".tags WHERE id = ? AND {$tagDomClause['fragment']}",
                        array_merge([$tagId], $tagDomClause['params'])
                    );
                    if ($validTag) {
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".course_tags (course_id, tag_id) VALUES (?, ?)",
                            [$_GET['id'], $tagId]
                        );
                    }
                }

                // Uppdatera organisationstaggar
                execute("DELETE FROM " . DB_DATABASE . ".course_org_tags WHERE course_id = ?", [$_GET['id']]);
                $selectedOrgTags = $_POST['org_tags'] ?? [];
                $domainOrgTags = array_column($availableOrgTags, 'tag');
                foreach ($selectedOrgTags as $orgTag) {
                    if (in_array($orgTag, $domainOrgTags)) {
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".course_org_tags (course_id, tag) VALUES (?, ?)",
                            [$_GET['id'], $orgTag]
                        );
                    }
                }

                // Uppdatera delade domäner. share_mode = 'whole_org' rensar
                // alla, 'specific_domains' sparar bara de rutade. Validerar att
                // domänen faktiskt tillhör användarens organisation för att
                // förhindra att admin begränsar till en främmande domän.
                // Endast huvuddomän-admins får ändra synlighet — sub-domän-
                // användares POST ignoreras helt (befintligt värde behålls).
                if ($isOnPrimaryDomain) {
                    $shareMode = $_POST['share_mode'] ?? 'whole_org';
                    if ($shareMode === 'specific_domains') {
                        $userOrgRow2 = getOrganizationByDomain(substr(strrchr($_SESSION['user_email'], '@'), 1));
                        $allowedDomainList = $userOrgRow2 ? getOrganizationDomains($userOrgRow2['id']) : [];
                        $submittedDomains = array_values(array_filter((array)($_POST['shared_domains'] ?? [])));
                        $filtered = array_intersect($submittedDomains, $allowedDomainList);
                        setCourseSharedDomains((int)$_GET['id'], $filtered);
                    } else {
                        // Hela organisationen — rensa alla
                        setCourseSharedDomains((int)$_GET['id'], []);
                    }
                }

                $_SESSION['message'] = 'Kursen har uppdaterats.';
            } else {
                // Hitta högsta sort_order
                $maxOrder = queryOne("SELECT MAX(sort_order) as max_order FROM " . DB_DATABASE . ".courses")['max_order'] ?? 0;

                // Hämta användarens ID och domän
                $author = queryOne("SELECT id, email FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
                $authorId = $author ? $author['id'] : null;
                $organizationDomain = $author ? substr(strrchr($author['email'], "@"), 1) : null;

                // Skapa ny kurs med nästa sort_order och organization_domain.
                // original_organization_domain sätts permanent till skaparens org
                // och får aldrig skrivas över senare.
                execute("INSERT INTO " . DB_DATABASE . ".courses
                        (title, description, completion_content, status, deadline, start_date, sequential_mode, sequential_interval_days, sequential_reminder_delay_days, seq_new_lesson_subject, seq_new_lesson_body, seq_reminder_subject, seq_reminder_body, sequential_status, enrollment_type, sort_order, image_url, is_global, author_id, organization_domain, original_organization_domain, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [$title, $description, $completionContent, $status, $deadline, $startDate, $sequentialMode, $sequentialIntervalDays, $sequentialReminderDelayDays, $seqNewLessonSubject, $seqNewLessonBody, $seqReminderSubject, $seqReminderBody, $sequentialStatus, $enrollmentType, $maxOrder + 1, $imageUrl, $isGlobal, $authorId, $organizationDomain, $organizationDomain]);

                // Hämta det nya kurs-ID:t
                $newCourseId = getDb()->lastInsertId();

                // Diplom-kriterier + retry-flagga för ny kurs
                execute("UPDATE " . DB_DATABASE . ".courses SET allow_quiz_retry = ? WHERE id = ?",
                        [$allowQuizRetry, $newCourseId]);
                if ($minQuizPct !== null) {
                    execute("INSERT INTO " . DB_DATABASE . ".course_completion_criteria (course_id, criterion_type, threshold_value) VALUES (?, 'min_quiz_percentage', ?)",
                            [$newCourseId, $minQuizPct]);
                }

                // Lägg till skaparen som redaktör för kursen
                execute("INSERT INTO " . DB_DATABASE . ".course_editors
                        (course_id, email, created_by)
                        VALUES (?, ?, ?)",
                        [$newCourseId, $_SESSION['user_email'], $_SESSION['user_email']]);

                // Lägg till taggar för ny kurs
                foreach ($selectedTags as $tagId) {
                    $tagId = (int)$tagId;
                    // Verifiera att taggen tillhör någon av adminens orgs domäner
                    $validTag = queryOne(
                        "SELECT id FROM " . DB_DATABASE . ".tags WHERE id = ? AND {$tagDomClause['fragment']}",
                        array_merge([$tagId], $tagDomClause['params'])
                    );
                    if ($validTag) {
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".course_tags (course_id, tag_id) VALUES (?, ?)",
                            [$newCourseId, $tagId]
                        );
                    }
                }

                // Lägg till organisationstaggar för ny kurs
                $selectedOrgTags = $_POST['org_tags'] ?? [];
                $domainOrgTags = array_column($availableOrgTags, 'tag');
                foreach ($selectedOrgTags as $orgTag) {
                    if (in_array($orgTag, $domainOrgTags)) {
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".course_org_tags (course_id, tag) VALUES (?, ?)",
                            [$newCourseId, $orgTag]
                        );
                    }
                }

                // Delade domäner för ny kurs
                if ($isOnPrimaryDomain) {
                    // Huvuddomän får välja som vanligt
                    $shareMode = $_POST['share_mode'] ?? 'whole_org';
                    if ($shareMode === 'specific_domains') {
                        $userOrgRow2 = getOrganizationByDomain(substr(strrchr($_SESSION['user_email'], '@'), 1));
                        $allowedDomainList = $userOrgRow2 ? getOrganizationDomains($userOrgRow2['id']) : [];
                        $submittedDomains = array_values(array_filter((array)($_POST['shared_domains'] ?? [])));
                        $filtered = array_intersect($submittedDomains, $allowedDomainList);
                        setCourseSharedDomains((int)$newCourseId, $filtered);
                    }
                } elseif (!empty($organizationDomain)) {
                    // Sub-domän: begränsa automatiskt till skaparens egen domän
                    // så kursen inte syns för andra sub-domäner i orgen.
                    setCourseSharedDomains((int)$newCourseId, [$organizationDomain]);
                }

                $_SESSION['message'] = 'Kursen har skapats.';
            }

            $_SESSION['message_type'] = 'success';
            header('Location: courses.php');
            exit;
        }
    }
}

// Sätt sidtitel
$page_title = isset($_GET['id']) ? 'Redigera kurs' : 'Skapa ny kurs';

// Inkludera header
require_once 'include/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="m-0 font-weight-bold text-muted"><?= $page_title ?></h6>
                    <?php if ($course): ?>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <?php
                            $origDomain = $course['original_organization_domain'] ?? null;
                            if ($origDomain && $origDomain !== ($course['organization_domain'] ?? null)):
                                $origLabel = getOriginalOrganizationLabel($origDomain);
                            ?>
                            <span class="badge bg-info text-dark"
                                  title="Permanent etikett — kursen kopierades ursprungligen från denna organisation och kan inte ändras">
                                <i class="bi bi-diagram-3 me-1"></i>Ursprung: <?= htmlspecialchars($origLabel) ?>
                            </span>
                            <?php endif; ?>
                            <span class="badge bg-secondary">ID: <?= $course['id'] ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $course['id'] ?? '' ?>">

                        <!-- Override: adminens globala .nav-link-styling är vit (för
                             den mörka sidopanelen). Här behöver vi mörk text på ljus
                             flikbakgrund. -->
                        <style>
                            #editCourseTabs .nav-link {
                                color: #495057 !important;
                                border-left: none !important;
                                background: transparent;
                                font-weight: 500;
                            }
                            #editCourseTabs .nav-link:hover {
                                color: #0d6efd !important;
                                background: rgba(13,110,253,.05);
                                border-left-color: transparent !important;
                            }
                            #editCourseTabs .nav-link.active {
                                color: #0d6efd !important;
                                background: #fff;
                                border-bottom-color: #fff !important;
                            }
                        </style>

                        <!-- Flikrad — fyra logiska sektioner -->
                        <ul class="nav nav-tabs mb-3" id="editCourseTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link active" data-course-tab="content"><i class="bi bi-journal-text me-1"></i>Allmänt</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link" data-course-tab="sequential"><i class="bi bi-list-ol me-1"></i>Stegvisa lektioner</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link" data-course-tab="assignment"><i class="bi bi-people me-1"></i>Tilldelning &amp; synlighet</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link" data-course-tab="publishing"><i class="bi bi-broadcast me-1"></i>Publicering</button>
                            </li>
                        </ul>

                        <!-- Tab: Publicering (Status + Publik kurs) -->
                        <div class="course-tab-section" data-tab-section="publishing" style="display:none;">

                        <div class="card mb-3 <?= ($course['status'] ?? '') === 'active' ? 'border-success' : 'border-warning' ?>" id="statusCard">
                            <div class="card-body py-2 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    <i class="bi <?= ($course['status'] ?? '') === 'active' ? 'bi-check-circle-fill text-success' : 'bi-pause-circle-fill text-warning' ?> me-2 fs-5" id="statusIcon"></i>
                                    <span class="fw-bold" id="statusText"><?= ($course['status'] ?? '') === 'active' ? 'Kursen är aktiv och synlig' : 'Kursen är inaktiv och dold' ?></span>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="status" name="status"
                                           value="active" <?= ($course['status'] ?? '') === 'active' ? 'checked' : '' ?>
                                           style="width: 3em; height: 1.5em;">
                                    <label class="form-check-label fw-bold" for="status">Aktiv</label>
                                </div>
                            </div>
                        </div>

                        <?php if ($isSuperAdmin): ?>
                            <!-- Global synlighet (endast superadmin) -->
                            <?php $isGlobalNow = !empty($course['is_global']); ?>
                            <div class="card mb-3 <?= $isGlobalNow ? 'border-info' : '' ?>">
                                <div class="card-body py-2 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <i class="bi <?= $isGlobalNow ? 'bi-globe2 text-info' : 'bi-building text-secondary' ?> me-2 fs-5"></i>
                                        <div>
                                            <div class="fw-bold">
                                                <?= $isGlobalNow ? 'Global kurs — synlig för alla organisationer' : 'Org-scoped — synlig endast för din organisation' ?>
                                            </div>
                                            <small class="text-muted">
                                                Endast superadmin kan ändra detta. Globala kurser ignorerar org-domän, taggar och delningsregler.
                                            </small>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_global" name="is_global"
                                               value="1" <?= $isGlobalNow ? 'checked' : '' ?>
                                               style="width: 3em; height: 1.5em;">
                                        <label class="form-check-label fw-bold" for="is_global">Global</label>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($course['id'])): // Publik kurs-panelen kräver sparad kurs ?>
                        <?php
                        $isPublicNow = !empty($course['is_public']);
                        $publicToken = $course['public_registration_token'] ?? null;
                        $publicUrl = $isPublicNow && $publicToken
                            ? (rtrim(getenv('SYSTEM_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'stimma.sambruk.se')), '/')
                               . '/public_register.php?course_id=' . (int)$course['id'] . '&token=' . $publicToken)
                            : '';
                        $participantCount = (int)(queryOne(
                            "SELECT COUNT(*) AS c FROM " . DB_DATABASE . ".public_course_access WHERE course_id = ?",
                            [(int)$course['id']]
                        )['c'] ?? 0);
                        ?>
                        <div class="card mb-3 border-info" id="publicCourseCard">
                            <div class="card-header bg-info bg-opacity-10 py-2">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-globe me-2"></i>Publik kurs</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <label class="mb-0">
                                        <strong>Låt vem som helst registrera sig via unik länk</strong><br>
                                        <small class="text-muted">Externa deltagare kan anmäla sig med valfri e-postadress. De får endast tillgång till den här kursen.</small>
                                    </label>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="publicCourseToggle"
                                               <?= $isPublicNow ? 'checked' : '' ?>
                                               data-course-id="<?= (int)$course['id'] ?>"
                                               data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                               style="width: 3em; height: 1.5em;">
                                    </div>
                                </div>

                                <div id="publicLinkArea" class="<?= $isPublicNow ? '' : 'd-none' ?>">
                                    <label class="form-label small text-muted">Registreringslänk:</label>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control font-monospace" id="publicRegUrl" value="<?= htmlspecialchars($publicUrl) ?>" readonly>
                                        <button type="button" class="btn btn-outline-secondary" id="copyPublicUrlBtn" title="Kopiera länken">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-warning" id="regenPublicTokenBtn" title="Skapa ny länk"
                                                data-course-id="<?= (int)$course['id'] ?>"
                                                data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <i class="bi bi-arrow-clockwise"></i> Förnya
                                        </button>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Att stänga av publik registrering blockerar nya anmälningar men behåller befintliga deltagare. Rensa via <a href="public_participants.php?course_id=<?= (int)$course['id'] ?>">Hantera publika deltagare</a>.
                                    </div>
                                    <div class="mt-2">
                                        <a href="public_participants.php?course_id=<?= (int)$course['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-people me-1"></i>Hantera publika deltagare (<?= $participantCount ?>)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                        (function() {
                            const toggle = document.getElementById('publicCourseToggle');
                            const linkArea = document.getElementById('publicLinkArea');
                            const urlInput = document.getElementById('publicRegUrl');
                            const copyBtn = document.getElementById('copyPublicUrlBtn');
                            const regenBtn = document.getElementById('regenPublicTokenBtn');
                            if (!toggle) return;

                            toggle.addEventListener('change', async function() {
                                const formData = new FormData();
                                formData.append('csrf_token', toggle.dataset.csrf);
                                formData.append('course_id', toggle.dataset.courseId);
                                formData.append('is_public', toggle.checked ? '1' : '0');
                                try {
                                    const r = await fetch('ajax/toggle_public_course.php', { method: 'POST', body: formData });
                                    const data = await r.json();
                                    if (!data.success) { alert(data.message || 'Kunde inte spara'); toggle.checked = !toggle.checked; return; }
                                    if (data.public_url) {
                                        urlInput.value = data.public_url;
                                        linkArea.classList.remove('d-none');
                                    } else {
                                        linkArea.classList.add('d-none');
                                    }
                                } catch (e) { alert('Nätverksfel'); toggle.checked = !toggle.checked; }
                            });

                            if (copyBtn) copyBtn.addEventListener('click', function() {
                                urlInput.select();
                                navigator.clipboard.writeText(urlInput.value).then(() => {
                                    copyBtn.innerHTML = '<i class="bi bi-check"></i>';
                                    setTimeout(() => copyBtn.innerHTML = '<i class="bi bi-clipboard"></i>', 1500);
                                });
                            });

                            if (regenBtn) regenBtn.addEventListener('click', async function() {
                                if (!confirm('Skapa en ny registreringslänk?\nDen gamla länken slutar fungera omedelbart. Befintliga deltagare påverkas ej.')) return;
                                const formData = new FormData();
                                formData.append('csrf_token', regenBtn.dataset.csrf);
                                formData.append('course_id', regenBtn.dataset.courseId);
                                try {
                                    const r = await fetch('ajax/regenerate_public_token.php', { method: 'POST', body: formData });
                                    const data = await r.json();
                                    if (data.success) { urlInput.value = data.public_url; }
                                    else { alert(data.message || 'Kunde inte förnya länken'); }
                                } catch (e) { alert('Nätverksfel'); }
                            });
                        })();
                        </script>
                        <?php endif; ?>

                        </div><!-- /.tab-section publishing -->

                        <!-- Tab: Innehåll -->
                        <div class="course-tab-section" data-tab-section="content">

                        <div class="card mb-3">
                            <div class="card-header py-2"><h6 class="m-0 fw-semibold"><i class="bi bi-journal-text me-1 text-primary"></i>Kursens innehåll</h6></div>
                            <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-lg-3 col-md-6">
                                <div class="form-floating h-100">
                                    <input type="text" class="form-control" id="title" name="title"
                                           value="<?= htmlspecialchars($course['title'] ?? '') ?>" required>
                                    <label for="title">Titel</label>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <div class="form-floating h-100">
                                    <textarea class="form-control" id="description" name="description"
                                              style="height: 100%; min-height: 58px;"><?= htmlspecialchars($course['description'] ?? '') ?></textarea>
                                    <label for="description">Beskrivning</label>
                                </div>
                            </div>

                            <div class="col-lg-4 col-md-8">
                                <label for="image" class="form-label small mb-1">Bild
                                    <span class="text-muted ms-1" data-bs-toggle="tooltip"
                                          data-bs-title="Max 5 MB. JPG/PNG/GIF. Rekommenderad storlek 1200×630 px.">
                                        <i class="bi bi-info-circle"></i>
                                    </span>
                                </label>
                                <div id="current-image-container" class="mb-1" <?= empty($course['image_url']) ? 'style="display:none;"' : '' ?>>
                                    <img id="current-image" src="<?= !empty($course['image_url']) ? '../upload/' . htmlspecialchars($course['image_url']) : '' ?>" alt="Kursbild" class="img-thumbnail" style="max-height: 50px;">
                                    <input type="hidden" name="image_url" id="image_url" value="<?= htmlspecialchars($course['image_url'] ?? '') ?>">
                                </div>
                                <div class="d-flex gap-2 align-items-start">
                                    <input type="file" class="form-control form-control-sm flex-grow-1" id="image" name="image" accept="image/jpeg,image/png,image/gif">
                                    <?php if (isset($course['id'])): ?>
                                    <button type="button" id="generate-ai-image-btn" class="btn btn-sm btn-outline-primary" title="Generera AI-bild">
                                        <i class="bi bi-stars"></i> AI
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div id="ai-image-status" class="mt-1 small" style="display: none;">
                                    <span class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">…</span></span>
                                    <span class="ms-1 text-muted">Genererar AI-bild (~60 s)...</span>
                                </div>
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label for="deadline" class="form-label small mb-1">Slutdatum
                                    <span class="text-muted ms-1" data-bs-toggle="tooltip"
                                          data-bs-title="Datum då kursen ska vara genomförd. Lämna tomt om inget slutdatum.">
                                        <i class="bi bi-info-circle"></i>
                                    </span>
                                </label>
                                <input type="date" class="form-control" id="deadline" name="deadline"
                                       value="<?= htmlspecialchars($course['deadline'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="completion_content" class="form-label">Avslutssida</label>
                            <div class="alert alert-info py-2 mb-2 small">
                                <i class="bi bi-info-circle me-1"></i>
                                Visas för kursdeltagaren när sista lektionen är avklarad — tillsammans med länk till diplomet. Lämna tomt för att använda standardtexten.
                            </div>
                            <?php require_once 'include/editor.php'; renderEditor($course['completion_content'] ?? '', 'completion_content', 'completionEditor', true); ?>
                        </div>

                        <!-- Diplom-kriterier (utöver att lektionerna ska vara klara) -->
                        <div class="mb-3 p-3 border rounded bg-light">
                            <label class="form-label fw-semibold"><i class="bi bi-award me-1 text-secondary"></i>Diplom-kriterier</label>
                            <div class="form-text mb-3">
                                Här kan du ställa krav som måste vara uppfyllda — utöver att alla lektioner ska vara avklarade — innan diplomet utfärdas.
                            </div>

                            <div class="mb-3">
                                <label for="min_quiz_percentage" class="form-label">Minsta andel rätt svar (%)</label>
                                <div class="input-group" style="max-width: 220px;">
                                    <input type="number" min="0" max="100" step="1"
                                           class="form-control" id="min_quiz_percentage" name="min_quiz_percentage"
                                           value="<?= htmlspecialchars((string)$courseMinQuizPct) ?>"
                                           placeholder="t.ex. 80">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">
                                    Räknas över <strong>senaste svaret</strong> per fråga på alla quiz i kursens aktiva lektioner. Lämna tomt för inget procentkrav.
                                </div>
                            </div>

                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="allow_quiz_retry" name="allow_quiz_retry"
                                       <?= (!isset($course['allow_quiz_retry']) || (int)$course['allow_quiz_retry'] === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="allow_quiz_retry">
                                    Tillåt deltagare att svara om på samma fråga
                                </label>
                                <div class="form-text">
                                    Av: varje fråga kan besvaras en gång — sista chansen för deltagaren. På (default): deltagaren kan göra om quiz och svaren skrivs över med senaste resultatet.
                                </div>
                            </div>
                        </div>

                            </div><!-- /.card-body Kursens innehåll -->
                        </div><!-- /.card Kursens innehåll -->

                        </div><!-- /.tab-section content -->

                        <!-- Tab: Stegvisa lektioner -->
                        <div class="course-tab-section" data-tab-section="sequential" style="display:none;">

                        <div class="card mb-3">
                            <div class="card-header py-2"><h6 class="m-0 fw-semibold"><i class="bi bi-list-ol me-1 text-primary"></i>Stegvisa lektioner</h6></div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="sequential_mode" name="sequential_mode"
                                           value="1" <?= ($course['sequential_mode'] ?? 0) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="sequential_mode">Stegvisa lektioner</label>
                                </div>
                                <div class="form-text mb-3">
                                    Aktivera för att skicka ut en lektion i taget med tidsstyrt intervall. Användare måste klara varje lektion innan nästa blir tillgänglig.
                                </div>
                                <div id="sequentialSettings" style="display: <?= ($course['sequential_mode'] ?? 0) ? 'block' : 'none' ?>;">
                                    <?php if (!empty($course['sequential_status'])): ?>
                                    <div class="mb-3">
                                        <?php
                                        $statusBadges = [
                                            'pending' => '<span class="badge bg-secondary">Väntar</span>',
                                            'sending' => '<span class="badge bg-warning text-dark">Skickar</span>',
                                            'active' => '<span class="badge bg-success">Aktiv</span>',
                                            'completed' => '<span class="badge bg-info">Slutförd</span>',
                                        ];
                                        echo 'Status: ' . ($statusBadges[$course['sequential_status']] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($course['sequential_status']) . '</span>');
                                        ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Registreringsläge</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="enrollment_type" id="enrollment_bulk" value="bulk_start"
                                                   <?= ($course['enrollment_type'] ?? 'bulk_start') === 'bulk_start' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="enrollment_bulk">
                                                <i class="bi bi-people me-1"></i>Gemensamt startdatum — alla startar samtidigt
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="enrollment_type" id="enrollment_rolling" value="rolling"
                                                   <?= ($course['enrollment_type'] ?? '') === 'rolling' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="enrollment_rolling">
                                                <i class="bi bi-person-plus me-1"></i>Löpande registrering — skriv in användare individuellt med valfritt startdatum
                                            </label>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label for="sequential_interval_days" class="form-label">Dagar mellan lektioner</label>
                                            <input type="number" class="form-control" id="sequential_interval_days" name="sequential_interval_days"
                                                   value="<?= htmlspecialchars($course['sequential_interval_days'] ?? 7) ?>" min="1" max="365">
                                            <div class="form-text">Dagar innan nästa lektion efter avklarad.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="sequential_reminder_delay_days" class="form-label">Påminnelse efter (dagar)</label>
                                            <input type="number" class="form-control" id="sequential_reminder_delay_days" name="sequential_reminder_delay_days"
                                                   value="<?= htmlspecialchars($course['sequential_reminder_delay_days'] ?? 3) ?>" min="1" max="90">
                                            <div class="form-text">Dagar innan påminnelse skickas.</div>
                                        </div>
                                        <div class="col-md-4" id="bulkStartDateField" style="display: <?= ($course['enrollment_type'] ?? 'bulk_start') === 'rolling' ? 'none' : 'block' ?>;">
                                            <label for="start_date" class="form-label">Startdatum</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date"
                                                   value="<?= htmlspecialchars($course['start_date'] ?? '') ?>">
                                            <div class="form-text">Kursen startar automatiskt detta datum.</div>
                                        </div>
                                    </div>

                                    <!-- E-postmallar: hopvikta i details för kompakthet — anpassas sällan -->
                                    <details class="mb-3">
                                        <summary class="fw-semibold py-2 px-3 bg-light border rounded" style="cursor:pointer;">
                                            <i class="bi bi-envelope-paper me-1 text-success"></i>E-postmallar (anpassa rubrik och text)
                                            <span class="text-muted small ms-1">— klicka för att öppna</span>
                                        </summary>
                                        <div class="border-start border-end border-bottom rounded-bottom p-3">
                                            <!-- E-postmall: Ny lektion -->
                                            <div class="mb-3 pb-3 border-bottom">
                                                <h6 class="text-success mb-2"><i class="bi bi-envelope-plus me-1"></i>Ny lektion</h6>
                                                <div class="mb-2">
                                                    <label for="seq_new_lesson_subject" class="form-label small">Ämnesrad</label>
                                                    <input type="text" class="form-control" id="seq_new_lesson_subject" name="seq_new_lesson_subject"
                                                           value="<?= htmlspecialchars($course['seq_new_lesson_subject'] ?? '') ?>"
                                                           placeholder="Ny lektion tillgänglig: {{lesson_title}}">
                                                </div>
                                                <div class="mb-0">
                                                    <label for="seq_new_lesson_body" class="form-label small">Brödtext</label>
                                                    <textarea class="form-control" id="seq_new_lesson_body" name="seq_new_lesson_body" rows="4"
                                                              placeholder="Hej {{user_name}}!&#10;&#10;En ny lektion i kursen {{course_title}} är tillgänglig:&#10;{{lesson_title}}&#10;&#10;Gå till lektionen: {{lesson_url}}"><?= htmlspecialchars($course['seq_new_lesson_body'] ?? '') ?></textarea>
                                                </div>
                                            </div>

                                            <!-- E-postmall: Påminnelse -->
                                            <div class="mb-3 pb-3 border-bottom">
                                                <h6 class="text-warning mb-2"><i class="bi bi-bell me-1"></i>Påminnelse</h6>
                                                <div class="mb-2">
                                                    <label for="seq_reminder_subject" class="form-label small">Ämnesrad</label>
                                                    <input type="text" class="form-control" id="seq_reminder_subject" name="seq_reminder_subject"
                                                           value="<?= htmlspecialchars($course['seq_reminder_subject'] ?? '') ?>"
                                                           placeholder="Påminnelse: {{lesson_title}} väntar på dig">
                                                </div>
                                                <div class="mb-0">
                                                    <label for="seq_reminder_body" class="form-label small">Brödtext</label>
                                                    <textarea class="form-control" id="seq_reminder_body" name="seq_reminder_body" rows="4"
                                                              placeholder="Hej {{user_name}}!&#10;&#10;Du har en lektion som väntar: {{lesson_title}}&#10;i kursen {{course_title}}.&#10;&#10;Gå till lektionen: {{lesson_url}}"><?= htmlspecialchars($course['seq_reminder_body'] ?? '') ?></textarea>
                                                </div>
                                            </div>

                                            <!-- Variabellista -->
                                            <div class="small text-muted">
                                                <i class="bi bi-braces me-1"></i><strong>Tillgängliga variabler:</strong>
                                                <code>{{user_name}}</code> <code>{{user_email}}</code> <code>{{course_title}}</code>
                                                <code>{{lesson_title}}</code> <code>{{lesson_url}}</code> <code>{{lesson_number}}</code>
                                                <code>{{total_lessons}}</code> <code>{{course_url}}</code> <code>{{deadline}}</code>
                                                <code>{{days_remaining}}</code> <code>{{system_name}}</code>.
                                                Lämna tomt för standardmallen.
                                            </div>
                                        </div>
                                    </details>

                                    <?php if (isset($_GET['id'])): ?>
                                    <!-- Testmail -->
                                    <div class="card mb-3 border-info">
                                        <div class="card-header bg-info bg-opacity-10">
                                            <h6 class="m-0"><i class="bi bi-envelope-check me-2"></i>Skicka testmail</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="input-group">
                                                <input type="email" class="form-control" id="seqTestEmail" placeholder="din@epost.se"
                                                       value="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>">
                                                <button type="button" class="btn btn-info" id="sendSeqTestBtn">
                                                    <i class="bi bi-send me-1"></i>Skicka test
                                                </button>
                                            </div>
                                            <div id="seqTestResult" class="mt-2" style="display: none;"></div>
                                        </div>
                                    </div>

                                    <!-- Starta utskick nu (bulk_start) -->
                                    <div id="bulkStartSection" style="display: <?= ($course['enrollment_type'] ?? 'bulk_start') === 'rolling' ? 'none' : 'block' ?>;">
                                    <?php if (empty($course['sequential_status']) || $course['sequential_status'] === 'pending'): ?>
                                    <div class="card mb-3 border-primary">
                                        <div class="card-body text-center">
                                            <p class="mb-2 text-muted">Registrera alla berörda användare och skicka första lektionen.</p>
                                            <button type="button" class="btn btn-primary" id="triggerStartBtn">
                                                <i class="bi bi-play-circle me-1"></i>Starta utskick nu
                                            </button>
                                            <div id="triggerStartResult" class="mt-2" style="display: none;"></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    </div>

                                    <!-- Manuell inskrivning (både bulk_start och rolling) -->
                                    <div id="manualEnrollSection">
                                    <?php
                                    $currentEnrollmentType = $course['enrollment_type'] ?? 'bulk_start';
                                    $courseStartDate = $course['start_date'] ?? '';
                                    $bulkStartReachable = $courseStartDate && strtotime($courseStartDate) >= strtotime(date('Y-m-d'));
                                    ?>
                                    <?php if (isset($_GET['id'])): ?>
                                    <div class="card mb-3 border-primary">
                                        <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <h6 class="m-0"><i class="bi bi-person-plus me-2"></i>Aktiv inskrivning av användare</h6>
                                            <span class="badge bg-primary"><i class="bi bi-envelope-fill me-1"></i>Skickar e-post + påminnelser</span>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-primary py-2 small mb-3">
                                                <i class="bi bi-arrow-down-circle me-1"></i><strong>Push-tilldelning.</strong>
                                                Användaren får ett mail om att kursen finns och påminnelser enligt schema. Detta är aktiv tilldelning — inte att förväxla med synligheten i kurskatalogen som sätts längre ned (<em>"Vem ska se kursen?"</em>).
                                            </div>
                                            <p class="text-muted small mb-3">
                                                <span id="enrollDescRolling" style="display: <?= $currentEnrollmentType === 'rolling' ? 'inline' : 'none' ?>;">Sök och välj användare att skriva in i kursen. Varje användare startar det valda datumet och får lektioner med det inställda intervallet.</span>
                                                <span id="enrollDescBulk" style="display: <?= $currentEnrollmentType === 'rolling' ? 'none' : 'inline' ?>;">Sök och välj användare att skriva in i kursen. Användare skrivs in och startar tillsammans på kursens gemensamma startdatum.</span>
                                            </p>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-5">
                                                    <label class="form-label small">Användare</label>
                                                    <input type="text" class="form-control" id="rollingUserSearch"
                                                           placeholder="Sök namn eller e-post..." autocomplete="off">
                                                    <div id="rollingUserResults" class="list-group position-absolute mt-1" style="z-index: 1050; display: none;"></div>
                                                    <input type="hidden" id="rollingUserEmail" value="">
                                                </div>
                                                <div class="col-md-3" id="rollingDateCol" style="display: <?= $currentEnrollmentType === 'rolling' ? 'block' : 'none' ?>;">
                                                    <label class="form-label small">Startdatum</label>
                                                    <input type="date" class="form-control" id="rollingStartDate"
                                                           value="<?= date('Y-m-d') ?>">
                                                </div>
                                                <div class="col-md-3" id="bulkDateInfoCol" style="display: <?= $currentEnrollmentType === 'rolling' ? 'none' : 'block' ?>;">
                                                    <label class="form-label small">Startdatum</label>
                                                    <div class="form-control-plaintext small py-1">
                                                        <?php if ($bulkStartReachable): ?>
                                                            <i class="bi bi-calendar-event me-1 text-primary"></i><strong><?= htmlspecialchars($courseStartDate) ?></strong>
                                                            <div class="text-muted" style="font-size: 0.75rem;">tillsammans med övriga</div>
                                                        <?php else: ?>
                                                            <i class="bi bi-lightning-charge me-1 text-warning"></i><strong>Direkt</strong>
                                                            <div class="text-muted" style="font-size: 0.75rem;"><?= $courseStartDate ? 'startdatum passerat' : 'inget startdatum satt' ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($availableOrgTags)): ?>
                                                <div class="col-md-2">
                                                    <label class="form-label small">Eller org-tagg</label>
                                                    <select class="form-select" id="rollingOrgTag">
                                                        <option value="">— Välj —</option>
                                                        <?php foreach ($availableOrgTags as $ot): ?>
                                                        <option value="<?= htmlspecialchars($ot['tag']) ?>"><?= htmlspecialchars($ot['tag']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php endif; ?>
                                                <div class="col-md-2 d-flex align-items-end">
                                                    <button type="button" class="btn btn-primary w-100" id="rollingEnrollBtn" disabled>
                                                        <i class="bi bi-person-plus me-1"></i>Skriv in
                                                    </button>
                                                </div>
                                            </div>
                                            <div id="rollingEnrollResult" style="display: none;"></div>

                                            <?php
                                            // Visa redan inskrivna användare
                                            $enrolledUsers = query(
                                                "SELECT ce.user_id, ce.started_at, ce.status, u.name, u.email
                                                 FROM " . DB_DATABASE . ".course_enrollments ce
                                                 JOIN " . DB_DATABASE . ".users u ON ce.user_id = u.id
                                                 WHERE ce.course_id = ?
                                                 ORDER BY ce.started_at DESC",
                                                [$courseId]
                                            );
                                            if (!empty($enrolledUsers)):
                                            ?>
                                            <hr>
                                            <h6 class="mb-2"><i class="bi bi-people me-1"></i>Inskrivna användare (<?= count($enrolledUsers) ?>)</h6>
                                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Namn</th>
                                                            <th>E-post</th>
                                                            <th>Startdatum</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($enrolledUsers as $eu): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($eu['name'] ?: '-') ?></td>
                                                            <td class="small"><?= htmlspecialchars($eu['email']) ?></td>
                                                            <td><?= $eu['started_at'] ? date('Y-m-d', strtotime($eu['started_at'])) : '-' ?></td>
                                                            <td>
                                                                <?php if ($eu['status'] === 'completed'): ?>
                                                                    <span class="badge bg-success">Klar</span>
                                                                <?php elseif ($eu['status'] === 'active'): ?>
                                                                    <span class="badge bg-primary">Aktiv</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary"><?= htmlspecialchars($eu['status']) ?></span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-1"></i>Spara kursen först, sedan kan du skriva in användare individuellt.
                                    </div>
                                    <?php endif; ?>
                                    </div>

                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <script>
                        document.getElementById('sequential_mode').addEventListener('change', function() {
                            document.getElementById('sequentialSettings').style.display = this.checked ? 'block' : 'none';
                        });

                        // Registreringsläge toggle
                        document.querySelectorAll('input[name="enrollment_type"]').forEach(function(radio) {
                            radio.addEventListener('change', function() {
                                var isRolling = this.value === 'rolling';
                                var bulkDateField = document.getElementById('bulkStartDateField');
                                var bulkStartSection = document.getElementById('bulkStartSection');
                                var rollingDateCol = document.getElementById('rollingDateCol');
                                var bulkDateInfoCol = document.getElementById('bulkDateInfoCol');
                                var descRolling = document.getElementById('enrollDescRolling');
                                var descBulk = document.getElementById('enrollDescBulk');
                                if (bulkDateField) bulkDateField.style.display = isRolling ? 'none' : 'block';
                                if (bulkStartSection) bulkStartSection.style.display = isRolling ? 'none' : 'block';
                                if (rollingDateCol) rollingDateCol.style.display = isRolling ? 'block' : 'none';
                                if (bulkDateInfoCol) bulkDateInfoCol.style.display = isRolling ? 'none' : 'block';
                                if (descRolling) descRolling.style.display = isRolling ? 'inline' : 'none';
                                if (descBulk) descBulk.style.display = isRolling ? 'none' : 'inline';
                            });
                        });

                        // Rolling enrollment: användarsökning
                        var rollingSearch = document.getElementById('rollingUserSearch');
                        var rollingResults = document.getElementById('rollingUserResults');
                        var rollingEmailInput = document.getElementById('rollingUserEmail');
                        var rollingEnrollBtn = document.getElementById('rollingEnrollBtn');
                        var rollingOrgTag = document.getElementById('rollingOrgTag');

                        if (rollingSearch) {
                            var searchTimeout = null;
                            rollingSearch.addEventListener('input', function() {
                                var q = this.value.trim();
                                if (rollingEmailInput) rollingEmailInput.value = '';
                                updateRollingEnrollBtn();
                                if (q.length < 2) { rollingResults.style.display = 'none'; return; }
                                clearTimeout(searchTimeout);
                                searchTimeout = setTimeout(function() {
                                    fetch('ajax/search_users.php?search=' + encodeURIComponent(q))
                                        .then(function(r) { return r.json(); })
                                        .then(function(data) {
                                            rollingResults.innerHTML = '';
                                            if (data.success && data.users && data.users.length > 0) {
                                                data.users.forEach(function(u) {
                                                    var item = document.createElement('a');
                                                    item.href = '#';
                                                    item.className = 'list-group-item list-group-item-action py-1 small';
                                                    item.textContent = (u.name || '') + ' (' + u.email + ')';
                                                    item.addEventListener('click', function(e) {
                                                        e.preventDefault();
                                                        rollingSearch.value = (u.name || '') + ' (' + u.email + ')';
                                                        rollingEmailInput.value = u.email;
                                                        rollingResults.style.display = 'none';
                                                        if (rollingOrgTag) rollingOrgTag.value = '';
                                                        updateRollingEnrollBtn();
                                                    });
                                                    rollingResults.appendChild(item);
                                                });
                                                rollingResults.style.display = 'block';
                                            } else {
                                                rollingResults.style.display = 'none';
                                            }
                                        });
                                }, 300);
                            });

                            document.addEventListener('click', function(e) {
                                if (!rollingResults.contains(e.target) && e.target !== rollingSearch) {
                                    rollingResults.style.display = 'none';
                                }
                            });
                        }

                        if (rollingOrgTag) {
                            rollingOrgTag.addEventListener('change', function() {
                                if (this.value) {
                                    rollingSearch.value = '';
                                    rollingEmailInput.value = '';
                                }
                                updateRollingEnrollBtn();
                            });
                        }

                        function updateRollingEnrollBtn() {
                            if (!rollingEnrollBtn) return;
                            var hasUser = rollingEmailInput && rollingEmailInput.value;
                            var hasTag = rollingOrgTag && rollingOrgTag.value;
                            rollingEnrollBtn.disabled = !(hasUser || hasTag);
                        }

                        if (rollingEnrollBtn) {
                            rollingEnrollBtn.addEventListener('click', function() {
                                var btn = this;
                                var resultDiv = document.getElementById('rollingEnrollResult');
                                var email = rollingEmailInput ? rollingEmailInput.value : '';
                                var orgTag = rollingOrgTag ? rollingOrgTag.value : '';
                                var startDate = document.getElementById('rollingStartDate').value;

                                if (!email && !orgTag) return;

                                btn.disabled = true;
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Skriver in...';
                                resultDiv.style.display = 'none';

                                var formData = new FormData();
                                formData.append('course_id', '<?= $courseId ?? '' ?>');
                                formData.append('csrf_token', CSRF_TOKEN);
                                formData.append('start_date', startDate);
                                if (email) formData.append('email', email);
                                if (orgTag) formData.append('org_tag', orgTag);

                                fetch('ajax/enroll_user_sequential.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    resultDiv.style.display = 'block';
                                    resultDiv.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
                                    resultDiv.textContent = data.message || 'Ett fel uppstod.';
                                    btn.disabled = false;
                                    btn.innerHTML = '<i class="bi bi-person-plus me-1"></i>Skriv in';
                                    if (data.success && data.enrolled > 0) {
                                        rollingSearch.value = '';
                                        rollingEmailInput.value = '';
                                        if (rollingOrgTag) rollingOrgTag.value = '';
                                        updateRollingEnrollBtn();
                                        // Ladda om sidan efter 2 sekunder för att visa uppdaterad lista
                                        setTimeout(function() { location.reload(); }, 2000);
                                    }
                                })
                                .catch(function() {
                                    resultDiv.style.display = 'block';
                                    resultDiv.className = 'alert alert-danger';
                                    resultDiv.textContent = 'Nätverksfel. Försök igen.';
                                    btn.disabled = false;
                                    btn.innerHTML = '<i class="bi bi-person-plus me-1"></i>Skriv in';
                                });
                            });
                        }

                        // Testmail
                        const seqTestBtn = document.getElementById('sendSeqTestBtn');
                        if (seqTestBtn) {
                            seqTestBtn.addEventListener('click', function() {
                                const btn = this;
                                const email = document.getElementById('seqTestEmail').value;
                                const resultDiv = document.getElementById('seqTestResult');

                                if (!email) { resultDiv.style.display = 'block'; resultDiv.className = 'mt-2 alert alert-danger'; resultDiv.textContent = 'Ange en e-postadress.'; return; }

                                btn.disabled = true;
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Skickar...';
                                resultDiv.style.display = 'none';

                                const formData = new FormData();
                                formData.append('course_id', '<?= (int)($_GET['id'] ?? 0) ?>');
                                formData.append('test_email', email);
                                formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');

                                fetch('ajax/send_sequential_test_email.php', { method: 'POST', body: formData })
                                    .then(r => r.json())
                                    .then(data => {
                                        resultDiv.style.display = 'block';
                                        resultDiv.className = 'mt-2 alert ' + (data.success ? 'alert-success' : 'alert-danger');
                                        resultDiv.textContent = data.message;
                                    })
                                    .catch(() => {
                                        resultDiv.style.display = 'block';
                                        resultDiv.className = 'mt-2 alert alert-danger';
                                        resultDiv.textContent = 'Nätverksfel.';
                                    })
                                    .finally(() => {
                                        btn.disabled = false;
                                        btn.innerHTML = '<i class="bi bi-send me-1"></i>Skicka test';
                                    });
                            });
                        }

                        // Starta utskick
                        const triggerBtn = document.getElementById('triggerStartBtn');
                        if (triggerBtn) {
                            triggerBtn.addEventListener('click', function() {
                                if (!confirm('Vill du starta utskicket? Alla berörda användare kommer registreras och få första lektionen.')) return;

                                const btn = this;
                                const resultDiv = document.getElementById('triggerStartResult');

                                btn.disabled = true;
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Startar...';
                                resultDiv.style.display = 'none';

                                const formData = new FormData();
                                formData.append('course_id', '<?= (int)($_GET['id'] ?? 0) ?>');
                                formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');

                                fetch('ajax/trigger_sequential_start.php', { method: 'POST', body: formData })
                                    .then(r => r.json())
                                    .then(data => {
                                        resultDiv.style.display = 'block';
                                        resultDiv.className = 'mt-2 alert ' + (data.success ? 'alert-success' : 'alert-danger');
                                        resultDiv.textContent = data.message;
                                        if (data.success) { btn.style.display = 'none'; }
                                    })
                                    .catch(() => {
                                        resultDiv.style.display = 'block';
                                        resultDiv.className = 'mt-2 alert alert-danger';
                                        resultDiv.textContent = 'Nätverksfel.';
                                    })
                                    .finally(() => {
                                        btn.disabled = false;
                                        btn.innerHTML = '<i class="bi bi-play-circle me-1"></i>Starta utskick nu';
                                    });
                            });
                        }
                        </script>

                        </div><!-- /.tab-section sequential -->

                        <!-- Tab: Tilldelning & synlighet -->
                        <div class="course-tab-section" data-tab-section="assignment" style="display:none;">

                        <?php if (!empty($availableTags)): ?>
                        <div class="mb-3">
                            <label class="form-label">Taggar</label>
                            <div class="row">
                                <?php foreach ($availableTags as $tag): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="tags[]" value="<?= $tag['id'] ?>"
                                               id="tag_<?= $tag['id'] ?>"
                                               <?= in_array($tag['id'], $courseTags) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="tag_<?= $tag['id'] ?>">
                                            <span class="badge bg-primary"><?= htmlspecialchars($tag['name']) ?></span>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">
                                Välj en eller flera taggar för kursen.
                                <a href="tags.php" class="text-decoration-none">Hantera taggar</a>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label">Taggar</label>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle me-2"></i>
                                Inga taggar har skapats för din organisation ännu.
                                <a href="tags.php" class="alert-link">Skapa taggar</a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Domän-delning (primär mekanism för att begränsa inom org) -->
                        <?php
                        // Hämta användarens org + alla orgens domäner
                        $courseOrgDomainList = [];
                        $userOrgRow = !empty($userDomain) ? getOrganizationByDomain($userDomain) : null;
                        if ($userOrgRow) {
                            $courseOrgDomainList = getOrganizationDomains($userOrgRow['id']);
                        } elseif (!empty($userDomain)) {
                            $courseOrgDomainList = [$userDomain];
                        }
                        $courseSharedDomains = !empty($course['id']) ? getCourseSharedDomains($course['id']) : [];
                        $shareMode = empty($courseSharedDomains) ? 'whole_org' : 'specific_domains';
                        // Default: alla domäner markerade när inget explicit val finns (ny kurs / hela org)
                        $prefillAllDomains = ($shareMode === 'whole_org');
                        ?>
                        <?php if ($isOnPrimaryDomain): ?>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <label class="form-label fw-semibold mb-0"><i class="bi bi-eye me-1 text-secondary"></i>Synlighet — vem ska se kursen i sin kurskatalog?</label>
                                <span class="badge bg-secondary"><i class="bi bi-eye-fill me-1"></i>Passiv — inga mail</span>
                            </div>
                            <div class="alert alert-secondary py-2 small mb-3">
                                <i class="bi bi-info-circle me-1"></i><strong>Synlighetsinställning.</strong>
                                Styr vilka användare som <em>ser</em> kursen i sin kurskatalog och kan välja att starta den själva. <strong>Inga mail skickas automatiskt.</strong>
                                Om du istället vill aktivt tilldela kursen till specifika användare (med mail + påminnelser) — använd <em>"Aktiv inskrivning av användare"</em> i sektionen för stegvisa kurser ovan.
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="share_mode" id="share_mode_org" value="whole_org" <?= $shareMode === 'whole_org' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="share_mode_org">
                                    <strong>Delas med hela organisationen</strong>
                                    <?php if ($userOrgRow): ?>
                                    <div class="small text-muted">Alla användare i <strong><?= htmlspecialchars($userOrgRow['name']) ?></strong> (<?= count($courseOrgDomainList) ?> domäner) ser kursen.</div>
                                    <?php else: ?>
                                    <div class="small text-muted">Alla användare på <strong><?= htmlspecialchars($userDomain) ?></strong> ser kursen.</div>
                                    <?php endif; ?>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="share_mode" id="share_mode_specific" value="specific_domains" <?= $shareMode === 'specific_domains' ? 'checked' : '' ?> <?= count($courseOrgDomainList) < 2 ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="share_mode_specific">
                                    <strong>Dela med vissa domäner inom organisationen</strong>
                                    <?php if (count($courseOrgDomainList) < 2): ?>
                                    <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Kräver att organisationen har flera grupperade domäner — din organisation har bara <?= count($courseOrgDomainList) ?>.</div>
                                    <?php else: ?>
                                    <div class="small text-muted">Endast användare med e-post i valda domäner ser kursen.</div>
                                    <?php endif; ?>
                                </label>
                            </div>

                            <div id="specificDomainsBox" class="ms-4 mt-2" style="<?= $shareMode === 'specific_domains' ? '' : 'display:none;' ?>">
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="shareDomainsSelectAll">Markera alla</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="shareDomainsSelectNone">Avmarkera alla</button>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($courseOrgDomainList as $dom): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="form-check">
                                            <input class="form-check-input share-domain-check" type="checkbox" name="shared_domains[]"
                                                   id="share_dom_<?= htmlspecialchars(md5($dom)) ?>" value="<?= htmlspecialchars($dom) ?>"
                                                   <?= ($prefillAllDomains || in_array($dom, $courseSharedDomains, true)) ? 'checked' : '' ?>>
                                            <label class="form-check-label font-monospace small" for="share_dom_<?= htmlspecialchars(md5($dom)) ?>">
                                                <?= htmlspecialchars($dom) ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text mt-1"><i class="bi bi-exclamation-circle me-1"></i>Om inga domäner markeras blir kursen ej synlig för någon — välj minst en, eller byt till "Delas med hela organisationen".</div>
                            </div>
                        </div>

                        <script>
                        (function() {
                            var radioOrg = document.getElementById('share_mode_org');
                            var radioSpecific = document.getElementById('share_mode_specific');
                            var box = document.getElementById('specificDomainsBox');
                            var selectAll = document.getElementById('shareDomainsSelectAll');
                            var selectNone = document.getElementById('shareDomainsSelectNone');
                            function sync() { box.style.display = radioSpecific.checked ? '' : 'none'; }
                            if (radioOrg) radioOrg.addEventListener('change', sync);
                            if (radioSpecific) radioSpecific.addEventListener('change', sync);
                            if (selectAll) selectAll.addEventListener('click', function() {
                                document.querySelectorAll('.share-domain-check').forEach(function(cb) { cb.checked = true; });
                            });
                            if (selectNone) selectNone.addEventListener('click', function() {
                                document.querySelectorAll('.share-domain-check').forEach(function(cb) { cb.checked = false; });
                            });
                        })();
                        </script>
                        <?php else: ?>
                        <div class="alert alert-secondary py-2 small mb-4">
                            <i class="bi bi-info-circle me-1"></i>
                            Synlighet styrs av administratör på organisationens huvuddomän. Kursen blir synlig för användare på din egen domän.
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($availableOrgTags)): ?>
                        <?php
                        // Sortera i bokstavsordning
                        $sortedOrgTags = $availableOrgTags;
                        usort($sortedOrgTags, function($a, $b) { return strcasecmp($a['tag'], $b['tag']); });

                        // Dela upp i 5 kolumner, jämnt fördelade
                        $colCount = 5;
                        $totalTags = count($sortedOrgTags);
                        $perCol = (int)ceil($totalTags / $colCount);
                        $columns = [];
                        for ($i = 0; $i < $colCount; $i++) {
                            $columns[$i] = array_slice($sortedOrgTags, $i * $perCol, $perCol);
                        }
                        // Beräkna bokstavsetikett per kolumn
                        $colLabels = [];
                        foreach ($columns as $ci => $colTags) {
                            if (empty($colTags)) {
                                $colLabels[$ci] = '';
                                continue;
                            }
                            $first = mb_strtoupper(mb_substr($colTags[0]['tag'], 0, 1));
                            $last = mb_strtoupper(mb_substr(end($colTags)['tag'], 0, 1));
                            $colLabels[$ci] = ($first === $last) ? $first : $first . '–' . $last;
                        }
                        ?>
                        <div class="mb-3">
                            <label class="form-label">Organisationstaggar</label>
                            <div class="form-text mb-2">
                                Frivillig segmentering inom organisationen (avdelning, roll). Användare måste ha minst en matchande tagg för att se kursen — eller så krävs inga taggar om rutan ovan är satt till hela organisationen.
                            </div>

                            <!-- Valda taggar -->
                            <div id="selectedOrgTags" class="mb-2 d-flex flex-wrap gap-1">
                                <?php foreach ($sortedOrgTags as $orgTag):
                                    if (in_array($orgTag['tag'], $courseOrgTags)):
                                ?>
                                <span class="badge bg-success d-inline-flex align-items-center py-2 px-3 selected-org-tag" data-tag="<?= htmlspecialchars($orgTag['tag']) ?>">
                                    <?= htmlspecialchars($orgTag['tag']) ?>
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: .55rem;" aria-label="Ta bort"></button>
                                    <input type="hidden" name="org_tags[]" value="<?= htmlspecialchars($orgTag['tag']) ?>">
                                </span>
                                <?php
                                    endif;
                                endforeach; ?>
                            </div>

                            <!-- Sökfält + knappar -->
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="orgTagSearch" placeholder="Sök organisationstaggar..." autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary" id="orgTagSelectAll" title="Markera alla synliga">Alla</button>
                                <button type="button" class="btn btn-outline-secondary" id="orgTagClearAll" title="Rensa alla">Rensa</button>
                            </div>

                            <!-- 5-kolumns tagg-lista -->
                            <div id="orgTagList" style="max-height: 340px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: .375rem; background: #fff;">
                                <div class="row g-0">
                                    <?php foreach ($columns as $ci => $colTags): ?>
                                    <?php if (!empty($colTags)): ?>
                                    <div class="col org-tag-column" style="min-width: 0; border-right: <?= $ci < $colCount - 1 ? '1px solid #dee2e6' : 'none' ?>;">
                                        <div class="px-2 py-1 bg-light border-bottom text-center" style="position: sticky; top: 0; z-index: 1;">
                                            <small class="fw-bold text-muted"><?= $colLabels[$ci] ?></small>
                                        </div>
                                        <?php foreach ($colTags as $orgTag):
                                            $isSelected = in_array($orgTag['tag'], $courseOrgTags);
                                        ?>
                                        <div class="org-tag-item px-2 py-1 <?= $isSelected ? 'org-tag-selected' : '' ?>"
                                             data-tag="<?= htmlspecialchars($orgTag['tag']) ?>"
                                             data-search="<?= htmlspecialchars(mb_strtolower($orgTag['tag'])) ?>"
                                             role="button"
                                             style="cursor: pointer; font-size: .82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"
                                             title="<?= htmlspecialchars($orgTag['tag']) ?>">
                                            <i class="bi bi-check-circle-fill text-success me-1 org-tag-icon-sel" style="display: <?= $isSelected ? 'inline' : 'none' ?>;"></i>
                                            <i class="bi bi-plus-circle text-muted me-1 org-tag-icon-add" style="display: <?= $isSelected ? 'none' : 'inline' ?>;"></i>
                                            <span><?= htmlspecialchars($orgTag['tag']) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <div id="orgTagNoResults" class="text-muted text-center py-3" style="display: none;">
                                    Inga taggar matchar sökningen.
                                </div>
                            </div>
                            <div class="form-text mt-1">
                                <span id="orgTagCount"><?= count($courseOrgTags) ?></span> av <?= $totalTags ?> taggar valda.
                            </div>
                        </div>
                        <style>
                        .org-tag-item:hover { background-color: #f0f7ff; }
                        .org-tag-item.org-tag-selected { background-color: #e8f5e9; }
                        .org-tag-item.org-tag-hidden { display: none !important; }
                        </style>
                        <script>
                        (function() {
                            const searchInput = document.getElementById('orgTagSearch');
                            const tagList = document.getElementById('orgTagList');
                            const selectedContainer = document.getElementById('selectedOrgTags');
                            const countEl = document.getElementById('orgTagCount');
                            const noResults = document.getElementById('orgTagNoResults');
                            const allItems = tagList.querySelectorAll('.org-tag-item');
                            const colHeaders = tagList.querySelectorAll('.org-tag-column > .bg-light');

                            function updateCount() {
                                countEl.textContent = selectedContainer.querySelectorAll('.selected-org-tag').length;
                            }

                            function markItemSelected(item, selected) {
                                const iconSel = item.querySelector('.org-tag-icon-sel');
                                const iconAdd = item.querySelector('.org-tag-icon-add');
                                if (selected) {
                                    item.classList.add('org-tag-selected');
                                    iconSel.style.display = 'inline';
                                    iconAdd.style.display = 'none';
                                } else {
                                    item.classList.remove('org-tag-selected');
                                    iconSel.style.display = 'none';
                                    iconAdd.style.display = 'inline';
                                }
                            }

                            function addTag(tagValue) {
                                if (selectedContainer.querySelector('[data-tag="' + CSS.escape(tagValue) + '"]')) return;

                                const badge = document.createElement('span');
                                badge.className = 'badge bg-success d-inline-flex align-items-center py-2 px-3 selected-org-tag';
                                badge.setAttribute('data-tag', tagValue);
                                badge.appendChild(document.createTextNode(tagValue));

                                const closeBtn = document.createElement('button');
                                closeBtn.type = 'button';
                                closeBtn.className = 'btn-close btn-close-white ms-2';
                                closeBtn.style.fontSize = '.55rem';
                                closeBtn.setAttribute('aria-label', 'Ta bort');
                                badge.appendChild(closeBtn);

                                const hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = 'org_tags[]';
                                hidden.value = tagValue;
                                badge.appendChild(hidden);

                                selectedContainer.appendChild(badge);

                                const listItem = tagList.querySelector('.org-tag-item[data-tag="' + CSS.escape(tagValue) + '"]');
                                if (listItem) markItemSelected(listItem, true);

                                updateCount();
                            }

                            function removeTag(tagValue) {
                                const badge = selectedContainer.querySelector('.selected-org-tag[data-tag="' + CSS.escape(tagValue) + '"]');
                                if (badge) badge.remove();

                                const listItem = tagList.querySelector('.org-tag-item[data-tag="' + CSS.escape(tagValue) + '"]');
                                if (listItem) markItemSelected(listItem, false);

                                updateCount();
                            }

                            function filterList() {
                                const term = searchInput.value.toLowerCase().trim();
                                let visibleCount = 0;
                                allItems.forEach(function(item) {
                                    const match = !term || item.dataset.search.indexOf(term) !== -1;
                                    if (match) {
                                        item.classList.remove('org-tag-hidden');
                                        visibleCount++;
                                    } else {
                                        item.classList.add('org-tag-hidden');
                                    }
                                });
                                noResults.style.display = visibleCount === 0 ? '' : 'none';

                                // Uppdatera kolumnrubriker vid sökning
                                tagList.querySelectorAll('.org-tag-column').forEach(function(col) {
                                    const visibleItems = col.querySelectorAll('.org-tag-item:not(.org-tag-hidden)');
                                    const header = col.querySelector('.bg-light small');
                                    if (visibleItems.length === 0) {
                                        col.style.display = 'none';
                                    } else {
                                        col.style.display = '';
                                        if (term) {
                                            const first = visibleItems[0].dataset.tag.charAt(0).toUpperCase();
                                            const last = visibleItems[visibleItems.length - 1].dataset.tag.charAt(0).toUpperCase();
                                            header.textContent = first === last ? first : first + '–' + last;
                                        }
                                    }
                                });

                                // Återställ rubriker om sökning rensas
                                if (!term) {
                                    const labels = <?= json_encode(array_values($colLabels)) ?>;
                                    tagList.querySelectorAll('.org-tag-column').forEach(function(col, i) {
                                        col.style.display = '';
                                        col.querySelector('.bg-light small').textContent = labels[i] || '';
                                    });
                                }
                            }

                            // Klicka i listan -> toggla
                            tagList.addEventListener('click', function(e) {
                                const item = e.target.closest('.org-tag-item');
                                if (!item) return;
                                const tag = item.dataset.tag;
                                if (item.classList.contains('org-tag-selected')) {
                                    removeTag(tag);
                                } else {
                                    addTag(tag);
                                }
                            });

                            // Klicka X på badge -> ta bort
                            selectedContainer.addEventListener('click', function(e) {
                                if (e.target.classList.contains('btn-close')) {
                                    const badge = e.target.closest('.selected-org-tag');
                                    if (badge) removeTag(badge.dataset.tag);
                                }
                            });

                            searchInput.addEventListener('input', filterList);

                            document.getElementById('orgTagSelectAll').addEventListener('click', function() {
                                allItems.forEach(function(item) {
                                    if (!item.classList.contains('org-tag-hidden')) {
                                        addTag(item.dataset.tag);
                                    }
                                });
                            });

                            document.getElementById('orgTagClearAll').addEventListener('click', function() {
                                selectedContainer.querySelectorAll('.selected-org-tag').forEach(function(badge) {
                                    removeTag(badge.dataset.tag);
                                });
                                searchInput.value = '';
                                filterList();
                            });
                        })();
                        </script>
                        <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label">Organisationstaggar</label>
                            <div class="alert alert-light border mb-0">
                                <i class="bi bi-info-circle me-2"></i>
                                Inga organisationstaggar finns för din domän. De skapas automatiskt vid API-synkronisering av användare.
                            </div>
                        </div>
                        <?php endif; ?>

                        </div><!-- /.tab-section assignment -->

                        <script>
                        (function(){
                            var tabBtns = document.querySelectorAll('#editCourseTabs [data-course-tab]');
                            // Sektionerna re-queryas vid varje flikbyte eftersom Kurs-
                            // redaktörer-blocket ligger UTANFÖR formuläret och därför
                            // inte är parsat när scriptet kör inline.
                            function showTab(name) {
                                tabBtns.forEach(function(b){ b.classList.toggle('active', b.dataset.courseTab === name); });
                                document.querySelectorAll('[data-tab-section]').forEach(function(s){
                                    s.style.display = (s.dataset.tabSection === name) ? '' : 'none';
                                });
                                try { history.replaceState(null, '', '#tab=' + name); } catch (e) {}
                            }
                            tabBtns.forEach(function(b){
                                b.addEventListener('click', function(e){ e.preventDefault(); showTab(this.dataset.courseTab); });
                            });
                            // Hoppa till rätt flik vid invalid required-fält så browsern kan
                            // visa felmeddelande på fältet (annars är fältet dolt → tom no-op).
                            var form = document.querySelector('#editCourseTabs').closest('form');
                            if (form) form.addEventListener('invalid', function(e){
                                var section = e.target.closest('[data-tab-section]');
                                if (section) showTab(section.dataset.tabSection);
                            }, true);
                            // Initial-state. Måste köras efter DOMContentLoaded så att
                            // Kursredaktörer-blocket utanför formet hinner parseras.
                            function applyInitial() {
                                var match = (location.hash || '').match(/tab=([a-z]+)/i);
                                var initialTab = (match && document.querySelector('[data-tab-section="' + match[1] + '"]'))
                                    ? match[1] : 'content';
                                showTab(initialTab);
                            }
                            if (document.readyState === 'loading') {
                                document.addEventListener('DOMContentLoaded', applyInitial);
                            } else {
                                applyInitial();
                            }

                            // Bootstrap tooltips (info-ikonerna i Innehåll-fliken). Init görs
                            // när bootstrap-bundle har laddats; använd kort polling som
                            // fallback om scripten ännu inte är klar vid DOM-parse.
                            function initTooltips() {
                                if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return false;
                                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                                });
                                return true;
                            }
                            if (!initTooltips()) {
                                var tries = 0;
                                var iv = setInterval(function() {
                                    if (initTooltips() || ++tries > 30) clearInterval(iv);
                                }, 100);
                            }
                        })();
                        </script>

                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-primary">Spara</button>
                            <a href="courses.php" class="btn btn-secondary">Avbryt</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (isset($course['id'])): ?>
<!-- Kursredaktörer hör till "Allmänt"-fliken (data-tab-section="content")
     så den följer flikbytet som resten av sidan. -->
<div class="course-tab-section" data-tab-section="content">
<div class="row mt-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header">
                <h6 class="m-0 font-weight-bold text-muted">Kursredaktörer</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="input-group">
                        <input type="text" class="form-control" id="editorSearch" placeholder="Sök efter användare...">
                        <button class="btn btn-primary" type="button" id="addEditorBtn" disabled>Lägg till redaktör</button>
                    </div>
                    <div id="userSearchResults" class="list-group mt-2" style="display: none;"></div>
                </div>
                <div id="editorsList">
                    <?php
                    $editors = queryAll("SELECT ce.email, u.name 
                                       FROM " . DB_DATABASE . ".course_editors ce 
                                       JOIN " . DB_DATABASE . ".users u ON ce.email COLLATE utf8mb4_swedish_ci = u.email COLLATE utf8mb4_swedish_ci 
                                       WHERE ce.course_id = ?", [$course['id']]);
                    
                    foreach ($editors as $editor):
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-2 editor-item" data-email="<?= htmlspecialchars($editor['email']) ?>">
                        <span><?= htmlspecialchars($editor['name'] ?? $editor['email']) ?></span>
                        <button class="btn btn-sm btn-danger remove-editor" type="button">Ta bort</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dynamisk statusindikator
    const statusToggle = document.getElementById('status');
    const statusCard = document.getElementById('statusCard');
    const statusIcon = document.getElementById('statusIcon');
    const statusText = document.getElementById('statusText');
    if (statusToggle) {
        statusToggle.addEventListener('change', function() {
            if (this.checked) {
                statusCard.className = 'card mb-3 border-success';
                statusIcon.className = 'bi bi-check-circle-fill text-success me-2 fs-5';
                statusText.textContent = 'Kursen är aktiv och synlig';
            } else {
                statusCard.className = 'card mb-3 border-warning';
                statusIcon.className = 'bi bi-pause-circle-fill text-warning me-2 fs-5';
                statusText.textContent = 'Kursen är inaktiv och dold';
            }
        });
    }

    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Validera filtyp
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                alert('Endast JPG, PNG och GIF bilder är tillåtna.');
                e.target.value = '';
                return;
            }

            // Validera filstorlek (5MB)
            const maxSize = 5 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('Bilden får inte vara större än 5MB. Din bild är ' + (file.size / 1024 / 1024).toFixed(2) + ' MB.');
                e.target.value = '';
                return;
            }

            // Visa förhandsvisning av vald bild
            const reader = new FileReader();
            reader.onload = function(event) {
                const imageContainer = document.getElementById('current-image-container');
                const currentImage = document.getElementById('current-image');
                const imagePath = document.getElementById('image-path');

                currentImage.src = event.target.result;
                imagePath.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Ny bild vald: ' + file.name + ' (sparas när du klickar Spara)</span>';
                imageContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    }

    // AI Image Generation för kurs
    const generateAiImageBtn = document.getElementById('generate-ai-image-btn');
    if (generateAiImageBtn) {
        generateAiImageBtn.addEventListener('click', function() {
            const courseId = <?= $course['id'] ?? 0 ?>;
            const courseTitle = document.getElementById('title').value.trim();
            const courseDescription = document.getElementById('description').value.trim();
            const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';

            if (!courseTitle) {
                alert('Ange en titel för kursen först.');
                return;
            }

            if (courseId <= 0) {
                alert('Spara kursen först innan du genererar en AI-bild.');
                return;
            }

            // Show loading status
            const statusDiv = document.getElementById('ai-image-status');
            statusDiv.style.display = 'block';
            generateAiImageBtn.disabled = true;

            // Make AJAX call
            const formData = new FormData();
            formData.append('course_id', courseId);
            formData.append('course_title', courseTitle);
            formData.append('course_description', courseDescription);
            formData.append('csrf_token', csrfToken);

            fetch('ajax/generate_course_image.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                statusDiv.style.display = 'none';
                generateAiImageBtn.disabled = false;

                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    alert('Serverfel: ' + text.substring(0, 200));
                    return;
                }

                if (data.success) {
                    // Update image preview
                    const imageContainer = document.getElementById('current-image-container');
                    const currentImage = document.getElementById('current-image');
                    const imageUrlInput = document.getElementById('image_url');
                    const imagePath = document.getElementById('image-path');

                    currentImage.src = '../upload/' + data.image_url;
                    imageUrlInput.value = data.image_url;
                    imagePath.textContent = 'Sökväg: ' + data.image_url;
                    imageContainer.style.display = 'block';

                    alert('Bild genererad!');
                } else {
                    alert('Fel: ' + (data.message || 'Kunde inte generera bild.'));
                }
            })
            .catch(error => {
                statusDiv.style.display = 'none';
                generateAiImageBtn.disabled = false;
                console.error('Fetch error:', error);
                alert('Nätverksfel vid generering av bild.');
            });
        });
    }

    // Hantera kursredaktörer
    const addEditorBtn = document.getElementById('addEditorBtn');
    const editorSearch = document.getElementById('editorSearch');
    const userSearchResults = document.getElementById('userSearchResults');
    const editorsList = document.getElementById('editorsList');
    const courseId = <?= $course['id'] ?? 'null' ?>;
    let selectedUser = null;

    if (addEditorBtn && courseId) {
        // Sök efter användare när användaren skriver
        editorSearch.addEventListener('input', function() {
            const search = this.value.trim();
            if (search.length < 2) {
                userSearchResults.style.display = 'none';
                addEditorBtn.disabled = true;
                return;
            }

            fetch(`ajax/search_users.php?search=${encodeURIComponent(search)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        userSearchResults.innerHTML = '';
                        if (data.users.length > 0) {
                            data.users.forEach(user => {
                                const item = document.createElement('a');
                                item.href = '#';
                                item.className = 'list-group-item list-group-item-action';
                                item.textContent = user.name ? `${user.name} (${user.email})` : user.email;
                                item.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    editorSearch.value = user.name ? `${user.name} (${user.email})` : user.email;
                                    selectedUser = user;
                                    userSearchResults.style.display = 'none';
                                    addEditorBtn.disabled = false;
                                });
                                userSearchResults.appendChild(item);
                            });
                            userSearchResults.style.display = 'block';
                        } else {
                            const noResults = document.createElement('div');
                            noResults.className = 'list-group-item';
                            if (data.message) {
                                const alert = document.createElement('div');
                                alert.className = 'alert alert-warning mb-0';
                                alert.innerHTML = `
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    ${data.message}
                                `;
                                noResults.appendChild(alert);
                            } else {
                                noResults.textContent = 'Inga användare hittades';
                                noResults.classList.add('text-muted');
                            }
                            userSearchResults.appendChild(noResults);
                            userSearchResults.style.display = 'block';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        });

        // Lägg till redaktör
        addEditorBtn.addEventListener('click', function() {
            if (!selectedUser) return;

            fetch('ajax/add_course_editor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `course_id=${courseId}&email=${encodeURIComponent(selectedUser.email)}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const editorItem = document.createElement('div');
                    editorItem.className = 'd-flex justify-content-between align-items-center mb-2 editor-item';
                    editorItem.setAttribute('data-email', selectedUser.email);
                    editorItem.innerHTML = `
                        <span>${selectedUser.name ? `${selectedUser.name} (${selectedUser.email})` : selectedUser.email}</span>
                        <button class="btn btn-sm btn-danger remove-editor" type="button">Ta bort</button>
                    `;
                    editorsList.appendChild(editorItem);
                    editorSearch.value = '';
                    selectedUser = null;
                    addEditorBtn.disabled = true;
                } else {
                    alert(data.message || 'Ett fel uppstod');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ett fel uppstod');
            });
        });

        // Ta bort redaktör
        editorsList.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-editor')) {
                const editorItem = e.target.closest('.editor-item');
                const email = editorItem.getAttribute('data-email');

                fetch('ajax/remove_course_editor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `course_id=${courseId}&email=${encodeURIComponent(email)}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        editorItem.remove();
                    } else {
                        alert(data.message || 'Ett fel uppstod');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Ett fel uppstod');
                });
            }
        });

        // Dölj sökresultat när man klickar utanför
        document.addEventListener('click', function(e) {
            if (!editorSearch.contains(e.target) && !userSearchResults.contains(e.target)) {
                userSearchResults.style.display = 'none';
            }
        });
    }
});
</script>
</div><!-- /.tab-section content (Kursredaktörer) -->
<?php endif; ?>

<?php
// Inkludera footer
require_once 'include/footer.php';
