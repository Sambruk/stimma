# Kursformatet i Stimma — instruktion för att skapa kurser

Det här dokumentet beskriver hur en kurs i Stimma är uppbyggd och hur en ny kurs kommer in i
systemet. Det är skrivet för att Claude ska kunna skapa kompletta kurser **på egen hand** utifrån en
kort beskrivning, och själv besluta om struktur, innehåll, bilder och quiz.

Allt här är kontrollerat mot koden och databasen 2026-10-01, bilderna i lektionstexten (avsnitt 5.6)
2026-10-29. Inläsningen i avsnitt 9 är testad
skarpt, och alla frågetyper i avsnitt 6 rättas korrekt av Stimmas egen rättningsfunktion.

---

## 1. Arbetsflödet i korthet

För varje kursbeskrivning:

1. **Planera kursen:** målgrupp, lärandemål, antal lektioner och vad varje lektion ska lära ut
   (avsnitt 8). Finns det ett underlag: gå igenom dess illustrationer och bestäm vilka som ska in i
   lektionstexten (avsnitt 5.6).
2. **Skriv ett kurspaket:** `course.json` i formatet `stimma-kurspaket-1` (avsnitt 3–7, fullständigt
   exempel i avsnitt 10).
3. **Skapa bilderna:** gör det med det bildverktyg du har tillgång till, och lägg dem i mappen
   `images/` (avsnitt 7). Lägg också dit illustrationerna från underlaget (avsnitt 5.6). Packa sedan
   `course.json` och `images/` i en ZIP-fil.
4. **Läs in:** via **Admin → Importera kurs** (avsnitt 9A).<!-- intern --> Arbetar du på servern
   finns också kommandoradsverktyget (avsnitt 9B).<!-- /intern --> Allt kontrolleras först. Vid fel sparas ingenting, och du får
   alla fel i en lista. Rätta och försök igen. Kursen skapas **alltid som inaktiv**.
5. **Rapportera:** ange kurs-id, antal lektioner och frågor, vilka bilder som saknas och allt du är
   osäker på (avsnitt 11).

Aktivera aldrig kursen själv. En människa granskar och aktiverar.

---

## 2. Så är en kurs uppbyggd

```
Kurs
├── Infosida          (valfri, t.ex. "Välkommen" — räknas inte som lektion, inget quiz)
├── Lektion 1         (innehåll i sidor, ev. video, ev. AI-chatt, quizfrågor)
│   └── Infosida      (valfri fördjupning som hör till lektion 1)
├── Lektion 2
└── …
```

- **Kurs:** titel, beskrivning, nivå, bildstil, omslagsbild och avslutstext.
- **Lektion** (`lesson_type: "lesson"`): det deltagaren arbetar sig igenom. Varje lektion har
  innehåll uppdelat i bläddringsbara sidor, eventuellt en video och en AI-chatt, och avslutas med
  quizfrågor. Lektionen räknas som klar när quizet är godkänt.
- **Infosida** (`lesson_type: "info_page"`): ren information. Den räknas inte i "X av Y lektioner",
  har aldrig quiz och får en "Fortsätt"-knapp. En infosida kan vara fristående eller höra till en
  lektion (`belongs_to`).
- **Quizfråga:** elva typer finns i Stimma, varav nio går att skapa i ett kurspaket (avsnitt 6).

Ordningen i paketet är den ordning deltagaren ser.

**Moduler finns inte.** Det finns en `admin/delete_module.php`, men den pekar på en tabell som inte
finns i databasen. Använd infosidor som mellanrubriker om kursen behöver delas in i block.

---

## 3. Kurspaketet — översikt

Ett kurspaket är en **ZIP-fil**:

```
min-kurs.zip
├── course.json        ← kursen, se nedan
└── images/            ← färdiga bilder som course.json pekar ut med image_file
    ├── omslag.webp
    ├── lektion-1.webp
    └── …
```

`course.json` har formen:

```json
{
  "format": "stimma-kurspaket-1",
  "course": { … },
  "lessons": [ { … }, { … } ]
}
```

- **`format`** måste vara exakt `"stimma-kurspaket-1"`. Det är så importen känner igen ett
  kurspaket.
- **Text:** all text är UTF-8 och skrivs på svenska.
- **Utan bilder:** ett paket utan färdiga bilder kan också vara en ensam JSON-fil, men då kan
  `image_file` inte användas.
- **Undermapp:** allt får också ligga i **en enda** undermapp, t.ex. `min-kurs/course.json` och
  `min-kurs/images/`. Så blir det när man högerklickar på kursmappen och väljer "Komprimera".
  Skräpfiler från macOS och Windows (`__MACOSX/`, `.DS_Store`, `Thumbs.db`) ignoreras. Två kursmappar
  i samma ZIP-fil går inte, utan det blir en ZIP-fil per kurs.

---

## 4. Kursens fält (`course`)

