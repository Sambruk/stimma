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

// Sätt sidtitel
$page_title = isset($_GET['id']) ? 'Redigera lektion' : 'Skapa ny lektion';

// Om ID finns, hämta lektionsinformation
if (isset($_GET['id'])) {
    $lesson = queryOne("SELECT * FROM " . DB_DATABASE . ".lessons WHERE id = ?", [$_GET['id']]);
    if (!$lesson) {
        $_SESSION['message'] = 'Lektionen hittades inte.';
        $_SESSION['message_type'] = 'danger';
        header('Location: lessons.php');
        exit;
    }
}

// Hämta kurser för dropdown — scope:as per behörighet:
//   - Admin: alla kurser i den egna organisationens samtliga domäner
//   - Redaktör: bara kurser man själv är författare till ELLER tilldelats som
//     redaktör för (via course_editors)
$userDomain = substr(strrchr($_SESSION['user_email'], "@"), 1);
$lessonOrgScope = getOrgScopeDomains($_SESSION['user_email']);
$lessonCourseClause = buildDomainInClause($lessonOrgScope, 'c.organization_domain');
if ($isAdmin) {
    $courses = queryAll(
        "SELECT c.* FROM " . DB_DATABASE . ".courses c
         WHERE {$lessonCourseClause['fragment']}
         ORDER BY c.sort_order ASC",
        $lessonCourseClause['params']
    );
} else {
    $courses = queryAll(
        "SELECT DISTINCT c.* FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON ce.course_id = c.id
         WHERE {$lessonCourseClause['fragment']}
           AND (c.author_id = ? OR ce.email = ?)
         ORDER BY c.sort_order ASC",
        array_merge($lessonCourseClause['params'], [$_SESSION['user_id'], $_SESSION['user_email']])
    );
}

