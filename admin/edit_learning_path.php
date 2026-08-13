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

/**
 * Redigera en lärväg: namn, beskrivning, bild, status, synlighet och vilka
 * kurser som ingår (samt i vilken ordning).
 *
 * Kurskopplingen sparas tillsammans med formuläret — kurslistan skickas som
 * en JSON-array av kurs-ID i vald ordning. Servern validerar varje ID mot
 * adminens scope innan setLearningPathCourses() anropas.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once '../include/learning_paths.php';

require_once 'include/auth_check.php';

$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$userDomain = getUserDomain($currentUser['email']);
$isAdmin = $currentUser['is_admin'] == 1;
$isSuperAdmin = $currentUser['role'] === 'super_admin';
$isOnPrimaryDomain = isUserOnPrimaryOrgDomain($_SESSION['user_email']);

$pathId = (int)($_GET['id'] ?? 0);
$path = getLearningPath($pathId);

if (!$path || !userCanModifyLearningPath($path)) {
    $_SESSION['message'] = 'Lärvägen kunde inte hittas eller så saknar du behörighet.';
    $_SESSION['message_type'] = 'danger';
    header('Location: learning_paths.php');
    exit;
}

// Kurser adminen får koppla: egen org-scope, kurser delade till scopet, samt
// globala kurser. Samma synlighetsregler som admin/courses.php använder.
$orgScopeDomains = getEffectiveOrgScopeDomains($_SESSION['user_email']);
$courseDomClause = buildDomainInClause($orgScopeDomains, 'c.organization_domain');
$sharedDomClause = buildDomainInClause($orgScopeDomains, 'csd.domain');
$selectableFragment = "(
    {$courseDomClause['fragment']}
    OR c.is_global = 1
    OR EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd
                WHERE csd.course_id = c.id AND {$sharedDomClause['fragment']})
)";
$selectableParams = array_merge($courseDomClause['params'], $sharedDomClause['params']);

$page_title = 'Redigera lärväg';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: edit_learning_path.php?id=' . $pathId);
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $isGlobal = $isSuperAdmin ? (!empty($_POST['is_global']) ? 1 : 0) : (int)$path['is_global'];
    $imageUrl = $path['image_url'];
    $error = null;

    if ($title === '') {
        $error = 'Lärvägen måste ha ett namn.';
    } elseif (mb_strlen($title) > 255) {
        $error = 'Namnet får inte vara längre än 255 tecken.';
    }

    // Bilduppladdning — samma mönster som admin/edit_course.php
    if (!$error && isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Bilden kunde inte laddas upp (felkod ' . $_FILES['image']['error'] . ').';
        } elseif (!in_array($_FILES['image']['type'], ['image/jpeg', 'image/png', 'image/gif'], true)) {
            $error = 'Endast JPG, PNG och GIF är tillåtna.';
        } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            $error = 'Bilden får inte vara större än 5 MB.';
        } else {
            $uploadDir = __DIR__ . '/../upload/';
            $fileName = uniqid() . '_' . basename($_FILES['image']['name']);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                $oldImage = $path['image_url'];
                $imageUrl = $fileName;
                if (!empty($oldImage) && $oldImage !== $imageUrl && file_exists($uploadDir . $oldImage)) {
                    unlink($uploadDir . $oldImage);
                }
            } else {
                $error = 'Kunde inte spara bilden. Kontrollera filrättigheter.';
            }
        }
    }

    if ($error) {
        $_SESSION['message'] = $error;
        $_SESSION['message_type'] = 'danger';
        header('Location: edit_learning_path.php?id=' . $pathId);
        exit;
    }

    updateLearningPath($pathId, [
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'image_url' => $imageUrl,
        'status' => $status,
        'is_global' => $isGlobal,
    ]);

    // Kurser: JSON-array av ID i vald ordning. Varje ID valideras mot scope så
    // att ingen kan koppla in kurser utanför sin organisation (IDOR).
    $submitted = json_decode($_POST['course_ids'] ?? '[]', true);
    if (is_array($submitted)) {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $submitted))));
        $valid = [];
        if (!empty($wanted)) {
            $placeholders = implode(',', array_fill(0, count($wanted), '?'));
            $rows = query(
                "SELECT c.id FROM " . DB_DATABASE . ".courses c
                 WHERE c.id IN ($placeholders) AND $selectableFragment",
                array_merge($wanted, $selectableParams)
            );
            $allowed = array_map('intval', array_column($rows ?: [], 'id'));
            // Behåll den inskickade ordningen
            foreach ($wanted as $cid) {
                if (in_array($cid, $allowed, true)) {
                    $valid[] = $cid;
                }
            }
        }
        setLearningPathCourses($pathId, $valid);
    }

    // Synlighet: bara huvuddomän-admin får styra delningen.
    if ($isOnPrimaryDomain) {
        $shareMode = $_POST['share_mode'] ?? 'whole_org';
        if ($shareMode === 'specific_domains') {
            $orgRow = getOrganizationByDomain($userDomain);
            $orgDomains = $orgRow ? getOrganizationDomains($orgRow['id']) : [$userDomain];
            $submittedDomains = array_values(array_filter((array)($_POST['shared_domains'] ?? [])));
            $filtered = array_values(array_intersect($submittedDomains, $orgDomains));
            setLearningPathSharedDomains($pathId, $filtered);
        } else {
            setLearningPathSharedDomains($pathId, []);
        }
    }

    logActivity($_SESSION['user_email'], 'Uppdaterade lärväg', [
        'action' => 'learning_path_updated',
        'learning_path_id' => $pathId,
        'title' => $title,
    ]);

    $_SESSION['message'] = 'Lärvägen har sparats.';
    $_SESSION['message_type'] = 'success';
    header('Location: edit_learning_path.php?id=' . $pathId);
    exit;
}

// --- Data för formuläret -----------------------------------------------------

$selectedCourses = getLearningPathCourses($pathId);
$selectedIds = array_map(function ($c) { return (int)$c['id']; }, $selectedCourses);

$availableCourses = query(
    "SELECT c.id, c.title, c.status, c.organization_domain, c.is_global,
            (SELECT COUNT(*) FROM " . DB_DATABASE . ".lessons l
              WHERE l.course_id = c.id AND l.status = 'active') AS lesson_count,
            EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_shared_domains csd2
                     WHERE csd2.course_id = c.id) AS has_shared_domains,
            EXISTS (SELECT 1 FROM " . DB_DATABASE . ".course_org_tags cot
                     WHERE cot.course_id = c.id) AS has_org_tags
     FROM " . DB_DATABASE . ".courses c
     WHERE $selectableFragment
     ORDER BY c.title",
    $selectableParams
) ?: [];

$pathSharedDomains = getLearningPathSharedDomains($pathId);
$shareMode = empty($pathSharedDomains) ? 'whole_org' : 'specific_domains';
$orgRow = getOrganizationByDomain($path['organization_domain']);
$orgDomainList = $orgRow ? getOrganizationDomains($orgRow['id']) : [$path['organization_domain']];

/**
 * Har kursen snävare synlighet än lärvägen? I så fall ser inte alla deltagare
 * den, och den räknas inte i deras procent.
 */
