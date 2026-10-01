# Kursformatet i Stimma — instruktion för att skapa kurser

Det här dokumentet beskriver hur en kurs i Stimma är uppbyggd och hur en ny kurs kommer in i
systemet. Det är skrivet för att Claude ska kunna skapa kompletta kurser **på egen hand** utifrån en
kort beskrivning, och själv besluta om struktur, innehåll, bilder och quiz.

Allt här är kontrollerat mot koden och databasen 2026-10-01. Verktyget i avsnitt 9 är testat skarpt,
och alla frågetyper i avsnitt 6 rättas korrekt av Stimmas egen rättningsfunktion.

---

## 1. Arbetsflödet i korthet

För varje kursbeskrivning:

1. **Planera kursen:** målgrupp, lärandemål, antal lektioner och vad varje lektion ska lära ut
   (avsnitt 8).
2. **Skriv ett kurspaket:** en JSON-fil i formatet `stimma-kurspaket-1` (avsnitt 3–7, fullständigt
   exempel i avsnitt 10).
3. **Provkör:** kör verktyget med `--provkor` och rätta tills det inte finns några fel (avsnitt 9).
4. **Läs in:** kör verktyget skarpt med `--bilder`. Kursen skapas **alltid som inaktiv** och syns
   inte för deltagare.
5. **Rapportera:** ange kurs-id, länk till redigering, antal lektioner och frågor, bildkostnad och
   allt du är osäker på (avsnitt 11).

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

```json
{
  "format": "stimma-kurspaket-1",
  "course": { … },
  "lessons": [ { … }, { … } ]
}
```

`format` måste vara exakt `"stimma-kurspaket-1"`. All text är UTF-8 och skrivs på svenska.

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
| `image_wish` | Högst 300 tecken | Vad omslagsbilden ska föreställa. Se avsnitt 7. |
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
| `image_wish` | Högst 300 tecken | Motiv för lektionsbilden. Utelämna fältet för att inte få någon bild. |
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
- **Använd inte** `h1` (lektionstiteln är redan sidans rubrik), `img`, `script`, `style`,
  `iframe`, händelseattribut eller andra HTML-kommentarer än sidbrytningen.
- **Rubriker:** `h3` för avsnitt och `h4` för underrubriker. Hoppa inte över nivåer.
- **Länkar:** bara fullständiga `https://`-adresser. De öppnas automatiskt i ny flik. Länka bara
  till sidor du vet finns (myndigheter, lagtext på riksdagen.se och liknande). Hitta aldrig på
  adresser.
- **Bilder i löptexten stöds inte** i kurspaket. Använd `image_wish` för lektionsbilden.

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

Claude bestämmer bildstil och motiv. Bilderna skapas av verktyget med `--bilder` (OpenAI
gpt-image), med samma prompt som knapparna i admin.

| Bild | Format | Ungefärlig kostnad |
|---|---|---|
| Kursomslag | 1536 × 1024, hög kvalitet, WebP | ~17 US-cent |
| Lektionsbild | 1024 × 1024, medelkvalitet, WebP | ~3–5 US-cent |

En kurs med 8 lektionsbilder kostar alltså ungefär 0,50 USD. Bilderna drar också från
organisationens AI-kvot.

**`image_style`** gäller hela kursen:
- `illustration`: varm, handritad stil i varma färger. Passar de flesta kurser.
- `foto`: fotografisk stil. Passar kurser om konkreta miljöer och yrken.
- `abstrakt`: lugn, grafisk stil. Passar abstrakta ämnen som regelverk och strategi.

**`image_wish`** är det viktigaste för en bra bild. Stimma lägger själv till stil, format och
negativa krav som ingen text i bilden. Beskriv bara **motivet** som en konkret vardagsscen i svensk
offentlig miljö, högst 300 tecken:

- Bra: *"En socialsekreterare som låser sin datorskärm innan hen går till ett möte"*
- Bra: *"Två kollegor vid ett fikabord som tittar på ett misstänkt sms i en mobiltelefon"*
- Dåligt: *"Säkerhet"*, *"En bild om lektion 3"*, eller ett motiv som innehåller text eller logotyper.

Infosidor behöver sällan bild. Utelämna `image_wish` där.

Om en bild misslyckas (kvot, nätverk eller tidsgräns) finns kursen kvar utan just den bilden. Det
syns i verktygets resultat. Rapportera vilka bilder som saknas. En admin kan skapa dem med knappen
"Generera bild" i redigeraren.

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

## 9. Läsa in kursen — `tools/las_in_kurspaket.php`

Verktyget körs i webbcontainern från kommandoraden. `tools/` ligger utanför webbroten och kan inte
nås från webben.

