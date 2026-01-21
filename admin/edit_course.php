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

// Hämta kursdata om vi redigerar en befintlig kurs
$course = null;
$courseTags = [];
if (isset($_GET['id'])) {
    $courseId = (int)$_GET['id'];
    $course = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);

    if (!$course) {
        $_SESSION['message'] = 'Kursen hittades inte.';
        $_SESSION['message_type'] = 'danger';
        header('Location: courses.php');
        exit;
    }

    // Kontrollera om användaren har behörighet att redigera kursen
    if (!$isAdmin) {
        // Kontrollera om användaren är redaktör för denna specifika kurs
        $isEditor = queryOne("SELECT 1 FROM " . DB_DATABASE . ".course_editors WHERE course_id = ? AND email = ?", [$courseId, $userEmail]);
        if (!$isEditor) {
            $_SESSION['message'] = 'Du har inte behörighet att redigera denna kurs.';
            $_SESSION['message_type'] = 'danger';
            header('Location: courses.php');
            exit;
        }
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

// Hämta alla tillgängliga taggar för organisationen
$availableTags = query(
    "SELECT * FROM " . DB_DATABASE . ".tags WHERE organization_domain = ? ORDER BY name ASC",
    [$userDomain]
);

// Hantera formulärskickning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
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
                        status = ?,
                        deadline = ?,
                        image_url = ?,
                        updated_at = NOW()
                        WHERE id = ?",
                        [$title, $description, $status, $deadline, $imageUrl, $_GET['id']]);

                // Uppdatera kursens taggar
                // Ta bort befintliga taggar
                execute("DELETE FROM " . DB_DATABASE . ".course_tags WHERE course_id = ?", [$_GET['id']]);

                // Lägg till nya taggar (endast taggar från användarens organisation)
                foreach ($selectedTags as $tagId) {
                    $tagId = (int)$tagId;
                    // Verifiera att taggen tillhör användarens organisation
                    $validTag = queryOne(
                        "SELECT id FROM " . DB_DATABASE . ".tags WHERE id = ? AND organization_domain = ?",
                        [$tagId, $userDomain]
                    );
                    if ($validTag) {
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".course_tags (course_id, tag_id) VALUES (?, ?)",
                            [$_GET['id'], $tagId]
                        );
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

                // Skapa ny kurs med nästa sort_order och organization_domain
                execute("INSERT INTO " . DB_DATABASE . ".courses
                        (title, description, status, deadline, sort_order, image_url, author_id, organization_domain, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [$title, $description, $status, $deadline, $maxOrder + 1, $imageUrl, $authorId, $organizationDomain]);

                // Hämta det nya kurs-ID:t
                $newCourseId = getDb()->lastInsertId();

                // Lägg till skaparen som redaktör för kursen
                execute("INSERT INTO " . DB_DATABASE . ".course_editors
                        (course_id, email, created_by)
                        VALUES (?, ?, ?)",
                        [$newCourseId, $_SESSION['user_email'], $_SESSION['user_email']]);

                // Lägg till taggar för ny kurs
                foreach ($selectedTags as $tagId) {
                    $tagId = (int)$tagId;
                    // Verifiera att taggen tillhör användarens organisation
                    $validTag = queryOne(
                        "SELECT id FROM " . DB_DATABASE . ".tags WHERE id = ? AND organization_domain = ?",
                        [$tagId, $userDomain]
                    );
                    if ($validTag) {
                        execute(
                            "INSERT INTO " . DB_DATABASE . ".course_tags (course_id, tag_id) VALUES (?, ?)",
                            [$newCourseId, $tagId]
                        );
                    }
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
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-muted"><?= $page_title ?></h6>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?= $error ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $course['id'] ?? '' ?>">
                        
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="title" name="title" 
                                   value="<?= htmlspecialchars($course['title'] ?? '') ?>" required>
                            <label for="title">Titel</label>
                        </div>

                        <div class="form-floating mb-3">
                            <textarea class="form-control" id="description" name="description" 
                                      style="height: 100px"><?= htmlspecialchars($course['description'] ?? '') ?></textarea>
                            <label for="description">Beskrivning</label>
                        </div>

                        <div class="mb-3">
                            <label for="image" class="form-label">Bild</label>
                            <div id="current-image-container" class="mb-2" <?= empty($course['image_url']) ? 'style="display:none;"' : '' ?>>
                                <p class="text-muted">Nuvarande bild:</p>
                                <img id="current-image" src="<?= !empty($course['image_url']) ? '../upload/' . htmlspecialchars($course['image_url']) : '' ?>" alt="Kursbild" class="img-thumbnail" style="max-width: 200px;">
                                <input type="hidden" name="image_url" id="image_url" value="<?= htmlspecialchars($course['image_url'] ?? '') ?>">
                                <div class="form-text" id="image-path">Sökväg: <?= htmlspecialchars($course['image_url'] ?? '') ?></div>
                            </div>
                            <?php if (!empty($course['image_url'])): ?>
                                <p class="text-muted">Ladda upp ny bild för att ersätta den nuvarande:</p>
                            <?php endif; ?>
                            <div class="d-flex gap-2 align-items-start">
                                <div class="flex-grow-1">
                                    <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif">
                                    <div class="form-text">Max 5MB. Tillåtna format: JPG, PNG, GIF</div>
                                </div>
                                <?php if (isset($course['id'])): ?>
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

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="status" name="status"
                                   value="active" <?= ($course['status'] ?? '') === 'active' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status">Aktiv</label>
                        </div>

                        <div class="mb-3">
                            <label for="deadline" class="form-label">Slutdatum</label>
                            <input type="date" class="form-control" id="deadline" name="deadline"
                                   value="<?= htmlspecialchars($course['deadline'] ?? '') ?>">
                            <div class="form-text">
                                Ange ett datum om kursen ska vara genomförd senast ett visst datum.
                                Lämna tomt om inget slutdatum finns.
                            </div>
                        </div>

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
<?php endif; ?>

<?php
// Inkludera footer
require_once 'include/footer.php';