// Hantera formulär
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $course_id = (int)($_POST['course_id'] ?? 0);
    $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';
    
    // Hantera innehåll från editorn
    $content = $_POST['content'] ?? '';
    $ai_instruction = $_POST['ai_instruction'] ?? '';
    $ai_prompt = $_POST['ai_prompt'] ?? '';
    $quiz_question = $_POST['quiz_question'] ?? '';
    $background_color = trim($_POST['background_color'] ?? '');
    
    // Hantera vanliga textfält
    $quiz_type = $_POST['quiz_type'] ?? 'single_choice';
    $quiz_answer1 = trim($_POST['quiz_answer1'] ?? '');
    $quiz_answer2 = trim($_POST['quiz_answer2'] ?? '');
    $quiz_answer3 = trim($_POST['quiz_answer3'] ?? '');
    $quiz_answer4 = trim($_POST['quiz_answer4'] ?? '');
    $quiz_answer5 = trim($_POST['quiz_answer5'] ?? '');
    $quiz_correct_answer = (int)($_POST['quiz_correct_answer'] ?? 0);
    $quiz_correct_answers = trim($_POST['quiz_correct_answers'] ?? '');
    
    // Hantera bilduppladdning
    // SECURITY FIX: Enhanced file upload validation
    $image_url = $_POST['image_url'] ?? ''; // Behåll befintlig bild om ingen ny laddas upp

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $maxWidth = 1920;
        $maxHeight = 1080;

        $file = $_FILES['image'];
        // SECURITY FIX: Get extension from actual filename, not user-supplied
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Validera MIME-typ
        if (!in_array($file['type'], $allowedTypes)) {
            $error = 'Endast JPG, PNG och GIF bilder är tillåtna.';
        }
        // SECURITY FIX: Validate file extension
        elseif (!in_array($extension, $allowedExtensions)) {
            $error = 'Ogiltig filändelse. Endast JPG, PNG och GIF är tillåtna.';
        }
        // Validera storlek
        elseif ($file['size'] > $maxSize) {
            $error = 'Bilden får inte vara större än 5MB.';
        }
        else {
            // SECURITY FIX: Validate that file is actually an image using getimagesize
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                $error = 'Filen är inte en giltig bild.';
            }
            // SECURITY FIX: Validate image dimensions
            elseif ($imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight) {
                $error = "Bilden är för stor. Max dimensioner är {$maxWidth}x{$maxHeight} pixlar.";
            }
            else {
                // Sökväg till upload-mappen
                $uploadDir = __DIR__ . '/../upload/';

                // SECURITY FIX: Generate cryptographically secure random filename
                // Do NOT include any part of the original filename
                $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    // SECURITY FIX: Set restrictive file permissions
                    chmod($targetPath, 0644);
                    $image_url = $fileName;

                    // Ta bort gammal bild om den finns
                    if (isset($lesson['image_url']) && !empty($lesson['image_url'])) {
                        $oldImagePath = $uploadDir . basename($lesson['image_url']);
                        if (file_exists($oldImagePath) && $oldImagePath !== $targetPath) {
                            unlink($oldImagePath);
                        }
                    }
                } else {
                    $error = 'Kunde inte ladda upp bilden.';
                }
            }
        }
    }
    
    // Hantera video (YouTube eller lokal uppladdning)
    $video_type_input = $_POST['video_type'] ?? '';
    $video_filename = $_POST['video_filename'] ?? '';
    $video_url_input = $_POST['video_url'] ?? '';
    $video_url = null;
    $video_type = null;

    if ($video_type_input === 'local' && !empty($video_filename)) {
        $video_url = basename($video_filename); // Prevent path traversal
        $video_type = 'local';
    } elseif ($video_type_input === 'youtube' && !empty($video_url_input)) {
        $video_url = $video_url_input;
        $video_type = 'youtube';
    }

    // Om vi byter från local till annat: radera gammal videofil
    if (isset($lesson) && ($lesson['video_type'] ?? '') === 'local' && !empty($lesson['video_url'])) {
        if ($video_type !== 'local' || $video_url !== $lesson['video_url']) {
            $oldVideoPath = __DIR__ . '/../upload/videos/' . basename($lesson['video_url']);
            if (file_exists($oldVideoPath)) {
                unlink($oldVideoPath);
            }
        }
    }

    // Hantera ljud (uppladdad fil). Tomt värde = inget ljud / ta bort.
    $audio_filename = $_POST['audio_filename'] ?? '';
    $audio_url = !empty($audio_filename) ? basename($audio_filename) : null;

    // Om ljud ändras eller tas bort, radera gammal fil
    if (isset($lesson) && !empty($lesson['audio_url']) && $audio_url !== $lesson['audio_url']) {
        $oldAudioPath = __DIR__ . '/../upload/audio/' . basename($lesson['audio_url']);
        if (file_exists($oldAudioPath)) {
            unlink($oldAudioPath);
        }
    }

    if (empty($title)) {
        $error = 'Titel är obligatoriskt.';
    } elseif ($course_id > 0) {
        // Säkerhetsnät: validera att användaren har rätt att skriva till kursen.
        $targetCourse = queryOne(
            "SELECT organization_domain, author_id FROM " . DB_DATABASE . ".courses WHERE id = ?",
            [$course_id]
        );
        if (!$targetCourse || !in_array($targetCourse['organization_domain'], $lessonOrgScope, true)) {
            $error = 'Du har inte behörighet att skapa/redigera lektioner i den valda kursen.';
        } elseif (!$isAdmin) {
            $isCourseEditor = queryOne(
                "SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?",
                [$course_id, $_SESSION['user_email']]
            );
            $isCourseAuthor = ((int)$targetCourse['author_id'] === (int)$_SESSION['user_id']);
            if (!$isCourseEditor && !$isCourseAuthor) {
                $error = 'Du är inte redaktör eller författare för den valda kursen.';
            }
        }
    }

    if (empty($error) && !empty($title)) {
        if (isset($_GET['id'])) {
            // Uppdatera befintlig lektion
            execute("UPDATE " . DB_DATABASE . ".lessons SET
                    title = ?,
                    content = ?,
                    background_color = ?,
                    course_id = ?,
                    image_url = ?,
                    video_url = ?,
                    video_type = ?,
                    audio_url = ?,
                    status = ?,
                    ai_instruction = ?,
                    ai_prompt = ?,
                    quiz_question = ?,
                    quiz_type = ?,
                    quiz_answer1 = ?,
                    quiz_answer2 = ?,
                    quiz_answer3 = ?,
                    quiz_answer4 = ?,
                    quiz_answer5 = ?,
                    quiz_correct_answer = ?,
                    quiz_correct_answers = ?,
                    updated_at = NOW()
                    WHERE id = ?",
                    [$title, $content, $background_color ?: null, $course_id, $image_url, $video_url, $video_type, $audio_url, $status,
                     $ai_instruction, $ai_prompt, $quiz_question, $quiz_type,
                     $quiz_answer1, $quiz_answer2, $quiz_answer3, $quiz_answer4, $quiz_answer5,
                     $quiz_correct_answer, $quiz_correct_answers, $_GET['id']]);
            
            // Logga ändringen
            logActivity($_SESSION['user_email'], "Uppdaterade lektionen '" . $title . "' (ID: " . $_GET['id'] . ")");
            
            $_SESSION['message'] = 'Lektionen har uppdaterats.';
        } else {
            // Hitta högsta sort_order för denna kurs
            $maxOrder = queryOne("SELECT MAX(sort_order) as max_order FROM " . DB_DATABASE . ".lessons WHERE course_id = ?", [$course_id])['max_order'] ?? 0;
            
            // Hämta användarens ID
            $author = queryOne("SELECT id FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
            $authorId = $author ? $author['id'] : null;
            
            // Skapa ny lektion
            execute("INSERT INTO " . DB_DATABASE . ".lessons
                    (title, content, background_color, course_id, image_url, video_url, video_type, audio_url, status,
                     ai_instruction, ai_prompt, quiz_question, quiz_type,
                     quiz_answer1, quiz_answer2, quiz_answer3, quiz_answer4, quiz_answer5,
                     quiz_correct_answer, quiz_correct_answers,
                     sort_order, author_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [$title, $content, $background_color ?: null, $course_id, $image_url, $video_url, $video_type, $audio_url, $status,
                     $ai_instruction, $ai_prompt, $quiz_question, $quiz_type,
                     $quiz_answer1, $quiz_answer2, $quiz_answer3, $quiz_answer4, $quiz_answer5,
                     $quiz_correct_answer, $quiz_correct_answers,
                     $maxOrder + 1, $authorId]);
            
            $newId = getDb()->lastInsertId();
            logActivity($_SESSION['user_email'], "Skapade ny lektion '" . $title . "' (ID: " . $newId . ")");
            
            $_SESSION['message'] = 'Lektionen har skapats.';
        }
        
        $_SESSION['message_type'] = 'success';
        header('Location: lessons.php?course_id=' . $course_id);
        exit;
    }
}

// Inkludera header
require_once 'include/header.php';

$title = '';
$content = '';
$status = 'active';
$id = null;
$courseId = null;
$imageUrl = '';
$videoUrl = '';
$videoType = '';
$audioUrl = '';
$aiInstruction = '';
$aiPrompt = '';
$quizQuestion = '';
$quizType = 'single_choice';
$quizAnswer1 = '';
$quizAnswer2 = '';
$quizAnswer3 = '';
$quizAnswer4 = '';
$quizAnswer5 = '';
$quizCorrectAnswer = 0;
$quizCorrectAnswers = '';
$backgroundColor = '';

// Kontrollera om vi redigerar en befintlig lektion eller skapar en ny
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    $lesson = queryOne("SELECT * FROM " . DB_DATABASE . ".lessons WHERE id = ?", [$id]);
    
    if ($lesson) {
        $title = $lesson['title'] ?? '';
        $content = $lesson['content'] ?? '';
        $status = $lesson['status'] ?? 'active';
        $courseId = $lesson['course_id'] ?? null;
        $imageUrl = $lesson['image_url'] ?? '';
        $videoUrl = $lesson['video_url'] ?? '';
        $videoType = $lesson['video_type'] ?? '';
        $audioUrl = $lesson['audio_url'] ?? '';
        $aiInstruction = $lesson['ai_instruction'] ?? '';
        $aiPrompt = $lesson['ai_prompt'] ?? '';
        $quizQuestion = $lesson['quiz_question'] ?? '';
        $quizType = $lesson['quiz_type'] ?? 'single_choice';
        $quizAnswer1 = $lesson['quiz_answer1'] ?? '';
        $quizAnswer2 = $lesson['quiz_answer2'] ?? '';
        $quizAnswer3 = $lesson['quiz_answer3'] ?? '';
        $quizAnswer4 = $lesson['quiz_answer4'] ?? '';
        $quizAnswer5 = $lesson['quiz_answer5'] ?? '';
        $quizCorrectAnswer = (int)($lesson['quiz_correct_answer'] ?? 0);
        $quizCorrectAnswers = $lesson['quiz_correct_answers'] ?? '';
        $backgroundColor = $lesson['background_color'] ?? '';
    } else {
        $_SESSION['message'] = "Lektionen kunde inte hittas.";
        $_SESSION['message_type'] = "danger";
        header('Location: courses.php');
        exit;
    }
} elseif (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) {
    // Ny lektion för en specifik kurs
    $courseId = (int)$_GET['course_id'];
    
    // Kontrollera att kursen finns
    $course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
    if (!$course) {
        $_SESSION['message'] = "Kursen kunde inte hittas.";
        $_SESSION['message_type'] = "danger";
        header('Location: courses.php');
        exit;
    }
} else {
    $_SESSION['message'] = "Ingen kurs vald.";
    $_SESSION['message_type'] = "danger";
    header('Location: courses.php');
    exit;
}

