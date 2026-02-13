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

// Endast administratörer kan se PUB-dokument
if (!$isAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att se PUB-dokumenten. Endast administratörer har tillgång till den här funktionen.';
    $_SESSION['message_type'] = 'warning';
    redirect('index.php');
    exit;
}

// Kontrollera om användaren är super_admin
$currentUser = queryOne("SELECT role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isSuperAdmin = $currentUser && $currentUser['role'] === 'super_admin';

// Sätt sidtitel
$page_title = 'PUB-avtal & Bilagor';
$pdfDir = __DIR__ . '/../docs/pdf/';

// Hantera POST (superadmin-funktionalitet)
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: pub_documents.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'upload_pub_pdf') {
        $version = trim($_POST['version'] ?? '');

        if (empty($version)) {
            $_SESSION['message'] = 'Versionsnummer måste anges.';
            $_SESSION['message_type'] = 'danger';
        } elseif (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['message'] = 'Ingen fil laddades upp eller ett fel uppstod.';
            $_SESSION['message_type'] = 'danger';
        } else {
            $file = $_FILES['pdf_file'];

            // Validera filtyp
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($mimeType !== 'application/pdf') {
                $_SESSION['message'] = 'Endast PDF-filer tillåts.';
                $_SESSION['message_type'] = 'danger';
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $_SESSION['message'] = 'Filen är för stor. Max 10 MB.';
                $_SESSION['message_type'] = 'danger';
            } else {
                // Beräkna hash
                $fileHash = hash_file('sha256', $file['tmp_name']);

                // Generera säkert filnamn
                $originalFilename = basename($file['name']);
                $storedFilename = 'pub_avtal_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';

                if (!file_exists($pdfDir)) {
                    mkdir($pdfDir, 0755, true);
                }

                if (move_uploaded_file($file['tmp_name'], $pdfDir . $storedFilename)) {
                    // Sätt alla befintliga dokument som inaktiva
                    execute("UPDATE " . DB_DATABASE . ".pub_agreement_documents SET is_active = 0 WHERE is_active = 1");

                    // Spara i databasen
                    execute(
                        "INSERT INTO " . DB_DATABASE . ".pub_agreement_documents
                         (version, filename, original_filename, file_hash, uploaded_by, is_active)
                         VALUES (?, ?, ?, ?, ?, 1)",
                        [$version, $storedFilename, $originalFilename, $fileHash, $_SESSION['user_id']]
                    );

                    logActivity($_SESSION['user_email'], "Laddade upp nytt PUB-avtalsdokument: {$originalFilename} (version {$version})", [
                        'action' => 'pub_document_upload',
                        'version' => $version,
                        'filename' => $storedFilename,
                        'file_hash' => $fileHash
                    ]);

                    $_SESSION['message'] = "PUB-avtalsdokument version <strong>" . e($version) . "</strong> har laddats upp och aktiverats.";
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Kunde inte spara filen. Kontrollera filrättigheter.';
                    $_SESSION['message_type'] = 'danger';
                }
            }
        }

        header('Location: pub_documents.php');
        exit;
    }

    if ($action === 'activate_pub_pdf') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            execute("UPDATE " . DB_DATABASE . ".pub_agreement_documents SET is_active = 0 WHERE is_active = 1");
            execute("UPDATE " . DB_DATABASE . ".pub_agreement_documents SET is_active = 1 WHERE id = ?", [$docId]);

            logActivity($_SESSION['user_email'], "Aktiverade PUB-avtalsdokument ID: {$docId}", [
                'action' => 'pub_document_activate',
                'doc_id' => $docId
            ]);

            $_SESSION['message'] = 'Dokumentet har aktiverats.';
            $_SESSION['message_type'] = 'success';
        }

        header('Location: pub_documents.php');
        exit;
    }
}

