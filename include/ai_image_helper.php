<?php
/**
 * Stimma — Helpers för OpenAI:s bild-genererings-API
 *
 * Allt som rör Stimmas AI-bilder ligger här: stilval, promptbygge, format,
 * kvalitet och kostnadsberäkning. Anledningen är att prompten tidigare skrevs
 * på fyra ställen (kursomslag och lektionsbild, i admin och i bakgrundsjobbet)
 * och drev isär — samma kurs kunde få bilder i olika bildspråk.
 *
 * Modellfamiljerna har olika API-kontrakt:
 *   - dall-e-*       : `quality` är 'standard' | 'hd'; svar i `data[0].url`; alltid PNG
 *   - gpt-image-*    : `quality` är 'low'|'medium'|'high'; svar i `data[0].b64_json`;
 *                      stöder `output_format` png|jpeg|webp och `output_compression`
 */

if (!function_exists('aiImageBuildPayload')) {

/**
 * Bildstilar användaren kan välja per kurs.
 *
 * Nyckeln sparas i courses.image_style och ärvs av kursens lektionsbilder, så
 * att en kurs håller ihop visuellt. Texterna är på engelska eftersom
 * bildmodellerna följer engelska stilord betydligt mer förutsägbart.
 */
function aiImageStyles() {
    return [
        'illustration' => [
            'namn'  => 'Varm illustration',
            'hjalp' => 'Handritat anslag i varma färger. Passar de flesta kurser.',
            'stil'  => "Style: warm, friendly editorial illustration. Flat vector shapes with a subtle "
                     . "paper grain and soft hand-drawn edges, like a well-made magazine illustration. "
                     . "Warm palette built on cream, terracotta, ochre, muted sage green and dusty blue, "
                     . "with soft directional light. People are drawn simply and inclusively, with "
                     . "friendly, minimal facial features - eyes and a suggestion of a mouth - never "
                     . "blank or faceless.",
        ],
        'foto' => [
            'namn'  => 'Fotografiskt',
            'hjalp' => 'Naturligt ljus och verkliga miljöer. Passar praktiska ämnen, t.ex. hantverk eller mat.',
            'stil'  => "Style: natural documentary photography. Soft daylight, shallow depth of field, "
                     . "muted natural colours and real materials. Candid and unposed, as if photographed "
                     . "during ordinary work. No studio lighting, no staged smiles towards the camera.",
        ],
        'abstrakt' => [
            'namn'  => 'Lugnt grafiskt',
            'hjalp' => 'Enkla former och mönster utan personer. Passar abstrakta ämnen som juridik eller styrning.',
            'stil'  => "Style: calm geometric graphic composition. Simple layered shapes, gentle curves "
                     . "and a restrained palette of cream, sand, sage and dusty blue, with a subtle paper "
                     . "texture. Objects and materials connected to the subject may appear, but no people.",
        ],
    ];
}

function aiImageStyleKey($style) {
    $styles = aiImageStyles();
    $style = strtolower(trim((string)$style));
    return isset($styles[$style]) ? $style : 'illustration';
}

/**
 * Gemensam stilanvisning för en vald stil.
 *
 * Den negativa listan gäller alla stilar. Negativa exempel biter bättre än fler
 * positiva adjektiv: tidigare formuleringar om "clean, professional, minimalist"
 * och "abstract or conceptual" var i praktiken en beställning på just det
 * intetsägande som klagomålen gällde.
 */
function aiImageStyleDirective($style = 'illustration') {
    $styles = aiImageStyles();
    $vald = $styles[aiImageStyleKey($style)];

    $undvik = " Avoid: corporate stock photography, glossy 3D renders, neon gradients, dark "
            . "backgrounds, glowing networks, circuit boards, padlock icons, robots, floating icon "
            . "bubbles and other generic technology cliches. No text, letters or numbers anywhere "
            . "in the image.";

    return $vald['stil'] . $undvik;
}

/**
 * Bygg hela prompten för en bild.
 *
 * Det viktigaste ordet i prompten är "concrete": utan en uttrycklig beställning
 * på en igenkännbar situation svarar modellen med gradienter och symboler.
 *
 * @param string $typ         'kurs' eller 'lektion'
 * @param string $titel       Kursens eller lektionens namn
 * @param string $sammanhang  Kursbeskrivning (kurs) eller kursnamn (lektion)
 * @param string $style       Nyckel ur aiImageStyles()
 * @return string
 */
function aiImageBuildPrompt($typ, $titel, $sammanhang = '', $style = 'illustration', $onskemal = '') {
    $titel = trim((string)$titel);
    $sammanhang = trim((string)$sammanhang);
    // Användarens egen beskrivning av motivet. Titeln räcker inte alltid — en
    // lektion som heter "Del 3 – fortsättning" ger modellen nästan ingenting att
    // gå på, och då blir bilden generisk. Radbrytningar bort och en längdgräns,
    // så att ett inklistrat stycke inte tränger undan stil- och formatkraven.
    $onskemal = trim(preg_replace('/\s+/u', ' ', (string)$onskemal));
    if (mb_strlen($onskemal) > 300) {
        $onskemal = mb_substr($onskemal, 0, 300);
    }

    if ($typ === 'lektion') {
        $inledning = "Illustration for a lesson called '{$titel}'"
                   . ($sammanhang ? ", part of a course about '{$sammanhang}'" : '') . '.';
        // Med ett önskemål styr det bildutsnittet (närbild, miljöbild …). Ett fast
        // "centred" gjorde annars kursens lektionsbilder nästan likadana.
        $komposition = $onskemal !== ''
            ? " Uncluttered composition; follow the framing and distance described above."
            : " Centred, uncluttered composition with generous space around the subject.";
    } else {
        $inledning = "Cover illustration for a course called '{$titel}'"
                   . ($sammanhang ? ". The course is about: '{$sammanhang}'" : '') . '.';
        // Omslaget visas som banner (110 px i kurskortet, 340 px överst på
        // kurssidan) och beskärs på höjden. Be om lugna ytor upptill och nedtill.
        $komposition = " Wide, calm composition with the main subject slightly off-centre and quiet "
                     . "space at the top and bottom so the image can be cropped to a wide banner "
                     . "without losing the subject.";
    }

    if ($onskemal !== '') {
        // Önskemålet står först efter inledningen och är uttryckligen det som
        // ska avbildas — stilen längre ned styr hur, inte vad.
        $scen = " The image must show: {$onskemal}. Depict it as one concrete, recognisable scene or "
              . "object rather than as symbols.";
    } else {
        $scen = " Depict one concrete, recognisable everyday scene, situation or object connected to the "
              . "subject, set in an ordinary Swedish public-sector workplace or school where that fits the "
              . "topic. Show what people actually do, not symbols of it.";
    }

    return $inledning . $scen . $komposition . ' ' . aiImageStyleDirective($style);
}

/** Bildformat per typ. Omslaget är brett, lektionsbilden visas i en smal spalt. */
function aiImageSizeFor($typ) {
    return $typ === 'lektion' ? '1024x1024' : '1536x1024';
}

/** Kvalitet per typ. Omslaget syns först och genereras en gång per kurs. */
function aiImageQualityFor($typ) {
    return $typ === 'lektion' ? 'medium' : 'high';
}

/** gpt-image-modellerna kan leverera WebP direkt — en bild går från ~2,8 MB till ~150 kB. */
function aiImageSupportsWebp($model) {
    return stripos((string)$model, 'gpt-image') === 0;
}

function aiImageFileExtension($model) {
    return aiImageSupportsWebp($model) ? 'webp' : 'png';
}

/**
 * Bygg request-payload anpassad till modellfamiljens API-kontrakt.
 */
function aiImageBuildPayload($model, $prompt, $size = '1536x1024', $quality = null) {
    if (aiImageSupportsWebp($model)) {
        return [
            'model'              => $model,
            'prompt'             => $prompt,
            'n'                  => 1,
            'size'               => $size,
            'quality'            => $quality ?: 'high',
            'output_format'      => 'webp',
            'output_compression' => 82,
        ];
    }
    // dall-e-2 / dall-e-3 — legacy. Stöder varken webp eller de nya storlekarna.
    $legacySizes = ['1024x1024', '1792x1024', '1024x1792'];
    return [
        'model'   => $model,
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => in_array($size, $legacySizes, true) ? $size : '1024x1024',
        'quality' => 'standard',
    ];
}

/**
 * Extrahera bild-bytes ur parsat API-svar. Hanterar både b64_json (gpt-image)
 * och url (dall-e). Returnerar binär sträng eller null vid fel.
 */
function aiImageExtractBytes(array $parsedResponse) {
    if (isset($parsedResponse['data'][0]['b64_json'])) {
        $bytes = base64_decode($parsedResponse['data'][0]['b64_json'], true);
        return $bytes !== false && $bytes !== '' ? $bytes : null;
    }
    if (isset($parsedResponse['data'][0]['url'])) {
        $bytes = @file_get_contents($parsedResponse['data'][0]['url']);
        return $bytes !== false && $bytes !== '' ? $bytes : null;
    }
    return null;
}

/**
 * Plocka ut tokenanvändningen ur svaret. gpt-image-modellerna rapporterar
 * faktiska bildtokens, vilket är det enda sättet att veta vad bilden kostade —
 * priset varierar med både storlek och kvalitet.
 */
function aiImageUsageFromResponse(array $parsedResponse) {
    $u = $parsedResponse['usage'] ?? [];
    if (!$u) return [];
    return [
        'prompt_tokens'     => (int)($u['input_tokens'] ?? 0),
        'completion_tokens' => (int)($u['output_tokens'] ?? 0),
        'total_tokens'      => (int)($u['total_tokens'] ?? 0),
    ];
}

/**
 * Uppmätta bildtokens per storlek och kvalitet (gpt-image-2, mätt 2026-09-17).
 * Används bara för att visa ett ungefärligt pris INNAN bilden beställs; den
 * loggade kostnaden räknas alltid på faktiska tokens ur svaret.
 */
function aiImageEstimatedTokens($size = '1536x1024', $quality = 'high') {
    // Uppmätta värden: 1024x1024 medium = 1756 tokens, 1536x1024 high = 5488
    // (gpt-image-2, 2026-09-17). Övriga rader är skalade efter bildyta och
    // kvalitetssteg — de används bara för att visa ett ungefärligt belopp.
    $tabell = [
        '1024x1024' => ['low' => 440,  'medium' => 1756, 'high' => 3660],
        '1536x1024' => ['low' => 660,  'medium' => 2640, 'high' => 5488],
        '1024x1536' => ['low' => 660,  'medium' => 2640, 'high' => 5488],
    ];
    $rad = $tabell[$size] ?? $tabell['1536x1024'];
    return $rad[$quality] ?? $rad['high'];
}

/**
 * Ungefärlig kostnad i US-cent för en bild, innan den beställs.
 * Returnerar null om modellens pris inte är känt.
 */
function aiImageEstimatedCostCents($model, $size = '1536x1024', $quality = 'high') {
    if (!function_exists('getAiModelPricing')) {
        return null;
    }
    $pris = getAiModelPricing($model);
    $perMiljon = (int)($pris['image_token_per_1m_cents'] ?? 0);
    if ($perMiljon <= 0) {
        $fast = (int)($pris['image_per_call_cents'] ?? 0);
        return $fast > 0 ? $fast : null;
    }
    return aiImageEstimatedTokens($size, $quality) * $perMiljon / 1000000;
}

/**
 * Kostnad som text för gränssnittet. Beloppen är i US-cent eftersom det är
 * valutan OpenAI fakturerar i — räkna inte om till kronor med en påhittad kurs.
 */
function aiImageCostLabel($cents) {
    if ($cents === null) {
        return '';
    }
    if ($cents < 10) {
        return number_format((float)$cents, 1, ',', ' ') . ' US-cent';
    }
    return '$' . number_format((float)$cents / 100, 2, ',', ' ');
}

} // end function_exists guard