| Fält | Krav | Beskrivning |
|---|---|---|
| `title` | **Krävs**, 3–255 tecken, ingen HTML | Kursens namn. Kort och konkret, t.ex. "Informationssäkerhet i vardagen". |
| `description` | **Krävs**, 20–2000 tecken, ingen HTML | Visas på kurssidan och i katalogen. 2–4 meningar: vad deltagaren lär sig och för vem kursen är. |
| `difficulty_level` | `beginner` \| `intermediate` \| `advanced` | Standard är `beginner`. |
| `duration_minutes` | Heltal | Beräknas automatiskt som summan av lektionernas tid om fältet utelämnas. Utelämna det. |
| `prerequisites` | Text | Förkunskaper. Utelämna om inga behövs. |
| `image_style` | `illustration` \| `foto` \| `abstrakt` | Bildstil för omslaget **och alla lektionsbilder**. Se avsnitt 7. Standard är `illustration`. |
| `image_file` | Filnamn i `images/` | Färdig omslagsbild, se avsnitt 7. Går före `image_wish`. |
| `image_wish` | Högst 300 tecken | Vad omslagsbilden föreställer. Används för att skapa bilden i Stimma när `image_file` saknas, se avsnitt 7. |
| `completion_content` | HTML enligt avsnitt 5 | Texten på avslutssidan när kursen är klar. Utelämnas den visas en standardtext. |
| `allow_quiz_retry` | `true` \| `false` | Om deltagaren får göra om ett underkänt quiz. Standard är `true`. Ändra inte. |

Följande ställs in av en admin efteråt och ska **inte** ingå i paketet: aktivering, synlighet för
andra organisationer, publik registrering, stegvis utskick, deadline och kursintyg.

---

## 5. Lektionens fält (`lessons[]`)

| Fält | Krav | Beskrivning |
|---|---|---|
| `key` | **Krävs**, unik, `a-z 0-9 _ -`, högst 40 tecken | Din egen nyckel, t.ex. `"phishing"`. Används av `belongs_to` och i verktygets resultat. |
| `lesson_type` | `lesson` \| `info_page` | Standard är `lesson`. Kursen måste ha minst en `lesson`. |
| `title` | **Krävs**, 2–250 tecken, ingen HTML | Lektionens namn. |
| `estimated_duration` | Heltal 1–120 (minuter) | Realistisk tid inklusive quiz. Standard är 5. |
| `content` | **Krävs**, HTML enligt nedan | Lektionens innehåll. |
| `belongs_to` | `key` för en **tidigare** lektion | Bara för `info_page`: kopplar infosidan till den lektionen. |
| `image_file` | Filnamn i `images/` | Färdig lektionsbild, se avsnitt 7. Går före `image_wish`. |
| `image_wish` | Högst 300 tecken | Motiv för lektionsbilden, om den ska skapas i Stimma. Utelämna både `image_file` och `image_wish` för att inte få någon bild. |
| `video_url` | YouTube-länk | `https://www.youtube.com/watch?v=…` eller `https://youtu.be/…`. Se avsnitt 5.4. |
| `video_position` | `top` \| `bottom` | Var videon visas om innehållet saknar `[video]`-markör. Standard är `bottom`. |
| `ai_chat` | `{ "instruction": "…", "prompt": "…" }` | Slår på AI-chatten för lektionen. Se avsnitt 5.5. |
| `quiz_pass_mode` | `require_all_correct` \| `any_result` | Standard är `require_all_correct`: alla svar måste vara rätt för att lektionen ska bli klar. Ändra inte utan skäl. |
| `questions` | Lista med frågor, avsnitt 6 | Bara för `lesson`. En `info_page` med frågor stoppas. |

### 5.1 Tillåten HTML

Innehållet saneras när det visas. Allt som saneringen skulle ta bort stoppas redan av verktyget, så
att det deltagaren ser blir exakt det du skrev.

- **Taggar:** `p`, `h3`, `h4`, `h5`, `h2`, `ul`, `ol`, `li`, `strong`, `em`, `b`, `i`, `u`, `s`,
  `sub`, `sup`, `br`, `hr`, `blockquote`, `span`, `a`, `table`, `thead`, `tbody`, `tr`, `th`, `td`
  och `div` (bara med klasserna nedan).
- **`img`** bara i lektionens `content`, och bara enligt avsnitt 5.6.
- **Använd inte** `h1` (lektionstiteln är redan sidans rubrik), `script`, `style`, `iframe`,
  händelseattribut eller andra HTML-kommentarer än sidbrytningen.
- **Rubriker:** `h3` för avsnitt och `h4` för underrubriker. Hoppa inte över nivåer.
- **Länkar:** bara fullständiga `https://`-adresser. De öppnas automatiskt i ny flik. Länka bara
  till sidor du vet finns (myndigheter, lagtext på riksdagen.se och liknande). Hitta aldrig på
  adresser.
- **Bilder i löptexten** (skärmbilder, diagram) beskrivs i avsnitt 5.6.

### 5.2 Innehållsblock

Stimma har sex färdigformaterade block. De är det som gör lektionerna överskådliga. Använd dem.

```html
<div class="lesson-intro"><p>Kort introduktion: vad lektionen handlar om och varför det spelar roll.</p></div>
<div class="lesson-tip"><strong>Tips:</strong> Ett konkret råd.</div>
<div class="lesson-info"><strong>Visste du att…</strong> En fördjupande fakta.</div>
<div class="lesson-example"><strong>Exempel:</strong> Ett vardagsnära exempel.</div>
<div class="lesson-warning"><strong>Obs!</strong> Något som ofta går fel.</div>
<div class="lesson-summary"><h4>Sammanfattning</h4><ul><li>Nyckelpunkt</li></ul></div>
```

Andra klasser på `div` stoppas av verktyget.

### 5.3 Sidbrytning

Lektionen delas i bläddringsbara sidor med markören `<!-- pagebreak -->`, skriven exakt så.
Varje sida ska rymmas på skärmen utan att man behöver scrolla.

- **Sida 1:** `lesson-intro` och en överblick.
- **Därefter:** en `<!-- pagebreak -->` före varje `h3`-avsnitt.
- **Sista sidan:** en `<!-- pagebreak -->` före `lesson-summary`, så att sammanfattningen hamnar
  ensam sist.
