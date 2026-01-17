<?php
/**
 * Stimma - Certifikathantering
 *
 * Admin-sida för att hantera certifikatinställningar och förhandsgranska certifikat
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

// Include centralized authentication and authorization check
require_once 'include/auth_check.php';

// Endast administratörer kan hantera certifikat
if (!$isAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att hantera certifikat.';
    $_SESSION['message_type'] = 'warning';
    redirect('admin/index.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Certifikathantering';

// Hämta alla kurser med certifikatinfo
$courses = query("SELECT c.*,
                         (SELECT COUNT(*) FROM " . DB_DATABASE . ".certificates WHERE course_id = c.id) as cert_count
                  FROM " . DB_DATABASE . ".courses c
                  WHERE c.status = 'active'
                  ORDER BY c.title");

// Hantera bilduppladdning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = 'Ogiltig förfrågan.';
        $_SESSION['message_type'] = 'danger';
        redirect('admin/certificates.php');
        exit;
    }

    $courseId = (int)($_POST['course_id'] ?? 0);

    if ($_POST['action'] === 'upload_image' && isset($_FILES['certificate_image'])) {
        $file = $_FILES['certificate_image'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mimeType, $allowedTypes)) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'cert_' . $courseId . '_' . time() . '.' . $ext;
                $uploadPath = __DIR__ . '/../upload/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    execute("UPDATE " . DB_DATABASE . ".courses SET certificate_image_url = ? WHERE id = ?",
                            [$filename, $courseId]);
                    $_SESSION['message'] = 'Certifikatbild uppladdad!';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Kunde inte spara filen.';
                    $_SESSION['message_type'] = 'danger';
                }
            } else {
                $_SESSION['message'] = 'Ogiltig filtyp. Använd JPG, PNG, GIF eller WEBP.';
                $_SESSION['message_type'] = 'danger';
            }
        } else {
            $_SESSION['message'] = 'Fel vid uppladdning.';
            $_SESSION['message_type'] = 'danger';
        }
        redirect('admin/certificates.php');
        exit;
    }

    if ($_POST['action'] === 'remove_image') {
        execute("UPDATE " . DB_DATABASE . ".courses SET certificate_image_url = NULL WHERE id = ?", [$courseId]);
        $_SESSION['message'] = 'Certifikatbild borttagen.';
        $_SESSION['message_type'] = 'success';
        redirect('admin/certificates.php');
        exit;
    }
}

// Hämta vald kurs för förhandsvisning
$previewCourseId = (int)($_GET['preview'] ?? 0);
$previewCourse = null;
if ($previewCourseId) {
    $previewCourse = queryOne("SELECT * FROM " . DB_DATABASE . ".courses WHERE id = ?", [$previewCourseId]);
}

// Inkludera header
require_once 'include/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1"><i class="bi bi-award me-2"></i>Certifikathantering</h4>
                    <p class="text-muted mb-0">Hantera certifikatbilder och förhandsgranska certifikat</p>
                </div>
            </div>

            <div class="row">
                <!-- Kurslista -->
                <div class="col-lg-5">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Kurser</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                                <?php foreach ($courses as $course): ?>
                                <div class="list-group-item <?= $previewCourseId == $course['id'] ? 'active' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1 <?= $previewCourseId == $course['id'] ? 'text-white' : '' ?>">
                                                <?= htmlspecialchars($course['title']) ?>
                                            </h6>
                                            <small class="<?= $previewCourseId == $course['id'] ? 'text-white-50' : 'text-muted' ?>">
                                                <?= $course['cert_count'] ?> certifikat utfärdade
                                            </small>
                                            <?php if ($course['certificate_image_url']): ?>
                                            <span class="badge bg-success ms-2">
                                                <i class="bi bi-image me-1"></i>Bild
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?preview=<?= $course['id'] ?>"
                                               class="btn btn-<?= $previewCourseId == $course['id'] ? 'light' : 'outline-primary' ?>"
                                               title="Förhandsgranska">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <button type="button"
                                                    class="btn btn-<?= $previewCourseId == $course['id'] ? 'light' : 'outline-secondary' ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#uploadModal<?= $course['id'] ?>"
                                                    title="Hantera bild">
                                                <i class="bi bi-image"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Upload Modal -->
                                <div class="modal fade" id="uploadModal<?= $course['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Certifikatbild: <?= htmlspecialchars($course['title']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if ($course['certificate_image_url']): ?>
                                                <div class="text-center mb-3">
                                                    <p class="text-muted mb-2">Nuvarande bild:</p>
                                                    <img src="../upload/<?= htmlspecialchars($course['certificate_image_url']) ?>"
                                                         alt="Certifikatbild"
                                                         class="img-fluid rounded border"
                                                         style="max-height: 150px;">
                                                </div>
                                                <form method="post" class="mb-3">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="action" value="remove_image">
                                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                                        <i class="bi bi-trash me-1"></i>Ta bort bild
                                                    </button>
                                                </form>
                                                <hr>
                                                <?php endif; ?>

                                                <form method="post" enctype="multipart/form-data">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="action" value="upload_image">
                                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label">Ladda upp ny bild</label>
                                                        <input type="file" name="certificate_image" class="form-control"
                                                               accept="image/jpeg,image/png,image/gif,image/webp" required>
                                                        <div class="form-text">
                                                            JPG, PNG, GIF eller WEBP. Rekommenderad storlek: 200x200 px eller större (kvadratisk).
                                                        </div>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary w-100">
                                                        <i class="bi bi-upload me-1"></i>Ladda upp
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Förhandsvisning -->
                <div class="col-lg-7">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-eye me-2"></i>Förhandsvisning</h5>
                            <?php if ($previewCourse): ?>
                            <a href="../certificate.php?id=PREVIEW" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Öppna i nytt fönster
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body bg-light p-4">
                            <?php if ($previewCourse):
                                $months = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
                                $formattedDate = date('j') . ' ' . $months[(int)date('n') - 1] . ' ' . date('Y');
                            ?>
                            <!-- Certificate Preview - A4 Portrait -->
                            <div class="certificate-preview">
                                <div class="certificate-border"></div>
                                <div class="certificate-border-inner"></div>

                                <div class="corner-decoration top-left"></div>
                                <div class="corner-decoration top-right"></div>
                                <div class="corner-decoration bottom-left"></div>
                                <div class="corner-decoration bottom-right"></div>

                                <div class="certificate-content">
                                    <div class="certificate-header">
                                        <div class="logo-container">
                                            <?php if ($previewCourse['certificate_image_url']): ?>
                                            <img src="../upload/<?= htmlspecialchars($previewCourse['certificate_image_url']) ?>"
                                                 alt="Kurslogotyp" class="course-logo">
                                            <?php else: ?>
                                            <img src="../images/stimma-logo.png" alt="Stimma" class="logo">
                                            <?php endif; ?>
                                        </div>
                                        <h1 class="certificate-title">Certifikat</h1>
                                        <p class="certificate-subtitle">Intyg om genomförd utbildning</p>
                                    </div>

                                    <div class="certificate-body">
                                        <p class="presented-to">Detta certifikat tilldelas</p>
                                        <h2 class="recipient-name">Anna Andersson</h2>
                                        <p class="completion-text">för att framgångsrikt ha genomfört kursen</p>
                                        <h3 class="course-title"><?= htmlspecialchars($previewCourse['title']) ?></h3>
                                        <p class="completion-date">Slutförd den <?= $formattedDate ?></p>
                                    </div>

                                    <div class="certificate-footer">
                                        <div class="certificate-number">
                                            Certifikatnummer:<br>
                                            STIMMA-<?= date('Y') ?>-0001-<?= str_pad($previewCourse['id'], 4, '0', STR_PAD_LEFT) ?>-ABCDEF
                                        </div>
                                        <div class="seal">
                                            <span>STIMMA</span>
                                        </div>
                                        <div class="verification-text">
                                            Verifiera på:<br>
                                            <strong>stimma.sambruk.se</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 p-3 bg-white rounded border">
                                <h6><i class="bi bi-info-circle me-1"></i>Information</h6>
                                <ul class="mb-0 small text-muted">
                                    <li>Certifikatet är i <strong>A4-format (stående)</strong> - optimerat för utskrift och att sätta upp på väggen.</li>
                                    <li>Certifikatet genereras automatiskt när en användare slutför alla lektioner i kursen.</li>
                                    <li>Om du laddar upp en kursbild visas den istället för Stimma-logotypen.</li>
                                    <li>Rekommenderad bildstorlek: 200x100 px (liggande) för bästa resultat.</li>
                                    <li>Användare kan skriva ut certifikatet som PDF via webbläsarens utskriftsfunktion (välj "Stående" orientering).</li>
                                </ul>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-arrow-left-circle text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3 text-muted">Välj en kurs</h5>
                                <p class="text-muted">Klicka på ögon-ikonen för en kurs för att förhandsgranska dess certifikat.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Statistik -->
                    <div class="card shadow-sm mt-4">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Certifikatstatistik</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $totalCerts = queryOne("SELECT COUNT(*) as count FROM " . DB_DATABASE . ".certificates");
                            $recentCerts = query("SELECT c.*, co.title as course_title, u.email
                                                  FROM " . DB_DATABASE . ".certificates c
                                                  JOIN " . DB_DATABASE . ".courses co ON c.course_id = co.id
                                                  JOIN " . DB_DATABASE . ".users u ON c.user_id = u.id
                                                  ORDER BY c.issued_at DESC
                                                  LIMIT 5");
                            ?>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h2 mb-0 text-primary"><?= $totalCerts['count'] ?></div>
                                        <small class="text-muted">Totalt utfärdade</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h2 mb-0 text-success"><?= count($courses) ?></div>
                                        <small class="text-muted">Aktiva kurser</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center">
                                        <?php
                                        $coursesWithImage = array_filter($courses, fn($c) => !empty($c['certificate_image_url']));
                                        ?>
                                        <div class="h2 mb-0 text-info"><?= count($coursesWithImage) ?></div>
                                        <small class="text-muted">Med egen bild</small>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($recentCerts)): ?>
                            <h6 class="mt-4 mb-2">Senast utfärdade</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Datum</th>
                                            <th>Användare</th>
                                            <th>Kurs</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentCerts as $cert): ?>
                                        <tr>
                                            <td><?= date('Y-m-d', strtotime($cert['issued_at'])) ?></td>
                                            <td><?= htmlspecialchars($cert['email']) ?></td>
                                            <td><?= htmlspecialchars($cert['course_title']) ?></td>
                                            <td>
                                                <a href="../certificate.php?id=<?= urlencode($cert['certificate_number']) ?>"
                                                   target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Certificate Preview Styles - A4 Portrait */
