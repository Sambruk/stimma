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

// Användarhandboken är tillgänglig för alla inloggade användare
if (!isLoggedIn()) {
    redirect('../index.php');
    exit;
}

// Sätt variabler för header (behövs för admin-menyn)
$user = queryOne("SELECT is_admin, is_editor FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
$isAdmin = $user && $user['is_admin'] == 1;
$isEditor = $user && $user['is_editor'] == 1;

// Sätt sidtitel
$page_title = 'Användarhandbok';

// Inkludera header
require_once 'include/header.php';
?>

<!-- Hero Section -->
<div class="guide-hero mb-5">
    <div class="row align-items-center">
        <div class="col-lg-8">
            <h1 class="display-5 fw-bold text-white mb-3">
                <i class="bi bi-book-half me-3"></i>Användarhandbok
            </h1>
            <p class="lead text-white-50 mb-0">
                Lär dig använda Stimma - din plattform för mikroutbildning.
                Här hittar du guider för alla användarroller.
            </p>
        </div>
        <div class="col-lg-4 text-end d-none d-lg-block">
            <div class="hero-illustration">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
        </div>
    </div>
</div>

<!-- Quick Navigation -->
<div class="row mb-5">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h5 class="card-title mb-4"><i class="bi bi-signpost-2 me-2 text-primary"></i>Snabbnavigering</h5>
                <div class="row g-3">
                    <div class="col-md-3 col-6">
                        <a href="#studenter" class="quick-nav-card student">
                            <div class="icon"><i class="bi bi-person-fill"></i></div>
                            <div class="label">Studenter</div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="#redaktorer" class="quick-nav-card editor">
                            <div class="icon"><i class="bi bi-pencil-fill"></i></div>
                            <div class="label">Redaktörer</div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="#administratorer" class="quick-nav-card admin">
                            <div class="icon"><i class="bi bi-shield-fill"></i></div>
                            <div class="label">Administratörer</div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="#superadmin" class="quick-nav-card superadmin">
                            <div class="icon"><i class="bi bi-stars"></i></div>
                            <div class="label">Superadmin</div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="#pub-avtal" class="quick-nav-card pub">
                            <div class="icon"><i class="bi bi-file-earmark-lock-fill"></i></div>
                            <div class="label">PUB-avtal</div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="#behorigheter" class="quick-nav-card permissions">
                            <div class="icon"><i class="bi bi-key-fill"></i></div>
                            <div class="label">Behörigheter</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Roller Section -->
<div class="row mb-5">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon bg-primary"><i class="bi bi-people-fill"></i></span>
            <h2>Användarroller i Stimma</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="role-card student">
                    <div class="role-icon"><i class="bi bi-person-fill"></i></div>
                    <h5>Student</h5>
                    <p>Tar kurser och följer sin progress genom utbildningen.</p>
                    <ul class="role-features">
                        <li><i class="bi bi-check2"></i> Ta kurser</li>
                        <li><i class="bi bi-check2"></i> Svara på quiz</li>
                        <li><i class="bi bi-check2"></i> Chatta med AI-tutor</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="role-card editor">
                    <div class="role-icon"><i class="bi bi-pencil-fill"></i></div>
                    <h5>Redaktör</h5>
                    <p>Skapar och redigerar kurser och lektioner.</p>
                    <ul class="role-features">
                        <li><i class="bi bi-check2"></i> Skapa kurser</li>
                        <li><i class="bi bi-check2"></i> Redigera lektioner</li>
                        <li><i class="bi bi-check2"></i> Generera AI-innehåll</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="role-card admin">
                    <div class="role-icon"><i class="bi bi-shield-fill"></i></div>
                    <h5>Admin</h5>
                    <p>Hanterar användare och organisationens inställningar.</p>
                    <ul class="role-features">
                        <li><i class="bi bi-check2"></i> Hantera användare</li>
                        <li><i class="bi bi-check2"></i> Se statistik</li>
                        <li><i class="bi bi-check2"></i> Konfigurera påminnelser</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="role-card superadmin">
                    <div class="role-icon"><i class="bi bi-stars"></i></div>
                    <h5>Superadmin</h5>
                    <p>Fullständig systemåtkomst inklusive AI-inställningar.</p>
                    <ul class="role-features">
                        <li><i class="bi bi-check2"></i> Alla admin-funktioner</li>
                        <li><i class="bi bi-check2"></i> AI-inställningar</li>
                        <li><i class="bi bi-check2"></i> Guardrails</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Kom igång Section -->
<div class="row mb-5">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon bg-success"><i class="bi bi-rocket-takeoff-fill"></i></span>
            <h2>Kom igång</h2>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-6">
                        <h4 class="mb-4"><i class="bi bi-envelope-paper me-2 text-success"></i>Logga in med e-post</h4>
                        <p class="text-muted mb-4">Stimma använder lösenordsfri inloggning via e-post. Enkelt och säkert!</p>

                        <div class="login-steps">
                            <div class="login-step">
                                <div class="step-number">1</div>
                                <div class="step-content">
                                    <strong>Ange din e-postadress</strong>
                                    <p class="mb-0 small text-muted">Gå till inloggningssidan och fyll i din e-post</p>
                                </div>
                            </div>
                            <div class="login-step">
                                <div class="step-number">2</div>
                                <div class="step-content">
                                    <strong>Klicka på "Skicka inloggningslänk"</strong>
                                    <p class="mb-0 small text-muted">Ett e-postmeddelande skickas till dig</p>
                                </div>
                            </div>
                            <div class="login-step">
                                <div class="step-number">3</div>
                                <div class="step-content">
                                    <strong>Öppna länken i mailet</strong>
                                    <p class="mb-0 small text-muted">Klicka på länken så loggas du in automatiskt</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="tip-box info">
                            <div class="tip-icon"><i class="bi bi-lightbulb-fill"></i></div>
                            <div class="tip-content">
                                <strong>Tips!</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Inloggningslänken är giltig i 15 minuter</li>
                                    <li>Länken kan endast användas en gång</li>
                                    <li>Kolla skräpposten om du inte hittar mailet</li>
                                    <li>Välj "Kom ihåg mig" för att slippa logga in varje gång</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Session och Kom ihåg mig -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h5 class="mb-3"><i class="bi bi-clock-history me-2 text-primary"></i>Hur länge är jag inloggad?</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-start mb-3">
                                            <span class="badge bg-secondary rounded-circle p-2 me-3">
                                                <i class="bi bi-hourglass-split"></i>
                                            </span>
                                            <div>
                                                <strong>Utan "Kom ihåg mig"</strong>
                                                <p class="text-muted small mb-0">Din session är aktiv i <strong>24 timmar</strong>. Efter det behöver du logga in igen med en ny e-postlänk.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-start mb-3">
                                            <span class="badge bg-success rounded-circle p-2 me-3">
                                                <i class="bi bi-check2-circle"></i>
                                            </span>
                                            <div>
                                                <strong>Med "Kom ihåg mig"</strong>
                                                <p class="text-muted small mb-0">Du förblir inloggad i <strong>7 dagar</strong>. Perfekt om du använder din egen dator eller mobil.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="alert alert-warning mb-0 mt-2">
                                    <i class="bi bi-shield-exclamation me-2"></i>
                                    <small><strong>Säkerhetstips:</strong> Använd inte "Kom ihåg mig" på delade eller offentliga datorer.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Student Guide -->
<div class="row mb-5" id="studenter">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"><i class="bi bi-person-fill"></i></span>
            <h2>Guide för studenter</h2>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-search"></i>
                    </div>
                    <h5>Hitta kurser</h5>
                    <p>Bläddra bland tillgängliga kurser på startsidan. Filtrera på svårighetsgrad eller taggar för att hitta rätt kurs.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-play-circle"></i>
                    </div>
                    <h5>Genomför lektioner</h5>
                    <p>Läs innehållet, titta på videos och svara på quiz. Lektionerna genomförs i ordning.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-robot"></i>
                    </div>
                    <h5>AI-tutor</h5>
                    <p>Ställ frågor till AI-tutorn om du behöver hjälp. Den är tränad på lektionens innehåll.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-award"></i>
                    </div>
                    <h5>Diplom</h5>
                    <p>Slutför alla lektioner och quiz i en kurs för att få ditt diplom. Ladda ner eller skriv ut det.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <h5>Följ din progress</h5>
                    <p>Se hur långt du kommit i varje kurs. Din framsteg sparas automatiskt.</p>
                </div>
            </div>
        </div>

        <div class="tip-box warning mt-4">
            <div class="tip-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="tip-content">
                <strong>Obs!</strong> Du måste slutföra lektioner i ordning. Tidigare lektioner måste vara avklarade innan du kan gå vidare till nästa.
            </div>
        </div>
    </div>
</div>

<!-- Redaktör Guide -->
<div class="row mb-5" id="redaktorer">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);"><i class="bi bi-pencil-fill"></i></span>
            <h2>Guide för redaktörer</h2>
        </div>

        <div class="accordion custom-accordion" id="editorAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#createCourse">
                        <i class="bi bi-plus-circle me-2"></i>Skapa en ny kurs
                    </button>
                </h2>
                <div id="createCourse" class="accordion-collapse collapse show" data-bs-parent="#editorAccordion">
                    <div class="accordion-body">
                        <div class="row">
                            <div class="col-lg-7">
                                <ol class="styled-list">
                                    <li>Gå till <strong>Kurser</strong> i adminmenyn</li>
                                    <li>Klicka på <strong>"Ny kurs"</strong></li>
                                    <li>Fyll i kursinformation:
                                        <ul>
                                            <li><strong>Titel</strong> - Kursens namn</li>
                                            <li><strong>Beskrivning</strong> - Vad kursen handlar om</li>
                                            <li><strong>Svårighetsgrad</strong> - Nybörjare, Medel eller Avancerad</li>
                                            <li><strong>Slutdatum</strong> - När kursen ska vara klar (valfritt)</li>
                                            <li><strong>Taggar</strong> - Välj relevanta taggar</li>
                                        </ul>
                                    </li>
                                    <li>Ladda upp en kursbild eller klicka <strong>"Generera AI-bild"</strong></li>
                                    <li>Klicka <strong>"Spara"</strong></li>
                                </ol>
                            </div>
                            <div class="col-lg-5">
                                <div class="tip-box success">
                                    <div class="tip-icon"><i class="bi bi-calendar-check"></i></div>
                                    <div class="tip-content">
                                        <strong>Slutdatum</strong>
                                        <p class="mb-0 mt-2">Om du anger ett slutdatum visas det i påminnelsemail till användare. De ser även hur många dagar som återstår.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#createLesson">
                        <i class="bi bi-journal-plus me-2"></i>Skapa lektioner
                    </button>
                </h2>
                <div id="createLesson" class="accordion-collapse collapse" data-bs-parent="#editorAccordion">
                    <div class="accordion-body">
                        <ol class="styled-list">
                            <li>Öppna kursen du vill lägga till lektioner i</li>
                            <li>Klicka på <strong>"Ny lektion"</strong></li>
                            <li>Fyll i lektionsinformation:
                                <ul>
                                    <li><strong>Titel</strong> - Lektionens namn</li>
                                    <li><strong>Innehåll</strong> - Lektionstexten (stödjer HTML med formaterade rutor)</li>
                                    <li><strong>Video-URL</strong> - Länk till YouTube-video (valfritt)</li>
                                    <li><strong>Lektionsbild</strong> - Ladda upp eller generera med AI</li>
                                    <li><strong>Quiz</strong> - Fråga med upp till 5 svarsalternativ (enkelval eller flerval)</li>
                                    <li><strong>AI-handledare</strong> - Instruktion och prompt för AI-tutorn (valfritt)</li>
                                </ul>
                            </li>
                            <li>Klicka <strong>"Spara"</strong></li>
                        </ol>
                        <div class="tip-box info mt-3">
                            <div class="tip-icon"><i class="bi bi-arrows-move"></i></div>
                            <div class="tip-content">
                                <strong>Ordna lektioner:</strong> Dra och släpp lektioner för att ändra ordningen. Ändringen sparas automatiskt.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#aiGenerateCourse">
                        <i class="bi bi-stars me-2"></i>AI-generera en hel kurs
                    </button>
                </h2>
                <div id="aiGenerateCourse" class="accordion-collapse collapse" data-bs-parent="#editorAccordion">
                    <div class="accordion-body">
                        <p>Med AI-kursgenerering kan du skapa en komplett kurs med lektioner, quiz, bilder och diplom - allt på en gång.</p>

                        <h6 class="mt-3 mb-2"><i class="bi bi-1-circle me-2 text-primary"></i>Steg 1: Grundinställningar</h6>
                        <p class="text-muted small">Klicka på <strong>"AI Generera kurs"</strong> i Kurshanteringen och fyll i:</p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <ul class="styled-list mb-0">
                                    <li><strong>Kursnamn</strong> - Vad kursen ska heta</li>
                                    <li><strong>Beskrivning</strong> - Beskriv kursen utförligt (ju mer detaljer, desto bättre resultat)</li>
                                    <li><strong>Antal lektioner</strong> - Hur många lektioner som ska skapas</li>
                                    <li><strong>Svårighetsgrad</strong> - Nybörjare, Medel eller Avancerad</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul class="styled-list mb-0">
                                    <li><strong>Textlängd</strong> - Kort (~5-8 meningar), Mellan (~12-18) eller Lång (~25-35)</li>
                                    <li><strong>Tonalitet</strong> - Pedagogisk, Formell, Avslappnad eller Inspirerande</li>
                                    <li><strong>Språkstil</strong> - Formell, Informell, Akademisk eller Vardaglig</li>
                                    <li><strong>Målgrupp</strong> - T.ex. "nyanställda inom vården"</li>
                                </ul>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="mini-card">
                                    <h6><i class="bi bi-palette me-2 text-primary"></i>Färgtema</h6>
                                    <p class="small text-muted mb-0">Välj en färg som påverkar bildernas färgpalett.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mini-card">
                                    <h6><i class="bi bi-check2-square me-2 text-success"></i>Quiz & AI-tutor</h6>
                                    <p class="small text-muted mb-0">Kryssa i för att generera quiz per lektion och/eller AI-handledare.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mini-card">
                                    <h6><i class="bi bi-images me-2 text-warning"></i>Generera bilder</h6>
                                    <p class="small text-muted mb-0">AI skapar kursbild, lektionsbilder och diplombild automatiskt.</p>
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-4 mb-2"><i class="bi bi-2-circle me-2 text-primary"></i>Steg 2: AI ställer kompletterande frågor</h6>
                        <p class="text-muted small">AI:n analyserar din beskrivning och ställer 2-4 följdfrågor för att skapa bättre innehåll. Svara på frågorna eller klicka <strong>"Hoppa över"</strong>.</p>

                        <h6 class="mt-4 mb-2"><i class="bi bi-3-circle me-2 text-primary"></i>Steg 3: Generering pågår</h6>
                        <p class="text-muted small">AI:n arbetar i bakgrunden. En progress-indikator visar hur långt det kommit. Du kan stänga dialogen - genereringen fortsätter.</p>
                        <div class="tip-box success mt-3">
                            <div class="tip-icon"><i class="bi bi-lightbulb-fill"></i></div>
                            <div class="tip-content">
                                <strong>Bra att veta</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Kursen skapas som <strong>inaktiv</strong> - granska och aktivera manuellt</li>
                                    <li>Varje lektion får rik formatering med tips-, info-, exempel- och sammanfattningsrutor</li>
                                    <li>Quiz-frågor varieras automatiskt mellan enkelval och flerval</li>
                                    <li>Bildgenerering kan ta extra tid (10-30 sek per bild)</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#aiFeatures">
                        <i class="bi bi-magic me-2"></i>AI-bilder
                    </button>
                </h2>
                <div id="aiFeatures" class="accordion-collapse collapse" data-bs-parent="#editorAccordion">
                    <div class="accordion-body">
                        <p>Du kan generera AI-bilder med DALL-E 3 på flera ställen:</p>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="mini-card">
                                    <h6><i class="bi bi-book me-2 text-primary"></i>Kursbild</h6>
                                    <p class="small text-muted mb-0">I kursredigeringen, klicka <strong>"Generera AI-bild"</strong>. Bilden blir kursens omslag.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mini-card">
                                    <h6><i class="bi bi-journal me-2 text-success"></i>Lektionsbild</h6>
                                    <p class="small text-muted mb-0">I lektionsredigeringen, klicka <strong>"Generera AI-bild"</strong> för en illustration av lektionens ämne.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mini-card">
                                    <h6><i class="bi bi-award me-2 text-warning"></i>Diplombild</h6>
                                    <p class="small text-muted mb-0">I kursredigeringen under Certifikatbild. Visas på diplomet vid kursavslut.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#importExport">
                        <i class="bi bi-arrow-left-right me-2"></i>Importera och exportera kurser
                    </button>
                </h2>
                <div id="importExport" class="accordion-collapse collapse" data-bs-parent="#editorAccordion">
                    <div class="accordion-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <h6><i class="bi bi-box-arrow-up me-2 text-primary"></i>Exportera</h6>
                                <p class="small text-muted">Klicka på export-ikonen bredvid kursen i kurslistan. En ZIP-fil laddas ner med:</p>
                                <ul class="small">
                                    <li>Kursdata i JSON-format</li>
                                    <li>Alla bilder (kurs, lektioner, diplom)</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-box-arrow-in-down me-2 text-success"></i>Importera</h6>
                                <p class="small text-muted">Klicka <strong>"Importera kurs"</strong> i Kurshanteringen och ladda upp en ZIP-fil. Kursen skapas som inaktiv med alla lektioner och bilder.</p>
                            </div>
                        </div>
                        <div class="tip-box info mt-3">
                            <div class="tip-icon"><i class="bi bi-share"></i></div>
                            <div class="tip-content">
                                <strong>Dela kurser:</strong> Exportera en kurs från en organisation och importera den i en annan. Perfekt för att dela utbildningsmaterial mellan kommuner.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Admin Guide -->
