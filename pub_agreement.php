<?php
/**
 * Stimma - Teckna PUB-avtal
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 *
 * Trestegsflöde: 1) Granska PDF, 2) SMS-verifiering, 3) Fyll i och teckna
 */

require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';
require_once 'include/mail.php';
require_once 'include/sms.php';
require_once 'include/pdf_stamp.php';

// Kontrollera att användaren är inloggad
if (!isLoggedIn()) {
    redirect('index.php');
    exit;
}

// Hämta användarinfo
$userId = $_SESSION['user_id'];
$userEmail = $_SESSION['user_email'];
$userDomain = getUserDomain($userEmail);
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);

// Kontrollera om domänen redan har PUB-avtal
if (hasPubAgreement($userDomain)) {
    $_SESSION['message'] = 'Er organisation har redan ett tecknat PUB-avtal.';
    $_SESSION['message_type'] = 'info';
    redirect('index.php');
    exit;
}

// Hämta aktivt PUB-dokument
$activePubDoc = getActivePubDocument();

$page_title = 'Teckna PUB-avtal';
$errors = [];

// Kontrollera SMS-verifieringsstatus
$smsVerified = isset($_SESSION['sms_verified']) && $_SESSION['sms_verified'] === true;
$smsPhone = $_SESSION['sms_phone_raw'] ?? '';

