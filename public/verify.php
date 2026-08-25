<?php
/**
 * Stimma - Learn in small steps
 * Copyright (C) 2025 Christian Alfredsson
 * 
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 * 
 * The name "Stimma" is a trademark and subject to restrictions.
 */

/**
 * Email verification and login handler
 * 
 * This file handles:
 * - Email verification process
 * - Login token validation
 * - User session creation
 * - Error handling and user feedback
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required configuration and function files
require_once 'include/config.php';
require_once 'include/database.php';
require_once 'include/functions.php';
require_once 'include/auth.php';

// Get system name from environment variable or use default
$systemName = trim(getenv('SYSTEM_NAME'), '"\'') ?: 'Stimma';

// Initialize error and success message variables
$error = '';
$success = '';

// If already logged in, redirect to saved URL or home page
if (isLoggedIn()) {
    $_SESSION['flash_message'] = 'Du är redan inloggad.';
    $_SESSION['flash_type'] = 'info';
    $redirectUrl = 'index.php';
    if (!empty($_SESSION['redirect_after_login'])) {
        $candidate = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
        $parsed = parse_url($candidate);
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($parsed['host']) || $parsed['host'] === $currentHost) {
            $redirectUrl = $candidate;
        }
    }
    redirect($redirectUrl);
    exit;
}

// Check if token and email parameters exist
if (!isset($_GET['token']) || !isset($_GET['email'])) {
    $error = 'Inloggningslänken är ofullständig. Vänligen använd hela länken från e-postmeddelandet.';
} else {
    $token = $_GET['token'];
    $email = $_GET['email'];

    // SECURITY FIX: Use verifyLoginToken which now handles:
    // - Token format validation (64 hex chars)
    // - Token expiry check
    // - Single-use token invalidation
    $user = verifyLoginToken($email, $token);

    if (!$user) {
        // verifyLoginToken returns false for: invalid format, expired, wrong token, or non-existent user
        $error = 'Inloggningslänken är ogiltig eller har utgått. Vänligen begär en ny inloggningslänk.';
    } else {
        // Create login session (includes session_regenerate_id)
        createLoginSession($user);

        // Skapa "kom ihåg mig" token om användaren valde det
        if (isset($_SESSION['pending_remember_me']) && $_SESSION['pending_remember_me']) {
            createRememberToken($user['id']); // Använder REMEMBER_TOKEN_HOURS från .env (standard 720 h = 30 dagar)
        }
        // Rensa pending_remember_me från sessionen
        unset($_SESSION['pending_remember_me']);

        // Publik kursregistrering: slå upp intent per verifieringstoken (inte
        // per session — funkar cross-device). Om träff, grant access och
        // omdirigera till första lektionen (stegvis) eller /index.php.
        $pendingIntent = queryOne(
            "SELECT * FROM " . DB_DATABASE . ".public_registration_intents
             WHERE verification_token = ? AND expires_at > NOW() LIMIT 1",
            [$token]
        );
        $publicRedirectLesson = null;
        if ($pendingIntent) {
            grantPublicCourseAccess($user['id'], $pendingIntent['course_id']);
            // Hitta första lektionen för direkt-redirect om stegvis
            $course = queryOne("SELECT sequential_mode FROM " . DB_DATABASE . ".courses WHERE id = ?", [$pendingIntent['course_id']]);
            if ($course && !empty($course['sequential_mode'])) {
                $firstLesson = queryOne(
                    "SELECT id FROM " . DB_DATABASE . ".lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1",
                    [$pendingIntent['course_id']]
                );
                if ($firstLesson) {
                    $publicRedirectLesson = (int)$firstLesson['id'];
                }
            }
            // Konsumera intent-raden så länken inte kan återanvändas
            execute("DELETE FROM " . DB_DATABASE . ".public_registration_intents WHERE verification_token = ?", [$token]);
        }

        // Omdirigera till sparad URL eller publik kurs-destination eller startsidan
        $redirectUrl = 'index.php';
        if ($publicRedirectLesson) {
            $redirectUrl = 'lesson.php?id=' . $publicRedirectLesson;
        } elseif (!empty($_SESSION['redirect_after_login'])) {
            // Same-origin-check: endast relativa URL:er eller samma host
            $candidate = $_SESSION['redirect_after_login'];
            unset($_SESSION['redirect_after_login']);
            $parsed = parse_url($candidate);
            $currentHost = $_SERVER['HTTP_HOST'] ?? '';
            if (empty($parsed['host']) || $parsed['host'] === $currentHost) {
                $redirectUrl = $candidate;
            }
        }
        redirect($redirectUrl);
        exit;
    }
}

// If we have an error message, display it
if (!empty($error)) {
    $page_title = $systemName . ' - Verifiera e-post';
    require_once 'include/header.php';
    ?>
    <!-- Error display container -->
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center p-4">
                        <!-- Error icon -->
                        <div class="mb-4">
                            <i class="bi bi-exclamation-circle text-danger" style="font-size: 3rem;"></i>
                        </div>
                        <h1 class="h4 mb-3">Verifiera inloggning</h1>
                        
                        <!-- Error message display -->
                        <div class="alert alert-danger d-flex align-items-center" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <div><?= $error ?></div>
                        </div>
                        
                        <!-- Retry button -->
                        <div class="d-grid gap-2">
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-arrow-repeat me-2"></i> Försök igen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once 'include/footer.php';
} else {
    // If no error, show loading page
    $page_title = 'Bearbetar inloggning - ' . $systemName;
    require_once 'include/header.php';
    ?>
    <!-- Loading container -->
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center p-4">
                        <!-- Success icon -->
                        <div class="mb-4">
                            <i class="bi bi-mortarboard-fill text-primary" style="font-size: 3rem;"></i>
                        </div>
                        <h1 class="h4 mb-3">Bearbetar inloggning</h1>
                        <p class="text-muted mb-4">Vänta medan vi bearbetar din inloggningsbegäran...</p>
                        
                        <!-- Loading spinner -->
                        <div class="d-flex justify-content-center mb-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Laddar...</span>
                            </div>
                        </div>
                        
                        <p class="text-muted small">Du kommer att omdirigeras automatiskt</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once 'include/footer.php';
}
