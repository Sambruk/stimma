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

/**
 * Nedladdningsbar instruktion för att skapa kurser med AI (Claude) och läsa in
 * dem via Admin → Importera kurs.
 *
 * Filen byggs av två delar:
 *   Del A — vägledning för redaktören (skriven här nedan)
 *   Del B — docs/KURSFORMAT.md, utan avsnitt markerade <!-- intern --> … <!-- /intern -->
 *           (kommandoradsverktyget, serveruppgifter och källkodsbilagan)
 * Formatet underhålls alltså bara på ett ställe: docs/KURSFORMAT.md.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';
require_once 'include/auth_check.php';

// auth_check släpper även in läsbehöriga — instruktionen är för dem som kan importera.
if (!$isAdmin && !$isEditor) {
    http_response_code(403);
    exit('Du har inte behörighet att ladda ner instruktionen.');
}

$formatFil = __DIR__ . '/../../docs/KURSFORMAT.md';
$format = is_readable($formatFil) ? file_get_contents($formatFil) : false;
if ($format === false) {
    http_response_code(500);
    exit('Kursformatet kunde inte läsas.');
}
$format = preg_replace('/^[ \t]*<!-- intern -->.*?<!-- \/intern -->[ \t]*\n/ms', '', $format); // hela block
$format = preg_replace('/<!-- intern -->.*?<!-- \/intern -->/s', '', $format);                  // inne i en rad
$format = preg_replace("/\n{3,}/", "\n\n", $format);
// Rubrikerna i formatdokumentet flyttas ner två nivåer, så att de hamnar under "Del B".
// Kodblock hoppas över (där kan # vara en kommentar).
$iKod = false;
$format = implode("\n", array_map(function ($rad) use (&$iKod) {
    if (str_starts_with($rad, '```')) { $iKod = !$iKod; return $rad; }
    return $iKod ? $rad : preg_replace('/^(#{1,4}) /', '##$1 ', $rad);
}, explode("\n", $format)));

$idag = date('Y-m-d');
$stimmaUrl = rtrim(defined('SITE_URL') ? SITE_URL : 'https://stimma.sambruk.se', '/');

$delA = <<<MD
# Skapa en Stimma-kurs med AI

*Instruktion för redaktörer och administratörer i Stimma. Hämtad {$idag}.*

> **Till Claude:** Del A är skriven för människan som ger dig uppdraget. Formatet du ska följa
> finns i **Del B**. Följ det exakt.

## Del A — så gör du

Du beskriver vilken kurs du vill ha. En AI-assistent (Claude) skriver kursen: lektioner, quizfrågor
och gärna bilder. Resultatet läser du in i Stimma, granskar och aktiverar. Den här filen är både din
handledning och instruktionen till Claude.

### Det här behöver du

- **Ett konto i Stimma** som redaktör eller administratör.
- **Claude** med möjlighet att skapa filer, till exempel claude.ai med filskapande påslaget eller
  Claude Code. Claude behöver kunna lämna ifrån sig en fil som du laddar ner.
- **Gärna ett bildverktyg** som Claude kan använda, till exempel fal.ai. Utan bildverktyg går det
  också bra: då skapar du bilderna i Stimma efteråt (steg 5).

### Steg 1 — Beskriv kursen

Skriv några rader om varje kurs:

- **Ämne och syfte:** vad deltagaren ska kunna efteråt.
- **Målgrupp:** till exempel "alla medarbetare", "chefer" eller "handläggare inom socialtjänsten".
- **Omfattning:** ungefär hur lång kursen ska vara, till exempel "15–20 minuter".
- **Underlag**, om du har något: egna riktlinjer, faktatexter eller länkar som kursen ska bygga på.
  Bygger kursen på en befintlig kurs, en presentation eller en manual med **skärmbilder eller
  diagram**, så lägger Claude in dem på rätt plats i lektionstexten. Lektionsbilden bredvid texten
  är kvar som vanligt. Bifoga gärna bildfilerna separat (till exempel en ZIP-fil med alla
  skärmbilder) om de är svåra att få ut ur underlaget. Använd bara bilder som ni har rätt att
  använda.

### Steg 2 — Ge uppdraget till Claude

Bifoga **den här filen** och klistra in texten nedan. Fyll i det som står inom hakparentes.

```text
Du ska skapa en kurs för Stimma. Den bifogade filen beskriver kursformatet.
Följ Del B exakt och bestäm själv struktur, innehåll, bilder och quiz enligt riktlinjerna där.

Kursbeskrivning: [ämne och syfte]
Målgrupp: [målgrupp]
Omfattning: [ungefärlig längd]
Underlag: [bifogade filer eller "inget särskilt"]

Om underlaget innehåller skärmbilder, diagram eller andra illustrationer som förklarar innehållet:
lägg in dem i lektionstexten på motsvarande plats enligt avsnitt 5.6, oförändrade och med alt-text.
Lektionsbilderna skapas som vanligt. Leverera då alltid en ZIP-fil, med illustrationerna i images/.

Om du har ett bildverktyg: skapa bilderna och leverera en ZIP-fil med course.json i roten
och bilderna i mappen images/ (avsnitt 3 och 7A).
Om du inte har ett bildverktyg: leverera course.json och skriv ett image_wish för kursomslaget
och för varje lektion (avsnitt 7B).

Avsluta med rapporten enligt avsnitt 11.
```

**Flera kurser på en gång:** lista beskrivningarna under varandra och be om **en fil per kurs**.

**Personuppgifter och känsligt material:** lägg inte in personuppgifter eller material som omfattas av
sekretess i uppdraget, om inte din organisation har godkänt det.

### Steg 3 — Läs in kursen i Stimma

1. Ladda ner filen som Claude skapade: en ZIP-fil, eller `course.json` om den saknar bilder.
2. Logga in på {$stimmaUrl} och gå till **Admin → Kurser → Importera kurs**.
3. Välj filen och klicka på **Importera kurs**.
4. **Röd ruta:** något i filen är fel och ingenting har sparats. Kopiera felen till Claude, be om en
   rättad fil och importera igen.
5. **Grön ruta:** kursen är inläst. Den skapas alltid som **inaktiv** och syns inte för deltagarna
   förrän du aktiverar den.

Importera inte samma fil två gånger, det skapar en kopia av kursen.

### Steg 4 — Granska kursen

Du ansvarar för innehållet. Öppna kursen och gå igenom den som deltagare (förhandsgranska lektionerna):

- **Fakta:** stämmer innehållet med lagar, regler och era egna riktlinjer?
- **Quiz:** är de rätta svaren verkligen rätt, och är frågorna begripliga?
- **Ton och språk:** passar texten målgruppen?
- **Länkar och källor:** fungerar länkarna och är källorna trovärdiga?
- **Bilder:** inga bilder med text, förvrängda händer eller motiv som kan missförstås.
- **Skärmbilder och diagram i texten:** står de på rätt plats, syns detaljerna, och saknas någon
  som rapporten tar upp? En bild som saknas lägger du in med bildknappen i lektionsredigeraren.

Ändra direkt i redigeraren, eller be Claude om en ny version och importera den (radera då den
första kursen).

### Steg 5 — Skapa bilder som saknas

Den gröna rutan efter importen listar de bilder som saknas och vad de ska visa. Öppna kursen eller
lektionen, skriv önskemålet i fältet **"Vad ska bilden visa?"** och klicka på **Generera AI-bild**
(för kursomslaget: knappen **AI** vid kursbilden). Bilderna kostar högst ett par kronor styck.

### Steg 6 — Aktivera

När kursen är granskad: aktivera den under **Admin → Kurser**.

---

## Del B — kursformatet

MD;

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="stimma-skapa-kurs-med-ai-' . $idag . '.md"');
header('Cache-Control: no-store');
echo $delA, "\n", $format;
