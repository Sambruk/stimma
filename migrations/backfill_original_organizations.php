<?php
/**
 * Backfill-skript: försök identifiera ursprungsorganisation för befintliga
 * kopierade kurser.
 *
 * Bakgrund: Migration 024 lade till original_organization_domain men hade
 * ingen historisk data att backfilla från — alla befintliga rader fick sitt
 * current organization_domain som "original". Det här skriptet kompenserar
 * genom att matcha kopior mot sina källkurser via titel-mönster.
 *
 * Heuristik: copy_course.php sätter nya titlar till "<original> (kopia)"
 * eller "<original> (kopia YYYY-MM-DD HH:MM)" om en duplicate redan fanns.
 * Vi strippar den suffixen rekursivt (kurser kan vara kopior av kopior) och
 * söker efter en källkurs med den strippade titeln i en ANNAN organisation.
 *
 * Körning:
 *   docker exec stimma-web-1 php /var/www/html/migrations/backfill_original_organizations.php
 *     — dry-run (visar vad som SKULLE uppdateras utan att skriva).
 *
 *   docker exec stimma-web-1 php /var/www/html/migrations/backfill_original_organizations.php --apply
 *     — kör live och skriver uppdateringar.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Detta skript körs endast via CLI.');
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/connect.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';

$apply = in_array('--apply', $argv, true);

echo "=== Backfill av original_organization_domain ===" . PHP_EOL;
echo ($apply ? "LÄGE: --apply (skriver till databasen)" : "LÄGE: dry-run (inga ändringar skrivs). Använd --apply för att verkställa.") . PHP_EOL;
echo PHP_EOL;

/**
 * Strippa (kopia)- och (kopia YYYY-...)-suffix från en titel. Rekursivt —
 * kedjor som "X (kopia) (kopia)" blir "X".
 */
function stripCopySuffix($title) {
    $previous = null;
    $current = $title;
    while ($current !== $previous) {
        $previous = $current;
        // "(kopia)" eller "(kopia 2026-03-04 12:45)" — matchar trailing whitespace också
        $current = preg_replace('/\s*\(kopia(?:\s+\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2})?\)\s*$/u', '', $current);
    }
    return trim($current);
}

// Hämta alla kurser som ser ut som kopior
$copies = query(
    "SELECT id, title, organization_domain, original_organization_domain
     FROM " . DB_DATABASE . ".courses
     WHERE title LIKE '%(kopia)%' OR title LIKE '%(kopia 20%'
     ORDER BY id ASC"
);

echo "Hittade " . count($copies) . " kurser med (kopia)-mönster i titeln." . PHP_EOL . PHP_EOL;

$stats = [
    'matched' => 0,
    'already_correct' => 0,
    'ambiguous' => 0,
    'no_match' => 0,
    'updates' => [],
];

foreach ($copies as $copy) {
    $originalTitle = stripCopySuffix($copy['title']);

    if ($originalTitle === '' || $originalTitle === $copy['title']) {
        // Titel var "(kopia)" helt själv eller kunde inte strippas
        $stats['no_match']++;
        continue;
    }

    // Sök efter en källkurs med den strippade titeln i en ANNAN org
    $candidates = query(
        "SELECT id, title, organization_domain, original_organization_domain
         FROM " . DB_DATABASE . ".courses
         WHERE title = ? AND id != ? AND organization_domain != ?
         ORDER BY id ASC",
        [$originalTitle, $copy['id'], $copy['organization_domain']]
    );

    if (empty($candidates)) {
        // Testa en annan variant — kanske källan är en egen kopia (kedja)
        // så matcha på prefix till första (kopia)
        $candidates2 = query(
            "SELECT id, title, organization_domain, original_organization_domain
             FROM " . DB_DATABASE . ".courses
             WHERE title LIKE ? AND id != ? AND organization_domain != ?
             ORDER BY
               CASE WHEN title = ? THEN 0 ELSE 1 END,
               id ASC
             LIMIT 5",
            [$originalTitle . '%', $copy['id'], $copy['organization_domain'], $originalTitle]
        );
        if (empty($candidates2)) {
            $stats['no_match']++;
            printf("  [INGEN MATCH] #%d \"%s\" (%s) — strippat: \"%s\"%s",
                $copy['id'], $copy['title'], $copy['organization_domain'], $originalTitle, PHP_EOL);
            continue;
        }
        $candidates = $candidates2;
    }

    // Välj bästa kandidat: föredra den där original_organization_domain ===
    // organization_domain (dvs. kursen är själv sitt ursprung, inte en kopia).
    $best = null;
    foreach ($candidates as $c) {
        if (($c['original_organization_domain'] ?? '') === $c['organization_domain']) {
            $best = $c;
            break;
        }
    }
    if (!$best) {
        $best = $candidates[0];
    }

    // Avgör vad original_organization_domain ska bli — det är källans
    // original (som kan vara dess egna domän, eller i sin tur en kedja)
    $newOriginal = !empty($best['original_organization_domain'])
        ? $best['original_organization_domain']
        : $best['organization_domain'];

    if ($newOriginal === $copy['original_organization_domain']) {
        $stats['already_correct']++;
        continue;
    }

    if (count($candidates) > 1) {
        $stats['ambiguous']++;
        printf("  [FLERA MATCH] #%d \"%s\" (%s) → källkandidater: ",
            $copy['id'], $copy['title'], $copy['organization_domain']);
        echo implode(', ', array_map(fn($c) => '#' . $c['id'] . ' (' . $c['organization_domain'] . ')', $candidates));
        echo ' — väljer källa #' . $best['id'] . ' (' . $best['organization_domain'] . ')' . PHP_EOL;
    }

    $stats['matched']++;
    $stats['updates'][] = [
        'course_id' => (int)$copy['id'],
        'title' => $copy['title'],
        'current_org' => $copy['organization_domain'],
        'old_original' => $copy['original_organization_domain'],
        'new_original' => $newOriginal,
        'source_id' => (int)$best['id'],
    ];

    printf("  [MATCH]       #%d \"%s\" (%s): %s → %s (källa #%d)%s",
        $copy['id'], $copy['title'], $copy['organization_domain'],
        $copy['original_organization_domain'] ?? 'NULL',
        $newOriginal,
        $best['id'],
        PHP_EOL);
}

echo PHP_EOL . "=== Sammanfattning ===" . PHP_EOL;
echo "  Kurser att uppdatera:    " . $stats['matched'] . PHP_EOL;
echo "  Redan korrekta:          " . $stats['already_correct'] . PHP_EOL;
echo "  Flera kandidater:        " . $stats['ambiguous'] . " (använder bästa gissning)" . PHP_EOL;
echo "  Ingen match:             " . $stats['no_match'] . PHP_EOL;
echo PHP_EOL;

if (!$apply) {
    echo "Kör med --apply för att verkställa ändringarna." . PHP_EOL;
    exit(0);
}

echo "Applicerar uppdateringar..." . PHP_EOL;
$written = 0;
foreach ($stats['updates'] as $u) {
    execute(
        "UPDATE " . DB_DATABASE . ".courses SET original_organization_domain = ? WHERE id = ?",
        [$u['new_original'], $u['course_id']]
    );
    $written++;
}
echo "Klart — $written rader uppdaterade." . PHP_EOL;