<div class="row mb-5" id="administratorer">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);"><i class="bi bi-shield-fill"></i></span>
            <h2>Guide för administratörer</h2>
        </div>

        <!-- Dashboard KPIs -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard - Nyckeltal</h5>
                <p class="text-muted mb-0">När du loggar in ser du en dashboard med viktig statistik</p>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6 col-lg-3">
                        <div class="kpi-demo">
                            <i class="bi bi-people-fill text-primary"></i>
                            <span>Användare</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="kpi-demo">
                            <i class="bi bi-journal-text text-success"></i>
                            <span>Kurser</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="kpi-demo">
                            <i class="bi bi-check-circle-fill text-info"></i>
                            <span>Slutförda lektioner</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="kpi-demo">
                            <i class="bi bi-graph-up text-warning"></i>
                            <span>Slutförandegrad</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="kpi-demo">
                            <i class="bi bi-award-fill text-success"></i>
                            <span>Genomförda kurser</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="kpi-demo">
                            <i class="bi bi-person-check-fill text-primary"></i>
                            <span>Kurser/användare</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reminder Settings -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5><i class="bi bi-bell-fill me-2 text-warning"></i>Påminnelseinställningar</h5>
                <p class="text-muted mb-0">Konfigurera automatiska påminnelser till användare som inte slutfört sina kurser</p>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-lg-6">
                        <h6 class="mb-3">Inställningar</h6>
                        <ul class="config-list">
                            <li><i class="bi bi-calendar3"></i> <strong>Dagar efter kursstart</strong> - När första påminnelsen skickas</li>
                            <li><i class="bi bi-123"></i> <strong>Max antal påminnelser</strong> - Hur många som skickas totalt</li>
                            <li><i class="bi bi-arrow-repeat"></i> <strong>Dagar mellan påminnelser</strong> - Intervall mellan mail</li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="mb-3">Tillgängliga variabler i e-postmallen</h6>
                        <div class="variable-grid">
                            <code>{{course_title}}</code>
                            <code>{{completed_lessons}}</code>
                            <code>{{total_lessons}}</code>
                            <code>{{deadline}}</code>
                            <code>{{days_remaining}}</code>
                            <code>{{deadline_info}}</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Email -->
        <div class="tip-box success mb-4">
            <div class="tip-icon"><i class="bi bi-envelope-check-fill"></i></div>
            <div class="tip-content">
                <strong>Skicka testmail</strong>
                <p class="mb-0 mt-2">Under Påminnelseinställningar kan du skicka ett testmail för att verifiera att e-postinställningarna fungerar. Testmailet visar exempelvärden för alla variabler.</p>
            </div>
        </div>

        <!-- Other Admin Features -->
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="bi bi-people"></i>
                    </div>
                    <h5>Användarhantering</h5>
                    <p>Se alla användare i din organisation. Gör användare till admin eller redaktör.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="bi bi-bar-chart"></i>
                    </div>
                    <h5>Statistik</h5>
                    <p>Detaljerad kursstatistik och progress per användare. Exportera till Excel.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <h5>Aktivitetsloggar</h5>
                    <p>Se alla händelser i systemet: inloggningar, ändringar och användaråtgärder.</p>
                </div>
            </div>
        </div>

        <!-- Stegvisa kurser -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5><i class="bi bi-skip-forward me-2 text-primary"></i>Stegvisa kurser</h5>
                <p class="text-muted">Kurser kan konfigureras med stegvis leverans, där studenter får tillgång till en lektion i taget istället för alla på en gång.</p>

                <div class="row">
                    <div class="col-lg-6">
                        <h6 class="mb-3">Inställningar (under Redigera kurs)</h6>
                        <ul class="config-list">
                            <li><i class="bi bi-toggle-on"></i> <strong>Stegvis leverans</strong> – aktivera via checkboxen i kursredigeraren</li>
                            <li><i class="bi bi-calendar3"></i> <strong>Intervall</strong> – antal dagar mellan varje lektion (t.ex. 3 dagar)</li>
                            <li><i class="bi bi-bell"></i> <strong>Automatiska påminnelser</strong> – e-post skickas automatiskt när en ny lektion blir tillgänglig</li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="mb-3">Studentens upplevelse</h6>
                        <ul class="config-list">
                            <li><i class="bi bi-lock"></i> Låsikon visas på lektioner som ännu inte är tillgängliga</li>
                            <li><i class="bi bi-clock"></i> Klockikon och datum visas för nästa planerade lektion</li>
                            <li><i class="bi bi-envelope"></i> E-post skickas automatiskt när en ny lektion låses upp</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kursstatistik -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5><i class="bi bi-graph-up me-2 text-success"></i>Kursstatistik</h5>
                <p class="text-muted">Under menyn <strong>Kursstatistik</strong> får du en detaljerad översikt över hur studenter genomför varje kurs.</p>

                <div class="row">
                    <div class="col-lg-6">
                        <h6 class="mb-3">Funktioner</h6>
                        <ul class="config-list">
                            <li><i class="bi bi-bar-chart-line"></i> <strong>Kursöversikt</strong> – se andel påbörjade och slutförda per kurs</li>
                            <li><i class="bi bi-diagram-3"></i> <strong>Organisationstaggar</strong> – drill-down per org-tagg (avdelning/enhet)</li>
                            <li><i class="bi bi-person"></i> <strong>Användarnivå</strong> – se individuell progress för varje student</li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="mb-3">Manuell påminnelse</h6>
                        <ul class="config-list">
                            <li><i class="bi bi-send"></i> Skicka manuell påminnelse till enskild användare direkt från statistikvyn</li>
                            <li><i class="bi bi-chat-text"></i> Lägg till ett valfritt personligt meddelande</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Superadmin Guide -->
