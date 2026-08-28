<?php
/**
 * Stimma — Helpers för OpenAI:s bild-genererings-API
 *
 * Sedan 2026-05-12 deprekeras dall-e-2 och dall-e-3 av OpenAI. Vi default-byter
 * till gpt-image-1-mini men superadmin kan välja annan modell i UI:t. De två
 * modell-familjerna har olika API-kontrakt:
 *
 *   - dall-e-*       : `quality` är 'standard' | 'hd'; svar i `data[0].url`
 *   - gpt-image-*    : `quality` är 'low'|'medium'|'high'|'auto'; svar i `data[0].b64_json`
 *
 * Dessa helpers ger en gemensam yta så att de tre call-sites
 * (cron-bilder, kursomslag, lektionsbilder) inte behöver bry sig om vilken
 * familj som är konfigurerad.
 */

if (!function_exists('aiImageBuildPayload')) {

/**
 * Gemensam stilanvisning för alla bilder Stimma genererar.
 *
 * Ligger på ett ställe eftersom prompten skrivs på fyra: kursomslag i admin,
 * lektionsbild i admin, och båda motsvarigheterna i bakgrundsjobbet. Drev de
 * isär blev kursen en blandning av olika bildspråk.
 *
 * Tidigare bad varje prompt om "clean, professional, minimalist" och
 * "abstract or conceptual", vilket är en beställning på just det intetsägande
 * som gjorde bilderna trista — modellen levererade gradienter och former utan
 * motiv. Anvisningen nedan ber i stället om ett konkret motiv, varma färger och
 * ett illustrerat anslag, och räknar uttryckligen upp det som ska undvikas.
 * Negativa exempel biter bättre än fler positiva adjektiv.
 *
 * Texten är på engelska eftersom bildmodellerna följer engelska stilord
 * betydligt mer förutsägbart än svenska.
 *
 * @return string Stilanvisning att lägga sist i prompten
 */
function aiImageStyleDirective() {
    return "Style: warm, friendly editorial illustration. Flat vector shapes with "
         . "a subtle paper grain and soft hand-drawn edges, like a well-made magazine "
         . "or picture-book illustration. Warm palette built on cream, terracotta, "
         . "ochre, muted sage green and dusty blue, with soft directional light. "
         . "Depict a concrete, recognisable scene, object or situation connected to "
         . "the subject rather than abstract shapes or symbols. People may appear, "
         . "drawn simply and inclusively with warm skin tones and no detailed facial "
         . "features. Generous negative space and an uncluttered, inviting composition. "
         . "Avoid: corporate stock photography, glossy 3D renders, neon gradients, "
         . "dark backgrounds, glowing networks, circuit boards, robots and other "
         . "generic technology cliches. No text, letters or numbers anywhere in the image.";
}

/**
 * Bygg request-payload anpassad till modellfamiljens API-kontrakt.
 */
function aiImageBuildPayload($model, $prompt, $size = '1024x1024') {
    $isGptImage = stripos((string)$model, 'gpt-image') === 0;
    if ($isGptImage) {
        return [
            'model'   => $model,
            'prompt'  => $prompt,
            'n'       => 1,
            'size'    => $size,
            'quality' => 'medium',
        ];
    }
    // dall-e-2 / dall-e-3 — legacy
    return [
        'model'   => $model,
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => $size,
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

} // end function_exists guard