// Hämta kurser för dropdown (samma scope-regler som vid första anropet ovan)
if ($isAdmin) {
    $courses = queryAll(
        "SELECT c.* FROM " . DB_DATABASE . ".courses c
         WHERE {$lessonCourseClause['fragment']}
         ORDER BY c.sort_order ASC",
        $lessonCourseClause['params']
    );
} else {
    $courses = queryAll(
        "SELECT DISTINCT c.* FROM " . DB_DATABASE . ".courses c
         LEFT JOIN " . DB_DATABASE . ".course_editors ce ON ce.course_id = c.id
         WHERE {$lessonCourseClause['fragment']}
           AND (c.author_id = ? OR ce.email = ?)
         ORDER BY c.sort_order ASC",
        array_merge($lessonCourseClause['params'], [$_SESSION['user_id'], $_SESSION['user_email']])
    );
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-muted"><?= $id ? 'Redigera lektion' : 'Skapa ny lektion' ?></h6>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?>">
                            <?= htmlspecialchars($_SESSION['message']) ?>
                        </div>
                        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
                    <?php endif; ?>
                    
                    <form method="post" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="title" name="title" 
                                           value="<?= htmlspecialchars($title ?? '') ?>" required>
                                    <label for="title">Titel</label>
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <div class="form-floating">
                                    <select class="form-select" id="course_id" name="course_id" required>
                                        <?php foreach ($courses as $course): ?>
                                            <option value="<?= $course['id'] ?>" 
                                                    <?= $courseId == $course['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($course['title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="course_id">Kurs</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="content" class="form-label fw-normal">Innehåll</label>
                            <?php require_once 'include/editor.php'; renderEditor($content ?? '', 'content', 'contentEditor'); ?>
                        </div>

                        <div class="mb-3">
                            <label for="background_color" class="form-label fw-normal">Bakgrundsfärg för lektionen</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color" id="bg_color_picker"
                                       value="<?= htmlspecialchars($backgroundColor ?: '#ffffff') ?>"
                                       onchange="document.getElementById('background_color').value = this.value">
                                <input type="text" class="form-control" id="background_color" name="background_color"
                                       value="<?= htmlspecialchars($backgroundColor ?? '') ?>"
                                       placeholder="T.ex. #f5f5dc eller lämna tomt för standard"
                                       style="max-width: 200px;"
                                       onchange="if(this.value) document.getElementById('bg_color_picker').value = this.value">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="document.getElementById('background_color').value=''; document.getElementById('bg_color_picker').value='#ffffff';"
                                        title="Återställ till standard">
                                    <i class="bi bi-x-lg"></i> Rensa
                                </button>
                            </div>
                            <small class="text-muted">Lämna tomt för standardbakgrund (vit).</small>
                        </div>

                        <div class="mb-3">
                            <label for="image" class="form-label fw-normal">Bild</label>
                            <div id="current-image-container" class="mb-2" <?= empty($imageUrl) ? 'style="display:none;"' : '' ?>>
                                <p class="text-muted">Nuvarande bild:</p>
                                <img id="current-image" src="<?= !empty($imageUrl) ? '../upload/' . htmlspecialchars($imageUrl) : '' ?>" alt="Lektionsbild" class="img-thumbnail" style="max-width: 200px;">
                                <input type="hidden" name="image_url" id="image_url" value="<?= htmlspecialchars($imageUrl) ?>">
                                <div class="form-text" id="image-path">Sökväg: <?= htmlspecialchars($imageUrl) ?></div>
                            </div>
                            <?php if (!empty($imageUrl)): ?>
                                <p class="text-muted">Ladda upp ny bild för att ersätta den nuvarande:</p>
                            <?php endif; ?>
                            <div class="d-flex gap-2 align-items-start">
                                <div class="flex-grow-1">
                                    <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif">
                                    <div class="form-text">Max 5MB. Tillåtna format: JPG, PNG, GIF</div>
                                </div>
                                <?php if ($id): ?>
                                <button type="button" id="generate-ai-image-btn" class="btn btn-outline-primary" title="Generera AI-bild">
                                    <i class="bi bi-stars"></i> Generera AI-bild
                                </button>
                                <?php endif; ?>
                            </div>
                            <div id="ai-image-status" class="mt-2" style="display: none;">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Genererar...</span>
                                </div>
                                <span class="ms-2 text-muted">Genererar AI-bild, detta kan ta upp till 60 sekunder...</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-normal">Video</label>
                            <input type="hidden" name="video_type" id="video_type_field" value="<?= htmlspecialchars($videoType) ?>">
                            <input type="hidden" name="video_filename" id="video_filename_field" value="<?= $videoType === 'local' ? htmlspecialchars($videoUrl) : '' ?>">

                            <style>
                                #videoTabs .nav-link { color: #495057 !important; background: none; border-left: none; }
                                #videoTabs .nav-link:hover { color: #007bff !important; background: none; border-left: none; }
                                #videoTabs .nav-link.active { color: #212529 !important; background-color: #fff !important; border-left: none; border-bottom-color: #fff !important; }
                            </style>
                            <ul class="nav nav-tabs" id="videoTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link <?= $videoType !== 'local' ? 'active' : '' ?>" id="youtube-tab" data-bs-toggle="tab" data-bs-target="#youtube-pane" type="button" role="tab" onclick="switchVideoTab('youtube')"><i class="bi bi-youtube me-1"></i> YouTube</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link <?= $videoType === 'local' ? 'active' : '' ?>" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload-pane" type="button" role="tab" onclick="switchVideoTab('local')"><i class="bi bi-upload me-1"></i> Ladda upp video</button>
                                </li>
                            </ul>
                            <div class="tab-content border border-top-0 rounded-bottom p-3" id="videoTabContent">
                                <!-- YouTube tab -->
                                <div class="tab-pane fade <?= $videoType !== 'local' ? 'show active' : '' ?>" id="youtube-pane" role="tabpanel">
                                    <div class="form-floating">
                                        <input type="url" class="form-control" id="video_url" name="video_url"
                                               value="<?= $videoType !== 'local' ? htmlspecialchars($videoUrl ?? '') : '' ?>">
                                        <label for="video_url">YouTube-länk</label>
                                    </div>
                                    <div class="form-text">Klistra in en YouTube-länk (t.ex. https://www.youtube.com/watch?v=...) eller en embed-länk</div>
                                </div>
                                <!-- Upload tab -->
                                <div class="tab-pane fade <?= $videoType === 'local' ? 'show active' : '' ?>" id="upload-pane" role="tabpanel">
                                    <?php if ($videoType === 'local' && !empty($videoUrl)): ?>
                                    <div id="video-preview-container" class="mb-3">
                                        <p class="text-muted mb-1">Nuvarande video:</p>
                                        <video controls preload="metadata" style="max-width: 400px; max-height: 240px;" class="rounded border">
                                            <source src="../upload/videos/<?= htmlspecialchars(basename($videoUrl)) ?>" type="video/<?= pathinfo($videoUrl, PATHINFO_EXTENSION) === 'webm' ? 'webm' : 'mp4' ?>">
                                        </video>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLocalVideo()">
                                                <i class="bi bi-trash"></i> Ta bort video
                                            </button>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div id="video-preview-container" class="mb-3" style="display:none;">
                                        <p class="text-muted mb-1">Uppladdad video:</p>
                                        <video id="video-preview" controls preload="metadata" style="max-width: 400px; max-height: 240px;" class="rounded border"></video>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLocalVideo()">
                                                <i class="bi bi-trash"></i> Ta bort video
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" class="form-control" id="video_file_input" accept="video/mp4,video/webm">
                                    <div class="form-text">Max 100 MB. Tillåtna format: MP4, WebM</div>
                                    <div id="video-upload-progress" class="mt-2" style="display:none;">
                                        <div class="progress" style="height: 20px;">
                                            <div id="video-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                                        </div>
                                        <small id="video-upload-status" class="text-muted mt-1 d-block"></small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Ljud (tillgänglighet: inläst version, ljudinnehåll) -->
                        <div class="mb-3">
                            <label class="form-label fw-normal">
                                <i class="bi bi-volume-up me-1"></i>Ljud
                                <span class="form-text fw-normal">Ett ljudklipp kan spelas upp inline i lektionen. Bra för tillgänglighet (inläst text) eller pedagogiskt ljudmaterial.</span>
                            </label>
                            <input type="hidden" name="audio_filename" id="audio_filename_field" value="<?= htmlspecialchars($audioUrl ?? '') ?>">
                            <div class="border rounded p-3">
                                <div id="audio-preview-container" class="mb-3" <?= empty($audioUrl) ? 'style="display:none;"' : '' ?>>
                                    <p class="text-muted mb-1 small">Nuvarande ljudfil:</p>
                                    <audio id="audio-preview" controls preload="metadata" style="width: 100%; max-width: 400px;"
                                           <?php if (!empty($audioUrl)): ?>src="../upload/audio/<?= htmlspecialchars(basename($audioUrl)) ?>"<?php endif; ?>>
                                        Din webbläsare stödjer inte uppspelning av ljud.
                                    </audio>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLocalAudio()">
                                            <i class="bi bi-trash me-1"></i>Ta bort ljud
                                        </button>
                                    </div>
                                </div>
                                <input type="file" class="form-control" id="audio_file_input" accept="audio/mpeg,audio/mp3,audio/ogg,audio/wav,audio/mp4,audio/x-m4a">
                                <div class="form-text">Max 50 MB. Tillåtna format: MP3, OGG, WAV, M4A</div>
                                <div id="audio-upload-progress" class="mt-2" style="display:none;">
                                    <div class="progress" style="height: 20px;">
                                        <div id="audio-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%">0%</div>
                                    </div>
                                    <small id="audio-upload-status" class="text-muted mt-1 d-block"></small>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="ai_instruction" class="form-label fw-normal">AI Instruktion<br><span class="form-text fw-normal">Instruktioner som visas för användaren i AI-chatten.</span></label>
                            <?php require_once 'include/editor.php'; renderEditor($aiInstruction ?? '', 'ai_instruction', 'aiInstructionEditor'); ?>
                            
                        </div>

                        <div class="mb-3">
                            <label for="ai_prompt" class="form-label fw-normal">AI Prompt<br><span class="form-text fw-normal">Prompt som skickas till AI:n för att styra svaren. (Be gärna ett AI om hjälp)</span></label>
                            
                            <?php require_once 'include/editor.php'; renderEditor($aiPrompt ?? '', 'ai_prompt', 'aiPromptEditor'); ?>
                           
                        </div>

                        <div class="mb-3">
                            <div class="form-floating">
                                <select class="form-select" id="quiz_type" name="quiz_type" onchange="updateQuizTypeUI()">
                                    <option value="single_choice" <?= $quizType === 'single_choice' ? 'selected' : '' ?>>Enkelval (en rätt)</option>
                                    <option value="multiple_choice" <?= $quizType === 'multiple_choice' ? 'selected' : '' ?>>Flerval (flera rätta)</option>
                                </select>
                                <label for="quiz_type">Frågetyp</label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="quiz_question" class="form-label fw-normal">Quiz-fråga</label>
                            <?php require_once 'include/editor.php'; renderEditor($quizQuestion ?? '', 'quiz_question', 'quizQuestionEditor'); ?>
                        </div>

                        <div class="row" id="quiz-answers-container">
                            <div class="col-md-4 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="quiz_answer1" name="quiz_answer1"
                                           value="<?= htmlspecialchars($quizAnswer1 ?? '') ?>">
                                    <label for="quiz_answer1">Svarsalternativ 1</label>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="quiz_answer2" name="quiz_answer2"
                                           value="<?= htmlspecialchars($quizAnswer2 ?? '') ?>">
                                    <label for="quiz_answer2">Svarsalternativ 2</label>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="quiz_answer3" name="quiz_answer3"
                                           value="<?= htmlspecialchars($quizAnswer3 ?? '') ?>">
                                    <label for="quiz_answer3">Svarsalternativ 3</label>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="quiz_answer4" name="quiz_answer4"
                                           value="<?= htmlspecialchars($quizAnswer4 ?? '') ?>">
                                    <label for="quiz_answer4">Svarsalternativ 4 (valfritt)</label>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="quiz_answer5" name="quiz_answer5"
                                           value="<?= htmlspecialchars($quizAnswer5 ?? '') ?>">
                                    <label for="quiz_answer5">Svarsalternativ 5 (valfritt)</label>
                                </div>
                            </div>
                        </div>

                        <!-- Single choice: dropdown -->
                        <div class="mb-3" id="single-choice-container">
                            <div class="form-floating">
                                <select class="form-select" id="quiz_correct_answer" name="quiz_correct_answer">
                                    <option value="">Välj rätt svar...</option>
                                    <option value="1" <?= $quizCorrectAnswer === 1 ? 'selected' : '' ?>>Svar 1</option>
                                    <option value="2" <?= $quizCorrectAnswer === 2 ? 'selected' : '' ?>>Svar 2</option>
                                    <option value="3" <?= $quizCorrectAnswer === 3 ? 'selected' : '' ?>>Svar 3</option>
                                    <option value="4" <?= $quizCorrectAnswer === 4 ? 'selected' : '' ?>>Svar 4</option>
                                    <option value="5" <?= $quizCorrectAnswer === 5 ? 'selected' : '' ?>>Svar 5</option>
                                </select>
                                <label for="quiz_correct_answer">Rätt svar</label>
                            </div>
                        </div>

                        <!-- Multiple choice: checkboxes -->
                        <div class="mb-3" id="multiple-choice-container" style="display: none;">
                            <label class="form-label fw-normal">Rätta svar (välj alla som gäller)</label>
                            <?php
                            $correctAnswersArray = !empty($quizCorrectAnswers) ? explode(',', $quizCorrectAnswers) : [];
                            for ($i = 1; $i <= 5; $i++):
                            ?>
                            <div class="form-check">
                                <input class="form-check-input quiz-correct-checkbox" type="checkbox"
                                       id="quiz_correct_<?= $i ?>" value="<?= $i ?>"
                                       <?= in_array((string)$i, $correctAnswersArray) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="quiz_correct_<?= $i ?>">Svar <?= $i ?></label>
                            </div>
                            <?php endfor; ?>
                            <input type="hidden" id="quiz_correct_answers" name="quiz_correct_answers" value="<?= htmlspecialchars($quizCorrectAnswers ?? '') ?>">
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="status" name="status" 
                                   value="active" <?= $status === 'active' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status">Aktiv</label>
                        </div>

                        <div class="mt-4 d-flex justify-content-between">
                            <button type="submit" class="btn btn-primary">
                                <?= $id ? 'Spara ändringar' : 'Skapa lektion' ?>
                            </button>
                            <a href="lessons.php" class="btn btn-secondary">Avbryt</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Inkludera footer
require_once 'include/footer.php';
?>



<script>
document.addEventListener('DOMContentLoaded', function() {
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
                alert('Bilden får inte vara större än 5MB.');
                e.target.value = '';
                return;
            }

            // Validera bilddimensioner
            const img = new Image();
            img.onload = function() {
                const maxWidth = 1920;
                const maxHeight = 1080;
                if (this.width > maxWidth || this.height > maxHeight) {
                    alert('Bilden är för stor. Max dimensioner är ' + maxWidth + 'x' + maxHeight + ' pixlar.');
                    e.target.value = '';
                }
            };
            img.src = URL.createObjectURL(file);
        });
    }

    // AI Image Generation
    const generateAiImageBtn = document.getElementById('generate-ai-image-btn');
    if (generateAiImageBtn) {
        generateAiImageBtn.addEventListener('click', function() {
            const lessonId = <?= $id ? $id : 0 ?>;
            const lessonTitle = document.getElementById('title').value.trim();
            const courseSelect = document.getElementById('course_id');
            const courseName = courseSelect.options[courseSelect.selectedIndex].text;
            const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';

            if (!lessonTitle) {
                alert('Ange en titel för lektionen först.');
                return;
            }

            if (lessonId <= 0) {
                alert('Spara lektionen först innan du genererar en AI-bild.');
                return;
            }

            // Show loading status
            const statusDiv = document.getElementById('ai-image-status');
            statusDiv.style.display = 'block';
            generateAiImageBtn.disabled = true;

            // Make AJAX call
            const formData = new FormData();
            formData.append('lesson_id', lessonId);
            formData.append('lesson_title', lessonTitle);
            formData.append('course_name', courseName);
            formData.append('csrf_token', csrfToken);

            fetch('ajax/generate_lesson_image.php', {
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
});

// Quiz type UI switching
function updateQuizTypeUI() {
    const quizType = document.getElementById('quiz_type').value;
    const singleChoiceContainer = document.getElementById('single-choice-container');
    const multipleChoiceContainer = document.getElementById('multiple-choice-container');

    if (quizType === 'multiple_choice') {
        singleChoiceContainer.style.display = 'none';
        multipleChoiceContainer.style.display = 'block';
    } else {
        singleChoiceContainer.style.display = 'block';
        multipleChoiceContainer.style.display = 'none';
    }
}

// Update hidden field when checkboxes change
document.querySelectorAll('.quiz-correct-checkbox').forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
        const checked = [];
        document.querySelectorAll('.quiz-correct-checkbox:checked').forEach(function(cb) {
            checked.push(cb.value);
        });
        document.getElementById('quiz_correct_answers').value = checked.join(',');
    });
});

