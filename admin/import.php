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

/**
 * Byter inline-bildreferenser i HTML-content baserat på mappning.
 * upload/gammalt.png → upload/nytt.png
 */
function remapContentImages($html, $imageMapping) {
    if (empty($html) || empty($imageMapping)) {
        return $html;
    }
    return preg_replace_callback(
        '/(<img\s[^>]*src=["\'])(?:\.\.\/)?upload\/([^"\']+)(["\'])/i',
        function ($match) use ($imageMapping) {
            $oldFile = basename($match[2]);
            if (isset($imageMapping[$oldFile])) {
                return $match[1] . 'upload/' . $imageMapping[$oldFile] . $match[3];
            }
            return $match[0]; // Ingen mappning, behåll original
        },
        $html
    );
}

/**
 * Rekursiv borttagning av temp-katalog.
 */
function cleanupTempDir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            cleanupTempDir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * Hanterar ZIP-import: extraherar bilder, validerar, sparar med nya filnamn.
 * Returnerar [data, imageMapping] eller kastar Exception.
 */
function handleZipImport($tmpName) {
    $zip = new ZipArchive();
    if ($zip->open($tmpName) !== true) {
        throw new Exception('Kunde inte öppna ZIP-filen.');
    }

    // Säkerhetsvalidering
    if ($zip->numFiles > 500) {
        $zip->close();
        throw new Exception('ZIP-filen innehåller för många filer (max 500).');
    }

    // Validera entry-namn (path traversal-skydd)
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if (strpos($entryName, '..') !== false || strpos($entryName, '\\') !== false || $entryName[0] === '/') {
            $zip->close();
            throw new Exception('ZIP-filen innehåller ogiltiga sökvägar.');
        }
    }

    // Kontrollera att course.json finns
    if ($zip->locateName('course.json') === false) {
        $zip->close();
        throw new Exception('ZIP-filen saknar course.json.');
    }

    // Extrahera till temp-katalog
    $tempDir = sys_get_temp_dir() . '/stimma_import_' . bin2hex(random_bytes(8));
    if (!mkdir($tempDir, 0700, true)) {
        $zip->close();
        throw new Exception('Kunde inte skapa temporär katalog.');
    }

    try {
        $zip->extractTo($tempDir);
        $zip->close();

        // Kontrollera total storlek (max 50MB)
        $totalSize = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }
        if ($totalSize > 50 * 1024 * 1024) {
            throw new Exception('ZIP-filens innehåll överskrider maxgränsen (50MB).');
        }

        // Läs course.json
        $jsonContent = file_get_contents($tempDir . '/course.json');
        $data = json_decode($jsonContent, true);
        if (!$data || !isset($data['course'])) {
            throw new Exception('Ogiltig course.json i ZIP-filen.');
        }

        // Importera bilder
        $imageMapping = []; // gammalt filnamn => nytt filnamn
        $imagesDir = $tempDir . '/images';
        $uploadDir = realpath(__DIR__ . '/../upload');

        if (!$uploadDir) {
            $uploadDir = __DIR__ . '/../upload';
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Kunde inte skapa uppladdningsmapp.');
            }
            $uploadDir = realpath($uploadDir);
        }
        $uploadDir .= '/';

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxImageSize = 5 * 1024 * 1024; // 5MB per bild

        if (is_dir($imagesDir)) {
            $imageFiles = scandir($imagesDir);
            foreach ($imageFiles as $imgFile) {
                if ($imgFile === '.' || $imgFile === '..') continue;

                $imgPath = $imagesDir . '/' . $imgFile;
                if (!is_file($imgPath)) continue;

                // Validera filändelse
                $ext = strtolower(pathinfo($imgFile, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions)) continue;

                // Validera filstorlek
                if (filesize($imgPath) > $maxImageSize) continue;

                // Validera MIME-typ
                $mimeType = mime_content_type($imgPath);
                if (!in_array($mimeType, $allowedMimeTypes)) continue;

                // Validera att det är en riktig bild
                $imageInfo = getimagesize($imgPath);
                if ($imageInfo === false) continue;

                // Generera nytt säkert filnamn
                $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                $destPath = $uploadDir . $newFilename;

                if (copy($imgPath, $destPath)) {
                    chmod($destPath, 0644);
                    $imageMapping[$imgFile] = $newFilename;
                }
            }
        }

        return [$data, $imageMapping, $tempDir];

    } catch (Exception $e) {
        cleanupTempDir($tempDir);
        throw $e;
    }
}

