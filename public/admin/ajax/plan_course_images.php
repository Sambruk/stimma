<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * Föreslå bildmotiv för en hel kurs (2026-10-02).
 *
 * Ett textanrop läser kursens lektioner och föreslår ETT eget motiv per lektion,
 * med uttrycklig variation i miljö, avstånd och antal personer — annars blir
 * lektionsbilderna i en kurs nästan likadana, eftersom prompten utan önskemål
 * bara bygger på lektionstitel, kursnamn och en allmän miljöbeskrivning.
 * Själva bilderna skapas sedan en i taget av generate_lesson_image.php och
 * generate_course_image.php med motivet som image_wish (lessons.php).
 *
 * Skriver ingenting i databasen.
 */

require_once '../../include/config.php';
require_once '../../include/database.php';
require_once '../../include/functions.php';
require_once '../../include/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Du måste vara inloggad.']);
    exit;
}
$currentUser = queryOne("SELECT * FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser || (empty($currentUser['is_admin']) && empty($currentUser['is_editor']) && ($currentUser['role'] ?? '') !== 'super_admin')) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet.']);
    exit;
}
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Ogiltig säkerhetstoken. Ladda om sidan.']);
    exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
$lage = ($_POST['mode'] ?? 'saknas') === 'alla' ? 'alla' : 'saknas';
$medOmslag = !empty($_POST['include_cover']);

$course = queryOne("SELECT id, title, description, organization_domain, image_url, image_style FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
if (!$course || !userCanModifyCourse(['id' => (int)$course['id'], 'organization_domain' => $course['organization_domain']])) {
    echo json_encode(['success' => false, 'message' => 'Du har inte behörighet att ändra denna kurs.']);
    exit;
}

// Infosidor får ingen bild (de visas utan bild i lektionsvyn).
$alla = query(
    "SELECT id, title, content, image_url FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND lesson_type = 'lesson' ORDER BY sort_order ASC, id ASC",
    [$courseId]
);
$valda = array_values(array_filter($alla, fn($l) => $lage === 'alla' || trim((string)$l['image_url']) === ''));
if (!$valda && !$medOmslag) {
    echo json_encode(['success' => false, 'message' => $lage === 'saknas' ? 'Alla lektioner har redan en bild.' : 'Kursen har inga lektioner.']);
    exit;
}

require_once '../../include/ai_image_helper.php';
require_once '../../include/ai_lesson_helper.php';

$stil = aiImageStyleKey($course['image_style'] ?? '');
$stilNamn = aiImageStyles()[$stil]['namn'];

// Underlaget: varje lektions titel och början av texten. ALLA lektioner skickas
// med (även de som redan har bild), så att de nya motiven skiljer sig från
// helheten — men bara de valda ska få förslag.
$rader = [];
foreach ($alla as $l) {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$l['content']), ENT_QUOTES, 'UTF-8')));
    $rader[] = [
        'id' => (int)$l['id'],
        'titel' => $l['title'],
        'utdrag' => mb_substr($text, 0, 700),
        'ska_ha_motiv' => in_array((int)$l['id'], array_map(fn($v) => (int)$v['id'], $valda), true),
    ];
}

$stilRegel = $stil === 'abstrakt'
    ? "Stilen är grafisk utan personer: beskriv föremål, material och miljöer — aldrig människor."
    : ($stil === 'foto'
        ? "Stilen är dokumentärt foto: beskriv verkliga, vardagliga situationer som går att fotografera."
        : "Stilen är varm illustration: beskriv vardagliga situationer med människor och föremål.");

$system = "Du planerar bilder till en webbkurs för medarbetare i svensk offentlig sektor. "
    . "Du skriver ett bildmotiv per lektion: en konkret, igenkännbar scen som visar lektionens kärna. "
    . "Motiven i samma kurs ska SKILJA SIG TYDLIGT åt — variera:\n"
    . "- miljö (kontor, mötesrum, reception, skola, vårdmiljö, hemma, utomhus, fordon, lager …),\n"
    . "- avstånd (närbild på händer eller ett föremål, halvbild, miljöbild),\n"
    . "- antal personer (ingen, en, två, en grupp) och vad de gör,\n"
    . "- tid på dygnet och ljus.\n"
    . "Upprepa aldrig samma miljö eller samma upplägg två gånger i rad. "
    . "Inga skärmar med läsbar text, inga skyltar, inga logotyper, inga symboler som hänglås eller glödande nätverk. "
    . $stilRegel . " "
    . "Varje motiv är EN mening på svenska, högst 250 tecken, och beskriver bara vad bilden visar — inte stil eller färger. "
    . "Svara ENBART med JSON.";

$user = "Kurs: " . $course['title'] . "\n"
    . "Om kursen: " . mb_substr(trim(strip_tags((string)$course['description'])), 0, 600) . "\n"
    . "Bildstil: " . $stilNamn . "\n\n"
    . "Lektioner i ordning (JSON):\n" . json_encode($rader, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
    . "Skriv motiv för de lektioner där ska_ha_motiv är true"
    . ($medOmslag ? ", och ett motiv för kursomslaget som fångar hela kursen och skiljer sig från lektionernas" : "")
    . ". Svara med JSON i formen:\n"
    . '{"omslag": "' . ($medOmslag ? 'motiv' : '') . '", "lektioner": [{"id": 123, "motiv": "…"}]}';

try {
    $svar = aiLessonCallOpenAI($system, $user, 3000, ['feature' => 'lesson_gen', 'course_id' => $courseId]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Kunde inte ta fram motiv: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = aiLessonParseJson($svar);
$motiv = [];
foreach (($data['lektioner'] ?? []) as $r) {
    if (isset($r['id'], $r['motiv']) && is_string($r['motiv'])) {
        $motiv[(int)$r['id']] = mb_substr(trim($r['motiv']), 0, 300);
    }
}

$modell = getModelForFeature('image', 'gpt-image-1-mini');
$prisLektion = aiImageEstimatedCostCents($modell, aiImageSizeFor('lektion'), aiImageQualityFor('lektion'));
$prisOmslag = aiImageEstimatedCostCents($modell, aiImageSizeFor('kurs'), aiImageQualityFor('kurs'));

$ut = [];
foreach ($valda as $l) {
    $ut[] = [
        'id' => (int)$l['id'],
        'title' => $l['title'],
        'has_image' => trim((string)$l['image_url']) !== '',
        // Saknas ett förslag (modellen hoppade över lektionen) får redaktören skriva själv
        'motif' => $motiv[(int)$l['id']] ?? '',
    ];
}

echo json_encode([
    'success' => true,
    'style' => $stilNamn,
    'cover' => $medOmslag ? [
        'motif' => is_string($data['omslag'] ?? null) ? mb_substr(trim($data['omslag']), 0, 300) : '',
        'has_image' => trim((string)$course['image_url']) !== '',
    ] : null,
    'lessons' => $ut,
    'cost_lesson_cents' => $prisLektion,
    'cost_cover_cents' => $prisOmslag,
], JSON_UNESCAPED_UNICODE);
