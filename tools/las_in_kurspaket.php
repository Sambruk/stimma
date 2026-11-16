<?php
/**
 * Stimma — läs in ett kurspaket (JSON) som en ny, inaktiv kurs.
 *
 * Formatet beskrivs i docs/KURSFORMAT.md. Samma regler som admins "Importera kurs"
 * — båda bygger på include/kurspaket.php. Paketet är en .json-fil eller en .zip
 * med course.json och färdiga bilder i images/.
 *
 * Körs i webbcontainern (CLI, aldrig via webben — tools/ ligger utanför webbroten):
 *
 *   docker cp paket.zip stimma-web-1:/tmp/paket.zip
 *   docker exec -u www-data stimma-web-1 php /var/www/html/tools/las_in_kurspaket.php \
 *       /tmp/paket.zip --forfattare=thomas.wennersten@sambruk.se [--provkor] [--bilder]
 *
 *   --forfattare  e-post till en befintlig användare. Blir författare och redaktör,
 *                 och kursen hamnar i den användarens organisation (e-postdomänen).
 *   --provkor     validera bara, skriv ingenting
 *   --bilder      generera de bilder som paketet bara har image_wish för (inte image_file)
 *                 via OpenAI efter inläsningen (kostar pengar och drar AI-kvot; ~17
 *                 US-cent för omslaget, ~3–5 cent per lektionsbild).
 *
 * Kursen skapas ALLTID som inaktiv. Den syns inte för deltagare förrän en admin
 * har granskat och aktiverat den.
 *
 * Avslutas med kod 0 vid lyckad inläsning (eller godkänd provkörning), 1 vid
 * valideringsfel, 2 vid övriga fel. Sista utskriften är en JSON-rad med resultatet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/../public');
require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/kurspaket.php';

// ---------------------------------------------------------------- argument

$fil = null;
$forfattare = null;
$provkor = false;
$bilder = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--provkor') $provkor = true;
    elseif ($a === '--bilder') $bilder = true;
    elseif (strpos($a, '--forfattare=') === 0) $forfattare = strtolower(trim(substr($a, 13)));
    elseif ($a[0] !== '-') $fil = $a;
    else avsluta(2, "Okänd flagga: $a");
}
if (!$fil || !$forfattare) avsluta(2, 'Användning: las_in_kurspaket.php <paket.json|paket.zip> --forfattare=<e-post> [--provkor] [--bilder]');
if (!is_readable($fil)) avsluta(2, "Kan inte läsa filen: $fil");

$author = queryOne("SELECT id, email FROM " . DB_DATABASE . ".users WHERE LOWER(email) = ?", [$forfattare]);
if (!$author) avsluta(2, "Ingen användare med e-post $forfattare");

$last = kurspaketLasFil($fil, $fil);
register_shutdown_function(fn() => kurspaketStada($last['tempdir']));
if ($last['fel']) avsluta(1, $last['fel']);
$paket = $last['paket'];
$zipBilder = strtolower(pathinfo($fil, PATHINFO_EXTENSION)) === 'zip' ? $last['bilder'] : null;

// ---------------------------------------------------------------- validering

$v = kurspaketValidera($paket, $zipBilder);
foreach ($v['varningar'] as $w) fwrite(STDERR, "VARNING: $w\n");
if ($v['fel']) {
    foreach ($v['fel'] as $f) fwrite(STDERR, "FEL: $f\n");
    avsluta(1, count($v['fel']) . ' fel — ingenting har skrivits.', ['fel' => $v['fel'], 'varningar' => $v['varningar']]);
}
if ($provkor) {
    avsluta(0, 'Provkörning godkänd — ingenting har skrivits.', [
        'lektioner' => count($paket['lessons']),
        'fragor' => array_sum(array_map(fn($l) => count($l['questions'] ?? []), $paket['lessons'])),
        'bilder_i_paketet' => count(array_filter(array_merge([$paket['course']['image_file'] ?? null], array_column($paket['lessons'], 'image_file')))),
        'varningar' => $v['varningar'],
    ]);
}

// ---------------------------------------------------------------- inläsning

try {
    $res = kurspaketLasIn($paket, $author, $last['bilder']);
} catch (Throwable $e) {
    avsluta(2, $e->getMessage());
}
$courseId = $res['kurs_id'];
$c = $paket['course'];

// ---------------------------------------------------------------- bilder via AI (valfritt)

$bildresultat = [];
if ($bilder) {
    require_once '../include/ai_lesson_helper.php';
    require_once '../include/ai_image_helper.php';

    if (empty($c['image_file'])) {
        $omslag = genereraKursomslag($courseId, $c, $author['email']);
        $bildresultat['omslag'] = $omslag;
        if ($omslag['success']) execute("UPDATE " . DB_DATABASE . ".courses SET image_url = ? WHERE id = ?", [$omslag['image_url'], $courseId]);
    }
    foreach ($res['saknar_bild'] as $key => $onskemal) {
        $l = current(array_filter($paket['lessons'], fn($x) => $x['key'] === $key));
        $r = aiGenerateLessonImage($l['title'], $c['title'], $courseId, $author['email'], $onskemal);
        $bildresultat[$key] = $r;
        if ($r['success']) execute("UPDATE " . DB_DATABASE . ".lessons SET image_url = ? WHERE id = ?", [$r['image_url'], $res['lektioner'][$key]]);
        fwrite(STDERR, ($r['success'] ? 'Bild klar' : 'Bild misslyckades') . ": {$l['title']}" . ($r['success'] ? '' : " — {$r['error']}") . "\n");
    }
}

avsluta(0, "Kursen är inläst som INAKTIV: {$c['title']}", [
    'kurs_id' => $courseId,
    'lektioner' => $res['lektioner'],
    'redigera' => "https://stimma.sambruk.se/admin/edit_course.php?id=$courseId",
    'bilder_fran_paketet' => $res['bilder'],
    'bilder_via_ai' => $bildresultat,
    'saknar_bild' => $bilder ? [] : $res['saknar_bild'],
    'varningar' => $v['varningar'],
]);

// ================================================================ funktioner

function avsluta(int $kod, string $meddelande, array $data = []): void {
    fwrite($kod === 0 ? STDOUT : STDERR, $meddelande . "\n");
    echo json_encode(['ok' => $kod === 0, 'meddelande' => $meddelande] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($kod);
}

/** Kursomslag med samma prompt, storlek och kvalitet som knappen i admin (generate_course_image.php). */
function genereraKursomslag(int $courseId, array $c, string $email): array {
    require_once '../include/ai_quota.php';
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    if ($apiKey === '') return ['success' => false, 'error' => 'AI API-nyckel saknas i konfigurationen.'];
    try { enforceAiQuotaForEmail($email); } catch (Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }

    $context = ['feature' => 'image', 'course_id' => $courseId, 'is_image' => true, 'user_email' => $email];
    $model = getModelForFeature('image', 'gpt-image-1-mini');
    $prompt = aiImageBuildPrompt('kurs', $c['title'], $c['description'], $c['image_style'] ?? 'illustration', (string)($c['image_wish'] ?? ''));
    $payload = aiImageBuildPayload($model, $prompt, aiImageSizeFor('kurs'), aiImageQualityFor('kurs'));

    $ch = curl_init(OPENAI_API_BASE . '/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 240,
    ]);
    $svar = curl_exec($ch);
    $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFel = curl_error($ch);
    curl_close($ch);
    if ($curlFel || $kod !== 200) {
        logAiUsage($context, [], $model, 'error');
        $msg = $curlFel ?: (json_decode((string)$svar, true)['error']['message'] ?? "HTTP $kod");
        return ['success' => false, 'error' => $msg];
    }
    $resultat = json_decode($svar, true);
    $bytes = aiImageExtractBytes(is_array($resultat) ? $resultat : []);
    if (!$bytes) {
        logAiUsage($context, [], $model, 'error');
        return ['success' => false, 'error' => 'Inga bilddata i API-svaret.'];
    }
    $filnamn = 'ai_' . uniqid() . '.' . aiImageFileExtension($model);
    if (!file_put_contents(ROOT_PATH . '/public/upload/' . $filnamn, $bytes)) {
        logAiUsage($context, [], $model, 'error');
        return ['success' => false, 'error' => 'Kunde inte spara bildfilen.'];
    }
    $usage = aiImageUsageFromResponse(is_array($resultat) ? $resultat : []);
    logAiUsage($context, $usage, $model, 'ok');
    return ['success' => true, 'image_url' => $filnamn,
            'cost_cents' => calculateAiCostCents($model, $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0, true)];
}
