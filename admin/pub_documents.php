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

// Sätt sidtitel
$page_title = 'PUB-avtal & Bilagor';

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
