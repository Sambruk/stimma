<?php
/**
 * Stimma — innehållstolkning för lektionsvyn C2 (kortformatet).
 *
 * Arbetar på redan sanerad HTML (cleanHtml) och ändrar bara PRESENTATIONEN.
 * Inget skrivs tillbaka till databasen.
 *
 *   c2Normalisera()      lösa textstumpar och inline-element på toppnivå läggs i
 *                        <p>. Rättar "två stycken på samma rad" när innehållet
 *                        har text utanför stycken, t.ex. "…Act.</em>Förslag: …".
 *   c2Begreppslistor()   <ul> där VARJE punkt börjar med <strong>Begrepp:</strong>
 *                        blir en numrerad lista med begreppet på egen rad.
 *   c2AiForslag()        "Förslag: Frågor att ställa till AI-läraren" + listan
 *                        efter plockas ut som frågeknappar till AI-läraren.
 *   c2LyftRubrik()       sidans första h2/h3 lyfts ut till kortets rubrik.
 */

if (!function_exists('c2Normalisera')) {

/** Läs ett HTML-fragment till en DOM med en <div id="c2rot"> som rot. */
function c2Dom(string $html): array {
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><div id="c2rot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $rot = $dom->getElementById('c2rot') ?: $dom->getElementsByTagName('div')->item(0);
    return [$dom, $rot];
}

function c2Html(DOMDocument $dom, DOMNode $rot): string {
    $ut = '';
    foreach ($rot->childNodes as $n) $ut .= $dom->saveHTML($n);
    return $ut;
}

define('C2_BLOCK', ['p','div','ul','ol','li','h1','h2','h3','h4','h5','h6','table','thead','tbody','tr','td','th',
                   'blockquote','hr','figure','section','pre']);

function c2Normalisera(string $html): string {
    if (trim($html) === '') return $html;
    [$dom, $rot] = c2Dom($html);
    $samlade = [];
    $toppnoder = iterator_to_array($rot->childNodes);
    $tom = function () use (&$samlade, $dom, $rot) {
        if (!$samlade) return;
        $text = '';
        foreach ($samlade as $n) $text .= $n->textContent;
        if (trim($text) === '' && !array_filter($samlade, fn($n) => $n instanceof DOMElement && $n->nodeName !== 'br')) {
            $samlade = [];
            return;
        }
        $p = $dom->createElement('p');
        $rot->insertBefore($p, $samlade[0]);
        foreach ($samlade as $n) $p->appendChild($n);
        // Inledande/avslutande <br> i det nya stycket är bara gamla radbrytningar
        while ($p->firstChild && $p->firstChild->nodeName === 'br') $p->removeChild($p->firstChild);
        while ($p->lastChild && $p->lastChild->nodeName === 'br') $p->removeChild($p->lastChild);
        $samlade = [];
    };
    foreach ($toppnoder as $n) {
        $arBlock = $n instanceof DOMElement && in_array(strtolower($n->nodeName), C2_BLOCK, true);
        if ($arBlock || $n instanceof DOMComment) { $tom(); continue; }
        // Tappad styckebrytning: "…Act.</em>Förslag" — en mening slutar och nästa
        // börjar med versal utan mellanslag emellan. Då är det två stycken.
        if ($samlade && $n instanceof DOMText && preg_match('/^\p{Lu}/u', $n->textContent)) {
            $foreText = end($samlade)->textContent;
            if (preg_match('/[.!?:]\s*$/u', $foreText) && !preg_match('/\s$/u', $foreText)) $tom();
        }
        $samlade[] = $n;
    }
    $tom();

    // Ett stycke som bara är en kort fetstil utan avslutande punkt är en rubrik
    // ("Vad menas?", "Resultat") — visas som underrubrik, och blir en riktig
    // rubrik för skärmläsare.
    foreach (iterator_to_array($rot->childNodes) as $p) {
        if (!($p instanceof DOMElement) || $p->nodeName !== 'p') continue;
        $barn = array_values(array_filter(iterator_to_array($p->childNodes), fn($c) => !($c instanceof DOMText && trim($c->textContent) === '')));
        if (count($barn) !== 1 || !($barn[0] instanceof DOMElement) || !in_array($barn[0]->nodeName, ['strong', 'b'], true)) continue;
        $t = trim($barn[0]->textContent);
        if ($t === '' || mb_strlen($t) > 80 || preg_match('/\.$/u', $t)) continue;
        $h = $dom->createElement('h3');
        $h->setAttribute('class', 'sl-subhead');
        $h->appendChild($dom->createTextNode($t));
        $rot->replaceChild($h, $p);
    }
    return c2Html($dom, $rot);
}

/** Varje punkt i listan börjar med <strong>…</strong> följt av kolon (i eller efter strong). */
function c2Begreppslistor(string $html): string {
    if (stripos($html, '<ul') === false) return $html;
    [$dom, $rot] = c2Dom($html);
    $xp = new DOMXPath($dom);
    foreach (iterator_to_array($xp->query('.//ul', $rot)) as $ul) {
        $punkter = [];
        foreach ($ul->childNodes as $li) {
            if (!($li instanceof DOMElement)) { if (trim($li->textContent) !== '') continue 2; else continue; }
            if ($li->nodeName !== 'li') continue 2;
            $forsta = $li->firstChild;
            while ($forsta && $forsta instanceof DOMText && trim($forsta->textContent) === '') $forsta = $forsta->nextSibling;
            if (!($forsta instanceof DOMElement) || !in_array($forsta->nodeName, ['strong', 'b', 'em', 'i'], true)) continue 2;
            $begrepp = trim($forsta->textContent);
            $efter = $forsta->nextSibling;
            if (substr($begrepp, -1) === ':') $begrepp = rtrim(substr($begrepp, 0, -1));
            elseif ($efter instanceof DOMText && preg_match('/^\s*[:–—-]/u', $efter->textContent)) {
                $efter->textContent = preg_replace('/^\s*[:–—-]\s*/u', '', $efter->textContent);
            } else continue 2;
            if ($begrepp === '' || mb_strlen($begrepp) > 80) continue 2;
            $punkter[] = [$li, $forsta, $begrepp];
        }
        if (count($punkter) < 2) continue;

        $ol = $dom->createElement('ol');
        $ol->setAttribute('class', 'sl-terms');
        foreach ($punkter as [$li, $forsta, $begrepp]) {
            $ny = $dom->createElement('li');
            $term = $dom->createElement('strong', '');
            $term->appendChild($dom->createTextNode($begrepp));
            $term->setAttribute('class', 'sl-term');
            $ny->appendChild($term);
            $forklaring = $dom->createElement('span');
            $forklaring->setAttribute('class', 'sl-term-text');
            $li->removeChild($forsta);
            if ($li->firstChild instanceof DOMText) $li->firstChild->textContent = ltrim($li->firstChild->textContent);
            while ($li->firstChild) $forklaring->appendChild($li->firstChild);
            if (trim($forklaring->textContent) !== '') $ny->appendChild($forklaring);
            $ol->appendChild($ny);
        }
        $ul->parentNode->replaceChild($ol, $ul);
    }
    return c2Html($dom, $rot);
}

/**
 * Plocka ut föreslagna frågor till AI-läraren. Returnerar [html utan blocket, [frågor]].
 * Känner igen ett stycke/rubrik som börjar "Förslag" och nämner AI-läraren, följt av en lista.
 */
function c2AiForslag(string $html): array {
    if (!preg_match('/AI.{0,3}lärare/iu', $html)) return [$html, []];
    [$dom, $rot] = c2Dom($html);
    $fragor = [];
    foreach (iterator_to_array($rot->childNodes) as $n) {
        if (!($n instanceof DOMElement) || !in_array($n->nodeName, ['p', 'h2', 'h3', 'h4', 'h5', 'strong'], true)) continue;
        if (!preg_match('/^\s*(Förslag|Frågor att ställa)\b.*AI.{0,3}lärare/iu', $n->textContent)) continue;
        $lista = $n->nextSibling;
        while ($lista && $lista instanceof DOMText && trim($lista->textContent) === '') $lista = $lista->nextSibling;
        if (!($lista instanceof DOMElement) || !in_array($lista->nodeName, ['ul', 'ol'], true)) continue;
        foreach ($lista->getElementsByTagName('li') as $li) {
            $t = trim(preg_replace('/\s+/u', ' ', $li->textContent));
            if ($t !== '' && mb_strlen($t) <= 300) $fragor[] = $t;
        }
        $rot->removeChild($lista);
        $rot->removeChild($n);
    }
    return [c2Html($dom, $rot), array_slice($fragor, 0, 3)];
}

/** Lyft ut sidans första h2/h3 om den står först. Returnerar [rubriktext|null, html utan rubriken]. */
function c2LyftRubrik(string $html): array {
    [$dom, $rot] = c2Dom($html);
    $forsta = $rot->firstChild;
    while ($forsta && (($forsta instanceof DOMText && trim($forsta->textContent) === '') || $forsta instanceof DOMComment)) {
        $forsta = $forsta->nextSibling;
    }
    if ($forsta instanceof DOMElement && in_array($forsta->nodeName, ['h2', 'h3'], true) && trim($forsta->textContent) !== ''
        && $forsta->getAttribute('class') !== 'sl-subhead') {
        $rubrik = trim(preg_replace('/\s+/u', ' ', $forsta->textContent));
        $rot->removeChild($forsta);
        return [$rubrik, c2Html($dom, $rot)];
    }
    return [null, $html];
}

} // end function_exists guard