<div class="row mb-5" id="superadmin">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);"><i class="bi bi-stars"></i></span>
            <h2>Guide för superadministratörer</h2>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-6">
                        <h5><i class="bi bi-cpu me-2 text-danger"></i>AI-inställningar - Guardrails</h5>
                        <p class="text-muted">Konfigurera hur AI-tutorn beter sig i hela systemet.</p>
                        <ul class="config-list">
                            <li><i class="bi bi-shield-check"></i> <strong>Guardrails</strong> - Säkerhetsbegränsningar för AI-svar</li>
                            <li><i class="bi bi-chat-text"></i> <strong>Systemprompt</strong> - Text som läggs till före AI-förfrågningar</li>
                            <li><i class="bi bi-x-octagon"></i> <strong>Blockerade ämnen</strong> - Ämnen AI:n inte får diskutera</li>
                            <li><i class="bi bi-list-check"></i> <strong>Svarsriktlinjer</strong> - Regler för hur AI:n ska svara</li>
                            <li><i class="bi bi-gear"></i> <strong>Anpassade instruktioner</strong> - Organisationsspecifika regler</li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <div class="tip-box warning">
                            <div class="tip-icon"><i class="bi bi-shield-exclamation"></i></div>
                            <div class="tip-content">
                                <strong>Bästa praxis</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Aktivera guardrails i produktionsmiljö</li>
                                    <li>Definiera tydliga blockerade ämnen</li>
                                    <li>Testa AI-svar regelbundet</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row align-items-start">
                    <div class="col-lg-6">
                        <h5><i class="bi bi-sliders me-2 text-primary"></i>AI-inställningar - Kursgenerering</h5>
                        <p class="text-muted">Styr hur AI-genererade kurser skapas.</p>
                        <ul class="config-list">
                            <li><i class="bi bi-123"></i> <strong>Max antal lektioner</strong> - Begränsa hur många lektioner som kan genereras per kurs (1-100)</li>
                            <li><i class="bi bi-file-text"></i> <strong>Genereringsprompt</strong> - Anpassa AI-prompten som styr hur kursinnehåll genereras</li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <div class="tip-box info">
                            <div class="tip-icon"><i class="bi bi-lightbulb-fill"></i></div>
                            <div class="tip-content">
                                <strong>Prompten styr kvaliteten</strong>
                                <p class="mb-0 mt-1">Genereringsprompten bestämmer hur AI:n strukturerar kurser. Anpassa den för att matcha er organisations pedagogiska stil. Klicka "Återställ standard" för att gå tillbaka till grundprompten.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Superadmin: Domain & PUB management -->
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <i class="bi bi-globe"></i>
                    </div>
                    <h5>Domänhantering</h5>
                    <p>Hantera organisationer/domäner, se PUB-avtalsstatus och klicka för avtalsdetaljer.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <i class="bi bi-file-earmark-lock"></i>
                    </div>
                    <h5>PUB-avtal</h5>
                    <p>Se alla digitalt tecknade PUB-avtal. Klicka för fullständiga detaljer: undertecknare, organisation, datum, IP och hash.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <h5>Alla användare</h5>
                    <p>Se och hantera användare från alla organisationer. Filtrera per domän, tilldela roller.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PUB-avtal Section -->