// Initialize UI on page load
updateQuizTypeUI();

// Video tab switching
function switchVideoTab(type) {
    document.getElementById('video_type_field').value = type;
}

// Video file upload with progress
document.getElementById('video_file_input').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Validate type
    const allowedTypes = ['video/mp4', 'video/webm'];
    if (!allowedTypes.includes(file.type)) {
        alert('Endast MP4 och WebM-videor är tillåtna.');
        e.target.value = '';
        return;
    }

    // Validate size (100MB)
    const maxSize = 100 * 1024 * 1024;
    if (file.size > maxSize) {
        alert('Filen är för stor. Max storlek är 100 MB.');
        e.target.value = '';
        return;
    }

    // Show progress bar
    const progressContainer = document.getElementById('video-upload-progress');
    const progressBar = document.getElementById('video-progress-bar');
    const statusText = document.getElementById('video-upload-status');
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';
    statusText.textContent = 'Laddar upp...';

    const formData = new FormData();
    formData.append('video', file);
    formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload_video.php', true);

    xhr.upload.addEventListener('progress', function(evt) {
        if (evt.lengthComputable) {
            const pct = Math.round((evt.loaded / evt.total) * 100);
            progressBar.style.width = pct + '%';
            progressBar.textContent = pct + '%';
            statusText.textContent = 'Laddar upp... ' + (evt.loaded / 1024 / 1024).toFixed(1) + ' / ' + (evt.total / 1024 / 1024).toFixed(1) + ' MB';
        }
    });

    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {

                    progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    progressBar.classList.add('bg-success');
                    progressBar.style.width = '100%';
                    progressBar.textContent = 'Klar!';
                    statusText.textContent = '';

                    // Set hidden fields
                    document.getElementById('video_type_field').value = 'local';
                    document.getElementById('video_filename_field').value = data.url;

                    // Show preview
                    const previewContainer = document.getElementById('video-preview-container');
                    const previewVideo = document.getElementById('video-preview');
                    if (previewVideo) {
                        const ext = data.url.split('.').pop();
                        previewVideo.innerHTML = '';
                        const source = document.createElement('source');
                        source.src = '../upload/videos/' + data.url;
                        source.type = ext === 'webm' ? 'video/webm' : 'video/mp4';
                        previewVideo.appendChild(source);
                        previewVideo.load();
                    }
                    previewContainer.style.display = 'block';
                } else {
                    progressBar.classList.add('bg-danger');
                    statusText.textContent = 'Fel: ' + (data.error || 'Okänt fel');
                }
            } catch (err) {
                progressBar.classList.add('bg-danger');
                console.error('Upload response:', xhr.responseText);
                statusText.textContent = 'Fel: ' + xhr.responseText.substring(0, 200);
            }
        } else {
            progressBar.classList.add('bg-danger');
            statusText.textContent = 'Uppladdningen misslyckades (HTTP ' + xhr.status + ')';
        }
    };

    xhr.onerror = function() {
        progressBar.classList.add('bg-danger');
        statusText.textContent = 'Nätverksfel vid uppladdning.';
    };

    xhr.send(formData);
});

