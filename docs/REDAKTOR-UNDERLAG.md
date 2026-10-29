# Så arbetar en redaktör i Stimma – underlag för en kurs

Det här dokumentet beskriver hur en **redaktör** arbetar i Stimma: från första inloggningen till en
publicerad kurs som följs upp. Det är skrivet för att Claude ska kunna bygga en **kurs i Stimma för
nya redaktörer**. Själva kursformatet (kurspaket, lektionsfält, quiztyper, bilder) står i
[`KURSFORMAT.md`](KURSFORMAT.md) – följ det när kursen byggs.

Underlaget är sammanställt 2026-10-28 från koden. Knapp- och fältnamn står inom citattecken
exakt som de heter i Stimma. Användarhandboken (`public/admin/user_guide.php`) är delvis inaktuell;
där de skiljer sig gäller det här dokumentet. För en översikt av hela Stimma, se
[`STIMMA-FUNKTIONER.md`](STIMMA-FUNKTIONER.md).

---

## 1. Så ska underlaget användas

**Målgrupp för kursen:** personer i kommuner, regioner och kommunala bolag som just fått rollen
redaktör – HR, utbildningsansvariga, kommunikatörer, verksamhetsutvecklare. De kan sitt ämne men
har inte byggt kurser i Stimma förut. De vill veta *hur man gör*, i vilken ordning, och vad man ska
undvika.

**Ton:** praktisk och handfast. Beskriv steg i den ordning man gör dem och använd Stimmas egna
knappnamn, så att deltagaren känner igen sig när hen sitter i verktyget. Kursen ska själv vara ett
gott exempel på det den lär ut: korta lektioner, formaterade rutor, varierade quiz.

**Påstå bara det som står här.** Lägg inte till funktioner, knappar eller siffror. Se särskilt
avsnitt 13, *Det här ska kursen inte påstå*.

---

## 2. Vad en redaktör är

- En redaktör kan allt en vanlig användare kan, och dessutom **skapa, redigera och följa upp
  kurser**.
- Redaktören ser och redigerar **bara sina egna kurser**: de hen själv har skapat, kopierat,
  importerat eller AI-genererat, och de där någon lagt till hen som kursredaktör. Hen ser alltså
  inte alla organisationens kurser – det gör admin.
- Rollen delas ut av en **admin** i organisationen.
- **Kursredaktör från annan organisation:** en redaktör kan bjuda in en person från en annan
  organisation att redigera en viss kurs. Den personen ser bara den kursen och bara fliken
  "Allmänt" i kursredigeringen.

**Vad redaktören inte gör (det gör admin):** hanterar användare och roller, gör en kurs publik med
registreringslänk, ställer in de allmänna påminnelserna, hanterar diplommallar, varumärke,
PUB-avtal och API-nycklar, och beställer fler AI-tokens.

---

## 3. Redaktörens meny

Efter inloggning når redaktören adminvyn. I vänstermenyn finns:

| Menyval | Vad det är |
|---|---|
| "Översikt" | Startsida med nyckeltal och organisationens AI-saldo |
| "Kurser" | Kurslistan – här skapas och hanteras kurser |
| "Lärvägar" | Paket av flera kurser i rekommenderad ordning |
| "Kopiera kurs" | Bläddra bland andra organisationers kurser och kopiera |
| "Taggar" | Ämnestaggar för att sortera kurser |
| "Kursstatistik" | Hur det går för deltagarna i redaktörens kurser |
| "Statistik per användare" | Samma sak sett per person |
| "Handbok" | Användarhandboken |
| "Användarvyn" | Tillbaka till Stimma som deltagare |

---

## 4. Arbetsgången i stort

1. **Skapa kursen** – på någon av vägarna i avsnitt 5. Kursen blir alltid **inaktiv** och syns
   inte för deltagare.
2. **Bygg och finslipa** – lektioner, innehållsblock, bilder, video, quiz, AI-handledare.
3. **Ställ in kursen** – vem ska se den, stegvis eller inte, diplomkriterier, slutdatum.
4. **Förhandsgranska** själv, och **bjud in granskare** som läser och lämnar synpunkter.
5. **Aktivera** kursen under fliken "Publicering".
6. **Följ upp** i kursstatistiken och skicka påminnelser vid behov.