<div class="row mb-5" id="pub-avtal">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #667eea 0%, #00b09b 100%);"><i class="bi bi-file-earmark-lock-fill"></i></span>
            <h2>PUB-avtal (Personuppgiftsbiträdesavtal)</h2>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row align-items-start">
                    <div class="col-lg-7">
                        <h5 class="mb-3"><i class="bi bi-shield-lock me-2 text-success"></i>Vad är ett PUB-avtal?</h5>
                        <p class="text-muted">Ett PUB-avtal (Personuppgiftsbiträdesavtal) reglerar hur personuppgifter hanteras mellan din organisation och Sambruk. Enligt GDPR krävs detta avtal innan personuppgifter behandlas i systemet.</p>

                        <div class="tip-box warning mt-3">
                            <div class="tip-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                            <div class="tip-content">
                                <strong>Viktigt!</strong>
                                <p class="mb-0 mt-1">Organisationer utan tecknat PUB-avtal ser en varningsruta i systemet. Kontakta din organisations administratör för att teckna avtalet.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="mini-card">
                            <h6><i class="bi bi-check-circle me-2 text-success"></i>PUB-status visas i menyn</h6>
                            <p class="small text-muted mb-2">Admin och redaktörer ser PUB-avtalets status som en badge i sidhuvudet:</p>
                            <div class="d-flex gap-2 flex-wrap">
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>PUB tecknat</span>
                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>PUB saknas</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5><i class="bi bi-pencil-square me-2 text-primary"></i>Teckna PUB-avtal - steg för steg</h5>
                <p class="text-muted mb-0">Behörig person i organisationen tecknar avtalet digitalt</p>
            </div>
            <div class="card-body p-4">
                <div class="login-steps">
                    <div class="login-step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <strong>Granska avtalet</strong>
                            <p class="mb-0 small text-muted">Läs igenom PUB-avtalets PDF-dokument som visas på sidan. Avtalet måste först vara kontrasignerat av Sambruk.</p>
                        </div>
                    </div>
                    <div class="login-step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <strong>Fyll i uppgifter och verifiera med SMS</strong>
                            <p class="mb-0 small text-muted">Ange namn, titel och e-post. Verifiera din identitet med en 6-siffrig SMS-kod. Intyga att du har behörighet att teckna avtal för organisationen.</p>
                        </div>
                    </div>
                    <div class="login-step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <strong>Ange organisationsuppgifter och signera</strong>
                            <p class="mb-0 small text-muted">Fyll i organisationsnamn och organisationsnummer. Klicka "Signera avtal". Ett stämplat PDF-avtal skapas och skickas per e-post.</p>
                        </div>
                    </div>
                </div>

                <div class="tip-box success mt-4">
                    <div class="tip-icon"><i class="bi bi-envelope-check-fill"></i></div>
                    <div class="tip-content">
                        <strong>Efter signering</strong>
                        <p class="mb-0 mt-1">Det stämplade avtalet skickas automatiskt till undertecknarens e-post, organisationens registrator och till Sambruk. Avtalet loggas med tidsstämpel, IP-adress och PDF-hash för spårbarhet.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Behörigheter Section -->