// Remove local video
function removeLocalVideo() {
    document.getElementById('video_type_field').value = '';
    document.getElementById('video_filename_field').value = '';
    document.getElementById('video-preview-container').style.display = 'none';
    document.getElementById('video_file_input').value = '';

    // Reset progress
    const progressContainer = document.getElementById('video-upload-progress');
    const progressBar = document.getElementById('video-progress-bar');
    progressContainer.style.display = 'none';
    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';
}

// Audio upload (same pattern as video)
document.getElementById('audio_file_input').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    const progressContainer = document.getElementById('audio-upload-progress');
    const progressBar = document.getElementById('audio-progress-bar');
    const statusText = document.getElementById('audio-upload-status');

    progressContainer.style.display = 'block';
    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';
    statusText.textContent = 'Laddar upp...';

    const formData = new FormData();
    formData.append('audio', file);
    formData.append('csrf_token', CSRF_TOKEN);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload_audio.php', true);

    xhr.upload.onprogress = function(ev) {
        if (ev.lengthComputable) {
            const pct = Math.round((ev.loaded / ev.total) * 100);
            progressBar.style.width = pct + '%';
            progressBar.textContent = pct + '%';
        }
    };

    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    progressBar.style.width = '100%';
                    progressBar.textContent = 'Klar!';
                    statusText.textContent = '';

                    document.getElementById('audio_filename_field').value = data.url;

                    const previewContainer = document.getElementById('audio-preview-container');
                    const previewAudio = document.getElementById('audio-preview');
                    if (previewAudio) {
                        previewAudio.src = '../upload/audio/' + data.url;
                        previewAudio.load();
                    }
                    previewContainer.style.display = 'block';
                } else {
                    progressBar.classList.remove('bg-success');
                    progressBar.classList.add('bg-danger');
                    statusText.textContent = 'Fel: ' + (data.error || 'Okänt fel');
                }
            } catch (err) {
                progressBar.classList.remove('bg-success');
                progressBar.classList.add('bg-danger');
                statusText.textContent = 'Fel: ' + xhr.responseText.substring(0, 200);
            }
        } else {
            progressBar.classList.remove('bg-success');
            progressBar.classList.add('bg-danger');
            statusText.textContent = 'Uppladdningen misslyckades (HTTP ' + xhr.status + ')';
        }
    };

    xhr.onerror = function() {
        progressBar.classList.remove('bg-success');
        progressBar.classList.add('bg-danger');
        statusText.textContent = 'Nätverksfel vid uppladdning.';
    };

    xhr.send(formData);
});

function removeLocalAudio() {
    document.getElementById('audio_filename_field').value = '';
    document.getElementById('audio-preview-container').style.display = 'none';
    document.getElementById('audio_file_input').value = '';
    const audioEl = document.getElementById('audio-preview');
    if (audioEl) { audioEl.pause(); audioEl.removeAttribute('src'); audioEl.load(); }

    const progressContainer = document.getElementById('audio-upload-progress');
    const progressBar = document.getElementById('audio-progress-bar');
    progressContainer.style.display = 'none';
    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';
}
</script>
