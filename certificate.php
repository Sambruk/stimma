<?php
/**
 * Stimma - Certifikat
 *
 * Visar och genererar kurscertifikat
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/gamification.php';

// Hämta certifikatnummer från URL
$certNumber = $_GET['id'] ?? '';

if (empty($certNumber)) {
    // Om användaren är inloggad, visa deras certifikat
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }

    $user = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
    $certificates = getUserCertificates($user['id']);

    // Visa lista över certifikat
    ?>
    <!DOCTYPE html>
    <html lang="sv">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mina certifikat - Stimma</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-award me-2"></i>Mina certifikat</h1>
                <a href="index.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left me-1"></i>Tillbaka
                </a>
            </div>

            <?php if (empty($certificates)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                Du har inga certifikat ännu. Slutför en kurs för att få ditt första certifikat!
            </div>
            <?php else: ?>
            <div class="row g-4">
                <?php foreach ($certificates as $cert): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <i class="bi bi-award text-warning" style="font-size: 3rem;"></i>
                            </div>
                            <h5 class="card-title"><?= htmlspecialchars($cert['course_title']) ?></h5>
                            <p class="text-muted small mb-2">
                                Utfärdat: <?= date('Y-m-d', strtotime($cert['issued_at'])) ?>
                            </p>
                            <p class="text-muted small">
                                <code><?= htmlspecialchars($cert['certificate_number']) ?></code>
                            </p>
                            <a href="certificate.php?id=<?= urlencode($cert['certificate_number']) ?>"
                               class="btn btn-primary btn-sm" target="_blank">
                                <i class="bi bi-eye me-1"></i>Visa certifikat
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Hämta certifikat
$certificate = getCertificateByNumber($certNumber);

if (!$certificate) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="sv">
    <head>
        <meta charset="UTF-8">
        <title>Certifikat ej funnet - Stimma</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container py-5 text-center">
            <h1 class="text-danger"><i class="bi bi-x-circle"></i></h1>
            <h2>Certifikat ej funnet</h2>
            <p class="text-muted">Certifikatet med nummer <?= htmlspecialchars($certNumber) ?> kunde inte hittas.</p>
            <a href="index.php" class="btn btn-primary">Tillbaka till startsidan</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Visa certifikat
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certifikat - <?= htmlspecialchars($certificate['course_title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }

        .print-controls {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .print-controls button, .print-controls a {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .btn-print {
            background: #007bff;
            color: white;
        }

        .btn-print:hover {
            background: #0056b3;
        }

        .btn-back {
            background: #6c757d;
            color: white;
        }

        .btn-back:hover {
            background: #545b62;
        }

        .print-hint {
            background: #fff3cd;
            color: #856404;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
        }

        /* A4 Portrait: 210mm x 297mm */
        .certificate {
            width: 210mm;
            height: 297mm;
            background: white;
            position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            overflow: hidden;
        }

        .certificate-border {
            position: absolute;
            top: 8mm;
            left: 8mm;
            right: 8mm;
            bottom: 8mm;
            border: 4px solid #1a365d;
        }

        .certificate-border-inner {
            position: absolute;
            top: 12mm;
            left: 12mm;
            right: 12mm;
            bottom: 12mm;
            border: 2px solid #c9a227;
        }

        .certificate-content {
            position: absolute;
            top: 18mm;
            left: 18mm;
            right: 18mm;
            bottom: 18mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            padding: 8mm;
        }

        .certificate-header {
            width: 100%;
        }

        .logo-container {
            margin-bottom: 8mm;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12mm;
            min-height: 25mm;
        }

        .logo {
            height: 22mm;
        }

        .course-logo {
            max-height: 28mm;
            max-width: 70mm;
            object-fit: contain;
        }

        .certificate-title {
            font-family: 'Playfair Display', serif;
            font-size: 48pt;
            color: #1a365d;
            margin-bottom: 4mm;
            letter-spacing: 6px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .certificate-subtitle {
            font-size: 14pt;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 3px;
            font-weight: 600;
        }

        .certificate-body {
            width: 100%;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 10mm 0;
        }

        .presented-to {
            font-size: 14pt;
            color: #666;
            margin-bottom: 6mm;
            letter-spacing: 1px;
        }

        .recipient-name {
            font-family: 'Playfair Display', serif;
            font-size: 36pt;
            color: #1a365d;
            margin-bottom: 8mm;
            padding-bottom: 4mm;
            border-bottom: 3px solid #c9a227;
            display: inline-block;
            font-weight: 700;
            max-width: 90%;
            word-wrap: break-word;
        }

        .completion-text {
            font-size: 14pt;
            color: #444;
            margin-bottom: 6mm;
            letter-spacing: 0.5px;
        }

        .course-title {
            font-family: 'Playfair Display', serif;
            font-size: 24pt;
            color: #1a365d;
            margin-bottom: 8mm;
            font-weight: 600;
            line-height: 1.3;
            max-width: 90%;
            margin-left: auto;
            margin-right: auto;
        }

        .completion-date {
            font-size: 13pt;
            color: #555;
            font-weight: 500;
        }

        .certificate-footer {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-top: 5mm;
        }

        .certificate-number {
            font-size: 9pt;
            color: #888;
            font-family: 'Courier New', monospace;
            text-align: left;
        }

        .verification-text {
            font-size: 9pt;
            color: #888;
            text-align: right;
            line-height: 1.4;
        }

        .seal {
            width: 30mm;
            height: 30mm;
            background: linear-gradient(135deg, #c9a227 0%, #f4d03f 50%, #c9a227 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1a365d;
            font-weight: bold;
            font-size: 11pt;
            text-transform: uppercase;
            box-shadow: 0 3px 15px rgba(201, 162, 39, 0.5);
            letter-spacing: 1px;
        }

        /* Dekorativa hörndetaljer */
        .corner-decoration {
            position: absolute;
            width: 25mm;
            height: 25mm;
        }

        .corner-decoration.top-left {
            top: 10mm;
            left: 10mm;
            border-top: 4px solid #c9a227;
            border-left: 4px solid #c9a227;
            opacity: 0.6;
        }

        .corner-decoration.top-right {
            top: 10mm;
            right: 10mm;
            border-top: 4px solid #c9a227;
            border-right: 4px solid #c9a227;
            opacity: 0.6;
        }

        .corner-decoration.bottom-left {
            bottom: 10mm;
            left: 10mm;
            border-bottom: 4px solid #c9a227;
            border-left: 4px solid #c9a227;
            opacity: 0.6;
        }

        .corner-decoration.bottom-right {
            bottom: 10mm;
            right: 10mm;
            border-bottom: 4px solid #c9a227;
            border-right: 4px solid #c9a227;
            opacity: 0.6;
        }

        /* Dekorativt vattenmärke */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 180pt;
            color: rgba(26, 54, 93, 0.03);
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            pointer-events: none;
            white-space: nowrap;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .print-controls {
                display: none !important;
            }

            .certificate {
                box-shadow: none;
                width: 210mm;
                height: 297mm;
                margin: 0;
                page-break-after: avoid;
                page-break-inside: avoid;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }

            /* Säkerställ att färger skrivs ut */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }

        /* Responsiv visning på skärm */
        @media screen and (max-width: 800px) {
            .certificate {
                width: 100%;
                height: auto;
                aspect-ratio: 210/297;
                max-width: 95vw;
            }

            .certificate-title {
                font-size: 28pt;
            }

            .recipient-name {
                font-size: 22pt;
            }

            .course-title {
                font-size: 16pt;
            }

            .logo {
                height: 15mm;
            }

            .course-logo {
                max-height: 18mm;
            }

            .seal {
                width: 20mm;
                height: 20mm;
                font-size: 8pt;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button onclick="window.print()" class="btn-print">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
            </svg>
            Skriv ut / Spara som PDF
        </button>
        <a href="certificate.php" class="btn-back">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
            </svg>
            Mina certifikat
        </a>
        <span class="print-hint">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="vertical-align: -2px;">
                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
            </svg>
            A4-format - välj "Stående" vid utskrift
        </span>
    </div>

    <div class="certificate">
        <div class="certificate-border"></div>
        <div class="certificate-border-inner"></div>

        <div class="corner-decoration top-left"></div>
        <div class="corner-decoration top-right"></div>
        <div class="corner-decoration bottom-left"></div>
        <div class="corner-decoration bottom-right"></div>

        <!-- Subtilt vattenmärke -->
        <div class="watermark">CERTIFIKAT</div>

        <div class="certificate-content">
            <div class="certificate-header">
                <div class="logo-container">
                    <?php if (!empty($certificate['certificate_image_url'])):
                        // Check if it's a full URL or just a filename
                        $certImageUrl = $certificate['certificate_image_url'];
                        if (!preg_match('/^https?:\/\//', $certImageUrl)) {
                            $certImageUrl = 'upload/' . $certImageUrl;
                        }
                    ?>
                    <img src="<?= htmlspecialchars($certImageUrl) ?>" alt="<?= htmlspecialchars($certificate['course_title']) ?>" class="course-logo" onerror="this.parentNode.innerHTML='<img src=\'images/stimma-logo.png\' alt=\'Stimma\' class=\'logo\'>'">
                    <?php else: ?>
                    <img src="images/stimma-logo.png" alt="Stimma" class="logo">
                    <?php endif; ?>
                </div>
                <h1 class="certificate-title">Certifikat</h1>
                <p class="certificate-subtitle">Intyg om genomförd utbildning</p>
            </div>

            <div class="certificate-body">
                <p class="presented-to">Detta certifikat tilldelas</p>
                <h2 class="recipient-name"><?= htmlspecialchars($certificate['user_name']) ?></h2>
                <p class="completion-text">för att framgångsrikt ha genomfört kursen</p>
                <h3 class="course-title"><?= htmlspecialchars($certificate['course_title']) ?></h3>
                <?php
                    $months = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
                    $date = strtotime($certificate['completion_date']);
                    $formattedDate = date('j', $date) . ' ' . $months[(int)date('n', $date) - 1] . ' ' . date('Y', $date);
                ?>
                <p class="completion-date">Slutförd den <?= $formattedDate ?></p>
            </div>

            <div class="certificate-footer">
                <div class="certificate-number">
                    Certifikatnummer:<br>
                    <?= htmlspecialchars($certificate['certificate_number']) ?>
                </div>
                <div class="seal">
                    <span>STIMMA</span>
                </div>
                <div class="verification-text">
                    Verifiera certifikatet på:<br>
                    <strong>stimma.sambruk.se</strong>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