<div class="row mb-5" id="behorigheter">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);"><i class="bi bi-key-fill"></i></span>
            <h2>Utökade behörigheter</h2>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-7">
                        <h5><i class="bi bi-person-up me-2 text-success"></i>Bli Redaktör eller Admin</h5>
                        <p class="text-muted mb-4">
                            Som vanlig användare kan du ta kurser och följa din progress. Om du behöver skapa kurser
                            eller hantera användare i din organisation behöver du utökade behörigheter.
                        </p>

                        <div class="tip-box info">
                            <div class="tip-icon"><i class="bi bi-envelope-fill"></i></div>
                            <div class="tip-content">
                                <strong>Begär utökad behörighet</strong>
                                <p class="mb-2 mt-2">
                                    Om du önskar få behörighet som <strong>Redaktör</strong> eller <strong>Admin</strong>
                                    för den organisation du tillhör, skicka en förfrågan till:
                                </p>
                                <a href="mailto:hjalp@sambruksupport.se" class="btn btn-primary btn-sm">
                                    <i class="bi bi-envelope me-2"></i>hjalp@sambruksupport.se
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="permission-comparison">
                            <h6 class="mb-3"><i class="bi bi-list-check me-2"></i>Vad får du tillgång till?</h6>
                            <div class="permission-item">
                                <span class="badge bg-warning text-dark me-2">Redaktör</span>
                                <small class="text-muted">Skapa och redigera kurser, generera AI-innehåll</small>
                            </div>
                            <div class="permission-item mt-2">
                                <span class="badge bg-info me-2">Admin</span>
                                <small class="text-muted">Hantera användare, se statistik, konfigurera påminnelser</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- REST API & Användarsynk -->
