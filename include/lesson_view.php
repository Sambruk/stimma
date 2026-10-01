<?php
/**
 * Stimma — växeln mellan klassisk lektionsvy och C2 (kortformatet).
 *
 * Inställningarna ligger i den befintliga nyckel/värde-tabellen ai_settings,
 * så ingen ändring av datamodellen behövs:
 *
 *   lesson_view_default    'klassisk' | 'c2'   — vyn för alla kurser
 *   lesson_view_c2_courses '12,35,161'          — kurser som får C2 även när
 *                                                 standarden är klassisk
 *
 * Utöver det kan vem som helst jämföra med ?ui=c2 / ?ui=klassisk i adressen.
 * Valet minns i sessionen tills ?ui=auto (eller utloggning), så att det håller
 * när man bläddrar mellan lektioner.
 */

if (!function_exists('lessonViewFor')) {

define('LESSON_VIEW_VYER', ['klassisk', 'c2']);

function lessonViewSettings(): array {
    $rader = query(
        "SELECT setting_key, setting_value FROM " . DB_DATABASE . ".ai_settings
         WHERE setting_key IN ('lesson_view_default', 'lesson_view_c2_courses')"
    ) ?: [];
    $s = ['default' => 'klassisk', 'c2_courses' => []];
    foreach ($rader as $r) {
        if ($r['setting_key'] === 'lesson_view_default' && in_array($r['setting_value'], LESSON_VIEW_VYER, true)) {
            $s['default'] = $r['setting_value'];
        }
        if ($r['setting_key'] === 'lesson_view_c2_courses') {
            $s['c2_courses'] = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$r['setting_value'])))));
        }
    }
    return $s;
}

function lessonViewSave(string $default, array $c2Courses, string $email): void {
    if (!in_array($default, LESSON_VIEW_VYER, true)) throw new InvalidArgumentException('Okänd vy');
    $kurser = implode(',', array_values(array_unique(array_filter(array_map('intval', $c2Courses)))));
    foreach ([
        ['lesson_view_default', $default, 'Lektionsvy för alla kurser: klassisk eller c2'],
        ['lesson_view_c2_courses', $kurser, 'Kurs-id:n som visas i C2 även när standarden är klassisk'],
    ] as [$nyckel, $varde, $beskrivning]) {
        execute(
            "INSERT INTO " . DB_DATABASE . ".ai_settings (setting_key, setting_value, description, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$nyckel, $varde, $beskrivning, $email]
        );
    }
}

/**
 * Vilken vy ska lektionen i den här kursen visas i? 'klassisk' eller 'c2'.
 * Ordning: ?ui= i adressen (minns i sessionen) → kurslistan → standarden.
 */
function lessonViewFor(int $courseId): string {
    if (isset($_GET['ui'])) {
        $ui = strtolower((string)$_GET['ui']);
        if (in_array($ui, LESSON_VIEW_VYER, true)) $_SESSION['lesson_ui'] = $ui;
        elseif ($ui === 'auto') unset($_SESSION['lesson_ui']);
    }
    if (isset($_SESSION['lesson_ui']) && in_array($_SESSION['lesson_ui'], LESSON_VIEW_VYER, true)) {
        return $_SESSION['lesson_ui'];
    }
    $s = lessonViewSettings();
    if (in_array($courseId, $s['c2_courses'], true)) return 'c2';
    return $s['default'];
}

} // end function_exists guard