---

## 5. Skapa en kurs – fyra vägar

I kurslistan ("Kurser") finns knapparna i rubrikraden.

| Väg | Så gör man | Bra när |
|---|---|---|
| **"Ny kurs"** | Fyll i titel och beskrivning, spara, lägg sedan till lektioner en i taget. | Du vill ha full kontroll från början. |
| **"AI Generera kurs"** | En guide i två steg, se nedan. AI:n bygger hela kursen i bakgrunden. | Du har ett ämne och vill ha ett första utkast snabbt. |
| **"Importera PowerPoint"** | Ladda upp en PPTX. Varje bild blir en lektion och AI utvecklar texten. Högst 25 bilder och 50 MB. | Det finns redan en presentation om ämnet. |
| **"Importera kurs"** | Ladda upp ett kurspaket (ZIP) eller en export från Stimma. Se avsnitt 11. | Kursen är byggd utanför Stimma, t.ex. med Claude, eller kommer från en annan Stimma. |

Dessutom: **"Kopiera kurs"** i menyn. Där syns andra organisationers aktiva kurser, och en kopia
hamnar i den egna organisationen för anpassning. Kopian är inaktiv och får en permanent
ursprungsetikett ("Ursprung: …") som visar vem som skapade originalet.

Vem som än skapar en kurs – på vilken väg som helst – blir automatiskt redaktör för den.

### AI Generera kurs, steg för steg

**Steg 1 – "Beskriv din kursidé".** Skriv en kort idé och klicka "Skapa förslag på namn och
beskrivning", eller fyll i själv:
- "Kursnamn" och "Beskrivning av kursen" – ju mer konkret beskrivning, desto bättre resultat
- "Antal lektioner", "Svårighetsnivå", "Textlängd per lektion"
- "Tonalitet" och "Språkstil"
- "Målgrupp", till exempel "nyanställda inom vården"
- "Färgtema för bilder"
- "Quiz per lektion", "AI-handledare" och "Generera bilder" – kryssrutor

**Steg 2 – AI:n frågar.** Klicka "Nästa - Låt AI ställa frågor". AI:n ställer 2–4 följdfrågor.
Svara, eller klicka "Hoppa över". Klicka sedan "Starta generering".

**Steg 3 – Vänta.** Genereringen pågår i bakgrunden och tar några minuter, längre med bilder.
Kursen skapas som **inaktiv** – granska den innan du aktiverar.

**Viktigt:** AI-innehåll är ett utkast. Redaktören ansvarar för att läsa igenom, rätta sakfel och
anpassa till den egna organisationen.

---

## 6. Kursredigeringen

Klicka "Redigera" (pennan) vid kursen. Kursredigeringen har fyra flikar.

### Fliken "Allmänt"
- "Titel" (obligatorisk) och "Beskrivning"
- "Bild" – ladda upp (JPG, PNG eller GIF, högst 5 MB) eller klicka "AI" för att generera.
  AI-knappen fungerar först när kursen har sparats en gång.
- "Bildstil för AI-bilder" – "Varm illustration", "Fotografiskt" eller "Lugnt grafiskt". Stilen
  gäller kursbilden och ärvs av lektionsbilderna, så att kursen ser enhetlig ut.
- "Vad ska bilden visa? (valfritt)" – styr motivet
- "Slutdatum" – visas i påminnelsemejl, med antal dagar kvar
- "Avslutssida" – text som visas när deltagaren gjort klart kursen
- "Diplom-kriterier" – "Minsta andel rätt svar (%)" och "Tillåt deltagare att svara om på samma
  fråga" (påslaget från början)
- "Kursredaktörer" – "Lägg till redaktör". Personer från andra organisationer märks "annan
  organisation" och får bara rätt till den här kursen.
- "Granskare" – "Lägg till granskare", se avsnitt 9