- **Omfång:** 80–180 ord per sida, 4–7 sidor per lektion.
- **Inte inuti block:** bryt aldrig sidan inuti ett `lesson-*`-block.

Video, AI-chatt och varje quizfråga läggs automatiskt på egna sidor efter innehållet.

### 5.4 Video

- Bara YouTube. Lokala videofiler kan inte ingå i ett paket.
- Skriv `[video]` i innehållet där spelaren ska visas, högst en gång. Utan markören visas videon
  på en egen sida (`video_position`).
- **Använd bara en video om du har kontrollerat den.** En länk kan se giltig ut men vara borttagen
  eller spärrad för inbäddning, och då ser deltagaren "This video is unavailable". Kontrollera med
  `curl -s -o /dev/null -w '%{http_code}' 'https://www.youtube.com/oembed?url=<länken>&format=json'`:
  - **200:** videon finns och inbäddning är inte avstängd.
  - **401:** inbäddning är avstängd. Använd inte videon.
  - **400 eller 404:** videon finns inte.

  Kontrollen visar inte att videon faktiskt spelas. Det kan regionspärrar eller ålderskrav stoppa.
  Ta därför upp varje video i rapporten, så att den som granskar kursen provspelar den. Hitta aldrig
  på videolänkar. De flesta kurser klarar sig utan video.

### 5.5 AI-chatt

`ai_chat` slår på en chattruta i lektionen där deltagaren kan ställa frågor till en AI.

- **`instruction`** visas för deltagaren ovanför chatten, t.ex. *"Fråga gärna om fler exempel från
  din egen verksamhet."*
- **`prompt`** är en dold instruktion till AI:n, t.ex. *"Du hjälper medarbetare i en kommun att
  förstå phishing. Svara kort, på svenska och med konkreta exempel."*

Använd AI-chatten där deltagare rimligen har egna följdfrågor, till exempel lektioner om
tillämpning, regelverk eller den egna verksamheten. Varje fråga i chatten kostar AI-tokens, så slå
inte på den i varje lektion.

### 5.6 Bilder i lektionstexten (skärmbilder, diagram)

En lektion kan ha två sorters bilder, och de är oberoende av varandra:

| | Lektionsbild | Bild i lektionstexten |
|---|---|---|
| **Fält** | `image_file` på lektionen | `<img>` i `content` |
| **Visas** | I spalten bredvid texten, på alla sidor | På den plats i texten där den står |
| **Syfte** | Stämning och igenkänning | Bär information: skärmbild, diagram, schema, blankett |
| **Källa** | Skapas med bildverktyg (avsnitt 7) | Tas från underlaget, oförändrad |

En bild i texten ersätter **aldrig** lektionsbilden. Lektionen får sin lektionsbild som vanligt.

#### När du läser underlaget

Om underlaget är en befintlig kurs, en presentation, en manual eller ett dokument med bilder: gå
igenom **varje illustration** innan du skriver lektionerna och avgör vad den är.