```bash
# 1. Kopiera in paketet
docker cp kurs.json stimma-web-1:/tmp/kurs.json

# 2. Provkör — validerar allt, skriver ingenting
docker exec -u www-data stimma-web-1 php /var/www/html/tools/las_in_kurspaket.php \
    /tmp/kurs.json --forfattare=thomas.wennersten@sambruk.se --provkor

# 3. Läs in på riktigt, med bilder
docker exec -u www-data stimma-web-1 php /var/www/html/tools/las_in_kurspaket.php \
    /tmp/kurs.json --forfattare=thomas.wennersten@sambruk.se --bilder

# 4. Städa
docker exec stimma-web-1 rm /tmp/kurs.json
```

- **`--forfattare`** måste vara e-postadressen till en befintlig användare. Hen blir författare och
  redaktör för kursen, och kursen hamnar i hens organisation (e-postdomänen). Använd den adress som
  uppdraget anger, eller `thomas.wennersten@sambruk.se` om ingen anges.
- **Provkörningen** listar alla fel på en gång (`FEL: …`) och avslutas med kod 1. Rätta paketet och
  provkör igen tills den går igenom. Varningar (`VARNING: …`) stoppar inte inläsningen, men läs dem.
- **Den skarpa inläsningen** sker i en transaktion: antingen kommer hela kursen in, eller ingenting.
  Bilderna skapas efteråt, och en bild som misslyckas påverkar inte resten.
- **Den sista utskriften är en JSON-rad**, till exempel:
  ```json
  {"ok":true,"meddelande":"Kursen är inläst som INAKTIV: …","kurs_id":153,
   "lektioner":{"valkommen":1472,"phishing":1473},
   "redigera":"https://stimma.sambruk.se/admin/edit_course.php?id=153","bilder":{…},"varningar":[]}
  ```
- **Kör aldrig samma paket två gånger.** Det skapar en kopia av kursen. Om något blev fel efter
  inläsningen: rapportera det och låt en admin ta bort kursen, i stället för att läsa in igen.

**Använd inte** admins import ("Importera kurs", `admin/import.php`). Den bygger på ett äldre format
och tappar quizfrågor av alla moderna typer, infosidor, video och AI-chatt.

---

## 10. Fullständigt exempel

```json
{
  "format": "stimma-kurspaket-1",
  "course": {
    "title": "Nätfiske — känn igen och stoppa bluffmejl",
    "description": "En kort kurs för alla medarbetare om hur nätfiske går till, hur du känner igen ett bluffmejl eller sms och vad du gör om du har klickat. Kursen tar cirka 25 minuter.",
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
      "content": "<div class=\"lesson-intro\"><p>Nätfiske är det vanligaste sättet för angripare att ta sig in i en organisation. I den här kursen lär du dig känna igen försöken och vad du gör om du har klickat.</p></div><h3>Så går kursen till</h3><ul><li>Två lektioner med quiz</li><li>Cirka 25 minuter totalt</li></ul>"
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

- **Resultat:** kurs-id, titel och länken från verktygets resultat (`redigera`).
- **Omfång:** antal lektioner och infosidor, antal frågor och vilka typer.
- **Bilder:** vilka som skapades och vilka som misslyckades, och bildkostnaden.
- **Videor:** varje länk, så att granskaren kan provspela den.
- **Antaganden:** de val du gjorde som beskrivningen inte styrde, t.ex. målgrupp, nivå och avgränsningar.
- **Osäkerheter:** påståenden och källor som en ämnesexpert bör granska. Lista dem konkret.
- **Påminnelse:** att kursen är **inaktiv** och måste granskas och aktiveras av en admin.

---

## 12. Kontrollista innan inläsning

- [ ] `format` är `stimma-kurspaket-1`.
- [ ] Varje `key` är unik och varje `belongs_to` pekar bakåt på en `lesson`.
- [ ] Varje lektion har intro, minst två `h3`-avsnitt, exempel, tips eller info och en sammanfattning.
- [ ] Varje lektion har 4–7 sidor och 80–180 ord per sida.
- [ ] Varje lektion har 2–4 frågor som går att besvara med lektionens innehåll, med varierade typer och spridda rätta svar.
- [ ] Inga påhittade länkar, videor, siffror eller paragrafer.
- [ ] Kursen och varje lektion har ett `image_wish` som beskriver en konkret scen, utan text i bilden.
- [ ] Provkörningen går igenom utan fel.

---

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
| Inläsning | `tools/las_in_kurspaket.php` |

Om Stimma får en ny frågetyp, nya innehållsblock eller nya lektionsfält måste både det här
dokumentet och valideringen i `tools/las_in_kurspaket.php` uppdateras. Annars stoppar verktyget
giltigt innehåll, eller släpper igenom sådant som inte visas.