<div class="row mb-5" id="api">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"><i class="bi bi-cloud-arrow-up"></i></span>
            <h2>REST API &amp; Användarsynk</h2>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5><i class="bi bi-key me-2 text-warning"></i>API-nycklar</h5>
                <p class="text-muted">API-nycklar genereras under <strong>Admin → API-nycklar</strong>. Varje nyckel är knuten till en domän och börjar med <code>stm_</code>.</p>
                <ul class="config-list">
                    <li><i class="bi bi-plus-circle"></i> <strong>Skapa nyckel</strong> – välj domän, nyckeln visas en gång vid skapande</li>
                    <li><i class="bi bi-toggle-on"></i> <strong>Aktivera synk</strong> – slå på "Synkronisering" för domänen för att tillåta API-anrop</li>
                    <li><i class="bi bi-journal-text"></i> <strong>Synkloggar</strong> – se alla API-anrop under Admin → Synkloggar</li>
                </ul>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5><i class="bi bi-people me-2 text-primary"></i>Användarsynk-endpoint</h5>
                <p class="text-muted"><code>POST /api/sync_users.php</code> – synkronisera en komplett användarlista per domän.</p>

                <div class="row">
                    <div class="col-lg-6">
                        <h6 class="mb-3">Request</h6>
<pre class="bg-dark text-light p-3 rounded" style="font-size: 0.85rem;"><code>POST /api/sync_users.php
Authorization: Bearer stm_din_nyckel_här
Content-Type: application/json