// Hantera POST (signering)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_agreement'])) {
    // Validera CSRF-token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errors[] = 'Ogiltig säkerhetstoken. Försök igen.';
    }

    // Kontrollera att SMS-verifiering är genomförd
    if (!$smsVerified) {
        $errors[] = 'SMS-verifiering krävs innan avtalet kan tecknas.';
    }

    // Hämta och sanera formulärdata
    $orgName = trim($_POST['org_name'] ?? '');
    $orgNumber = trim($_POST['org_number'] ?? '');
    $agreementEmail = trim($_POST['agreement_email'] ?? '');
    $signerName = trim($_POST['signer_name'] ?? '');
    $signerTitle = trim($_POST['signer_title'] ?? '');
    $signerEmail = trim($_POST['signer_email'] ?? '');
    $signerPhone = trim($_POST['signer_phone'] ?? '');
    $certification = isset($_POST['certification']) && $_POST['certification'] !== '' ? true : false;

    // Fallback: om JS inte synkade fälten, använd sessionsdata
    if (empty($signerPhone) && !empty($_SESSION['sms_phone_raw'])) {
        $signerPhone = $_SESSION['sms_phone_raw'];
    }
    if (empty($signerName) && !empty($currentUser['name'])) {
        $signerName = $currentUser['name'];
    }
    if (empty($signerEmail) && !empty($userEmail)) {
        $signerEmail = $userEmail;
    }
    if (!$certification && $smsVerified) {
        // Om SMS verifierat har användaren redan intygat i steg 2
        $certification = true;
    }

    // Validering
    if (empty($orgName)) {
        $errors[] = 'Organisationens namn måste anges.';
    }
    if (empty($orgNumber) || !preg_match('/^\d{6}-\d{4}$/', $orgNumber)) {
        $errors[] = 'Organisationsnummer måste anges i format NNNNNN-NNNN.';
    }
    if (empty($agreementEmail) || !filter_var($agreementEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'En giltig e-postadress för avtalskopia måste anges.';
    }
    if (empty($signerName)) {
        $errors[] = 'Undertecknarens namn måste anges.';
    }
    if (empty($signerEmail) || !filter_var($signerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Undertecknarens e-postadress måste vara giltig.';
    }
    if (empty($signerPhone)) {
        $errors[] = 'Undertecknarens telefonnummer måste anges.';
    }
    if (!$certification) {
        $errors[] = 'Du måste intyga att du har behörighet att teckna avtalet.';
    }

    if (empty($errors)) {
        // Beräkna PDF-hash om det finns ett aktivt dokument
        $pdfFilename = null;
        $pdfHash = null;
        $docVersion = '1.0';
        $pdfPath = null;

        if ($activePubDoc) {
            $pdfPath = __DIR__ . '/docs/pdf/' . $activePubDoc['filename'];
            if (file_exists($pdfPath)) {
                $pdfHash = hash_file('sha256', $pdfPath);
                $pdfFilename = $activePubDoc['original_filename'];
                $docVersion = $activePubDoc['version'];
            }
        }

        // Generera avtals-ID
        $agreementId = generateUuid();
        $signedAt = date('Y-m-d H:i:s');

        // Intygandetext
        $certificationText = "Jag intygar att jag har behörighet att teckna detta PUB-avtal (personuppgiftsbiträdesavtal) för {$orgName} (org.nr {$orgNumber}) med Sambruk.";

        // Spara artefakt
        $artifactData = [
            'agreement_id' => $agreementId,
            'version' => $docVersion,
            'pdf_filename' => $pdfFilename,
            'pdf_hash' => $pdfHash,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_id' => $userId,
            'user_email' => $signerEmail,
            'user_name' => $signerName,
            'user_title' => $signerTitle ?: null,
            'user_phone' => $signerPhone ?: null,
            'domain' => $userDomain,
            'org_name' => $orgName,
            'org_number' => $orgNumber,
            'agreement_email' => $agreementEmail,
            'certification_text' => $certificationText
        ];

        $result = savePubAgreementArtifact($artifactData);

        if ($result !== null) {
            // Uppdatera domänens PUB-status
            $today = date('Y-m-d');
            $notes = "Digitalt tecknat av {$signerName} ({$signerEmail}), {$orgName}, avtal-ID: {$agreementId}, SMS-verifierad";
            updatePubAgreement($userDomain, true, $today, $notes);

            // Logga aktiviteten
            logActivity($userEmail, "PUB-avtal tecknat för domän: {$userDomain}", [
                'action' => 'pub_agreement_signed',
                'agreement_id' => $agreementId,
                'domain' => $userDomain,
                'org_name' => $orgName,
                'org_number' => $orgNumber,
                'signer_name' => $signerName,
                'sms_verified' => true,
                'sms_phone' => $signerPhone
            ]);

            // Skapa stämplad PDF
            $stampedPdf = null;
            if ($pdfPath && file_exists($pdfPath)) {
                $stampedPdf = stampPubAgreementPdf($pdfPath, [
                    'agreement_id' => $agreementId,
                    'org_name' => $orgName,
                    'org_number' => $orgNumber,
                    'domain' => $userDomain,
                    'signer_name' => $signerName,
                    'signer_title' => $signerTitle ?: '-',
                    'signer_email' => $signerEmail,
                    'signer_phone' => $signerPhone,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    'signed_at' => $signedAt,
                    'pdf_hash' => $pdfHash,
                    'certification_text' => $certificationText
                ]);
            }

            // Skicka bekräftelsemail
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'Stimma';
            $subject = "Bekräftelse: PUB-avtal tecknat för {$orgName} - {$siteName}";

            $emailBody = "
            <!DOCTYPE html>
            <html lang='sv'>
            <head><meta charset='UTF-8'></head>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background-color: #d4edda; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;'>
                    <h1 style='color: #155724; margin: 0 0 10px 0; font-size: 22px;'>{$siteName} - PUB-avtal tecknat</h1>
                    <p style='margin: 0; color: #155724;'>Bekräftelse på digitalt tecknat personuppgiftsbiträdesavtal</p>
                </div>

                <div style='padding: 20px 0;'>
                    <p>Ett PUB-avtal (personuppgiftsbiträdesavtal) har tecknats digitalt med följande uppgifter:</p>

                    <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold; width: 40%;'>Avtals-ID</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($agreementId) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>Organisation</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($orgName) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>Org.nummer</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($orgNumber) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>Domän</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($userDomain) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>Undertecknare</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($signerName) . ($signerTitle ? ' (' . e($signerTitle) . ')' : '') . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>E-post</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($signerEmail) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>Telefon</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($signerPhone) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>Datum</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($signedAt) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>IP-adress</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>" . e($_SERVER['REMOTE_ADDR'] ?? '') . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>SMS-verifierad</td><td style='padding: 8px; border-bottom: 1px solid #dee2e6;'>Ja</td></tr>
                    </table>

                    <div style='background-color: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #007bff;'>
                        <strong>Intygande:</strong><br>
                        <em>" . e($certificationText) . "</em>
                    </div>" .
                    ($stampedPdf ? "<p style='color: #155724;'><strong>Bifogat:</strong> Det stämplade PUB-avtalet med signeringsintyg finns bifogat som PDF i detta mail.</p>" : "") . "
                </div>

                <div style='border-top: 1px solid #dee2e6; padding-top: 15px; margin-top: 20px; color: #6c757d; font-size: 12px;'>
                    <p>Detta är ett automatiskt meddelande från {$siteName}. Spara detta mail som kvitto på det tecknade PUB-avtalet.</p>
                </div>
            </body>
            </html>";

            // Förbered bilagor
            $attachments = [];
            if ($stampedPdf) {
                $attachments[] = [
                    'filename' => 'PUB-avtal-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $orgName) . '-signerat.pdf',
                    'content' => $stampedPdf,
                    'mime_type' => 'application/pdf'
                ];
            }

            // Skicka mail med funktion som stöder bilagor
            $mailFn = !empty($attachments) ? 'sendSmtpMailWithAttachment' : 'sendSmtpMail';

            // 1. Skicka till undertecknarens e-post
            $mailFn($signerEmail, $subject, $emailBody, $attachments);

            // 2. Skicka till avtalskopia-adressen (om annan)
            if (strtolower($agreementEmail) !== strtolower($signerEmail)) {
                $mailFn($agreementEmail, $subject, $emailBody, $attachments);
            }

            // 3. Skicka till admin@sambruk.se
            $mailFn('admin@sambruk.se', $subject, $emailBody, $attachments);

            // Rensa SMS-sessionsdata
            unset(
                $_SESSION['sms_verified'],
                $_SESSION['sms_verified_at'],
                $_SESSION['sms_verified_phone'],
                $_SESSION['sms_phone'],
                $_SESSION['sms_phone_raw'],
                $_SESSION['sms_send_count']
            );

            $_SESSION['message'] = 'PUB-avtalet har tecknats! En bekräftelse med det stämplade avtalet har skickats till din e-post.';
            $_SESSION['message_type'] = 'success';
            redirect('index.php');
            exit;
        } else {
            $errors[] = 'Kunde inte spara avtalet. Försök igen eller kontakta support.';
        }
    }
}

// Generera CSRF-token
$csrfToken = generateCsrfToken();

// Inkludera header
require_once 'include/header.php';
?>

<div class="container my-4" style="max-width: 800px;">
    <div class="card shadow">
        <div class="card-header bg-primary text-white py-3">
            <h4 class="m-0"><i class="bi bi-pen me-2"></i>Teckna PUB-avtal med Sambruk</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Varför behövs ett PUB-avtal?</strong><br>
                Ett personuppgiftsbiträdesavtal (PUB-avtal) måste tecknas mellan er organisation och Sambruk innan Stimma får användas för att medarbetare ska genomföra utbildningar. Utan avtal får plattformen bara användas för att skapa och testa utbildningar.
            </div>

            <!-- Steg 1: Granska avtalet -->
            <div class="card mb-4 border-primary">
                <div class="card-header bg-primary bg-opacity-10">
                    <h5 class="m-0"><span class="badge bg-primary me-2">1</span>Granska PUB-avtalet</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">Innan du kan teckna avtalet måste du granska PUB-avtalet. Klicka på knappen nedan för att öppna dokumentet i en ny flik.</p>
                    <?php if ($activePubDoc): ?>
                    <a href="docs/pdf/<?= e($activePubDoc['filename']) ?>" target="_blank" class="btn btn-primary" id="viewPdfBtn">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Visa PUB-avtalet (PDF)
                    </a>
                    <small class="text-muted ms-2">Version <?= e($activePubDoc['version']) ?></small>
                    <div id="pdfViewedMsg" class="mt-3" style="display:none">
                        <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Du har öppnat avtalet. Du kan nu gå vidare till steg 2.</span>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Inget PUB-avtalsdokument har laddats upp ännu. Kontakta <a href="mailto:hjalp@sambruksupport.se" class="alert-link">hjalp@sambruksupport.se</a>.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$activePubDoc): ?>
            <div class="alert alert-secondary">Formuläret är inte tillgängligt förrän ett PUB-avtalsdokument finns uppladdat.</div>
            <?php else: ?>

            <!-- Steg 2: Undertecknare & SMS-verifiering (dolt tills PDF granskats) -->
            <div id="smsSection" style="display:none">
            <div class="card mb-4 border-primary">
                <div class="card-header bg-primary bg-opacity-10">
                    <h5 class="m-0"><span class="badge bg-primary me-2">2</span>Undertecknare & SMS-verifiering</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">Fyll i dina uppgifter som undertecknare och verifiera dig via SMS.</p>

                    <h5 class="mb-3"><i class="bi bi-person me-2"></i>Undertecknare</h5>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="step2_signer_name"
                               placeholder="Namn" required
                               value="<?= e($_POST['signer_name'] ?? $currentUser['name'] ?? '') ?>">
                        <label for="step2_signer_name">Namn *</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="step2_signer_title"
                               placeholder="Titel (valfritt)"
                               value="<?= e($_POST['signer_title'] ?? '') ?>">
                        <label for="step2_signer_title">Titel (valfritt)</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="step2_signer_email"
                               placeholder="E-post" required
                               value="<?= e($_POST['signer_email'] ?? $userEmail ?? '') ?>">
                        <label for="step2_signer_email">E-post *</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="step2_agreement_email"
                               placeholder="E-post för avtalskopia" required
                               value="<?= e($_POST['agreement_email'] ?? '') ?>">
                        <label for="step2_agreement_email">E-post för avtalskopia *</label>
                        <div class="form-text">Hit skickas en kopia av det tecknade avtalet (t.ex. registrator eller avtalsansvarig), utöver till undertecknaren.</div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="step2_certification" required>
                        <label class="form-check-label" for="step2_certification">
                            <strong>Jag intygar att jag har behörighet att teckna detta PUB-avtal (personuppgiftsbiträdesavtal) för min organisation med Sambruk.</strong>
                        </label>
                    </div>

                    <hr class="my-4">

                    <h5 class="mb-3"><i class="bi bi-phone me-2"></i>SMS-verifiering</h5>

                    <?php if (!empty($errors) && !$smsVerified): ?>
                    <div class="alert alert-danger mb-3">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Du måste slutföra SMS-verifieringen innan du kan teckna avtalet.</strong>
                    </div>
                    <?php endif; ?>

                    <?php if ($smsVerified): ?>
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>SMS-verifiering genomförd!</strong> Telefonnummer: <?= e($smsPhone) ?>
                        <br>Du kan nu gå vidare till steg 3.
                    </div>
                    <?php else: ?>
                    <p class="mb-3">Ange ditt mobilnummer för att få en verifieringskod via SMS.</p>
                    <div id="smsPhoneGroup">
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                            <input type="tel" class="form-control" id="smsPhone" placeholder="070-123 45 67 eller +46701234567" value="<?= e($smsPhone) ?>">
                            <button type="button" class="btn btn-primary" id="sendSmsBtn">
                                <i class="bi bi-send me-1"></i>Skicka kod
                            </button>
                        </div>
                        <div class="form-text mb-2">Koden är giltig i 10 minuter.</div>
                    </div>

                    <div id="smsCodeGroup" style="display:none">
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="text" class="form-control" id="smsCode" placeholder="6-siffrig kod" maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code">
                            <button type="button" class="btn btn-success" id="verifySmsBtn">
                                <i class="bi bi-check-lg me-1"></i>Verifiera
                            </button>
                        </div>
                        <div class="form-text mb-2">Ange den 6-siffriga koden som skickats till din telefon.</div>
                        <button type="button" class="btn btn-link btn-sm p-0" id="resendSmsBtn">Skicka ny kod</button>
                    </div>

                    <div id="smsStatus" class="mt-2" style="display:none"></div>
                    <?php endif; ?>
                </div>
            </div>
            </div>

            <!-- Steg 3: Organisationsuppgifter & teckna (dolt tills SMS verifierats) -->
            <div id="formSection" style="<?= $smsVerified ? '' : 'display:none' ?>">
            <div class="card mb-4">
                <div class="card-header bg-primary bg-opacity-10">
                    <h5 class="m-0"><span class="badge bg-primary me-2">3</span>Organisationsuppgifter & teckna avtalet</h5>
                </div>
                <div class="card-body">

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <strong>Följande fel uppstod:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" action="pub_agreement.php" id="pubForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="sign_agreement" value="1">

                <!-- Dolda fält som fylls från steg 2 -->
                <input type="hidden" name="signer_name" id="signer_name" value="<?= e($_POST['signer_name'] ?? $currentUser['name'] ?? '') ?>">
                <input type="hidden" name="signer_title" id="signer_title" value="<?= e($_POST['signer_title'] ?? '') ?>">
                <input type="hidden" name="signer_email" id="signer_email" value="<?= e($_POST['signer_email'] ?? $userEmail ?? '') ?>">
                <input type="hidden" name="signer_phone" id="signer_phone" value="<?= e($_POST['signer_phone'] ?? $smsPhone ?? '') ?>">
                <input type="hidden" name="agreement_email" id="agreement_email" value="<?= e($_POST['agreement_email'] ?? '') ?>">
                <input type="hidden" name="certification" id="certification" value="">

                <!-- Sammanfattning från steg 2 -->
                <div class="alert alert-light border mb-4" id="step2Summary">
                    <h6 class="mb-2"><i class="bi bi-person-check me-1"></i>Undertecknare</h6>
                    <div class="row">
                        <div class="col-sm-6"><strong>Namn:</strong> <span id="summaryName">-</span></div>
                        <div class="col-sm-6"><strong>Titel:</strong> <span id="summaryTitle">-</span></div>
                        <div class="col-sm-6"><strong>E-post:</strong> <span id="summaryEmail">-</span></div>
                        <div class="col-sm-6"><strong>Telefon:</strong> <span id="summaryPhone">-</span> <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>SMS-verifierad</span></div>
                        <div class="col-12 mt-1"><strong>Avtalskopia till:</strong> <span id="summaryAgreementEmail">-</span></div>
                    </div>
                </div>

                <h5 class="mb-3"><i class="bi bi-building me-2"></i>Organisationsuppgifter</h5>

                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="org_name" name="org_name"
                           placeholder="Organisationens namn" required
                           value="<?= e($_POST['org_name'] ?? '') ?>">
                    <label for="org_name">Organisationens namn *</label>
                </div>

                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="org_number" name="org_number"
                           placeholder="NNNNNN-NNNN" required pattern="\d{6}-\d{4}"
                           value="<?= e($_POST['org_number'] ?? '') ?>">
                    <label for="org_number">Organisationsnummer (NNNNNN-NNNN) *</label>
                    <div class="form-text">Format: 123456-7890</div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-between mt-4">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Tillbaka
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                        <i class="bi bi-pen me-2"></i>Teckna PUB-avtal
                    </button>
                </div>
            </form>

                </div><!-- /card-body -->
            </div><!-- /card -->
            </div><!-- /formSection -->
            <?php endif; ?>

        </div><!-- /card-body outer -->
    </div><!-- /card outer -->