.certificate-preview {
    width: 100%;
    max-width: 350px;
    aspect-ratio: 210/297;
    background: white;
    position: relative;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-radius: 4px;
    overflow: hidden;
    font-family: 'Georgia', serif;
    margin: 0 auto;
}

.certificate-preview .certificate-border {
    position: absolute;
    top: 2.5%;
    left: 3.5%;
    right: 3.5%;
    bottom: 2.5%;
    border: 2px solid #1a365d;
}

.certificate-preview .certificate-border-inner {
    position: absolute;
    top: 4%;
    left: 5.5%;
    right: 5.5%;
    bottom: 4%;
    border: 1px solid #c9a227;
}

.certificate-preview .corner-decoration {
    position: absolute;
    width: 8%;
    height: 5.5%;
    opacity: 0.6;
}

.certificate-preview .corner-decoration.top-left {
    top: 3.3%;
    left: 4.5%;
    border-top: 2px solid #c9a227;
    border-left: 2px solid #c9a227;
}

.certificate-preview .corner-decoration.top-right {
    top: 3.3%;
    right: 4.5%;
    border-top: 2px solid #c9a227;
    border-right: 2px solid #c9a227;
}

.certificate-preview .corner-decoration.bottom-left {
    bottom: 3.3%;
    left: 4.5%;
    border-bottom: 2px solid #c9a227;
    border-left: 2px solid #c9a227;
}