{
  "users": [
    {
      "email": "anna@example.se",
      "name": "Anna Svensson",
      "role": "student",
      "organization": "Avd1/Enhet2"
    }
  ],
  "deactivate_missing": true
}</code></pre>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="mb-3">Response</h6>
<pre class="bg-dark text-light p-3 rounded" style="font-size: 0.85rem;"><code>{
  "success": true,
  "summary": {
    "total_in_payload": 1,
    "created": 1,
    "updated": 0,
    "deactivated": 0,
    "reactivated": 0
  },
  "sync_id": 42
}</code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5><i class="bi bi-check-circle me-2 text-success"></i>Kursstatus-endpoint</h5>
                <p class="text-muted"><code>GET /api/course_status.php?email=...&amp;course_id=...</code> – kontrollera om en användare har slutfört en kurs.</p>

                <div class="tip-box info mb-3">
                    <div class="tip-icon"><i class="bi bi-info-circle-fill"></i></div>
                    <div class="tip-content">
                        <strong>Var hittar jag kurs-ID?</strong>
                        <p class="mb-0 mt-1">Kurs-ID visas som en kolumn i kurslistan under Admin → Kurser, samt som en badge vid redigering av en kurs.</p>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <h6 class="mb-3">Request</h6>
<pre class="bg-dark text-light p-3 rounded" style="font-size: 0.85rem;"><code>GET /api/course_status.php?email=anna@example.se&amp;course_id=25
Authorization: Bearer stm_din_nyckel_här</code></pre>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="mb-3">Response (slutförd)</h6>
<pre class="bg-dark text-light p-3 rounded" style="font-size: 0.85rem;"><code>{
  "success": true,
  "status": 1,
  "email": "anna@example.se",
  "course_id": 25,
  "course_title": "Grundkurs GDPR",
  "completed_at": "2026-03-04"
}</code></pre>
                        <h6 class="mb-3 mt-3">Response (pågående)</h6>
<pre class="bg-dark text-light p-3 rounded" style="font-size: 0.85rem;"><code>{
  "success": true,
  "status": 0,
  "email": "anna@example.se",
  "course_id": 25,
  "course_title": "Grundkurs GDPR",
  "progress": "3/5"
}</code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="tip-box warning mb-4">
            <div class="tip-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="tip-content">
                <strong>Begränsningar</strong>
                <ul class="mb-0 mt-2">
                    <li>Max 10 anrop per timme per API-nyckel</li>
                    <li>E-postadressens domän måste matcha API-nyckelns domän</li>
                    <li>Synkronisering måste vara aktiverad för domänen</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Troubleshooting -->
<div class="row mb-5">
    <div class="col-12">
        <div class="section-header">
            <span class="section-icon bg-danger"><i class="bi bi-wrench-adjustable"></i></span>
            <h2>Felsökning</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="troubleshoot-card">
                    <h6><i class="bi bi-link-45deg text-danger me-2"></i>Inloggningslänken fungerar inte</h6>
                    <ul class="mb-0">
                        <li>Länken är giltig i max 15 minuter</li>
                        <li>Länken kan endast användas en gång</li>
                        <li>Begär en ny länk</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="troubleshoot-card">
                    <h6><i class="bi bi-eye-slash text-danger me-2"></i>Kan inte se kurser</h6>
                    <ul class="mb-0">
                        <li>Kontrollera att kursen är aktiverad</li>
                        <li>Kursen kanske tillhör en annan organisation</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="troubleshoot-card">
                    <h6><i class="bi bi-image text-danger me-2"></i>AI-bildgenerering misslyckas</h6>
                    <ul class="mb-0">
                        <li>Kontrollera att OpenAI API-nyckeln är konfigurerad</li>
                        <li>Försök igen vid tillfälligt serverfel</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="troubleshoot-card">
                    <h6><i class="bi bi-question-circle text-danger me-2"></i>Quiz sparas inte</h6>
                    <ul class="mb-0">
                        <li>Fyll i alla obligatoriska fält (fråga, svar, rätt svar)</li>
                        <li>Enkelval: ange korrekt svar (1-5)</li>
                        <li>Flerval: ange korrekta svar kommaseparerat (t.ex. 1,3)</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="troubleshoot-card">
                    <h6><i class="bi bi-stars text-danger me-2"></i>AI-kursgenerering misslyckas</h6>
                    <ul class="mb-0">
                        <li>Kontrollera att API-nyckeln är konfigurerad</li>
                        <li>Ge en tydlig och detaljerad kursbeskrivning</li>
                        <li>Försök med färre lektioner om det tar för lång tid</li>
                        <li>Bildgenerering kan misslyckas separat - kursen skapas ändå</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="troubleshoot-card">
                    <h6><i class="bi bi-file-earmark-lock text-danger me-2"></i>PUB-avtal kan inte signeras</h6>
                    <ul class="mb-0">
                        <li>Avtalet måste först vara kontrasignerat av Sambruk</li>
                        <li>SMS-verifiering krävs - kontrollera telefonnumret</li>
                        <li>Verifieringskoden är giltig i 10 minuter</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="text-center py-4 text-muted">
    <p class="mb-0"><em><i class="bi bi-mortarboard me-2"></i>Stimma - Lär dig i små steg</em></p>
</div>

<style>
/* Hero Section */
.guide-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 3rem;
    position: relative;
    overflow: hidden;
}
.guide-hero::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.hero-illustration {
    font-size: 8rem;
    color: rgba(255,255,255,0.2);
}