// Hantera AJAX-anrop separat
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
    // Kontrollera att användaren är inloggad
    if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Du måste vara inloggad.']);
        exit;
    }

    // Kontrollera behörighet
    $user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
    if (!$user || ($user['is_admin'] !== 1 && $user['is_editor'] !== 1)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Du har inte behörighet.']);
        exit;
    }

    // Hantera filuppladdning för AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['course_file'])) {
        $file = $_FILES['course_file'];
        $tempDir = null;

        // Detektera filformat baserat på filändelse
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $isZip = ($fileExtension === 'zip');

        if (!$isZip && $fileExtension !== 'json') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Endast JSON- och ZIP-filer är tillåtna.']);
            exit;
        }

        try {
            $data = null;
            $imageMapping = [];

            if ($isZip) {
                // ZIP-import
                list($data, $imageMapping, $tempDir) = handleZipImport($file['tmp_name']);
            } else {
                // Bakåtkompatibel JSON-import
                if ($file['type'] !== 'application/json') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Ogiltig filtyp för JSON.']);
                    exit;
                }

                $content = file_get_contents($file['tmp_name']);
                $data = json_decode($content, true);

                if (!$data || !isset($data['course'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Ogiltig JSON-fil.']);
                    exit;
                }
            }

            // Börja transaktion
            execute("START TRANSACTION");

            // Hämta användarens ID, is_admin och is_editor
            $user = queryOne("SELECT id, is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
            if (!$user) {
                throw new Exception('Kunde inte hitta användarinformation.');
            }

            // Hämta organization_domain från användarens e-post
            $emailParts = explode('@', $_SESSION['user_email']);
            $organizationDomain = isset($emailParts[1]) ? $emailParts[1] : '';

            // Mappa kursens image_url
            $courseImageUrl = $data['course']['image_url'] ?? null;
            if (!empty($courseImageUrl) && !empty($imageMapping)) {
                $courseImgFile = basename($courseImageUrl);
                if (isset($imageMapping[$courseImgFile])) {
                    $courseImageUrl = $imageMapping[$courseImgFile];
                }
            }

            // Skapa kursen
            $courseId = execute("
                INSERT INTO " . DB_DATABASE . ".courses (
                    title, description, difficulty_level, duration_minutes,
                    prerequisites, tags, image_url, status, sort_order,
                    featured, author_id, organization_domain, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $data['course']['title'],
                $data['course']['description'],
                $data['course']['difficulty_level'] ?? 'beginner',
                $data['course']['duration_minutes'] ?? 0,
                $data['course']['prerequisites'] ?? null,
                $data['course']['tags'] ?? null,
                $courseImageUrl ?: null,
                'inactive', // Sätt alltid till inaktiv vid import
                $data['course']['sort_order'] ?? 0,
                $data['course']['featured'] ?? 0,
                $user['id'],
                $organizationDomain
            ]);

            // Om användaren inte är admin, lägg till i course_editors
            if (isset($user['is_admin']) && intval($user['is_admin']) !== 1) {
                execute("INSERT INTO " . DB_DATABASE . ".course_editors (course_id, email, created_by) VALUES (?, ?, ?)", [$courseId, $_SESSION['user_email'], $user['id']]);
            }

            // Förbered statusinformation
            $status = [
                'success' => true,
                'message' => 'Kursen importeras...',
                'current' => 'Kurs: ' . $data['course']['title'],
                'progress' => 0,
                'steps' => []
            ];

            // Lägg till lektionerna
            if (isset($data['lessons']) && is_array($data['lessons'])) {
                $totalLessons = count($data['lessons']);
                foreach ($data['lessons'] as $index => $lesson) {
                    // Mappa lektionens image_url
                    $lessonImageUrl = $lesson['image_url'] ?? null;
                    if (!empty($lessonImageUrl) && !empty($imageMapping)) {
                        $lessonImgFile = basename($lessonImageUrl);
                        if (isset($imageMapping[$lessonImgFile])) {
                            $lessonImageUrl = $imageMapping[$lessonImgFile];
                        }
                    }

                    // Mappa inline-bilder i content
                    $lessonContent = $lesson['content'] ?? null;
                    if (!empty($lessonContent) && !empty($imageMapping)) {
                        $lessonContent = remapContentImages($lessonContent, $imageMapping);
                    }

                    // SECURITY FIX: Sanitize all imported HTML content to prevent XSS
                    $sanitizedContent = isset($lessonContent) ? cleanHtml($lessonContent) : null;
                    $sanitizedAiInstruction = isset($lesson['ai_instruction']) ? cleanHtml($lesson['ai_instruction']) : null;
                    $sanitizedAiPrompt = isset($lesson['ai_prompt']) ? cleanHtml($lesson['ai_prompt']) : null;
                    $sanitizedQuizQuestion = isset($lesson['quiz_question']) ? cleanHtml($lesson['quiz_question']) : null;

                    // SECURITY FIX: Sanitize text fields (strip HTML tags completely)
                    $sanitizedTitle = isset($lesson['title']) ? strip_tags($lesson['title']) : '';
                    $sanitizedQuizAnswer1 = isset($lesson['quiz_answer1']) ? strip_tags($lesson['quiz_answer1']) : null;
                    $sanitizedQuizAnswer2 = isset($lesson['quiz_answer2']) ? strip_tags($lesson['quiz_answer2']) : null;
                    $sanitizedQuizAnswer3 = isset($lesson['quiz_answer3']) ? strip_tags($lesson['quiz_answer3']) : null;

                    // SECURITY FIX: Validate video URL if present
                    $videoUrl = null;
                    if (isset($lesson['video_url']) && !empty($lesson['video_url'])) {
                        if (preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//', $lesson['video_url'])) {
                            $videoUrl = $lesson['video_url'];
                        }
                    }

                    execute("
                        INSERT INTO " . DB_DATABASE . ".lessons (
                            course_id, title, estimated_duration, image_url,
                            video_url, content, resource_links, tags, status,
                            sort_order, ai_instruction, ai_prompt,
                            quiz_question, quiz_answer1, quiz_answer2,
                            quiz_answer3, quiz_correct_answer, author_id, created_at,
                            updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $courseId,
                        $sanitizedTitle,
                        $lesson['estimated_duration'] ?? 5,
                        $lessonImageUrl ?: null,
                        $videoUrl,
                        $sanitizedContent,
                        $lesson['resource_links'] ?? null,
                        $lesson['tags'] ?? null,
                        $lesson['status'] ?? 'active',
                        $lesson['sort_order'] ?? 0,
                        $sanitizedAiInstruction,
                        $sanitizedAiPrompt,
                        $sanitizedQuizQuestion,
                        $sanitizedQuizAnswer1,
                        $sanitizedQuizAnswer2,
                        $sanitizedQuizAnswer3,
                        $lesson['quiz_correct_answer'] ?? null,
                        $user['id']
                    ]);

                    // Lägg till status för varje lektion
                    $status['steps'][] = [
                        'message' => 'Lektioner importeras...',
                        'current' => 'Lektion ' . ($index + 1) . ' av ' . $totalLessons . ': ' . $lesson['title'],
                        'progress' => round(($index + 1) / $totalLessons * 100)
                    ];
                }
            }

            // Slutför transaktionen
            execute("COMMIT");

            // Lägg till slutstatus
            $imageCount = count($imageMapping);
            $imageMsg = $imageCount > 0 ? " ({$imageCount} bilder importerade)" : "";
            $status['steps'][] = [
                'message' => 'Importen är klar!' . $imageMsg,
                'current' => 'Omdirigerar...',
                'progress' => 100,
                'redirect' => 'courses.php'
            ];

            // Skicka all statusinformation i ett svar
            header('Content-Type: application/json');
            echo json_encode($status);

        } catch (Exception $e) {
            // Återställ transaktionen vid fel
            execute("ROLLBACK");

            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Ett fel uppstod vid import av kursen: ' . $e->getMessage()
            ]);
        } finally {
            // Rensa temp-katalog
            if ($tempDir && is_dir($tempDir)) {
                cleanupTempDir($tempDir);
            }
        }
        exit;
    }
    exit;
}