</div>

<script>
(function() {
    var viewPdfBtn = document.getElementById('viewPdfBtn');
    var smsSection = document.getElementById('smsSection');
    var formSection = document.getElementById('formSection');
    var pdfViewedMsg = document.getElementById('pdfViewedMsg');
    var csrfToken = '<?= e($csrfToken) ?>';
    var smsVerified = <?= $smsVerified ? 'true' : 'false' ?>;

    // Steg 2 fält
    var s2Name = document.getElementById('step2_signer_name');
    var s2Title = document.getElementById('step2_signer_title');
    var s2Email = document.getElementById('step2_signer_email');
    var s2AgreementEmail = document.getElementById('step2_agreement_email');
    var s2Certification = document.getElementById('step2_certification');

    // Steg 3 dolda fält
    var h_name = document.getElementById('signer_name');
    var h_title = document.getElementById('signer_title');
    var h_email = document.getElementById('signer_email');
    var h_phone = document.getElementById('signer_phone');
    var h_agreementEmail = document.getElementById('agreement_email');
    var h_certification = document.getElementById('certification');

    // Sammanfattning
    var sumName = document.getElementById('summaryName');
    var sumTitle = document.getElementById('summaryTitle');
    var sumEmail = document.getElementById('summaryEmail');
    var sumPhone = document.getElementById('summaryPhone');
    var sumAgrEmail = document.getElementById('summaryAgreementEmail');

    // Sparade värden efter att steg 2 bekräftats (fälten ersätts med bekräftelsetext)
    var savedStep2 = null;

    // Synka steg 2 → steg 3 dolda fält + sammanfattning
    function syncStep2ToStep3() {
        // Om fälten redan är borta (ersatta med bekräftelse), använd sparade värden
        if (savedStep2) {
            if (h_name) h_name.value = savedStep2.name;
            if (h_title) h_title.value = savedStep2.title;
            if (h_email) h_email.value = savedStep2.email;
            if (h_phone) h_phone.value = savedStep2.phone;
            if (h_agreementEmail) h_agreementEmail.value = savedStep2.agreementEmail;
            if (h_certification) h_certification.value = savedStep2.certification;

            if (sumName) sumName.textContent = savedStep2.name || '-';
            if (sumTitle) sumTitle.textContent = savedStep2.title || '-';
            if (sumEmail) sumEmail.textContent = savedStep2.email || '-';
            if (sumPhone) sumPhone.textContent = savedStep2.phone || '-';
            if (sumAgrEmail) sumAgrEmail.textContent = savedStep2.agreementEmail || '-';
            return;
        }

        var nameVal = s2Name ? s2Name.value.trim() : '';
        var titleVal = s2Title ? s2Title.value.trim() : '';
        var emailVal = s2Email ? s2Email.value.trim() : '';
        var agrEmailVal = s2AgreementEmail ? s2AgreementEmail.value.trim() : '';
        var certVal = (s2Certification && s2Certification.checked) ? '1' : '';
        var phoneVal = document.getElementById('smsPhone') ? document.getElementById('smsPhone').value.trim() : '';

        if (h_name) h_name.value = nameVal;
        if (h_title) h_title.value = titleVal;
        if (h_email) h_email.value = emailVal;
        if (h_phone) h_phone.value = phoneVal;
        if (h_agreementEmail) h_agreementEmail.value = agrEmailVal;
        if (h_certification) h_certification.value = certVal;

        if (sumName) sumName.textContent = nameVal || '-';
        if (sumTitle) sumTitle.textContent = titleVal || '-';
        if (sumEmail) sumEmail.textContent = emailVal || '-';
        if (sumPhone) sumPhone.textContent = phoneVal || '-';
        if (sumAgrEmail) sumAgrEmail.textContent = agrEmailVal || '-';
    }

    // Spara steg 2-värden permanent (anropas innan fälten tas bort)
    function saveStep2Values() {
        savedStep2 = {
            name: s2Name ? s2Name.value.trim() : '',
            title: s2Title ? s2Title.value.trim() : '',
            email: s2Email ? s2Email.value.trim() : '',
            phone: document.getElementById('smsPhone') ? document.getElementById('smsPhone').value.trim() : '',
            agreementEmail: s2AgreementEmail ? s2AgreementEmail.value.trim() : '',
            certification: (s2Certification && s2Certification.checked) ? '1' : ''
        };
    }

    // --- Steg 1: Visa PDF → lås upp steg 2 ---
    function showSmsSection() {
        if (smsSection) smsSection.style.display = '';
        if (pdfViewedMsg) pdfViewedMsg.style.display = '';
    }

    if (viewPdfBtn) {
        viewPdfBtn.addEventListener('click', function() {
            sessionStorage.setItem('pubPdfViewed', '1');
            showSmsSection();
        });
    }

    if (sessionStorage.getItem('pubPdfViewed') === '1') {
        showSmsSection();
    }

    // Om SMS redan verifierat (sidladdning med session), visa steg 2 och 3
    if (smsVerified) {
        showSmsSection();
        if (formSection) formSection.style.display = '';
        // Fälten kanske inte finns (PHP renderade bekräftelse istället), sätt savedStep2 från dolda fält
        if (!s2Name) {
            savedStep2 = {
                name: h_name ? h_name.value : '',
                title: h_title ? h_title.value : '',
                email: h_email ? h_email.value : '',
                phone: h_phone ? h_phone.value : '',
                agreementEmail: h_agreementEmail ? h_agreementEmail.value : '',
                certification: h_certification ? h_certification.value : '1'
            };
        }
        syncStep2ToStep3();
    }

    // Om det finns POST-errors, visa steg 2 alltid, och steg 3 bara om SMS verifierat
    <?php if (!empty($errors)): ?>
    showSmsSection();
    <?php if ($smsVerified): ?>
    if (formSection) formSection.style.display = '';
    <?php endif; ?>
    <?php endif; ?>

    // --- Steg 2: Validering av fält innan SMS skickas ---
    function validateStep2Fields() {
        var errors = [];
        if (!s2Name || !s2Name.value.trim()) errors.push('Namn måste anges.');
        if (!s2Email || !s2Email.value.trim()) errors.push('E-post måste anges.');
        if (s2Email && s2Email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s2Email.value.trim())) errors.push('Ange en giltig e-postadress.');
        if (!s2AgreementEmail || !s2AgreementEmail.value.trim()) errors.push('E-post för avtalskopia måste anges.');
        if (s2AgreementEmail && s2AgreementEmail.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s2AgreementEmail.value.trim())) errors.push('Ange en giltig e-postadress för avtalskopia.');
        if (!s2Certification || !s2Certification.checked) errors.push('Du måste intyga att du har behörighet att teckna avtalet.');
        return errors;
    }

    // --- SMS-verifiering via AJAX ---
    var sendSmsBtn = document.getElementById('sendSmsBtn');
    var verifySmsBtn = document.getElementById('verifySmsBtn');
    var resendSmsBtn = document.getElementById('resendSmsBtn');
    var smsPhoneInput = document.getElementById('smsPhone');
    var smsCodeInput = document.getElementById('smsCode');
    var smsCodeGroup = document.getElementById('smsCodeGroup');
    var smsStatus = document.getElementById('smsStatus');

    function showStatus(message, type) {
        if (!smsStatus) return;
        smsStatus.style.display = '';
        smsStatus.className = 'mt-2 alert alert-' + type;
        smsStatus.innerHTML = message;
    }

    function hideStatus() {
        if (smsStatus) smsStatus.style.display = 'none';
    }

    function sendSms() {
        // Validera steg 2-fält först
        var errors = validateStep2Fields();
        if (errors.length > 0) {
            showStatus('<i class="bi bi-exclamation-circle me-1"></i>' + errors.join('<br>'), 'warning');
            return;
        }

        var phone = smsPhoneInput ? smsPhoneInput.value.trim() : '';
        if (!phone) {
            showStatus('<i class="bi bi-exclamation-circle me-1"></i>Ange ett telefonnummer.', 'warning');
            return;
        }

        if (sendSmsBtn) {
            sendSmsBtn.disabled = true;
            sendSmsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Skickar...';
        }
        hideStatus();

        var formData = new FormData();
        formData.append('action', 'send_sms');
        formData.append('phone', phone);
        formData.append('csrf_token', csrfToken);

        fetch('pub_agreement_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showStatus('<i class="bi bi-check-circle me-1"></i>Verifieringskod skickad till ' + phone + '.', 'success');
                if (smsCodeGroup) smsCodeGroup.style.display = '';
                if (smsCodeInput) smsCodeInput.focus();
                // Lås steg 2-fälten efter SMS skickats
                if (s2Name) s2Name.readOnly = true;
                if (s2Title) s2Title.readOnly = true;
                if (s2Email) s2Email.readOnly = true;
                if (s2AgreementEmail) s2AgreementEmail.readOnly = true;
                if (s2Certification) s2Certification.disabled = true;
                if (smsPhoneInput) smsPhoneInput.readOnly = true;
            } else {
                showStatus('<i class="bi bi-exclamation-circle me-1"></i>' + (data.error || 'Kunde inte skicka SMS.'), 'danger');
            }
        })
        .catch(function() {
            showStatus('<i class="bi bi-exclamation-circle me-1"></i>Nätverksfel. Försök igen.', 'danger');
        })
        .finally(function() {
            if (sendSmsBtn) {
                sendSmsBtn.disabled = false;
                sendSmsBtn.innerHTML = '<i class="bi bi-send me-1"></i>Skicka kod';
            }
        });
    }

    function verifySms() {
        var code = smsCodeInput ? smsCodeInput.value.trim() : '';
        if (!code || !/^\d{6}$/.test(code)) {
            showStatus('<i class="bi bi-exclamation-circle me-1"></i>Ange en 6-siffrig kod.', 'warning');
            return;
        }

        if (verifySmsBtn) {
            verifySmsBtn.disabled = true;
            verifySmsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifierar...';
        }
        hideStatus();

        var formData = new FormData();
        formData.append('action', 'verify_sms');
        formData.append('code', code);
        formData.append('csrf_token', csrfToken);

        fetch('pub_agreement_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                smsVerified = true;

                // Spara steg 2-värden INNAN fälten ersätts
                saveStep2Values();
                syncStep2ToStep3();

                // Lås upp steg 3
                if (formSection) formSection.style.display = '';

                // Visa bekräftelse i steg 2
                var smsCard = smsSection.querySelector('.card-body');
                if (smsCard) {
                    var name = s2Name ? s2Name.value.trim() : '';
                    var email = s2Email ? s2Email.value.trim() : '';
                    var phone = smsPhoneInput ? smsPhoneInput.value.trim() : '';
                    smsCard.innerHTML = '<div class="alert alert-success mb-0">' +
                        '<i class="bi bi-check-circle-fill me-2"></i>' +
                        '<strong>Steg 2 slutfört!</strong><br>' +
                        'Undertecknare: ' + name + ' (' + email + ')<br>' +
                        'Telefon: ' + phone + ' <span class="badge bg-success">SMS-verifierad</span>' +
                        '</div>';
                }

                // Scrolla till formuläret
                formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                showStatus('<i class="bi bi-exclamation-circle me-1"></i>' + (data.error || 'Felaktig kod.'), 'danger');
            }
        })
        .catch(function() {
            showStatus('<i class="bi bi-exclamation-circle me-1"></i>Nätverksfel. Försök igen.', 'danger');
        })
        .finally(function() {
            if (verifySmsBtn) {
                verifySmsBtn.disabled = false;
                verifySmsBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Verifiera';
            }
        });
    }

    if (sendSmsBtn) sendSmsBtn.addEventListener('click', sendSms);
    if (resendSmsBtn) resendSmsBtn.addEventListener('click', sendSms);
    if (verifySmsBtn) verifySmsBtn.addEventListener('click', verifySms);

    // Enter-tangenten i kodfältet → verifiera
    if (smsCodeInput) {
        smsCodeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                verifySms();
            }
        });
    }

    // Enter-tangenten i telefonfältet → skicka SMS
    if (smsPhoneInput) {
        smsPhoneInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendSms();
            }
        });
    }

    // Synka vid formulärsubmit (säkerhet)
    var pubForm = document.getElementById('pubForm');
    if (pubForm) {
        pubForm.addEventListener('submit', function() {
            syncStep2ToStep3();
        });
    }
})();
</script>

<?php require_once 'include/footer.php'; ?>