/* Quick Nav Cards */
.quick-nav-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1.5rem 1rem;
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}
.quick-nav-card:hover {
    transform: translateY(-4px);
}
.quick-nav-card .icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}
.quick-nav-card .label {
    font-weight: 600;
    font-size: 0.9rem;
}
.quick-nav-card.student { background: linear-gradient(135deg, rgba(102,126,234,0.1) 0%, rgba(118,75,162,0.1) 100%); color: #667eea; }
.quick-nav-card.student:hover { border-color: #667eea; }
.quick-nav-card.editor { background: linear-gradient(135deg, rgba(240,147,251,0.1) 0%, rgba(245,87,108,0.1) 100%); color: #f093fb; }
.quick-nav-card.editor:hover { border-color: #f093fb; }
.quick-nav-card.admin { background: linear-gradient(135deg, rgba(79,172,254,0.1) 0%, rgba(0,242,254,0.1) 100%); color: #4facfe; }
.quick-nav-card.admin:hover { border-color: #4facfe; }
.quick-nav-card.superadmin { background: linear-gradient(135deg, rgba(250,112,154,0.1) 0%, rgba(254,225,64,0.1) 100%); color: #fa709a; }
.quick-nav-card.superadmin:hover { border-color: #fa709a; }
.quick-nav-card.pub { background: linear-gradient(135deg, rgba(102,126,234,0.1) 0%, rgba(0,176,155,0.1) 100%); color: #00b09b; }
.quick-nav-card.pub:hover { border-color: #00b09b; }
.quick-nav-card.permissions { background: linear-gradient(135deg, rgba(17,153,142,0.1) 0%, rgba(56,239,125,0.1) 100%); color: #11998e; }
.quick-nav-card.permissions:hover { border-color: #11998e; }

/* Section Header */
.section-header {
    display: flex;
    align-items: center;
    margin-bottom: 1.5rem;
}
.section-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.3rem;
    margin-right: 1rem;
}
.section-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
}

/* Role Cards */
.role-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    height: 100%;
    border: 1px solid #eee;
    transition: all 0.3s ease;
}
.role-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
}
.role-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 1rem;
}
.role-card.student .role-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
.role-card.editor .role-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
.role-card.admin .role-icon { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
.role-card.superadmin .role-icon { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; }
.role-card h5 { font-weight: 700; margin-bottom: 0.5rem; }
.role-card p { color: #6c757d; font-size: 0.9rem; }
.role-features { list-style: none; padding: 0; margin: 0; }
.role-features li { padding: 0.25rem 0; font-size: 0.85rem; color: #495057; }
.role-features i { color: #28a745; margin-right: 0.5rem; }

/* Feature Cards */
.feature-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    height: 100%;
    border: 1px solid #eee;
    text-align: center;
}
.feature-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    margin: 0 auto 1rem;
}
.feature-card h5 { font-weight: 700; }
.feature-card p { color: #6c757d; font-size: 0.9rem; margin: 0; }

/* Login Steps */
.login-steps { display: flex; flex-direction: column; gap: 1rem; }
.login-step {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}
.step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}

/* Tip Boxes */
.tip-box {
    display: flex;
    gap: 1rem;
    padding: 1.25rem;
    border-radius: 12px;
    align-items: flex-start;
}
.tip-box.info { background: #e8f4fd; }
.tip-box.warning { background: #fff8e6; }
.tip-box.success { background: #e8f8ef; }
.tip-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.tip-box.info .tip-icon { background: #3498db; color: white; }
.tip-box.warning .tip-icon { background: #f39c12; color: white; }
.tip-box.success .tip-icon { background: #27ae60; color: white; }
.tip-content { flex: 1; }
.tip-content ul { padding-left: 1.25rem; margin-bottom: 0; }

/* Custom Accordion */
.custom-accordion .accordion-item {
    border: 1px solid #eee;
    border-radius: 12px !important;
    margin-bottom: 0.75rem;
    overflow: hidden;
}
.custom-accordion .accordion-button {
    font-weight: 600;
    padding: 1rem 1.25rem;
}
.custom-accordion .accordion-button:not(.collapsed) {
    background: linear-gradient(135deg, rgba(240,147,251,0.1) 0%, rgba(245,87,108,0.1) 100%);
    color: #f5576c;
}
.custom-accordion .accordion-body {
    padding: 1.25rem;
}

/* Styled List */
.styled-list {
    padding-left: 1.5rem;
}
.styled-list li {
    margin-bottom: 0.75rem;
    line-height: 1.6;
}
.styled-list ul {
    margin-top: 0.5rem;
    margin-bottom: 0;
}

/* Mini Card */
.mini-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 1rem;
    height: 100%;
}
.mini-card h6 { margin-bottom: 0.5rem; }

/* KPI Demo */
.kpi-demo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: #f8f9fa;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 500;
}
.kpi-demo i { font-size: 1.25rem; }

/* Config List */
.config-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.config-list li {
    padding: 0.5rem 0;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.config-list i {
    color: #6c757d;
    margin-top: 0.2rem;
}

/* Variable Grid */
.variable-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.variable-grid code {
    background: #e9ecef;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    font-size: 0.8rem;
}

/* Troubleshoot Card */
.troubleshoot-card {
    background: #fff5f5;
    border-radius: 12px;
    padding: 1.25rem;
    height: 100%;
    border-left: 4px solid #dc3545;
}
.troubleshoot-card h6 { margin-bottom: 0.75rem; }
.troubleshoot-card ul {
    padding-left: 1.25rem;
    margin: 0;
    color: #6c757d;
}
.troubleshoot-card li { margin-bottom: 0.25rem; }

/* Permission Comparison */
.permission-comparison {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 1.25rem;
}
.permission-item {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}
</style>

<?php require_once 'include/footer.php'; ?>