- **Innehållsbild:** skärmbilder av ett system, diagram, flödesscheman, tabeller som bild,
  exempel på blanketter eller mejl. Den förklarar något som texten hänvisar till ("klicka på knappen
  längst upp till höger", "så här ser rutan ut"). **Den ska med** i lektionstexten.
- **Dekorativ bild:** stämningsbilder, ikoner, logotyper, bakgrunder och illustrationer som inte
  tillför något utöver texten. **Den tas inte med.** Lektionsbilden fyller den rollen.

För varje innehållsbild:

1. **Ta bilden som den är.** Spara originalet från underlaget i `images/`. Beskär bara bort tomma
   kanter eller sådant som inte hör till bilden (sidhuvud, sidnummer). Skapa **aldrig** en ny eller
   "förbättrad" version med ett bildverktyg, eftersom en AI-tolkad skärmbild visar ett system som inte
   finns. Regeln "ingen text i bilden" gäller bara bilder du skapar själv. Skärmbilder innehåller text.
2. **Placera den där underlaget har den:** direkt efter stycket som hänvisar till den, på samma sida
   (`<!-- pagebreak -->`) som texten den hör till. Behåll underlagets ordning.
3. **Skriv en alt-text** som säger vad bilden visar och vad deltagaren ska lägga märke till, t.ex.
   *"Inloggningsrutan med fälten Användarnamn och Lösenord och knappen Logga in nere till höger"*.
   Skriv inte "Bild" eller "Skärmbild 3".
4. **Kontrollera personuppgifter.** Syns namn, personnummer, e-postadresser eller ärendeuppgifter
   för verkliga personer i en skärmbild: ta inte med bilden, utan ta upp den i rapporten.

Om en bild inte går att få ut ur underlaget, till exempel för att du bara har texten, så skriver du
**ingen** platshållare i kursen. Ta i stället upp den i rapporten (avsnitt 11) med lektion, sida,
texten den står efter och vad den visar, så att redaktören kan lägga in den i lektionsredigeraren.

#### Så skrivs bilden

```html
<h3>Logga in</h3>
<p>Öppna systemet och fyll i dina uppgifter i inloggningsrutan.</p>
<p><img src="images/inloggning.png" alt="Inloggningsrutan med fälten Användarnamn och Lösenord och knappen Logga in nere till höger" class="img-lg img-center"></p>
<p>Klicka på <strong>Logga in</strong>.</p>
```

- **`src`** är `images/` följt av filnamnet, och filen ska ligga i ZIP-filens `images/`. Bilder
  på andra adresser stoppas. Stimma byter namn och sökväg vid inläsningen.
- **`alt`** krävs.
- **`class`** styr storlek och placering. Det är samma val som i lektionsredigerarens bilddialog:

  | `class` | Används till |
  |---|---|
  | `img-lg img-center` | **Skärmbilder, standard.** 75 % av bredden, centrerad. |
  | `img-full` | Breda skärmbilder och diagram där detaljerna måste synas. |
  | `img-md img-center` | Mindre bilder, t.ex. en dialogruta. |
  | `img-sm img-center` | Små detaljer, t.ex. en enskild knapp eller ikon. |
  | `img-md img-left`, `img-md img-right`, `img-sm img-left`, `img-sm img-right` | Bild bredvid texten. Använd sparsamt, eftersom bilden hamnar under texten i mobilen. |

- **`width` och `height`** (heltal i pixlar) får anges men behövs inte. Andra attribut stoppas.
- **Lägg `<img>` i ett eget `<p>`**, inte inuti ett `lesson-*`-block.
- **Format och storlek:** samma som i avsnitt 7A (JPG, PNG eller WebP, högst 5 MB). PNG passar
  skärmbilder bäst. En bild kan användas på flera ställen.
- **Bildtext** behövs sällan. Behövs den skriver du den som ett vanligt stycke under bilden.
- **Omfång:** en sida med en skärmbild ska ha mindre text, ungefär 40–100 ord, så att bilden och
  texten ryms utan att man behöver scrolla. Högst två bilder per sida. Hellre en sidbrytning till.
- **Quizfrågor** kan inte ha bilder. Bildfrågetyperna kräver uppladdning (avsnitt 6.1).

---

## 6. Quizfrågor (`questions[]`)

Varje fråga har formen:

```json
{ "question_type": "single_choice", "question_text": "Frågan?", "quiz_data": { … }, "points": 1 }
```

- `question_text` får innehålla enkel HTML enligt 5.1, men oftast räcker ren text.
- `points` är valfritt, med standardvärdet 1.
- Alla index är **0-baserade**.

### 6.1 De nio typerna

| Typ | Visas som | `quiz_data` |
|---|---|---|
| `single_choice` | Radioknappar, ett rätt | `{ "answers": ["A", "B", "C"], "correct": 1 }` — minst 2 alternativ, unika |
| `multiple_choice` | Kryssrutor, flera rätt | `{ "answers": ["A", "B", "C", "D"], "correct": [0, 2] }` — minst 3 alternativ. Alla rätta måste kryssas och inga fel. |
| `true_false` | Sant / Falskt | `{ "correct": true }` — ett riktigt `true`/`false`, inte en sträng |
| `fill_blank` | Lucktext | `{ "template": "Sverige gick med i EU år {{0}}.", "blanks": [ { "answers": ["1995"], "case_sensitive": false } ] }` — en markör `{{n}}` per lucka, numrerade från 0. Ange alla rimliga stavningar i `answers`. |
| `order` | Dra till rätt ordning | `{ "items": ["Steg 1", "Steg 2", "Steg 3"] }` — listan skrivs i **rätt** ordning. Stimma blandar den. Minst 3. |
| `match_pairs` | Dra höger till vänster | `{ "pairs": [ { "left": "MSB", "right": "Samhällsskydd" } ] }` — minst 3 par, unika högerled |
| `categorize` | Dra till kategori | `{ "categories": ["Personuppgift", "Inte personuppgift"], "items": [ { "text": "Personnummer", "category": 0 } ] }` — minst 2 kategorier och 4 objekt |
| `numeric` | Talfält | `{ "correct": 72, "tolerance": 0, "unit": "timmar" }` — godkänt om svaret ligger inom ± `tolerance` |
| `short_text` | Kort textsvar | `{ "answers": ["IMY", "Integritetsskyddsmyndigheten"], "case_sensitive": false }` — exakt matchning efter trimning. Lista alla godtagbara varianter. |

`image_choice` och `hotspot` finns i Stimma men kräver uppladdade bilder och stöds inte i
kurspaket.

### 6.2 Regler för bra frågor

- **2–4 frågor per lektion.** De ska pröva lektionens viktigaste lärandemål, inte detaljer.
- **Bara det som lektionen lär ut.** Frågan måste gå att besvara med lektionens innehåll. Med
  `require_all_correct` fastnar deltagaren annars.
- **Variera typerna inom kursen.** `single_choice` som grund, men använd `order` för processer,
  `match_pairs` och `categorize` för begrepp, och `true_false` för vanliga missuppfattningar.
- **Sprid rätt svar** över alla positioner. Inte alltid B.
- **Rimliga distraktorer:** felaktiga alternativ ska vara troliga missförstånd, inte skämt.
- **Undvik** "Alla ovanstående" och "Inget av ovanstående", dubbla negationer och kuggfrågor.
- **Var försiktig med `short_text` och `fill_blank`.** Ett korrekt svar med annan stavning
  underkänns. Använd dem bara när svaret är ett entydigt ord, namn eller tal, och lista varianter.
- **Var sparsam med `numeric`.** Använd den bara när talet uttryckligen står i lektionen.

---

## 7. Bilder

Claude bestämmer bildstil och motiv för kursomslaget och varje lektion. Bilderna kan komma in på två
sätt. Det första är att föredra.

Avsnittet gäller kursomslaget och lektionsbilderna. Skärmbilder och andra illustrationer från
underlaget hamnar i lektionstexten och följer avsnitt 5.6.

### 7A. Färdiga bilder i paketet (`image_file`)

Skapa bilderna med det bildverktyg du har, lägg dem i `images/` och peka ut dem:

```json
"course":  { …, "image_file": "omslag.webp" },
"lessons": [ { "key": "phishing", …, "image_file": "phishing.webp" } ]
```

| Bild | Format | Visas |
|---|---|---|
| **Kursomslag** | **Liggande 3:2, helst 1536 × 1024** | Banner på kurssidan och kort i katalogen. Kanterna kan beskäras, så håll motivet i mitten. |
| **Lektionsbild** | **Kvadratisk, helst 1024 × 1024** | I en smal spalt till vänster om lektionstexten, ungefär en tredjedel av bredden. |

- **Filformat:** JPG, PNG eller WebP (WebP ger minst filer). Högst **5 MB per bild** och 50 MB för hela
  ZIP-filen.
- **Filnamn:** bara själva namnet i `image_file`, t.ex. `"phishing.webp"`, inte `"images/phishing.webp"`.
  Små bokstäver, siffror och bindestreck. Stimma byter namn vid inläsningen.
- **Samma bild** får användas av flera lektioner.
- **Kontroll vid import:** att bilden finns, är en riktig bild och inte är för stor. Bilder i
  `images/` som inget pekar på ger en varning.

**Så blir bilderna bra.** Samma regler gäller som när Stimma skapar bilderna själv:
- **Motiv:** en konkret vardagsscen i svensk offentlig miljö, med vänliga ansiktsdrag.
- **Ingen text** i bilden, inga logotyper och inga varumärken.
- **Sammanhållen stil:** samma stil i hela kursen, och den ska motsvara `image_style`.
- **Inga verkliga, igenkännbara personer.**

#### Skapa bilderna med fal.ai

Skapa alla bilder med fal.ai och lägg dem i kurspaketet. Använd inte `image_wish` som ersättning
för en bild.

- **Modell:** `fal-ai/flux/schnell`. Använd `fal-ai/flux/dev` om bilderna behöver högre kvalitet.
- **Kursomslag:** `"image_size": {"width": 1536, "height": 1024}`.
- **Lektionsbild:** `"image_size": "square_hd"` (1024 × 1024). Infosidor får ingen bild.
- **Prompten** skrivs på engelska och beskriver en konkret vardagsscen i svensk offentlig miljö, med
  vänliga ansiktsdrag. Använd samma stilbeskrivning i alla bilder i kursen, så att den motsvarar
  `image_style`. Avsluta alltid prompten med: `no text, no letters, no logos`.
- **Ladda ner** varje bild direkt från URL:en i svaret, eftersom länken är tillfällig. Spara den i
  `images/` med ett kort filnamn, till exempel `omslag.jpg` och `lektion-1.jpg`.
- **Titta på varje bild.** Om den innehåller text, förvrängda händer eller fel motiv skapar du en ny.
- **Ange filnamnet** i `image_file` på kursen och på varje lektion. Behåll motivbeskrivningen på
  svenska i `image_wish`.
- **Packa** varje kurs som en ZIP-fil med `course.json` i roten och bilderna i `images/`, enligt
  avsnitt 3.

**Om inget fal-verktyg finns:** anropa fal.ai:s API direkt.
- **Adress:** `https://fal.run/fal-ai/flux/schnell` med POST.
- **Rubrik:** `Authorization: Key <FAL_KEY>`.
- **Kropp:** JSON-kroppen `{"prompt": …, "image_size": …}`.
- **Svar:** bild-URL:en finns i `images[0].url`.

<!-- intern -->
Går anropen via Sambruks MCP-gateway i stället för direkt till fal.ai anges omslagets storlek som
`"extra": {"image_size": {"width": 1536, "height": 1024}}`, eftersom gatewayens verktyg bara tar de
fasta formaten i `image_size`.
<!-- /intern -->

### 7B. Bilder som Stimma skapar (`image_wish`)

Saknas `image_file` men finns `image_wish` kan Stimma skapa bilden med sin egen bildtjänst (OpenAI
gpt-image), med samma prompt som knapparna i admin:

<!-- intern -->
- **Med kommandoradsverktyget och `--bilder`** skapas bilderna direkt vid inläsningen (avsnitt 9B).
<!-- /intern -->
- **Med webbimporten** skapas inga bilder vid importen. Resultatet listar i stället varje bild som
  saknas tillsammans med önskemålet. En redaktör eller admin skapar dem sedan i redigeraren: önskemålet skrivs i
  fältet "Vad ska bilden visa?", och sedan klickar man på "Generera AI-bild" i lektionsredigeraren
  eller "AI" vid kursbilden i kursredigeraren.

| Bild | Format | Ungefärlig kostnad |
|---|---|---|
| Kursomslag | 1536 × 1024, hög kvalitet, WebP | ~17 US-cent |
| Lektionsbild | 1024 × 1024, medelkvalitet, WebP | ~3–5 US-cent |

**`image_wish`** beskriver bara **motivet**, högst 300 tecken. Stimma lägger själv till stil, format
och krav som att bilden inte ska innehålla text:

- Bra: *"En socialsekreterare som låser sin datorskärm innan hen går till ett möte"*
- Bra: *"Två kollegor vid ett fikabord som tittar på ett misstänkt sms i en mobiltelefon"*
- Dåligt: *"Säkerhet"*, *"En bild om lektion 3"*, eller ett motiv som innehåller text eller logotyper.

Ange gärna `image_wish` även när du har `image_file`. Då går bilden att skapa om i Stimma senare.

### För båda vägarna

**`image_style`** gäller hela kursen:
- `illustration`: varm, handritad stil i varma färger. Passar de flesta kurser.
- `foto`: fotografisk stil. Passar kurser om konkreta miljöer och yrken.
- `abstrakt`: lugn, grafisk stil. Passar abstrakta ämnen som regelverk och strategi.

Stilen styr Stimmas bilder, och den som redigerar kursen i efterhand ser den. Gör därför egna bilder
i samma stil.

Infosidor behöver sällan bild.

---

## 8. Pedagogiska riktlinjer

Det här är de beslut Claude fattar själv. Riktmärkena kommer från Stimmas egen AI-generator och
från befintliga kurser.

**Målgrupp.** Medarbetare i kommuner, regioner och myndigheter, oftast utan djupa förkunskaper i
ämnet. Skriv på **klarspråk**: korta meningar, aktiva verb, förklara facktermer första gången de
används och tilltala läsaren med "du".

**Omfång**
- **Antal lektioner:** 4–10 för en vanlig kurs. Färre om ämnet är smalt, upp till cirka 15 för
  breda ämnen. Dela hellre upp i två kurser än att göra en mycket lång.
- **Tid:** 5–15 minuter per lektion, inklusive quiz.
- **Tidsuppgift för deltagaren:** ange tid **per lektion**, aldrig total kurstid. Skriv inte att
  man kan göra lektionerna i valfri takt eller "en lektion i taget" – kursen kan köras i stegvist
  läge där lektionerna släpps en i taget. Använd samma formuleringar som baskurserna:
  - I `description`: "Räkna med ungefär 5–10 minuter per lektion."
  - I välkomstlektionen, eget stycke efter upplägget: "Hur lång tid en lektion tar beror på flera
    saker, till exempel hur van du är vid ämnet, hur mycket du går tillbaka och läser om och om du
    kan sitta ostört. En tumregel är 5–10 minuter per lektion."
- **Textmängd:** 400–600 ord per lektion, fördelade på 4–7 sidor.

**Struktur**
- **Kursen:** gärna en inledande infosida ("Välkommen", med kursens mål och upplägg), sedan
  lektionerna i en logisk progression från grunder och begrepp via tillämpning till fördjupning.
  Avsluta med en lektion som knyter ihop helheten.
- **Varje lektion:** `lesson-intro`, minst två `h3`-avsnitt, minst ett `lesson-example`, minst ett
  `lesson-tip` eller `lesson-info`, och sist `lesson-summary`. Använd `lesson-warning` för vanliga
  misstag.
- **Ett lärandemål per lektion.** Titeln ska säga vad man lär sig, t.ex. "Så känner du igen ett
  nätfiskemejl", inte "Del 2".

**Fakta**
- Skriv bara sådant du är säker på. Om kursen bygger på lagar, regelverk eller myndigheters råd:
  ange källan i texten ("enligt MSB…", "dataskyddsförordningen (GDPR) säger…") och länka till den
  om du vet adressen säkert.
- Hitta aldrig på siffror, datum, paragrafer, citat, personer eller organisationer.
- Det du är osäker på tar du upp i rapporten (avsnitt 11) i stället för att gissa i kursen.

**Tillgänglighet**
- Rubrikerna ska vara i rätt ordning.
- Länktexten ska beskriva målet. Skriv inte "klicka här".
- Färg får aldrig vara det enda sättet att förmedla information.
- Använd tabeller bara för tabelldata, aldrig för layout.

---

## 9. Läsa in kursen

<!-- intern -->
Två vägar, med exakt samma regler. Båda bygger på `include/kurspaket.php`.
<!-- /intern -->

### 9A. Admin → Importera kurs<!-- intern --> (utan serveråtkomst)<!-- /intern -->

1. Logga in på https://stimma.sambruk.se som admin eller redaktör. Gå till **Admin → Kurser →
   Importera kurs**.
2. Välj ZIP-filen, eller JSON-filen om paketet saknar bilder, och klicka på **Importera kurs**.
3. **Om något är fel** visas en röd ruta med alla fel. Ingenting har sparats. Rätta paketet och
   importera igen.
4. **När importen lyckas** visas en grön ruta med:
   - antal lektioner, infosidor, quizfrågor och bilder,
   - **bilder som saknas**, tillsammans med sina önskemål,
   - eventuella varningar,
   - en knapp till kursen.

Kursen hamnar i den inloggades organisation, och den inloggade blir författare och redaktör.

Importen känner igen ett kurspaket på `"format": "stimma-kurspaket-1"`. En fil utan det fältet
behandlas som en gammal export från "Exportera kurs". Den vägen tappar quizfrågor, infosidor och
bilder. **Kontrollera därför fältet `format` om kursen kommer in utan frågor.**

<!-- intern -->
### 9B. Kommandoradsverktyget (på servern)

Verktyget körs i webbcontainern. `tools/` ligger utanför webbroten och kan inte nås från webben.

```bash
docker cp kurs.zip stimma-web-1:/tmp/kurs.zip

# Provkör — validerar allt, skriver ingenting
docker exec -u www-data stimma-web-1 php /var/www/html/tools/las_in_kurspaket.php \
    /tmp/kurs.zip --forfattare=thomas.wennersten@sambruk.se --provkor

# Läs in. --bilder skapar de bilder som bara har image_wish (kostar, se avsnitt 7B)
docker exec -u www-data stimma-web-1 php /var/www/html/tools/las_in_kurspaket.php \
    /tmp/kurs.zip --forfattare=thomas.wennersten@sambruk.se --bilder

docker exec stimma-web-1 rm /tmp/kurs.zip
```

- **`--forfattare`** måste vara e-postadressen till en befintlig användare. Kursen hamnar i hens
  organisation. Använd den adress som uppdraget anger, eller `thomas.wennersten@sambruk.se`.
- **Fel och varningar:** provkörningen listar alla fel (`FEL: …`) och avslutas med kod 1. Varningar
  (`VARNING: …`) stoppar inte inläsningen.
- **Resultatet:** den sista utskriften är en JSON-rad med `kurs_id`, lektionernas id:n, en länk till
  redigeringen, antal bilder och eventuella bilder som saknas.

<!-- /intern -->
### Att tänka på vid inläsningen

- **Inläsningen sker i en transaktion:** antingen kommer hela kursen in, eller ingenting, och inga
  bilder blir kvar.
- **Läs aldrig in samma paket två gånger.** Det skapar en kopia av kursen. Om något blev fel efter
  inläsningen: rapportera det och låt en admin ta bort kursen.

---

## 10. Fullständigt exempel

Exemplet nedan är `course.json` utan färdiga bilder, med bara `image_wish`. Med färdiga bilder lägger
du till `"image_file": "…"` på kursen och på varje lektion, och packar filen i en ZIP-fil tillsammans
med `images/` (avsnitt 3 och 7A).

```json
{
  "format": "stimma-kurspaket-1",
  "course": {
    "title": "Nätfiske — känn igen och stoppa bluffmejl",
    "description": "En kort kurs för alla medarbetare om hur nätfiske går till, hur du känner igen ett bluffmejl eller sms och vad du gör om du har klickat. Räkna med ungefär 5–10 minuter per lektion.",
    "difficulty_level": "beginner",
    "image_style": "illustration",
    "image_wish": "En kommunanställd vid sin dator som stannar upp och granskar ett mejl med en förstoringsglas-ikon bredvid skärmen",
    "completion_content": "<p>Bra jobbat! Du vet nu hur nätfiske går till och vad du ska göra om något verkar misstänkt.</p><p>Kom ihåg: <strong>hellre en gång för mycket</strong> att fråga IT-supporten.</p>"
  },
  "lessons": [
    {
      "key": "valkommen",
      "lesson_type": "info_page",
      "title": "Välkommen till kursen",
      "estimated_duration": 2,
      "content": "<div class=\"lesson-intro\"><p>Nätfiske är det vanligaste sättet för angripare att ta sig in i en organisation. I den här kursen lär du dig känna igen försöken och vad du gör om du har klickat.</p></div><h3>Så går kursen till</h3><p>Kursen har två lektioner med quiz.</p><p>Hur lång tid en lektion tar beror på flera saker, till exempel hur van du är vid ämnet, hur mycket du går tillbaka och läser om och om du kan sitta ostört. En tumregel är 5–10 minuter per lektion.</p>"
    },
    {
      "key": "kanna-igen",
      "title": "Så känner du igen ett bluffmejl",
      "estimated_duration": 12,
      "image_wish": "En medarbetare som håller upp sin mobiltelefon och jämför ett sms med ett brev på skrivbordet",
      "content": "<div class=\"lesson-intro\"><p>Ett bluffmejl försöker få dig att <strong>klicka</strong>, <strong>logga in</strong> eller <strong>betala</strong> — fort.</p></div><!-- pagebreak --><h3>Tre varningstecken</h3><ul><li><strong>Brådska:</strong> \"Ditt konto stängs i dag\"</li><li><strong>Avsändaren stämmer inte</strong> med organisationen</li><li><strong>Länken</strong> går till en annan adress än den ser ut att göra</li></ul><div class=\"lesson-example\"><strong>Exempel:</strong> Ett mejl från \"IT-avdelningen\" ber dig bekräfta ditt lösenord via en länk.</div><!-- pagebreak --><h3>Kontrollera innan du klickar</h3><p>Håll muspekaren över länken och läs adressen innan du klickar.</p><div class=\"lesson-tip\"><strong>Tips:</strong> IT-supporten ber dig aldrig om ditt lösenord.</div><!-- pagebreak --><div class=\"lesson-summary\"><h4>Sammanfattning</h4><ul><li>Brådska är ett varningstecken</li><li>Kontrollera avsändare och länk</li></ul></div>",
      "questions": [
        { "question_type": "multiple_choice", "question_text": "Vilka är typiska varningstecken på nätfiske?",
          "quiz_data": { "answers": ["Mejlet kräver att du agerar omedelbart", "Avsändarens adress stämmer inte med organisationen", "Mejlet har en signatur", "Länken går till en annan adress än den ser ut att göra"], "correct": [0, 1, 3] } },
        { "question_type": "true_false", "question_text": "IT-supporten kan ibland be dig skicka ditt lösenord via mejl.",
          "quiz_data": { "correct": false } }
      ]
    },
    {
      "key": "om-du-klickat",
      "title": "Om du har klickat",
      "estimated_duration": 10,
      "image_wish": "En medarbetare som ringer IT-supporten med lugnt ansiktsuttryck, datorn bredvid",
      "ai_chat": {
        "instruction": "Undrar du vad som gäller hos er? Fråga gärna AI:n.",
        "prompt": "Du hjälper medarbetare i offentlig sektor som kan ha klickat på en nätfiskelänk. Svara kort och lugnt på svenska. Hänvisa alltid till den egna IT-supporten för åtgärder."
      },
      "content": "<div class=\"lesson-intro\"><p>Alla kan klicka fel. Det viktiga är att du <strong>agerar snabbt</strong>.</p></div><!-- pagebreak --><h3>Gör så här</h3><p>Följ stegen i ordning.</p><ol><li>Koppla bort datorn från nätverket</li><li>Kontakta IT-supporten</li><li>Byt lösenord om du har angett det</li></ol><div class=\"lesson-warning\"><strong>Obs!</strong> Vänta inte — varje minut räknas.</div><!-- pagebreak --><div class=\"lesson-summary\"><h4>Sammanfattning</h4><ul><li>Agera snabbt</li><li>Kontakta IT-supporten</li></ul></div>",
      "questions": [
        { "question_type": "order", "question_text": "Sätt stegen i rätt ordning.",
          "quiz_data": { "items": ["Koppla bort datorn från nätverket", "Kontakta IT-supporten", "Byt lösenord om du har angett det"] } },
        { "question_type": "single_choice", "question_text": "Vad är viktigast om du har klickat på en misstänkt länk?",
          "quiz_data": { "answers": ["Vänta och se om något händer", "Agera snabbt och kontakta IT-supporten", "Radera mejlet och glöm det"], "correct": 1 } }
      ]
    }
  ]
}
```

---

## 11. Rapporten efter varje kurs

Avsluta varje kurs med en kort rapport till uppdragsgivaren:

- **Resultat:** kurs-id och titel, från importens gröna ruta<!-- intern --> eller verktygets JSON-rad<!-- /intern -->.
- **Omfång:** antal lektioner och infosidor, antal frågor och vilka typer.
- **Bilder:** vilka som följde med i paketet, och vilka som saknas tillsammans med önskemålet, så att
  redaktören kan skapa dem.
- **Illustrationer från underlaget:** hur många som finns i lektionstexten och var. Lista också dem
  som **inte** kom med: lektion, sida, texten de står efter, vad de visar och varför (gick inte att
  få ut, personuppgifter, bedömd som dekorativ). Redaktören lägger in dem med bildknappen i
  lektionsredigeraren.
- **Videor:** varje länk, så att granskaren kan provspela den.
- **Antaganden:** de val du gjorde som beskrivningen inte styrde, t.ex. målgrupp, nivå och avgränsningar.
- **Osäkerheter:** påståenden och källor som en ämnesexpert bör granska. Lista dem konkret.
- **Påminnelse:** att kursen är **inaktiv** och måste granskas och aktiveras av en redaktör eller admin.

---

## 12. Kontrollista innan inläsning

- [ ] `format` är `stimma-kurspaket-1`.
- [ ] Varje `key` är unik och varje `belongs_to` pekar bakåt på en `lesson`.
- [ ] Varje lektion har intro, minst två `h3`-avsnitt, exempel, tips eller info och en sammanfattning.
- [ ] Varje lektion har 4–7 sidor och 80–180 ord per sida.
- [ ] Varje lektion har 2–4 frågor som går att besvara med lektionens innehåll, med varierade typer och spridda rätta svar.
- [ ] Inga påhittade länkar, videor, siffror eller paragrafer.
- [ ] Kursen och varje lektion har en bild i `images/` (`image_file`) i rätt format: omslaget liggande 3:2, lektionsbilderna kvadratiska. Ingen text i bilderna.
- [ ] Varje `image_file` är ett filnamn utan sökväg, och filen finns i `images/` i ZIP-filen.
- [ ] Varje innehållsbild i underlaget (skärmbild, diagram) finns i lektionstexten på rätt plats,
      med `src="images/…"`, en beskrivande `alt` och en klass från avsnitt 5.6, eller står i
      rapporten. Lektionsbilden är kvar.
- [ ] `course.json` ligger i ZIP-filens rot eller i en enda undermapp tillsammans med `images/`. Det är en kurs per ZIP-fil.
- [ ] Inläsningen går igenom utan fel. Annars rättar du och försöker igen.

---

<!-- intern -->
## Bilaga: var formatet kommer ifrån

Dokumentet är för den som behöver underhålla verktyget eller formatet. Den som skapar kurser
behöver inte läsa det här.

| Del | Källa |
|---|---|
| Tabeller | `courses`, `lessons` (`lesson_type`, `belongs_to_lesson_id`, `video_*`, `ai_*`, `quiz_pass_mode`), `quiz_questions` (`question_type`, `quiz_data` som JSON) och `course_editors` |
| Rendering och rättning av frågor | `include/quiz.php`, `renderQuizQuestion()` och `gradeQuizQuestion()` |
| HTML-sanering | `cleanHtml()` i `include/functions.php` |
| Sidbrytning, video och AI-chatt | `public/lesson.php`, cirka rad 876–1150 |
| Innehållsblock och pedagogiska riktmärken | `aiLessonGenerateContent()` i `include/ai_lesson_helper.php` och `getCourseGenerationPrompt()` i `public/admin/cron/process_ai_jobs.php` |
| Bildprompter, storlekar och stilar | `include/ai_image_helper.php` |
| Validering och inläsning | `include/kurspaket.php`, som både `public/admin/import.php` och `tools/las_in_kurspaket.php` använder |
| Bilder i lektionstexten | `kpLopbilder()` i `include/kurspaket.php`; klasserna i `KP_IMG_KLASSER` = `image_class_list` i `public/admin/include/editor.php`, CSS i `include/css/style.css`. Test: `tests/test_kurspaket_bilder.php` |

Om Stimma får en ny frågetyp, nya innehållsblock eller nya lektionsfält måste både det här
dokumentet och valideringen i `include/kurspaket.php` uppdateras. Annars stoppar verktyget
giltigt innehåll, eller släpper igenom sådant som inte visas.
<!-- /intern -->