function lpCourseIsRestricted(array $course, array $path) {
    if (!empty($course['is_global'])) {
        return false;
    }
    if (!empty($course['has_shared_domains']) || !empty($course['has_org_tags'])) {
        return true;
    }
    return ($course['organization_domain'] ?? '') !== ($path['organization_domain'] ?? '');
}

require_once 'include/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-signpost-split me-2"></i><?= htmlspecialchars($path['title']) ?></h1>
            <span class="text-muted small">
                Lärväg #<?= (int)$path['id'] ?> · <?= htmlspecialchars($path['organization_domain']) ?>
            </span>
        </div>
        <div class="d-flex gap-2">
            <a href="learning_path_stats.php?path_id=<?= (int)$path['id'] ?>" class="btn btn-outline-secondary">
                <i class="bi bi-bar-chart me-1"></i>Statistik
            </a>
            <a href="learning_paths.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Till listan
            </a>
        </div>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" id="learningPathForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="course_ids" id="course_ids" value="">

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-info-circle me-2"></i>Om lärvägen</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="title" class="form-label">Namn <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" maxlength="255"
                           value="<?= htmlspecialchars($path['title']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="active" <?= $path['status'] === 'active' ? 'selected' : '' ?>>Aktiv — visas för deltagare</option>
                        <option value="inactive" <?= $path['status'] === 'inactive' ? 'selected' : '' ?>>Inaktiv — dold</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="image" class="form-label">Bild</label>
                    <?php if (!empty($path['image_url'])): ?>
                        <div class="mb-1">
                            <img src="../upload/<?= htmlspecialchars($path['image_url']) ?>" alt="Bild för lärvägen"
                                 class="img-thumbnail" style="max-height:50px;">
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control form-control-sm" id="image" name="image"
                           accept="image/jpeg,image/png,image/gif">
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Beskrivning</label>
                    <textarea class="form-control" id="description" name="description" rows="3"
                              placeholder="Kort beskrivning av vad deltagaren lär sig i lärvägen."><?= htmlspecialchars($path['description'] ?? '') ?></textarea>
                    <div class="form-text">Ren text — radbrytningar behålls, HTML används inte.</div>
                </div>

                <?php if ($isSuperAdmin): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_global" name="is_global" value="1"
                               <?= !empty($path['is_global']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_global">
                            <strong>Global lärväg</strong>
                            <span class="text-muted small d-block">Syns för alla organisationer, oavsett domän.</span>
                        </label>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-eye me-2"></i>Synlighet</h6>
        </div>
        <div class="card-body">
            <?php if ($isOnPrimaryDomain): ?>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="share_mode" id="lp_share_org"
                           value="whole_org" <?= $shareMode === 'whole_org' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lp_share_org">
                        <strong>Delas med hela organisationen</strong>
                        <span class="d-block small text-muted">
                            Alla användare i <?= $orgRow ? htmlspecialchars($orgRow['name']) : htmlspecialchars($path['organization_domain']) ?>
                            (<?= count($orgDomainList) ?> domän<?= count($orgDomainList) != 1 ? 'er' : '' ?>) ser lärvägen.
                        </span>
                    </label>
                </div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="radio" name="share_mode" id="lp_share_specific"
                           value="specific_domains" <?= $shareMode === 'specific_domains' ? 'checked' : '' ?>
                           <?= count($orgDomainList) < 2 ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="lp_share_specific">
                        <strong>Dela med vissa domäner inom organisationen</strong>
                        <?php if (count($orgDomainList) < 2): ?>
                            <span class="d-block small text-muted">
                                <i class="bi bi-info-circle me-1"></i>Kräver flera grupperade domäner — organisationen har bara <?= count($orgDomainList) ?>.
                            </span>
                        <?php endif; ?>
                    </label>
                </div>
                <div id="lpSpecificDomainsBox" class="ms-4 mt-2" style="<?= $shareMode === 'specific_domains' ? '' : 'display:none;' ?>">
                    <div class="row g-2">
                        <?php foreach ($orgDomainList as $dom): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="form-check">
                                    <input class="form-check-input lp-share-domain" type="checkbox" name="shared_domains[]"
                                           id="lp_dom_<?= md5($dom) ?>" value="<?= htmlspecialchars($dom) ?>"
                                           <?= in_array($dom, $pathSharedDomains, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label font-monospace small" for="lp_dom_<?= md5($dom) ?>">
                                        <?= htmlspecialchars($dom) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text mt-1">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        Markeras ingen domän blir lärvägen osynlig för alla — välj minst en, eller byt till hela organisationen.
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary py-2 small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Synlighet styrs av administratör på organisationens huvuddomän. Lärvägen visas för användare på
                    <strong><?= htmlspecialchars($userDomain) ?></strong>.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-list-ol me-2"></i>Kurser i lärvägen</h6>
        </div>
        <div class="card-body">
            <div class="alert alert-info py-2 small">
                <i class="bi bi-info-circle me-1"></i>
                Ordningen är en <strong>rekommendation</strong> — inga kurser låses. Deltagaren kan ta dem i valfri ordning.
                Kurser som en deltagare saknar åtkomst till döljs i lärvägen och räknas inte i deltagarens procent.
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label fw-semibold">
                        Valda kurser <span class="badge bg-primary" id="lpSelectedCount"><?= count($selectedCourses) ?></span>
                    </label>
                    <ul class="list-group" id="lpSelected">
                        <?php foreach ($selectedCourses as $c): ?>
                            <li class="list-group-item d-flex align-items-center gap-2" data-course-id="<?= (int)$c['id'] ?>">
                                <span class="grip-handle text-muted" style="cursor:move;"><i class="bi bi-grip-vertical"></i></span>
                                <span class="badge bg-secondary lp-step">–</span>
                                <span class="flex-grow-1">
                                    <?= htmlspecialchars($c['title']) ?>
                                    <span class="text-muted small">(<?= (int)$c['lesson_count'] ?> lektioner)</span>
                                    <?php if ($c['status'] !== 'active'): ?>
                                        <span class="badge bg-secondary" title="Inaktiv kurs visas inte för deltagare">Inaktiv</span>
                                    <?php endif; ?>
                                    <?php if (lpCourseIsRestricted($c, $path)): ?>
                                        <span class="badge bg-warning text-dark"
                                              title="Kursen har snävare synlighet än lärvägen — alla deltagare ser den inte">
                                            Begränsad synlighet
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-danger lp-remove" title="Ta bort ur lärvägen">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="text-muted small mt-1" id="lpEmptyHint" style="<?= count($selectedCourses) ? 'display:none;' : '' ?>">
                        Inga kurser valda ännu. Lägg till från listan till höger.
                    </div>
                </div>

                <div class="col-lg-6">
                    <label for="lpSearch" class="form-label fw-semibold">Tillgängliga kurser</label>
                    <input type="text" class="form-control form-control-sm mb-2" id="lpSearch" placeholder="Sök kurs…">
                    <ul class="list-group" id="lpAvailable" style="max-height:420px; overflow-y:auto;">
                        <?php foreach ($availableCourses as $c): ?>
                            <li class="list-group-item d-flex align-items-center gap-2 lp-available-item"
                                data-course-id="<?= (int)$c['id'] ?>"
                                data-title="<?= htmlspecialchars(mb_strtolower($c['title'])) ?>"
                                data-lessons="<?= (int)$c['lesson_count'] ?>"
                                data-inactive="<?= $c['status'] !== 'active' ? '1' : '0' ?>"
                                data-restricted="<?= lpCourseIsRestricted($c, $path) ? '1' : '0' ?>"
                                <?= in_array((int)$c['id'], $selectedIds, true) ? 'style="display:none;"' : '' ?>>
                                <span class="flex-grow-1">
                                    <?= htmlspecialchars($c['title']) ?>
                                    <span class="text-muted small">(<?= (int)$c['lesson_count'] ?> lektioner)</span>
                                    <?php if ($c['status'] !== 'active'): ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                    <?php if (lpCourseIsRestricted($c, $path)): ?>
                                        <span class="badge bg-warning text-dark">Begränsad synlighet</span>
                                    <?php endif; ?>
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-primary lp-add" title="Lägg till">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-5 d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i>Spara lärvägen
        </button>
        <a href="learning_paths.php" class="btn btn-outline-secondary">Avbryt</a>
    </div>
</form>

<?php
$extra_scripts = '
<script>
(function() {
    var selected = document.getElementById("lpSelected");
    var available = document.getElementById("lpAvailable");
    var form = document.getElementById("learningPathForm");
    var hidden = document.getElementById("course_ids");
    var counter = document.getElementById("lpSelectedCount");
    var emptyHint = document.getElementById("lpEmptyHint");

    function renumber() {
        var items = selected.querySelectorAll("li[data-course-id]");
        items.forEach(function(li, i) {
            var step = li.querySelector(".lp-step");
            if (step) { step.textContent = (i + 1); }
        });
        counter.textContent = items.length;
        emptyHint.style.display = items.length ? "none" : "";
    }

    // Lägg till kurs: flytta raden till vänsterpanelen
    available.addEventListener("click", function(e) {
        var btn = e.target.closest(".lp-add");
        if (!btn) { return; }
        var src = btn.closest("li");
        var id = src.getAttribute("data-course-id");

        var li = document.createElement("li");
        li.className = "list-group-item d-flex align-items-center gap-2";
        li.setAttribute("data-course-id", id);

        var badges = "";
        if (src.getAttribute("data-inactive") === "1") {
            badges += \' <span class="badge bg-secondary">Inaktiv</span>\';
        }
        if (src.getAttribute("data-restricted") === "1") {
            badges += \' <span class="badge bg-warning text-dark">Begränsad synlighet</span>\';
        }

        var titleText = src.querySelector("span").childNodes[0].textContent.trim();
        li.innerHTML =
            \'<span class="grip-handle text-muted" style="cursor:move;"><i class="bi bi-grip-vertical"></i></span>\' +
            \'<span class="badge bg-secondary lp-step">-</span>\' +
            \'<span class="flex-grow-1"></span>\' +
            \'<button type="button" class="btn btn-sm btn-outline-danger lp-remove" title="Ta bort ur lärvägen"><i class="bi bi-x-lg"></i></button>\';
        li.querySelector(".flex-grow-1").textContent = titleText;
        li.querySelector(".flex-grow-1").insertAdjacentHTML("beforeend",
            \' <span class="text-muted small">(\' + src.getAttribute("data-lessons") + \' lektioner)</span>\' + badges);

        selected.appendChild(li);
        src.style.display = "none";
        renumber();
    });

    // Ta bort kurs: visa den igen i högerpanelen
    selected.addEventListener("click", function(e) {
        var btn = e.target.closest(".lp-remove");
        if (!btn) { return; }
        var li = btn.closest("li");
        var id = li.getAttribute("data-course-id");
        var src = available.querySelector(\'li[data-course-id="\' + id + \'"]\');
        if (src) { src.style.display = ""; }
        li.remove();
        renumber();
    });

    // Sökfilter i högerpanelen
    document.getElementById("lpSearch").addEventListener("input", function() {
        var q = this.value.trim().toLowerCase();
        available.querySelectorAll(".lp-available-item").forEach(function(li) {
            var id = li.getAttribute("data-course-id");
            var isSelected = selected.querySelector(\'li[data-course-id="\' + id + \'"]\');
            if (isSelected) { li.style.display = "none"; return; }
            li.style.display = (!q || li.getAttribute("data-title").indexOf(q) !== -1) ? "" : "none";
        });
    });

    // Ordning via drag-and-drop
    $(function() {
        $("#lpSelected").sortable({
            handle: ".grip-handle",
            axis: "y",
            update: renumber
        });
    });

    // Skriv ordningen till det dolda fältet vid submit
    form.addEventListener("submit", function() {
        var ids = [];
        selected.querySelectorAll("li[data-course-id]").forEach(function(li) {
            ids.push(parseInt(li.getAttribute("data-course-id"), 10));
        });
        hidden.value = JSON.stringify(ids);
    });

    // Synlighetsradio: visa/dölj domänlistan
    var radioOrg = document.getElementById("lp_share_org");
    var radioSpecific = document.getElementById("lp_share_specific");
    var box = document.getElementById("lpSpecificDomainsBox");
    function syncShare() { if (box) { box.style.display = radioSpecific.checked ? "" : "none"; } }
    if (radioOrg) { radioOrg.addEventListener("change", syncShare); }
    if (radioSpecific) { radioSpecific.addEventListener("change", syncShare); }

    renumber();
})();
</script>';

require_once 'include/footer.php';
?>