// Vanlig sidvisning
// Kontrollera att användaren är inloggad
if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Kontrollera behörighet
$user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE id = ?", [$_SESSION['user_id']]);
if (!$user || ($user['is_admin'] !== 1 && $user['is_editor'] !== 1)) {
    header('Location: login.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Importera kurs';

// Inkludera header
require_once 'include/header.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-muted">Importera kurs</h6>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="mb-3">
                <label for="course_file" class="form-label">Välj kursfil (ZIP eller JSON)</label>
                <input type="file" class="form-control" id="course_file" name="course_file" accept=".json,.zip" required>
                <div class="form-text">Välj en ZIP- eller JSON-fil som innehåller kursdata som exporterats från en annan Stimma-installation. ZIP-filer inkluderar bilder.</div>
            </div>
            <button type="submit" class="btn btn-primary">Importera kurs</button>
            <a href="courses.php" class="btn btn-secondary">Avbryt</a>
        </form>

        <!-- Progress indicator -->
        <div id="importProgress" class="mt-4" style="display: none;">
            <div class="progress mb-3" style="height: 25px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar"
                     style="width: 0%"
                     aria-valuenow="0"
                     aria-valuemin="0"
                     aria-valuemax="100"></div>
            </div>
            <div id="importStatus" class="text-center">
                <div class="spinner-border text-primary mb-2" role="status">
                    <span class="visually-hidden">Laddar...</span>
                </div>
                <p class="mb-0" id="currentItem">Förbereder import...</p>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('importForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const progressBar = document.querySelector('.progress-bar');
    const importProgress = document.getElementById('importProgress');
    const currentItem = document.getElementById('currentItem');

    // Visa progress-indikatorn
    importProgress.style.display = 'block';

    // Skicka formuläret med fetch
    fetch('import.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let currentStep = 0;

            // Funktion för att visa nästa steg
            function showNextStep() {
                if (currentStep < data.steps.length) {
                    const step = data.steps[currentStep];

                    // Uppdatera progress-baren och status
                    if (step.progress !== undefined) {
                        progressBar.style.width = step.progress + '%';
                        progressBar.setAttribute('aria-valuenow', step.progress);
                    }

                    // Uppdatera statusmeddelandet
                    if (step.current) {
                        currentItem.textContent = step.current;
                    }

                    // Om det är sista steget och det finns en redirect
                    if (currentStep === data.steps.length - 1 && step.redirect) {
                        setTimeout(() => {
                            window.location.href = step.redirect;
                        }, 1000);
                    } else {
                        // Visa nästa steg efter 1 sekund
                        setTimeout(showNextStep, 1000);
                    }

                    currentStep++;
                }
            }

            // Starta visningen av stegen
            showNextStep();
        } else {
            // Visa felmeddelande
            alert(data.message || 'Ett fel uppstod vid importen');
            importProgress.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ett fel uppstod vid importen');
        importProgress.style.display = 'none';
    });
});
</script>

<?php
// Inkludera footer
require_once 'include/footer.php';