// Hämta uppladdade PUB-PDF:er och signeringsartefakter (för superadmin)
$uploadedPubDocs = [];
$signingArtifacts = [];
if ($isSuperAdmin) {
    $uploadedPubDocs = query("SELECT d.*, u.email as uploader_email
                              FROM " . DB_DATABASE . ".pub_agreement_documents d
                              LEFT JOIN " . DB_DATABASE . ".users u ON d.uploaded_by = u.id
                              ORDER BY d.created_at DESC");
    $signingArtifacts = query("SELECT * FROM " . DB_DATABASE . ".pub_agreement_artifacts ORDER BY signed_at DESC");
}

// Definiera tillgängliga dokument
$documents = [
    [
        'title' => 'PUB-avtal (Komplett)',
        'description' => 'Komplett personuppgiftsbiträdesavtal med alla bilagor i ett samlat dokument.',
        'filename' => 'PUB_AVTAL_KOMPLETT.docx',
        'icon' => 'bi-file-earmark-word-fill',
        'color' => 'primary',
        'recommended' => true
    ],
    [
        'title' => 'PUB-avtal (Huvudavtal)',
        'description' => 'Personuppgiftsbiträdesavtal baserat på SKR:s mall för personuppgiftsbiträdesavtal.',
        'filename' => 'PUB_AVTAL.docx',
        'icon' => 'bi-file-earmark-word',
        'color' => 'primary',
        'recommended' => false
    ],
    [
        'title' => 'Bilaga 1: Instruktion',
        'description' => 'Instruktion för behandling av personuppgifter - detaljerar vilka personuppgifter som behandlas och hur.',
        'filename' => 'PUB_BILAGA_1_INSTRUKTION.docx',
        'icon' => 'bi-file-earmark-word',
        'color' => 'info',
        'recommended' => false
    ],
    [
        'title' => 'Bilaga 2: Underbiträden',
        'description' => 'Lista över underbiträden som anlitas för behandling av personuppgifter.',
        'filename' => 'PUB_BILAGA_2_UNDERBITRADEN.docx',
        'icon' => 'bi-file-earmark-word',
        'color' => 'secondary',
        'recommended' => false
    ]
];

// Kontrollera vilka filer som faktiskt finns
$docsPath = __DIR__ . '/../docs/pdf/';
foreach ($documents as &$doc) {
    $doc['exists'] = file_exists($docsPath . $doc['filename']);
    if ($doc['exists']) {
        $doc['size'] = filesize($docsPath . $doc['filename']);
        $doc['modified'] = filemtime($docsPath . $doc['filename']);
    }
}
unset($doc);

// Inkludera header
require_once 'include/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Hero Section -->
            <div class="pub-hero mb-4">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <h1 class="display-6 fw-bold text-white mb-3">
                            <i class="bi bi-shield-check me-3"></i>PUB-avtal & Bilagor
                        </h1>
                        <p class="lead text-white-50 mb-0">
                            Personuppgiftsbiträdesavtal enligt GDPR för tjänsten Stimma.
                            Baserat på SKR-koncernens avtalsmall.
                        </p>
                    </div>
                    <div class="col-lg-4 text-end d-none d-lg-block">
                        <div class="hero-icon">
                            <i class="bi bi-file-earmark-lock2"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Info Alert -->
            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-start">
                    <i class="bi bi-info-circle-fill me-3 fs-5"></i>
                    <div>
                        <strong>Om dokumenten</strong>
                        <p class="mb-0 mt-1">
                            Dessa dokument reglerar hur personuppgifter behandlas i Stimma enligt GDPR.
                            Dokumenten är i Word-format (.docx) så att du kan redigera och anpassa dem efter din organisations behov.
                            Det kompletta dokumentet (rekommenderat) innehåller både huvudavtalet och alla bilagor.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Document Cards -->
            <div class="row g-4">
                <?php foreach ($documents as $doc): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 border-0 shadow-sm document-card <?= $doc['recommended'] ? 'recommended' : '' ?>">
                        <?php if ($doc['recommended']): ?>
                        <div class="recommended-badge">
                            <i class="bi bi-star-fill me-1"></i>Rekommenderad
                        </div>
                        <?php endif; ?>
                        <div class="card-body text-center p-4">
                            <div class="document-icon bg-<?= $doc['color'] ?> bg-opacity-10 text-<?= $doc['color'] ?> mb-3">
                                <i class="<?= $doc['icon'] ?>"></i>
                            </div>
                            <h5 class="card-title mb-2"><?= htmlspecialchars($doc['title']) ?></h5>
                            <p class="card-text text-muted small mb-3"><?= htmlspecialchars($doc['description']) ?></p>

                            <?php if ($doc['exists']): ?>
                            <div class="file-info mb-3">
                                <span class="badge bg-light text-dark">
                                    <i class="bi bi-file-earmark me-1"></i>
                                    <?= round($doc['size'] / 1024) ?> KB
                                </span>
                                <span class="badge bg-light text-dark">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= date('Y-m-d', $doc['modified']) ?>
                                </span>
                            </div>
                            <a href="../docs/pdf/<?= urlencode($doc['filename']) ?>"
                               class="btn btn-<?= $doc['recommended'] ? $doc['color'] : 'outline-' . $doc['color'] ?> w-100"
                               download>
                                <i class="bi bi-download me-2"></i>Ladda ner
                            </a>
                            <?php else: ?>
                            <div class="alert alert-warning py-2 mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Filen saknas
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($isSuperAdmin): ?>
            <!-- Superadmin: Ladda upp PUB-avtal PDF -->
            <div class="card border-0 shadow-sm mt-4 mb-4">
                <div class="card-header py-3 bg-primary text-white">
                    <h6 class="m-0 fw-bold">
                        <i class="bi bi-cloud-upload me-2"></i>Ladda upp PUB-avtal (PDF för digital signering)
                    </h6>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Ladda upp en PDF-version av PUB-avtalet som organisationer kan granska innan de tecknar avtalet digitalt.</p>
                    <form method="POST" action="pub_documents.php" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="upload_pub_pdf">
                        <div class="col-md-3">
                            <label for="version" class="form-label">Version</label>
                            <input type="text" class="form-control" id="version" name="version"
                                   placeholder="t.ex. 1.0, 2.0" required>
                        </div>
                        <div class="col-md-6">
                            <label for="pdf_file" class="form-label">PDF-fil (max 10 MB)</label>
                            <input type="file" class="form-control" id="pdf_file" name="pdf_file"
                                   accept="application/pdf" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-2"></i>Ladda upp
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Superadmin: Uppladdade PDF:er -->
            <?php if (!empty($uploadedPubDocs)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 fw-bold">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Uppladdade PUB-avtal (PDF)
                        <span class="badge bg-secondary ms-2"><?= count($uploadedPubDocs) ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Version</th>
                                    <th>Filnamn</th>
                                    <th>SHA-256</th>
                                    <th>Uppladdad av</th>
                                    <th>Datum</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end">Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploadedPubDocs as $udoc): ?>
                                <tr>
                                    <td><strong><?= e($udoc['version']) ?></strong></td>
                                    <td>
                                        <a href="../docs/pdf/<?= e($udoc['filename']) ?>" target="_blank" title="<?= e($udoc['original_filename']) ?>">
                                            <i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= e($udoc['original_filename']) ?>
                                        </a>
                                    </td>
                                    <td><code title="<?= e($udoc['file_hash']) ?>"><?= substr($udoc['file_hash'], 0, 12) ?>...</code></td>
                                    <td><?= e($udoc['uploader_email'] ?? 'Okänd') ?></td>
                                    <td><?= date('Y-m-d H:i', strtotime($udoc['created_at'])) ?></td>
                                    <td class="text-center">
                                        <?php if ($udoc['is_active']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$udoc['is_active']): ?>
                                        <form method="POST" action="pub_documents.php" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="activate_pub_pdf">
                                            <input type="hidden" name="doc_id" value="<?= (int)$udoc['id'] ?>">
                                            <button type="submit" class="btn btn-outline-success btn-sm"
                                                    onclick="return confirm('Aktivera version <?= e($udoc['version']) ?>? Det nuvarande aktiva dokumentet inaktiveras.')">
                                                <i class="bi bi-check-circle me-1"></i>Aktivera
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Superadmin: Signeringsartefakter -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 fw-bold">
                        <i class="bi bi-pen me-2"></i>Digitalt tecknade PUB-avtal
                        <span class="badge bg-secondary ms-2"><?= count($signingArtifacts) ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($signingArtifacts)): ?>
                        <div class="p-4">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle me-2"></i>
                                Inga organisationer har tecknat PUB-avtal digitalt ännu.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Avtals-ID</th>
                                        <th>Organisation</th>
                                        <th>Org.nr</th>
                                        <th>Domän</th>
                                        <th>Undertecknare</th>
                                        <th>Datum</th>
                                        <th>Version</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($signingArtifacts as $idx => $art): ?>
                                    <tr role="button" data-bs-toggle="modal" data-bs-target="#artifactModal_<?= $idx ?>" title="Klicka för att visa avtalsdetaljer" style="cursor: pointer;">
                                        <td><code title="<?= e($art['agreement_id']) ?>"><?= substr($art['agreement_id'], 0, 8) ?>...</code></td>
                                        <td><strong><?= e($art['org_name']) ?></strong></td>
                                        <td><?= e($art['org_number']) ?></td>
                                        <td><?= e($art['domain']) ?></td>
                                        <td>
                                            <?= e($art['user_name']) ?>
                                            <?php if ($art['user_title']): ?>
                                                <br><small class="text-muted"><?= e($art['user_title']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('Y-m-d H:i', strtotime($art['signed_at'])) ?></td>
                                        <td><?= e($art['version']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Modaler för avtalsdetaljer -->
                        <?php foreach ($signingArtifacts as $idx => $art): ?>
                        <div class="modal fade" id="artifactModal_<?= $idx ?>" tabindex="-1" aria-labelledby="artifactModal_<?= $idx ?>Label" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-success text-white">
                                        <h5 class="modal-title" id="artifactModal_<?= $idx ?>Label">
                                            <i class="bi bi-file-earmark-check me-2"></i>PUB-avtal – <?= e($art['org_name']) ?>
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Stäng"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-12">
                                                <h6 class="text-muted text-uppercase small fw-bold mb-3">
                                                    <i class="bi bi-info-circle me-1"></i>Avtalsinformation
                                                </h6>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Avtals-ID:</strong></p>
                                                <p class="text-muted"><code><?= e($art['agreement_id']) ?></code></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Version:</strong></p>
                                                <p class="text-muted"><?= e($art['version']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Organisation:</strong></p>
                                                <p class="text-muted"><?= e($art['org_name']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Organisationsnummer:</strong></p>
                                                <p class="text-muted"><?= e($art['org_number']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Domän:</strong></p>
                                                <p class="text-muted"><?= e($art['domain']) ?></p>
                                            </div>
                                        </div>

                                        <hr>

                                        <div class="row mb-3">
                                            <div class="col-12">
                                                <h6 class="text-muted text-uppercase small fw-bold mb-3">
                                                    <i class="bi bi-person-check me-1"></i>Undertecknare
                                                </h6>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Namn:</strong></p>
                                                <p class="text-muted"><?= e($art['user_name']) ?></p>
                                            </div>
                                            <?php if (!empty($art['user_title'])): ?>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Titel:</strong></p>
                                                <p class="text-muted"><?= e($art['user_title']) ?></p>
                                            </div>
                                            <?php endif; ?>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>E-post:</strong></p>
                                                <p class="text-muted"><?= e($art['user_email']) ?></p>
                                            </div>
                                            <?php if (!empty($art['user_phone'])): ?>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Telefon:</strong></p>
                                                <p class="text-muted"><?= e($art['user_phone']) ?></p>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($art['agreement_email'])): ?>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Avtalskopia skickad till:</strong></p>
                                                <p class="text-muted"><?= e($art['agreement_email']) ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <hr>

                                        <div class="row mb-3">
                                            <div class="col-12">
                                                <h6 class="text-muted text-uppercase small fw-bold mb-3">
                                                    <i class="bi bi-clock-history me-1"></i>Signeringsdetaljer
                                                </h6>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Signerat:</strong></p>
                                                <p class="text-muted"><?= date('Y-m-d H:i:s', strtotime($art['signed_at'])) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>IP-adress:</strong></p>
                                                <p class="text-muted"><code><?= e($art['ip_address']) ?></code></p>
                                            </div>
                                            <?php if (!empty($art['pdf_hash'])): ?>
                                            <div class="col-md-12">
                                                <p class="mb-1"><strong>PDF SHA-256:</strong></p>
                                                <p class="text-muted"><code style="font-size: 0.8em;"><?= e($art['pdf_hash']) ?></code></p>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($art['pdf_filename'])): ?>
                                            <div class="col-md-12">
                                                <p class="mb-1"><strong>PDF-fil vid signering:</strong></p>
                                                <p class="text-muted"><?= e($art['pdf_filename']) ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($art['certification_text'])): ?>
                                        <hr>
                                        <div class="row">
                                            <div class="col-12">
                                                <h6 class="text-muted text-uppercase small fw-bold mb-3">
                                                    <i class="bi bi-patch-check me-1"></i>Intygande
                                                </h6>
                                                <div class="alert alert-light border">
                                                    <em><?= e($art['certification_text']) ?></em>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Stäng</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Additional Info Section -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-body p-4">
                    <h5 class="mb-3"><i class="bi bi-question-circle me-2 text-primary"></i>Vanliga frågor</h5>

                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item border-0 mb-2">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    Vad är ett PUB-avtal?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Ett personuppgiftsbiträdesavtal (PUB-avtal) är ett avtal mellan en personuppgiftsansvarig
                                    (t.ex. en kommun) och ett personuppgiftsbiträde (Stimma) som reglerar hur personuppgifter
                                    ska behandlas enligt GDPR.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item border-0 mb-2">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    Vilka personuppgifter behandlas i Stimma?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Stimma behandlar namn, e-postadress och utbildningsprogression (vilka kurser och lektioner
                                    som genomförts). Detaljerad information finns i Bilaga 1: Instruktion.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item border-0">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    Var lagras data?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    All data lagras inom EU/EES. Information om eventuella underbiträden och deras
                                    lokalisering finns i Bilaga 2: Lista över underbiträden.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Source Link -->
            <div class="text-center mt-4 mb-4 text-muted">
                <small>
                    <i class="bi bi-link-45deg me-1"></i>
                    Mallarna är baserade på
                    <a href="https://skr.se/juridik/dataskyddsforordningengdpr/personuppgiftsbitradesavtalpubavtal.8095.html"
                       target="_blank"
                       class="text-muted">
                        SKR:s mallar för personuppgiftsbiträdesavtal
                    </a>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
.pub-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%);
    border-radius: 16px;
    padding: 2.5rem;
    position: relative;
    overflow: hidden;
}

.pub-hero::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

.hero-icon {
    font-size: 6rem;
    color: rgba(255,255,255,0.15);
}

.document-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
}

.document-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1) !important;
}

.document-card.recommended {
    border: 2px solid #0d6efd !important;
}

.recommended-badge {
    position: absolute;
    top: 12px;
    right: -30px;
    background: #0d6efd;
    color: white;
    padding: 4px 40px;
    font-size: 0.7rem;
    font-weight: 600;
    transform: rotate(45deg);
}

.document-icon {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto;
}

.file-info {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.accordion-button:not(.collapsed) {
    background-color: #e7f1ff;
    color: #0d6efd;
}

.accordion-button:focus {
    box-shadow: none;
}
</style>

<?php require_once 'include/footer.php'; ?>