.certificate-preview .corner-decoration.bottom-right {
    bottom: 3.3%;
    right: 4.5%;
    border-bottom: 2px solid #c9a227;
    border-right: 2px solid #c9a227;
}

.certificate-preview .certificate-content {
    position: absolute;
    top: 6%;
    left: 8%;
    right: 8%;
    bottom: 6%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    text-align: center;
    padding: 2%;
}

.certificate-preview .certificate-header {
    width: 100%;
}

.certificate-preview .logo-container,
.certificate-preview .course-logo-container {
    margin-bottom: 3%;
    min-height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.certificate-preview .logo {
    height: 30px;
}

.certificate-preview .course-logo {
    height: 40px;
    max-width: 120px;
    object-fit: contain;
}

.certificate-preview .certificate-title {
    font-size: 1.6rem;
    color: #1a365d;
    margin-bottom: 0.3rem;
    letter-spacing: 3px;
    font-weight: 700;
}

.certificate-preview .certificate-subtitle {
    font-size: 0.55rem;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin: 0;
    font-weight: 600;
}

.certificate-preview .certificate-body {
    width: 100%;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.certificate-preview .presented-to {
    font-size: 0.55rem;
    color: #666;
    margin-bottom: 0.4rem;
    letter-spacing: 0.5px;
}

.certificate-preview .recipient-name {
    font-size: 1.2rem;
    color: #1a365d;
    margin-bottom: 0.5rem;
    padding-bottom: 0.3rem;
    border-bottom: 2px solid #c9a227;
    display: inline-block;
    font-weight: 700;
}

.certificate-preview .completion-text {
    font-size: 0.55rem;
    color: #444;
    margin-bottom: 0.4rem;
}

.certificate-preview .course-title {
    font-size: 0.85rem;
    color: #1a365d;
    margin-bottom: 0.5rem;
    font-weight: 600;
    line-height: 1.3;
}

.certificate-preview .completion-date {
    font-size: 0.5rem;
    color: #555;
    margin: 0;
    font-weight: 500;
}

.certificate-preview .certificate-footer {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    padding-top: 2%;
}

.certificate-preview .certificate-number {
    font-size: 0.4rem;
    color: #888;
    font-family: 'Courier New', monospace;
    text-align: left;
    line-height: 1.3;
}

.certificate-preview .verification-text {
    font-size: 0.4rem;
    color: #888;
    text-align: right;
    line-height: 1.3;
}

.certificate-preview .seal {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #c9a227 0%, #f4d03f 50%, #c9a227 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #1a365d;
    font-weight: bold;
    font-size: 0.45rem;
    text-transform: uppercase;
    box-shadow: 0 2px 8px rgba(201, 162, 39, 0.4);
    letter-spacing: 0.5px;
}
</style>

<?php require_once 'include/footer.php'; ?>
