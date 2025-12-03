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

// Sätt sidtitel
$page_title = 'Användarhandbok';

// Läs markdown-filen
$markdownFile = __DIR__ . '/../docs/USER_GUIDE.md';
$markdownContent = file_exists($markdownFile) ? file_get_contents($markdownFile) : 'Handboken kunde inte hittas.';

// Enkel markdown till HTML-konvertering
function convertMarkdownToHtml($markdown) {
    // Escapa HTML först
    $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

    // Rubriker (måste göras före andra regler)
    $html = preg_replace('/^### (.+)$/m', '<h5 class="mt-4 mb-3">$1</h5>', $html);
    $html = preg_replace('/^## (.+)$/m', '<h4 class="mt-5 mb-3 text-primary">$1</h4>', $html);
    $html = preg_replace('/^# (.+)$/m', '<h3 class="mb-4">$1</h3>', $html);

    // Horisontell linje
    $html = preg_replace('/^---$/m', '<hr class="my-4">', $html);

    // Fetstil
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

    // Kursiv
    $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

    // Inline kod
    $html = preg_replace('/`([^`]+)`/', '<code class="bg-light px-1 rounded">$1</code>', $html);

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
    $html = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<table class="table table-bordered mb-4">$0</table>', $html);

    // Listor (oordnade)
    $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>\s*)+/s', '<ul class="mb-3">$0</ul>', $html);

    // Numrerade listor
    $html = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $html);

    // Länkar med ankare
    $html = preg_replace('/\[([^\]]+)\]\(#([^)]+)\)/', '<a href="#$2" class="text-decoration-none">$1</a>', $html);

    // Vanliga länkar
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="text-decoration-none" target="_blank">$1</a>', $html);

    // Tips och Obs (special formatting)
    $html = preg_replace('/\*\*Tips:\*\*/', '<span class="badge bg-info me-2">Tips</span>', $html);
    $html = preg_replace('/\*\*Obs:\*\*/', '<span class="badge bg-warning me-2">Obs</span>', $html);

    // Stycken (dubbla radbrytningar)
    $html = preg_replace('/\n\n+/', '</p><p class="mb-3">', $html);

    // Enskilda radbrytningar i listor ska behållas
    $html = str_replace("\n", ' ', $html);

    // Wrap i p-tag
    $html = '<p class="mb-3">' . $html . '</p>';

    // Städa upp tomma p-taggar
    $html = preg_replace('/<p class="mb-3">\s*<\/p>/', '', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<h[3-5])/i', '$1', $html);
    $html = preg_replace('/(<\/h[3-5]>)\s*<\/p>/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<hr)/i', '$1', $html);
    $html = preg_replace('/(<\/ul>)\s*<\/p>/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<ul)/i', '$1', $html);
    $html = preg_replace('/<p class="mb-3">\s*(<table)/i', '$1', $html);
    $html = preg_replace('/(<\/table>)\s*<\/p>/i', '$1', $html);

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
                        <i class="bi bi-book me-2"></i>Användarhandbok
                    </h6>
                    <div>
                        <?php if ($isAdmin): ?>
                        <a href="system_docs.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-gear me-1"></i>Systemdokumentation
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="user-guide-content">
                        <?= $htmlContent ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.user-guide-content h3 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}
.user-guide-content h4 {
    color: #3498db;
}
.user-guide-content h5 {
    color: #5a6268;
}
.user-guide-content ul {
    padding-left: 20px;
}
.user-guide-content li {
    margin-bottom: 5px;
}
.user-guide-content table {
    width: auto;
}
.user-guide-content code {
    font-size: 0.9em;
}
</style>

<?php require_once 'include/footer.php'; ?>