### Fliken "Stegvisa lektioner"
En stegvis kurs låser upp en lektion i taget och skickar mejl när nästa lektion öppnar.
- "Stegvisa lektioner" – slå på
- "Registreringsläge":
  - "Gemensamt startdatum — alla startar samtidigt"
  - "Löpande registrering — skriv in användare individuellt med valfritt startdatum" (passar
    introduktion för nyanställda)
- "Dagar mellan lektioner", "Påminnelse efter (dagar)", "Startdatum"
- "E-postmallar (anpassa rubrik och text)" och "Skicka testmail" – skicka alltid ett test till dig
  själv först

### Fliken "Tilldelning & synlighet"
- "Taggar" – ämnestaggar för sortering och filtrering
- "Organisationstaggar" – styr vilka grupper (förvaltning, enhet) som ser kursen. Utan
  organisationstaggar ser alla i organisationen kursen. Organisationstaggarna kommer från
  organisationens användarsynk; redaktören väljer bland dem men skapar dem inte.
- "Synlighet" – "Delas med hela organisationen" eller "Dela med vissa domäner inom
  organisationen" (visas för den som finns på organisationens huvuddomän)

### Fliken "Publicering"
- Reglaget "Aktiv" – "Kursen är aktiv och synlig" eller "Kursen är inaktiv och dold". Det är här
  kursen släpps till deltagarna.
- **Publik kurs** (registreringslänk för vem som helst, även utan organisationens e-postdomän)
  slås på av en **admin**. Vill redaktören ha en publik kurs ber hen en admin.

---

## 7. Lektionerna

Klicka "Hantera lektioner" vid kursen. Lektionslistan visar titel, typ, status, quiz och om
AI-stöd finns. Ordningen ändras med **dra och släpp** och sparas direkt.

Knappar: "Ny lektion", "AI-generera lektion" och "Generera bilder" (skapar saknade bilder för
kursens lektioner).

### Två typer av sidor
- **"Lektion"** – kan ha quiz
- **"Informationssida"** – utan quiz, t.ex. en introduktion eller ett avslut

### Redigera en lektion
- "Titel", "Typ"
- **"Innehåll"** i en textredigerare. Menyn **"Innehållsblock"** lägger in formaterade rutor:
  Introduktion, Tips, Information, Exempel, Varning och Sammanfattning. Använd dem – de gör texten
  lätt att skumma.
- **Sidbrytningar** delar upp en lång lektion i flera sidor. Knappen "Föreslå sidbrytningar" ger
  förslag.
- **Bilder i texten** – dra in dem i editorn (högst 5 MB)
- "Bild" – lektionsbilden, uppladdad eller "Generera AI-bild", med "Vad ska bilden visa?"
- "Video" – "YouTube" eller "Ladda upp video" (MP4/WebM, högst 100 MB), och var i lektionen den
  ska visas. "Video här" i editorn placerar den mitt i texten.
- "Ljud" (högst 50 MB)
- "Bakgrundsfärg för lektionen"
- **AI-handledare:** "Aktivera AI-chat för denna lektion", med "AI Instruktion" och "AI Prompt".
  Deltagaren kan då ställa frågor om lektionen.
- **"Hur ska deltagaren komma vidare?"** – "Kräv alla rätt" eller "Spara svar och fortsätt oavsett
  resultat"
- "Aktiv"

### AI-generera lektion
Lägger till en AI-skriven lektion i en befintlig kurs. Fält: "Vad ska lektionen handla om?",
"Typ", "Textlängd", "Ton", och kryssrutorna "Inkludera 2-4 quizfrågor", "Generera även AI-bild
till lektionen" och "Bygg som flera sidor".

### Tumregler för bra lektioner
- En lektion = en sak. Några minuter, inte en kvart.
- Börja med varför det spelar roll, avsluta med en sammanfattningsruta.
- Hellre ett konkret exempel från vardagen än en definition.
- Quiz direkt efter innehållet, medan kunskapen är färsk.

---

## 8. Quiz

Klicka "Hantera quizfrågor" (eller "Hantera frågor" i lektionen). "Lägg till fråga" ger elva
frågetyper:

Enkelval · Flerval · Sant/falskt · Lucktext · Bildval · Ordna i rätt ordning · Matcha par ·
Kategorisera · Siffersvar · Klicka i bild · Kort textsvar

Varje fråga har "Frågetext", "Poäng" (1–100) och en valfri "Frågebild". En lektion kan ha flera
frågor; ordningen ändras genom att dra.

**Tips:** variera frågetyperna. "Ordna i rätt ordning" passar processer, "Matcha par" begrepp och
förklaringar, "Kategorisera" passar att sortera exempel.

---

## 9. Granska innan publicering

### Förhandsgranska själv
Ögonikonen "Förhandsgranska" i lektionslistan öppnar lektionen som deltagaren ser den. Inga
framsteg sparas. Längst upp står "Förhandsgranskning – dina framsteg sparas inte" och en länk
tillbaka till redigeringen. I förhandsgranskningen kan redaktören hoppa över en quizfråga för att
snabbt bläddra vidare.

### Bjud in granskare
Under "Granskare" i kursredigeringen: "Lägg till granskare".
- Granskaren får ett mejl, "Granska kursen – <kursens titel>", med knappen "Öppna kursen".
- Kursen syns för granskaren under "Kurser att granska" på startsidan – **även när kursen är
  inaktiv**, och även om granskaren tillhör en annan organisation.
- Granskaren läser kursen och provar quizen men kan inte ändra något, och inga svar sparas.
- Med knappen "Skicka synpunkter" mejlar granskaren den som bjöd in.

---

## 10. Följa upp

- **"Kursstatistik"** – välj en av dina kurser. Deltagare, progress per lektion, resultat.
  "Exportera Excel" för vidare bearbetning.
- I stegvisa kurser: **"Skicka påminnelse"** till den som ligger efter, och vid löpande
  registrering **"Skriv in användare"** med eget startdatum.
- **"Statistik per användare"** – samma uppgifter sett per person, för den egna organisationen.
- **Publika deltagare** (om en admin gjort kursen publik): "Hantera publika deltagare" visar vilka
  som registrerat sig via länken.

---

## 11. Kurspaket – kurser byggda med AI utanför Stimma

En kurs kan tas fram med en AI-assistent som Claude och läsas in färdig.

1. Gå till "Importera kurs" och klicka **"Ladda ner instruktionen (.md)"**. Instruktionen
   beskriver kursformatet.
2. Ge instruktionen till AI-assistenten tillsammans med ditt underlag. Assistenten levererar ett
   kurspaket (ZIP med `course.json` och bilder).
3. Välj filen under "Välj kursfil (ZIP eller JSON)" och läs in.
4. Stimma kontrollerar paketet först. Finns fel visas alla, och ingenting sparas.
5. Kursen blir **inaktiv**. Klicka "Granska kursen".
6. Saknas bilder kan de skapas efteråt med "Generera AI-bild".

**Läs aldrig in samma paket två gånger** – det skapar en kopia av kursen. Rätta i stället i Stimma.

---

## 12. Övrigt redaktören behöver veta

### AI-saldo
- AI-funktionerna drar tokens från **organisationens** saldo: kursgenerering, AI-lektioner,
  PowerPoint-import, AI-bilder och AI-handledaren.
- Saldot syns på "Översikt". När det börjar ta slut visas en banner. Det är en **admin** som
  beställer mer – redaktören ber en admin.
- Bilder är det som drar mest. Generera inte om bilder i onödan.
- **Om kontakten bryts när en bild skapas:** bilden blir ofta klar ändå. Ladda om sidan och
  kontrollera innan du försöker igen, annars betalar organisationen för två bilder.

### Lärvägar
Redaktören kan skapa lärvägar: "Lärvägar" → "Skapa ny lärväg", lägg till kurser och dra dem i
ordning, sätt status Aktiv. Ordningen är en rekommendation; kurserna låses inte. Kurser som en
deltagare inte har tillgång till döljs för hen.

### Taggar
Redaktören kan skapa och redigera ämnestaggar under "Taggar".

