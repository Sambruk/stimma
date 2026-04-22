<?php
/**
 * Tillgänglighetsredogörelse enligt DOS-lagen (2018:1937) och WCAG 2.1 AA.
 *
 * Publik sida — ingen auth krävs. Följer Diggs mall för redogörelser så att
 * innehållet lätt går att kopiera/läsa av tillsynsmyndigheten.
 *
 * URL: /tillganglighet.php (även via stimma.sambruk.se/tillganglighet.php)
 */
$page_title = 'Tillgänglighetsredogörelse — Stimma';
$lastUpdated = '2026-04-22';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="Tillgänglighetsredogörelse för Stimma enligt lagen om tillgänglighet till digital offentlig service.">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .content { max-width: 820px; margin: 0 auto; }
        .content h1 { color: #0d6efd; }
        .content h2 { color: #495057; margin-top: 2rem; border-bottom: 2px solid #dee2e6; padding-bottom: 0.5rem; }
        .content h3 { color: #495057; margin-top: 1.5rem; }
        .status-box { border-left: 4px solid #ffc107; background: #fff3cd; padding: 1rem 1.25rem; border-radius: 0.25rem; }
        .status-box.partial { border-color: #fd7e14; background: #ffe8d9; }
        .known-issue { background: white; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem; margin-bottom: 0.75rem; }
        .known-issue h4 { font-size: 1rem; margin-bottom: 0.5rem; color: #212529; }
        .known-issue .meta { font-size: 0.85rem; color: #6c757d; }
        .placeholder-text { background: #fff3cd; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; }
    </style>
</head>
<body>

<header class="bg-white shadow-sm py-3 mb-4">
    <div class="content px-3 d-flex justify-content-between align-items-center">
        <a href="/" class="text-decoration-none">
            <img src="images/stimma-logo.png" height="50" alt="Stimma — tillbaka till startsidan">
        </a>
        <a href="/" class="text-muted small text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>Till Stimma
        </a>
    </div>
</header>

<main class="content px-3 pb-5" id="main-content">

    <h1>Tillgänglighetsredogörelse för Stimma</h1>
    <p class="lead">Sambruk står bakom Stimma. Vi vill att så många som möjligt ska kunna använda webbplatsen. Den här sidan beskriver hur Stimma uppfyller lagen om tillgänglighet till digital offentlig service, eventuella kända brister och hur du kan rapportera problem.</p>

    <div class="alert alert-info">
        <strong>Webbplatsens adress:</strong> <a href="https://stimma.sambruk.se">https://stimma.sambruk.se</a><br>
        <strong>Ansvarig organisation:</strong> Föreningen Sambruk<br>
        <strong>Redogörelsen senast uppdaterad:</strong> <?= htmlspecialchars($lastUpdated) ?>
    </div>

    <h2>Hur tillgänglig är webbplatsen?</h2>
    <div class="status-box partial">
        <strong>Vi är medvetna om att delar av webbplatsen inte är helt tillgängliga.</strong>
        Se avsnittet "Innehåll som inte är tillgängligt" nedan för detaljer.
    </div>
    <p class="mt-3">Stimma är i huvudsak utvecklad enligt <abbr title="Web Content Accessibility Guidelines 2.1 nivå AA">WCAG 2.1 AA</abbr>. Vi arbetar kontinuerligt med att åtgärda identifierade brister och tar gärna emot synpunkter.</p>

    <h2>Vad du kan göra om du inte kan använda delar av webbplatsen</h2>
    <p>Om du behöver innehållet från Stimma i något annat format, exempelvis tillgänglig PDF, lättläst, ljudinspelning eller punktskrift, kan du kontakta oss på följande sätt:</p>
    <ul>
        <li>Mejla: <a href="mailto:kontakt@sambruk.se">kontakt@sambruk.se</a></li>
        <li>Kontaktperson: <span class="placeholder-text">[FYLL I: Kontaktperson eller funktionsbrevlåda]</span></li>
    </ul>
    <p>Svarstid är normalt <strong>tre arbetsdagar</strong>.</p>

    <h2>Rapportera brister i webbplatsens tillgänglighet</h2>
    <p>Vi strävar efter att hela tiden förbättra webbplatsens tillgänglighet. Om du upptäcker problem som inte är beskrivna på den här sidan, eller om du anser att vi inte uppfyller lagens krav, så meddela oss så att vi får veta att problemet finns.</p>
    <ul>
        <li>Skriv till: <a href="mailto:kontakt@sambruk.se">kontakt@sambruk.se</a></li>
        <li>Ange gärna vilken sida/funktion det gäller och vilken webbläsare/hjälpmedel du använder</li>
    </ul>

    <h2>Tillsyn</h2>
    <p>Myndigheten för digital förvaltning (Digg) har ansvaret för tillsyn över lagen om tillgänglighet till digital offentlig service. Om du inte är nöjd med hur vi hanterar dina synpunkter kan du <a href="https://www.digg.se/tdosanmalan" target="_blank" rel="noopener">kontakta Digg</a> och påtala det.</p>

    <h2>Teknisk information om webbplatsens tillgänglighet</h2>
    <p>Den här webbplatsen är <strong>delvis förenlig</strong> med nivå AA i standard Web Content Accessibility Guidelines (WCAG) version 2.1. Vilket innehåll som inte är tillgängligt beskrivs nedan.</p>

    <h2>Innehåll som inte är tillgängligt</h2>
    <p>Det innehåll som beskrivs nedan är på ett eller annat sätt inte helt tillgängligt.</p>

    <h3>Bristande förenlighet med lagkraven</h3>

    <div class="known-issue">
        <h4><i class="bi bi-exclamation-circle text-warning me-2"></i>AI-genererat innehåll kan sakna alternativ text på bilder</h4>
        <p class="mb-2">Kursbilder som genereras automatiskt av AI-tutorn får inte alltid en meningsfull alternativ text. Detta påverkar WCAG 1.1.1 (icke-textuellt innehåll).</p>
        <p class="meta"><strong>Planerad åtgärd:</strong> Vi utreder automatisk generering av alt-text via AI. Under tiden kan administratörer manuellt lägga till alt-text i kursredigeraren.</p>
    </div>

    <div class="known-issue">
        <h4><i class="bi bi-exclamation-circle text-warning me-2"></i>WYSIWYG-editor (TinyMCE) i administratörsvyn</h4>
        <p class="mb-2">Den rika textredigeraren som används av redaktörer för att skapa kursinnehåll är ett tredjepartsbibliotek (TinyMCE) och har delvis begränsad tangentbordsnavigering och skärmläsarstöd. Detta påverkar WCAG 2.1.1 (tangentbord) och 4.1.2 (namn, roll, värde).</p>
        <p class="meta"><strong>Påverkar främst:</strong> Administratörer och redaktörer. Slutanvändare (kursdeltagare) ser endast resultatet.</p>
    </div>

    <div class="known-issue">
        <h4><i class="bi bi-exclamation-circle text-warning me-2"></i>PDF-diplom</h4>
        <p class="mb-2">Diplom som genereras som PDF är inte alltid fullt tillgängliga — de saknar taggar för skärmläsare. Detta påverkar WCAG 1.3.1 (information och relationer) för PDF-innehåll.</p>
        <p class="meta"><strong>Planerad åtgärd:</strong> Vi undersöker möjligheten att erbjuda ett tillgängligt alternativ i form av webbsida med samma information.</p>
    </div>

    <div class="known-issue">
        <h4><i class="bi bi-exclamation-circle text-warning me-2"></i>Inbäddade YouTube-videor</h4>
        <p class="mb-2">Lektioner kan innehålla inbäddade YouTube-videor. Textning och transkription beror på den som publicerar kursen. Saknad textning påverkar WCAG 1.2.2 (textning) och 1.2.3 (syntolkning eller alternativ för inspelad media).</p>
        <p class="meta"><strong>Rekommendation:</strong> Kursskapare ansvarar för att tillhandahålla textad video och vid behov transkription i lektionens textinnehåll.</p>
    </div>

    <div class="known-issue">
        <h4><i class="bi bi-info-circle text-info me-2"></i>Kontrastförhållanden i vissa badges och statuselement</h4>
        <p class="mb-2">Vissa färgkodade badges (t.ex. "Publik", "Stegvis", org-taggar) kan i särskilda sammansättningar ha otillräcklig kontrast. Vi är medvetna om WCAG 1.4.3 (minimikontrast) och ser över färgpaletten.</p>
    </div>

    <h3>Oskäligt betungande anpassning</h3>
    <p>Sambruk åberopar för närvarande inte undantag för oskäligt betungande anpassning enligt 12 § lagen om tillgänglighet till digital offentlig service.</p>

    <h3>Innehåll som inte omfattas av lagen</h3>
    <p>Det innehåll som beskrivs här är inte fullt tillgängligt men undantas enligt 9 § lagen om tillgänglighet till digital offentlig service:</p>
    <ul>
        <li><strong>Kursinnehåll skapat av tredje part:</strong> Kurser som importeras eller kopieras från andra organisationer kan ha tillgänglighetsbrister som vi inte råder över. Den publicerande organisationen ansvarar för innehållets tillgänglighet.</li>
        <li><strong>Användaruppladdade filer:</strong> Bilder, videor och dokument som laddas upp av kursskapare omfattas av deras eget ansvar.</li>
        <li><strong>Administratörsgränssnittet (vissa delar):</strong> Ingenjörs- eller administrationsverktyg (t.ex. synkloggar, underhållsläge, migrationsskript) faller enligt 9 § utanför lagens krav.</li>
    </ul>

    <h2>Hur vi har testat webbplatsen</h2>
    <p>Sambruk har gjort en intern testning av Stimma. <span class="placeholder-text">[FYLL I: Eventuell extern granskning och datum, t.ex. "Senaste bedömningen gjordes <datum> av <utvärderare>"]</span>.</p>
    <p>I den interna testningen har vi använt:</p>
    <ul>
        <li>Automatiska verktyg (axe DevTools, WAVE, Lighthouse)</li>
        <li>Manuell tangentbordsnavigering</li>
        <li>Skärmläsartest med NVDA på Firefox och VoiceOver på Safari</li>
        <li>Granskning av färgkontraster mot WCAG 2.1 AA-nivå</li>
    </ul>
    <p><strong>Redogörelsen uppdaterades senast:</strong> <?= htmlspecialchars($lastUpdated) ?>.<br>
    <strong>Webbplatsen publicerades:</strong> <span class="placeholder-text">[FYLL I: datum för publicering]</span>.</p>

    <hr class="my-5">

    <p class="text-muted small">
        Denna redogörelse är utformad enligt Myndigheten för digital förvaltnings (Digg) mall för tillgänglighetsredogörelser.
        Källa: <a href="https://www.digg.se/digital-tillganglighet/gor-en-tillganglighetsredogorelse" target="_blank" rel="noopener">Digg: Gör en tillgänglighetsredogörelse</a>.
    </p>

    <div class="text-center mt-4">
        <a href="/" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left me-1"></i>Tillbaka till Stimma
        </a>
    </div>
</main>

<footer class="bg-white border-top py-3 mt-5">
    <div class="content px-3 text-center small text-muted">
        © <?= date('Y') ?> <a href="https://stimma.se" class="text-muted">Stimma.se</a> — Tillgänglighetsredogörelse enligt DOS-lagen (2018:1937)
    </div>
</footer>

</body>
</html>
