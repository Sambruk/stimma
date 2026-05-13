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