### Radera
Redaktören kan radera lektioner och sina egna kurser. Det går inte att ångra, och deltagarnas
resultat i kursen försvinner.

### Informationsmeddelanden
Sambruk skickar ibland meddelanden till alla redaktörer och admins. De visas som en ruta vid
inloggning: "Stäng (visa igen nästa gång)" eller "Förstått, visa inte igen".

---

## 13. Det här ska kursen inte påstå

- **Inte att redaktören kan göra en kurs publik** – det gör en admin.
- **Inte att redaktören ser alla organisationens kurser** – bara sina egna och de hen tilldelats.
- **Inte att redaktören beställer tokens** – det gör en admin.
- **Inte att SCORM-paket kan importeras** – funktionen är avstängd tills vidare.
- **Inte att quizfrågor har ett fält för förklaring** i redigeringen – det finns inte.
- **Inte att kursen har en svårighetsgrad att ställa in** i kursredigeringen – den finns bara som
  val i AI-guiden.
- **Inte att Stimma kontrollerar innehållet innan aktivering** – det enda kravet är en titel.
  Granskningen är redaktörens ansvar.
- **Inte att AI:n alltid har rätt.**
- **Inga priser och inga exakta tokenmängder.** Säg att bilder kostar mest.
- **Ingen total kurstid och inget "gör en lektion i taget"** – ange tid per lektion
  (se `KURSFORMAT.md`, Omfång).

---

## 14. Förslag på kursupplägg

Förslag, inte krav. 7–8 lektioner om ungefär 5–10 minuter vardera, avslutat med diplom.

1. **Välkommen som redaktör** – vad rollen innebär, vad du ser och inte ser, vad admin gör.
   Arbetsgången i sex steg. Quiz: ordna arbetsgången i rätt ordning.
2. **Fyra vägar till en kurs** – Ny kurs, AI Generera kurs, PowerPoint, kurspaket, plus Kopiera
   kurs. Quiz: matcha par (situation → väg).
3. **AI Generera kurs** – guiden steg för steg, vad som gör en bra beskrivning, varför AI-utkastet
   måste granskas. Quiz: flerval om vad som ger bättre resultat.
4. **Bygg en bra lektion** – innehållsblock, sidbrytningar, bilder och bildstil, video, ljud,
   AI-handledare, tumreglerna. Quiz: kategorisera innehåll (passar i Tips / Exempel / Varning /
   Sammanfattning).
5. **Quiz som lär** – frågetyperna, när man väljer vilken, "Kräv alla rätt" eller inte. Quiz: en
   av varje i några olika typer, så att deltagaren provar dem.
6. **Ställ in och publicera** – flikarna i kursredigeringen, synlighet och organisationstaggar,
   stegvisa kurser, diplomkriterier, granskare, aktivera. Quiz: scenarier (nyanställda, alla i
   kommunen, en förvaltning) → rätt inställning.
7. **Följ upp** – kursstatistik, export, påminnelser i stegvisa kurser, lärvägar. Quiz: enkelval.
8. **Bra att veta** – AI-saldot och bildregeln (ladda om innan du försöker igen), kurspaket från
   Claude, radering, vart man vänder sig. Avsluta med en AI-chatt där deltagaren kan ställa egna
   frågor om redaktörsarbetet, avgränsad till kursens innehåll.

**Bildidéer:** en redaktör vid skrivbordet som bygger en kurs av byggklossar, en checklista med
sex steg, en granskare med förstoringsglas över ett utkast, en fisk som simmar i täten för ett
stim (redaktören leder lärandet) – i en gemensam bildstil. Sambruks färg är #58A618 (använd
#3F7A11 för text och knappar på vit bakgrund). Bilder ska inte föreställa skärmdumpar med påhittad
text; vill man visa gränssnittet bör riktiga skärmdumpar läggas in efteråt.

---

## 15. Fler källor i repot

- `docs/KURSFORMAT.md` – kursformatet och reglerna för AI-skapade kurser
- `docs/STIMMA-FUNKTIONER.md` – översikt av hela Stimma
- `public/admin/user_guide.php` – användarhandboken (delvis inaktuell)
