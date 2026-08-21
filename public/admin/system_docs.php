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

// Endast administratörer kan se systemdokumentation
if (!$isAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att se systemdokumentationen. Endast administratörer har tillgång till den här funktionen.';
    $_SESSION['message_type'] = 'warning';
    redirect('user_guide.php');
    exit;
}

// Sätt sidtitel
$page_title = 'Systemdokumentation';

// Läs markdown-filen
$markdownFile = __DIR__ . '/../docs/SYSTEM_DOCUMENTATION.md';
$markdownContent = file_exists($markdownFile) ? file_get_contents($markdownFile) : 'Systemdokumentationen kunde inte hittas.';

// Funktion för att skapa slug från rubriktext (matchar GitHub/markdown-style)
function createSlug($text) {
    $text = mb_strtolower($text, 'UTF-8');
    // Ersätt mellanslag med bindestreck
    $text = preg_replace('/\s+/', '-', trim($text));
    // Ta bort allt utom bokstäver (inkl svenska), siffror och bindestreck
    $text = preg_replace('/[^\p{L}\p{N}-]/u', '', $text);
    // Normalisera multipla bindestreck till ett
    $text = preg_replace('/-+/', '-', $text);
    return $text;
}

// Enkel markdown till HTML-konvertering
function convertMarkdownToHtml($markdown) {
    // Escapa HTML först
    $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

    // Kod-block (måste göras först)
    $html = preg_replace_callback('/```(\w+)?\n(.*?)```/s', function($matches) {
        $lang = $matches[1] ?? '';
        $code = trim($matches[2]);
        return '<pre class="bg-dark text-light p-3 rounded mb-3"><code class="language-' . $lang . '">' . $code . '</code></pre>';
    }, $html);

    // Rubriker med id för ankarlänkar
    $html = preg_replace_callback('/^#### (.+)$/m', function($m) {
        $id = createSlug($m[1]);
        return '<h6 class="mt-4 mb-2" id="' . $id . '">' . $m[1] . '</h6>';
    }, $html);
    $html = preg_replace_callback('/^### (.+)$/m', function($m) {
        $id = createSlug($m[1]);
        return '<h5 class="mt-4 mb-3" id="' . $id . '">' . $m[1] . '</h5>';
    }, $html);
    $html = preg_replace_callback('/^## (.+)$/m', function($m) {
        $id = createSlug($m[1]);
        return '<h4 class="mt-5 mb-3 text-primary" id="' . $id . '">' . $m[1] . '</h4>';
    }, $html);
    $html = preg_replace_callback('/^# (.+)$/m', function($m) {
        $id = createSlug($m[1]);
        return '<h3 class="mb-4" id="' . $id . '">' . $m[1] . '</h3>';
    }, $html);

    // Horisontell linje
    $html = preg_replace('/^---$/m', '<hr class="my-4">', $html);

    // Fetstil
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

    // Kursiv
    $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

    // Inline kod
    $html = preg_replace('/`([^`]+)`/', '<code class="bg-light px-1 rounded text-danger">$1</code>', $html);

    // Tabeller
    $html = preg_replace_callback('/^\|(.+)\|$/m', function($matches) {
        $cells = explode('|', trim($matches[1]));
        $row = '<tr>';
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if (preg_match('/^[-:]+$/', $cell)) {
                return ''; // Hoppa över separator-rader
            }
            $row .= '<td class="border px-3 py-2">' . $cell . '</td>';
        }
        $row .= '</tr>';
        return $row;
    }, $html);

    // Wrap table rows in table
    $html = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<div class="table-responsive"><table class="table table-bordered table-sm mb-4">$0</table></div>', $html);

    // Listor (oordnade)
    $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>\s*)+/s', '<ul class="mb-3">$0</ul>', $html);

    // Numrerade listor
    $html = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $html);

    // Länkar med ankare
    $html = preg_replace('/\[([^\]]+)\]\(#([^)]+)\)/', '<a href="#$2" class="text-decoration-none">$1</a>', $html);

    // Vanliga länkar
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="text-decoration-none" target="_blank">$1</a>', $html);

    // Stycken (dubbla radbrytningar)
    $html = preg_replace('/\n\n+/', '</p><p class="mb-3">', $html);

    // Enskilda radbrytningar
    $html = str_replace("\n", ' ', $html);

    // Wrap i p-tag
    $html = '<p class="mb-3">' . $html . '</p>';

    // Städa upp tomma p-taggar
    $html = preg_replace('/<p class="mb-3">\s*<\/p>/', '', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<h[3-6])/i', '$1', $html);
    $html = preg_replace('/(<\/h[3-6]>)\s*<\/p>/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<hr)/i', '$1', $html);
    $html = preg_replace('/(<\/ul>)\s*<\/p>/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<ul)/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<div class="table-responsive">)/i', '$1', $html);
    $html = preg_replace('/(<\/div>)\s*<\/p>/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<pre)/i', '$1', $html);
    $html = preg_replace('/(<\/pre>)\s*<\/p>/i', '$1', $html);

    return $html;
}

$htmlContent = convertMarkdownToHtml($markdownContent);

// Inkludera header
require_once 'include/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-gear me-2"></i>Systemdokumentation
                    </h6>
                    <div>
                        <a href="user_guide.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-book me-1"></i>Användarhandbok
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Teknisk dokumentation</strong> - Denna sida innehåller teknisk information om systemet och är avsedd för administratörer och utvecklare.
                    </div>
                    <div class="system-docs-content">
                        <?= $htmlContent ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.system-docs-content h3 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}
.system-docs-content h4 {
    color: #3498db;
}
.system-docs-content h5 {
    color: #5a6268;
}
.system-docs-content h6 {
    color: #6c757d;
}
.system-docs-content ul {
    padding-left: 20px;
}
.system-docs-content li {
    margin-bottom: 5px;
}
.system-docs-content pre {
    max-height: 400px;
    overflow-y: auto;
}
.system-docs-content code {
    font-size: 0.85em;
}
.system-docs-content pre code {
    color: #98c379;
}
.system-docs-content table {
    font-size: 0.9em;
}
</style>

<?php require_once 'include/footer.php'; ?>
