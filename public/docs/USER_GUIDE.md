# Stimma - Användarhandbok

Denna handbok beskriver hur du använder Stimma e-learning plattform. Stimma är en svensk mikroutbildningsplattform som gör det enkelt att lära sig nya saker i korta, fokuserade lektioner.

---

## Innehållsförteckning

1. [Översikt över användarroller](#översikt-över-användarroller)
2. [Organisation, domäner och scope](#organisation-domäner-och-scope)
3. [Kom igång - Logga in](#kom-igång---logga-in)
   - [Hur länge är jag inloggad?](#hur-länge-är-jag-inloggad)
3. [Guide för användare](#guide-för-användare)
   - [Din dashboard](#din-dashboard)
   - [Gamification - XP och nivåer](#gamification---xp-och-nivåer)
   - [Diplom](#diplom)
   - [Lärvägar](#lärvägar)
4. [Guide för redaktörer](#guide-för-redaktörer)
   - [Skapa en ny kurs](#skapa-en-ny-kurs) (flikbaserad redigerare)
   - [Ange slutdatum för en kurs](#ange-slutdatum-för-en-kurs)
   - [Kursens avslutningsinnehåll](#kursens-avslutningsinnehåll)
   - [Stegvisa kurser](#stegvisa-kurser)
   - [E-postmallar för stegvisa kurser](#e-postmallar-för-stegvisa-kurser)
   - [Testmail för stegvisa kurser](#testmail-för-stegvisa-kurser)
   - [Starta stegvis kurs manuellt](#starta-stegvis-kurs-manuellt)
   - [Skriv in enskilda användare i en stegvis kurs](#skriv-in-enskilda-användare-i-en-stegvis-kurs)
   - [Skapa lektioner](#skapa-lektioner)
   - [Informationssidor](#informationssidor)
   - [Kursens startsida](#kursens-startsida)
   - [Skapa AI-genererad kurs](#skapa-ai-genererad-kurs)
   - [AI-generera en lektion till befintlig kurs](#ai-generera-en-lektion-till-befintlig-kurs)
   - [Importera PowerPoint till kurs](#importera-powerpoint-till-kurs)
   - [Importera SCORM-paket till kurs](#importera-scorm-paket-till-kurs)
   - [Kopiera en befintlig kurs](#kopiera-en-befintlig-kurs)
   - [Förhandsgranska lektioner](#förhandsgranska-lektioner)
   - [Skapa och hantera lärvägar](#skapa-och-hantera-lärvägar)
5. [Guide för administratörer](#guide-för-administratörer)
   - [Dashboard - Översikt](#dashboard---översikt)
   - [Diplomhantering](#diplomhantering)
   - [Lärvägsstatistik](#lärvägsstatistik)
   - [E-postlogg för stegvisa kurser](#e-postlogg-för-stegvisa-kurser)
   - [Påminnelseinställningar](#påminnelseinställningar)
   - [Skicka testmail](#skicka-testmail)
   - [Användarsynkronisering](#användarsynkronisering)
6. [Guide för superadministratörer](#guide-för-superadministratörer)
   - [AI-leverantörskonfiguration](#ai-leverantörskonfiguration)
   - [Testa AI-anslutning](#testa-ai-anslutning)
   - [Inställningar för stegvisa kurser](#inställningar-för-stegvisa-kurser)
   - [Domänhantering](#domänhantering)
7. [PUB-avtal (Personuppgiftsbiträdesavtal)](#pub-avtal-personuppgiftsbiträdesavtal)
   - [Räckvidd: organisation eller domän?](#räckvidd-organisation-eller-domän)
   - [Teckna PUB-avtal](#teckna-pub-avtal)

---

## Översikt över användarroller

Stimma har fem användarroller med olika behörigheter:

| Roll | Beskrivning |
|------|-------------|
| **Användare** | Kan ta kurser och spåra sin progress. Standardrollen för alla inloggade |
| **Redaktör** | Kan skapa och redigera kurser som tilldelats dem |
| **Läsbehörig** | Kan **se** kursstatistik, diplom och användarinformation inom sin organisation, och exportera underlaget. Kan inte ändra något |
| **Admin** | Kan hantera alla kurser, användare och inställningar inom sin organisation |
| **Superadmin** | Fullständig systemåtkomst inklusive AI-inställningar |

### Läsbehörig

Rollen är till för den som ska **följa upp** utbildning utan att förvalta systemet —
en chef, en HR-funktion eller en utbildningssamordnare. Tidigare krävde den
uppgiften full administratörsbehörighet, vilket också gav rätt att skapa och
radera användare, ändra andras roller och styra API-nycklar.

**Läsbehörig kommer åt:**

- **Översikt** och **Kursstatistik** för hela sitt domänscope
- **Diplom** — kan förhandsgranska, men inte ändra stämpelbilden
- **Användare** — ser listan med namn, e-post, roller, org-taggar och framsteg
- **Export till CSV** av både användarlista och statistik

**Läsbehörig kommer inte åt:** kursredigering, lärvägar, taggar, påminnelser,
API-nycklar, synkverktyg, PUB-avtal eller varumärkesinställningar.

**Läsbehörig är ingen roll utan en separat behörighet.** Rollfältet har tre
värden — Användare, Redaktör och Admin — och läsbehörigheten ligger vid sidan av
dem, som en egen på/av-knapp i användarlistan. Därför går den inte att välja i
synkverktygets rollista. Ska den sättas för många konton på en gång finns
**Admin → Synkverktyg → Läsbehörighet i batch**: en filuppladdning med
`email;läsbehörig` som bara ändrar den behörigheten och lämnar resten av kontot
orört. Varje skrivande
åtgärd stoppas på servern, inte bara genom att knappen döljs.

Rollen sätts av en administratör på **Användare**-sidan och kan inte tilldelas via
AD-synkens API — de tillåtna rollerna där är fortfarande `användare`, `redaktör` och
`admin`.

### Filtrera på din organisationsdel

På **Statistik**, **Kursstatistik** och **Användare** finns en filterkontroll där du
kan begränsa vyn till de organisationstaggar **du själv tillhör**. En chef på
IT-avdelningen kan alltså se just sin avdelning i stället för hela kommunen.

Filtret är valfritt — utan val visas allt inom ditt domänscope, som tidigare.
Det gäller både **Läsbehörig** och **Admin**, och erbjuder bara dina egna taggar:
det är en genväg till rätt del av det du redan får se, inte en väg in i andras
avdelningar. Valet följer med till CSV-exporten, så filen matchar det du tittade på.

> **Om taggar och hierarki:** taggar lagras platt. En synk av
> `"Kommun/Förvaltning/Avdelning"` skapar tre fristående taggar, inte en hierarki.
> Filtret matchar därför enskilda taggar — det går inte att välja "allt under
> Förvaltningen".

---

## Organisation, domäner och scope

Stimma bygger på att användare hör till **domäner** (e-postdomänen i deras adress) och att flera domäner kan grupperas under en gemensam **organisation**. För varje organisation pekas en av domänerna ut som **huvuddomän** (markerad med en stjärna under Admin → Organisationer). Huvuddomänen är "modersorganisationen" — admins och redaktörer där har överblick över hela organisationen, medan underdomäner bara hanterar sina egna resurser.

**Exempel: Kommunalförbundet ITSAM**
ITSAM samlar 11 medlemskommuner. Huvuddomänen är `itsam.se`. Underdomäner är t.ex. `atvidaberg.se`, `boxholm.se`, `vimmerby.se` osv. En admin på `itsam.se` ser alla kommuners kurser och användare; en admin på `atvidaberg.se` ser bara Åtvidabergs.

### Vad ser jag som admin eller redaktör?

| Du tillhör... | Du ser... |
|---|---|
| **Huvuddomänen** för en organisation | Alla resurser för alla underdomäner i organisationen — kurser, taggar, statistik, användare |
| **En underdomän** | Bara din egen domäns resurser, plus kurser som huvuddomänen explicit delat med din domän |
| **En domän utan organisation** | Bara din egen domän |

Detta gäller dessa admin-sidor: **Kurser**, **Taggar**, **Statistik** och **Användare**.

### Synlighet vid kursskapande

När du skapar eller redigerar en kurs:

| Du tillhör... | Du kan välja... |
|---|---|
| **Huvuddomänen** | "Delas med hela organisationen" *eller* "Dela med vissa domäner inom organisationen" |
| **En underdomän** | Inget val — kursen blir automatiskt synlig endast på din egen domän |

Underdomänens redaktörer kan ändå se och redigera kurser som huvuddomänen delat med dem (titel, lektioner, quiz, diplom-kriterier) — men inte ändra synlighetsinställningarna.

### Sätta huvuddomän

Superadmin sätter huvuddomän under **Admin → Organisationer** genom att klicka på stjärnan vid önskad domän. Endast en domän per organisation kan vara huvuddomän åt gången.

---

## Kom igång - Logga in

Stimma använder e-postbaserad inloggning utan lösenord. Så här loggar du in:

1. Gå till inloggningssidan
2. Ange din e-postadress
3. Klicka på **"Skicka inloggningslänk"**
4. Kontrollera din inkorg (och skräppost)
5. Klicka på länken i e-postmeddelandet
6. Du är nu inloggad!

**Tips:**
- Inloggningslänken är giltig i 15 minuter
- Länken kan endast användas en gång

### Hur länge är jag inloggad?

Hur länge du förblir inloggad beror på om du kryssar i **"Kom ihåg mig"** vid inloggning:

| Alternativ | Beteende |
|------------|----------|
| **Utan "Kom ihåg mig"** | Du förblir inloggad i **8 timmar** — en arbetsdag |
| **Med "Kom ihåg mig"** | Du förblir inloggad i **30 dagar räknat från ditt senaste besök**, även om du stänger webbläsaren |

**Rekommendation:**
- **Personlig dator/mobil**: Kryssa i "Kom ihåg mig" för bekvämlighet
- **Delad/offentlig dator**: Kryssa **inte** i "Kom ihåg mig" för säkerhetens skull

**Obs:** 30-dagarsklockan börjar om vid varje besök. Använder du Stimma minst en gång i månaden behöver du därför aldrig logga in på nytt — inloggningen förnyas automatiskt så länge du kommer tillbaka inom 30 dagar.

---

## Guide för användare

### Hitta kurser

1. Logga in i Stimma
2. Du ser en översikt över tillgängliga kurser på startsidan
3. Kurser kan filtreras efter:
   - Svårighetsgrad (nybörjare, medel, avancerad)
   - Taggar/kategorier
4. Klicka på en kurs för att se dess innehåll

### Genomföra en lektion

1. Öppna en kurs och klicka på en lektion
2. Läs igenom lektionsinnehållet
3. Titta på eventuella videor
4. Använd resurslänkarna för fördjupning
5. Om lektionen har en quiz:
   - Läs frågan noggrant
   - Välj ett av tre svarsalternativ
   - Klicka "Skicka svar"
6. Gå vidare till nästa lektion

**Obs:** Du måste slutföra lektioner i ordning. Tidigare lektioner måste vara avklarade innan du kan gå vidare.

### Använda AI-tutorn

Vissa lektioner har en inbyggd AI-tutor som kan hjälpa dig:

1. I lektionsvyn, hitta chattfunktionen
2. Skriv din fråga
3. AI:n svarar baserat på lektionens innehåll
4. Du kan ställa följdfrågor

**Tips:** AI-tutorn är tränad på just den lektion du befinner dig i och kan förklara koncept på nya sätt.

### Följ din progress

- Din framstegspanel visar:
  - Hur många lektioner du har slutfört
  - Pågående kurser
  - Dina quiz-resultat
  - Senast besökta lektion

### Din dashboard

Efter inloggning kommer du till din personliga dashboard som ger en översikt över din utbildning:

1. **Statistikpanelen** visar:
   - Totalt antal XP-poäng
   - Din nuvarande nivå
   - Antal slutförda kurser
   - Antal intjänade diplom

2. **Pågående kurser** - Se dina aktiva kurser med progressindikator

3. **Senaste aktivitet** - Snabb åtkomst till senast besökta lektioner

4. **Achievements** - Dina senaste utmärkelser och medaljer

### Gamification - XP och nivåer

Stimma belönar ditt lärande med XP-poäng och nivåer:

**Tjäna XP genom att:**
- **Slutföra lektioner** - Varje lektion ger XP baserat på längd
- **Klara quiz** - Rätt svar ger bonus-XP
- **Slutföra kurser** - Stor XP-bonus vid kursslut
- **Daglig aktivitet** - Streak-bonus för regelbundet lärande

**Nivåsystemet:**
| Nivå | XP krävs | Titel |
|------|----------|-------|
| 1 | 0 | Nybörjare |
| 2 | 100 | Lärling |
| 3 | 300 | Student |
| 4 | 600 | Utforskare |
| 5 | 1000 | Expert |
| 6+ | +500/nivå | Mästare |

**Achievements (Utmärkelser):**
- 🎯 **Första steget** - Slutför din första lektion
- 📚 **Kursklart** - Slutför din första kurs
- 🔥 **På rad** - 7 dagars streak
- ⭐ **Quiz-mästare** - 10 rätta quiz-svar i rad
- 🏆 **Certifierad** - Erhåll ditt första diplom

### Diplom

När du slutför en kurs får du automatiskt ett diplom:

1. Slutför alla lektioner i kursen
2. Diplomet genereras automatiskt
3. Hitta dina diplom på din dashboard under **"Mina diplom"**
4. Ladda ner diplomet som PDF
5. Dela eller skriv ut diplomet

**Diplomet innehåller:**
- Ditt namn
- Kursens namn
- Slutförandedatum
- Unikt diplomnummer
- Organisationens logotyp

### Lärvägar

En lärväg samlar flera kurser som hör ihop, till exempel "Introduktion för nyanställda". Du hittar dem under **Lärvägar** i vänstermenyn — posten visas bara om du har minst en lärväg — och som en sammanfattning under **Mina lärvägar** på översikten.

**Så läser du lärvägen:**

1. Kurserna är numrerade i en rekommenderad ordning
2. **Ordningen är bara en rekommendation** — ingenting är låst, du kan börja var du vill
3. Varje kurs visar din status:
   - **Genomförd** — diplomet är utfärdat, med länk till diplomet
   - **Påbörjad** — du har klarat minst en lektion, med procentsats
   - **Registrerad** — du är inskriven men har inte börjat än
   - **Ej påbörjad**
4. Längst upp visas hur många kurser du klarat och lärvägens totala procent

**Bra att veta:**
- Kurser du inte har åtkomst till visas inte i lärvägen, och räknas inte i din procent
- Det finns inget separat lärvägsdiplom — du får kursernas ordinarie diplom
- Ingår en stegvis kurs i lärvägen gäller dess lektionslåsning som vanligt inne i kursen

---

## Guide för redaktörer

Som redaktör kan du skapa och hantera utbildningsinnehåll.

### Åtkomst till adminpanelen

1. Logga in med ditt redaktörskonto
2. Gå till `/admin` eller klicka på "Admin" i menyn
3. Du ser en översikt med dina tilldelade kurser

### Skapa en ny kurs

Redigera-kurs-sidan är organiserad i fyra flikar som motsvarar olika
arbetsmoment:

- **Allmänt** — titel, beskrivning, bild, slutdatum, avslutssida och kursredaktörer
- **Stegvisa lektioner** — toggle, registreringstyp, intervall, e-postmallar och aktiv inskrivning av användare
- **Tilldelning & synlighet** — vem ska se kursen i sin kurskatalog (synlighetsinställning) plus taggar och organisationstaggar
- **Publicering** — aktiv/inaktiv-toggle och publik kurs (registreringslänk för externa)

Steg för att skapa en ny kurs:

1. Gå till **Kurser** i adminmenyn
2. Klicka på **"Ny kurs"**
3. På fliken **Allmänt** fyll i:
   - **Titel** - Kursens namn
   - **Beskrivning** - Vad kursen handlar om
   - **Bild** - Ladda upp en kursbild eller klicka **"Generera AI-bild"**
   - **Slutdatum** - Ange ett datum om kursen ska vara genomförd senast ett visst datum (valfritt)
   - **Avslutssida** - HTML-innehåll som visas när deltagaren slutfört kursen
4. Klicka **"Spara"**
5. Justera Tilldelning & synlighet och Publicering på respektive flik när du är redo

> Aktiv inskrivning (med e-post + påminnelser) konfigureras under **Stegvisa
> lektioner**-fliken. Synlighet i kurskatalogen (utan mail) sätts under
> **Tilldelning & synlighet**. Skillnaden är tydligt markerad i UI:t —
> aktiv inskrivning *pushar* kursen till valda användare, synlighet
> *visar* kursen i deras katalog så de kan välja att starta själva.

### Ange slutdatum för en kurs

Du kan ange ett slutdatum för när en kurs ska vara genomförd:

1. Öppna kursen för redigering
2. Hitta fältet **"Slutdatum"**
3. Välj ett datum i datumväljaren
4. Klicka **"Spara"**

**Tips:**
- Lämna fältet tomt om inget slutdatum ska gälla
- Slutdatumet visas i påminnelsemail till användare som inte slutfört kursen
- Användare ser hur många dagar som återstår

### Kursens avslutningsinnehåll

När en användare klarar sista lektionen skickas hen till en **kursavslutssida** som visar ett gratulationsmeddelande och länk till diplomet. Du kan anpassa innehållet per kurs:

1. Öppna kursen för redigering
2. Scrolla till fältet **"Avslutningsinnehåll"** (HTML-editor med full verktygsrad)
3. Skriv t.ex. reflektionsfrågor, nästa steg eller länkar till fördjupning
4. Klicka **"Spara"**

Lämnar du fältet tomt används en standardtext med kursnamn + diplomlänk.

### Stegvisa kurser

Stegvisa kurser levererar en lektion i taget med tidsstyrt intervall. Användare måste klara varje lektion innan nästa blir tillgänglig.

1. Öppna kursen för redigering
2. Kryssa i **"Stegvisa lektioner"**
3. Välj **registreringstyp**:
   - **Gemensamt startdatum** — alla berörda användare startar tillsammans på kursens startdatum. Passar utbildningar där en grupp ska följa samma schema.
   - **Löpande registrering** — admin skriver in användare en i taget med valfritt individuellt startdatum. Passar on-boarding-kurser där nya användare tillkommer löpande.
4. Konfigurera:
   - **Dagar mellan lektioner** - Antal dagar innan nästa lektion blir tillgänglig efter avklarad lektion (standard: 7)
   - **Påminnelse efter (dagar)** - Antal dagar innan en påminnelse skickas om användaren inte slutfört sin aktuella lektion (standard: 3)
   - **Startdatum** - (endast vid Gemensamt startdatum) datum för automatisk kursstart. Alla berörda användare registreras och får sin första lektion detta datum.
5. Klicka **"Spara"**

**Status för stegvisa kurser:**

| Status | Betydelse |
|--------|-----------|
| **Väntar** | Kursen har ett startdatum men har inte startats ännu |
| **Skickar** | E-post skickas ut till användare |
| **Aktiv** | Kursen är igång och lektioner levereras löpande |
| **Slutförd** | Alla användare har fått alla lektioner |

### E-postmallar för stegvisa kurser

Du kan anpassa e-postmallarna som skickas när en ny lektion blir tillgänglig och vid påminnelser. Om mallarna lämnas tomma används en standardmall.

1. Öppna kursen för redigering
2. Scrolla ned till stegvisa inställningar
3. Under **"E-postmall: Ny lektion"** fyller du i:
   - **Ämnesrad** - T.ex. `Ny lektion tillgänglig: {{lesson_title}}`
   - **Brödtext** - Meddelandets innehåll
4. Under **"E-postmall: Påminnelse"** fyller du i:
   - **Ämnesrad** - T.ex. `Påminnelse: {{lesson_title}} väntar på dig`
   - **Brödtext** - Påminnelsemeddelandets innehåll
5. Klicka **"Spara"**

**Tillgängliga variabler i mallarna:**

| Variabel | Beskrivning |
|----------|-------------|
| `{{user_name}}` | Användarens namn |
| `{{user_email}}` | Användarens e-postadress |
| `{{course_title}}` | Kursens titel |
| `{{lesson_title}}` | Lektionens titel |
| `{{lesson_url}}` | Direktlänk till lektionen |
| `{{lesson_number}}` | Lektionens nummer i kursen |
| `{{total_lessons}}` | Totalt antal lektioner i kursen |
| `{{course_url}}` | Länk till kurssidan |
| `{{deadline}}` | Kursens slutdatum (t.ex. "15 mars 2026") |
| `{{days_remaining}}` | Antal dagar kvar till deadline |
| `{{system_name}}` | Systemets namn (t.ex. "Stimma") |

**Tips:** Lämna mallarna tomma för att använda standardmallarna. Standardmallarna innehåller en snygg HTML-layout med kursnamn, lektionsnamn och en "Gå till lektionen"-knapp.

### Testmail för stegvisa kurser

Innan du startar en stegvis kurs bör du verifiera att e-postmallarna ser bra ut:

1. Öppna kursen för redigering (kursen måste vara sparad)
2. Scrolla ned till **"Skicka testmail"** under stegvisa inställningar
3. Ange din e-postadress (din adress är förifylld)
4. Klicka **"Skicka test"**
5. Kontrollera din inkorg — du får **två testmail**: ett "Ny lektion"-mail och ett "Påminnelse"-mail
6. Mallarna renderas med exempelvärden så du kan se hur det slutgiltiga mailet ser ut

### Starta stegvis kurs manuellt

Du kan starta en **Gemensamt startdatum**-kurs omedelbart utan att vänta på startdatumet:

1. Öppna kursen för redigering
2. Scrolla ned till **"Starta utskick nu"**-knappen (visas bara om kursen inte redan startats och registreringstypen är Gemensamt startdatum)
3. Klicka på knappen och bekräfta
4. Systemet:
   - Registrerar alla berörda användare (baserat på organisationstaggar, eller alla i domänen)
   - Köar e-post för första lektionen
   - Skickar en första batch direkt
   - Återstående e-post skickas via det nattliga cron-jobbet
5. Du ser en bekräftelse med antal registrerade användare och köade e-post

**Obs:** När kursen väl startats kan den inte startas igen via knappen. Nya användare som tillkommer kan skrivas in manuellt (se nedan).

### Skriv in enskilda användare i en stegvis kurs

Oavsett registreringstyp kan admin skriva in enskilda användare för hand. Användbart när någon missas i org-taggarna eller tillkommer sent.

1. Öppna kursen för redigering
2. Scrolla ned till kortet **"Skriv in användare"**
3. Sök fram användaren (namn eller e-post) eller välj en org-tagg
4. Ange startdatum:
   - **Löpande registrering** — välj valfritt datum (datumväljaren visas). Kan vara i framtiden — användaren låses då upp automatiskt det datumet.
   - **Gemensamt startdatum** — startdatum är låst till kursens startdatum (visas som infotext). Om kursens startdatum är passerat eller inte satt får användaren första lektionen direkt.
5. Klicka **"Skriv in"**
6. Om startdatumet är idag/passerat: första lektionens e-post skickas direkt
7. Om startdatumet ligger i framtiden: schemaläggs, cronen skickar vid rätt tidpunkt

**Obs:** Användaren måste tillhöra en av organisationens domäner. Manuella inskrivningar skrivs aldrig över av det automatiska kurs-start-cron-jobbet (INSERT IGNORE-kontrakt).

### Skapa lektioner

Lektionslistan har tre sätt att lägga till en ny lektion:
- **Ny lektion** — manuell skapelse
- **AI-generera lektion** — beskriv ämnet i fri text, AI bygger
  innehåll + quiz (se egen sektion nedan)
- Lektioner skapas också automatiskt vid AI-kursgenerering eller
  PowerPoint-import

Steg för manuell skapelse:

1. Öppna kursen du vill lägga till lektioner i
2. Klicka på **"Ny lektion"**
3. Fyll i:
   - **Titel** - Lektionens namn
   - **Typ** - Lektion (med quiz) eller Informationssida (utan quiz)
   - **Innehåll** - Lektionstexten (stödjer HTML-formatering)
   - **Längd** - Uppskattad tid i minuter
   - **Video-URL** - Länk till video (valfritt)
   - **Resurslänkar** - Externa länkar för fördjupning
4. Ladda upp en lektionsbild eller klicka **"Generera AI-bild"**
5. Klicka **"Spara"**

Quizfrågor hanteras i en separat vy. **Klicka direkt på Quiz-badgen
i lektionslistan** (eller på frågetecken-knappen i åtgärder-kolumnen)
för att gå direkt dit utan att öppna lektionsredigeraren först. När
du är klar tar tillbaka-länken dig till lektionslistan så du kan
fortsätta jobba med fler lektioner i kursen.

### Generera AI-bild för kurs eller lektion

1. I redigera kurs/lektion, leta upp bildavsnittet
2. Klicka på knappen **"Generera AI-bild"**
3. Vänta medan DALL-E 3 skapar bilden (kan ta 10-30 sekunder)
4. Bilden läggs automatiskt till kursen/lektionen
5. Klicka **"Spara"** för att behålla ändringen

### Ordna lektioner

1. Öppna en kurs
2. Dra och släpp lektioner för att ändra ordningen
3. Ändringen sparas automatiskt

### Informationssidor

Utöver lektioner kan kursen innehålla **informationssidor** — sidor som fungerar som lektioner (samma redigerare för text, bild, video, ljud och bakgrundsfärg) men som:

- **Inte räknas som lektioner** i stegvisa kurser (ingår inte i "X av Y lektioner"-summan)
- **Kräver inget quiz** — användaren klickar bara en "Fortsätt"-knapp för att gå vidare
- Inte visar "Avklarad"-badgen när de lästs

**Skapa en informationssida:**

1. Klicka **"Ny lektion"** i kursens lektionslista
2. Under **Typ** väljer du **Informationssida**
3. När Informationssida är vald döljs AI-rutor och quiz-sektionen automatiskt, och spara-knappen ändras till **"Skapa sida"**
4. Välj i dropdownen **"Tillhör lektion"**:
   - En specifik lektion — sidan låses upp samtidigt som den lektionen i stegvisa kurser
   - **Fristående** — sidan är alltid tillgänglig så snart kursen är tillgänglig (används för kursens startsida / välkomstsida)
5. Skriv innehållet och klicka **"Skapa sida"**

**Tillhör: föregående eller nästa lektion?**

Placeringen av infosidan i sort_order bestämmer om den ligger *före* eller *efter* den lektion den tillhör:

- **Intro-sida före en lektion** — placera infosidan precis före lektion B och sätt "Tillhör lektion = B". När lektion B blir tillgänglig blir även intro-sidan det, och mail-länken för "ny lektion" går till intro-sidan först.
- **Outro-sida efter en lektion** — placera infosidan precis efter lektion A och sätt "Tillhör lektion = A". Användaren når outro-sidan via "Fortsätt" efter att ha klarat A.

I lektionslistan i adminvyn visas infosidor med blå bakgrund och en **Tillhör**-kolumn. Pil-knappen bredvid tillhörigheten cyklar mellan **föregående lektion → nästa lektion → fristående** för snabb omkoppling utan att öppna redigeraren.

### Kursens startsida

En välkomst-/startsida är bara en **fristående informationssida** som ligger först i sort_order. När användaren klickar "Börja kursen" på kursens landningssida hamnar hen på startsidan, läser välkomsttexten och klickar **"Fortsätt"** för att komma till första riktiga lektionen.

Skapa den precis som en vanlig informationssida:

1. **Typ** = Informationssida
2. **Tillhör lektion** = Fristående
3. Dra sidan högst upp i lektionslistan (sort_order 0/1)

### Förhandsgranska lektioner

Innan du publicerar kan du förhandsgranska hur en lektion ser ut för användare:

1. Gå till **Kurser** och välj en kurs
2. I lektionslistan, klicka på **ögon-ikonen** (👁️) bredvid lektionen
3. Lektionen öppnas i förhandsgranskningsläge
4. En orange banner visas längst upp: "FÖRHANDSVISNING"
5. Testa lektionen - quiz, innehåll, video etc.
6. **Ingen data sparas** - din progress påverkas inte

**Tips:** Använd förhandsgranskning för att:
- Kontrollera att quiz fungerar korrekt
- Verifiera att videolänkar fungerar
- Se hur innehållet presenteras för användare
- Testa AI-tutorn om den är aktiverad

### Skapa och hantera lärvägar

En lärväg grupperar flera kurser till ett sammanhängande upplägg med en rekommenderad ordning.

**Skapa lärvägen:**

1. Gå till **Lärvägar** i adminmenyn
2. Skriv ett namn under **"Skapa ny lärväg"** — du kommer direkt till redigeringsvyn
3. Fyll i beskrivning (ren text) och ladda gärna upp en bild
4. Lägg till kurser från **Tillgängliga kurser** till höger med plusknappen
5. Dra kurserna i vänsterpanelen för att sätta ordningen — numreringen uppdateras direkt
6. Sätt status till **Aktiv** och klicka **Spara lärvägen**

Nya lärvägar skapas som **inaktiva** så att de inte visas för deltagare innan kurserna är på plats. I lärvägslistan kan du dra raderna för att styra i vilken ordning lärvägarna visas.

**Synlighet:**

| Val | Effekt |
|-----|--------|
| Delas med hela organisationen (standard) | Alla användare i organisationens domäner ser lärvägen |
| Dela med vissa domäner | Endast valda medlemsdomäner ser den |
| Global (endast superadmin) | Alla organisationer ser den |

Synligheten kan bara ändras av administratör på organisationens huvuddomän. Skapar du som administratör på en underdomän låses lärvägen automatiskt till din egen domän.

**Viktigt att veta:**

- **Ordningen låser ingenting.** Deltagaren ser kurserna numrerade men kan ta dem i valfri ordning. Vill du styra takten inom en kurs använder du stegvisa kurser.
- **Ingen separat tilldelning.** Alla som ser lärvägen har den — det finns ingen anmälningslista. Begränsa istället med delade domäner.
- **Kurser deltagaren saknar åtkomst till döljs** och räknas inte i deltagarens procent. Stegen numreras om så inga hål uppstår. Sådana kurser märks med **Begränsad synlighet** i redigeringsvyn.
- **Radering av lärvägen tar bara bort grupperingen.** Kurser, resultat och diplom påverkas inte. Raderar du däremot en kurs som ingår i en lärväg varnas du först.
- **Kopierade kurser ärver inte lärvägsmedlemskap** — lägg in kopian manuellt om den ska ingå.

Behörighet: administratör hanterar alla lärvägar i sin organisation, redaktör hanterar de lärvägar hen själv skapat, superadministratör hanterar alla.

### Skapa AI-genererad kurs

1. Gå till **Kurser**
2. Klicka på **"AI Generera kurs"**
3. Skriv en fri "råidé" (upp till 15000 tecken) — beskriv målgrupp,
   ämne och vad deltagaren ska lära sig. AI putsar fram ett kursnamn
   och en strukturerad beskrivning du kan justera
4. Välj inställningar:
   - Antal lektioner (1-20)
   - Svårighetsgrad, ton, språkstil, textlängd
   - Om quiz ska inkluderas
   - Om AI-tutor ska aktiveras
   - Bildalternativ (inga, sökta från internet, AI-genererade via DALL-E)
5. Klicka **"Generera"**
6. Kursen skapas i bakgrunden — följ statusen i kurslistan
7. Resulterande kurs skapas som **inaktiv** så du kan granska och
   justera innan publicering

### AI-generera en lektion till befintlig kurs

För att lägga till en enskild AI-genererad lektion till en befintlig kurs:

1. Öppna kursens lektionslista
2. Klicka **"AI-generera lektion"**
3. I fritext-fältet beskriv vad lektionen ska handla om (20–10000 tecken)
4. Välj:
   - **Typ** — Lektion eller Informationssida
   - **Textlängd** — Kort, Medium, eller Lång
   - **Ton** — Pedagogisk, Formell, Avslappnad eller Inspirerande
   - **Inkludera quiz** — kryssruta (döljs för informationssida)
5. Klicka **"Generera"** — anropet är synkront och tar ~30–60 sekunder
6. Den nya lektionen läggs sist i kursens sort_order. Använd
   drag-and-drop för att flytta den till önskad position

> AI-genererat innehåll bör alltid granskas före publicering. AI kan
> hitta på funktioner eller fakta som inte stämmer. Lägg särskilt
> märke till "Visste du att…"-rutor och tekniska påståenden.

### Importera PowerPoint till kurs

För att konvertera en befintlig PowerPoint-presentation till en
Stimma-kurs:

1. Gå till **Kurser**
2. Klicka **"Importera PowerPoint"**
3. Välj en `.pptx`-fil (max 50 MB)
4. Välj inställningar (ton, textlängd, svårighet, språkstil, quiz,
   AI-tutor, videolänkar) — samma som AI-kursgenereringen
5. Klicka **"Ladda upp och bearbeta"**
6. Kursen genereras i bakgrunden (typiskt 1-3 minuter)

**Det här följer med automatiskt:**
- Slide-titel → lektionstitel
- Slide-text + talar-notes → AI-utvecklat lektionsinnehåll
- Inbäddade PNG/JPG/GIF/WebP-bilder → första bilden per slide blir
  lektionens hero-bild
- 1 slide = 1 lektion, ordning bevarad
- Quizfrågor + AI-tutor + videolänkar (om valt)

**Begränsningar att vara medveten om:**
- Max **25 slides** per import (cap)
- Max **50 MB** filstorlek
- **Vektorgrafik, diagram, SmartArt och dekorerade textrutor**
  rasteras inte till bilder — texten extraheras men visuell layout
  försvinner. Kräver LibreOffice headless som inte är installerat på
  servern.
- **Tabeller** blir radvis text — visuell tabellstruktur förloras
- **Animations & transitioner** ignoreras

Den genererade kursen skapas **inaktiv** så du kan granska och justera
innan publicering.

### Importera SCORM-paket till kurs

Ett SCORM-paket (`.zip`) från Storyline, Rise 360, iSpring, Captivate eller
en annan LMS-export kan göras om till en vanlig Stimma-kurs.

**Viktigt att förstå:** paketet *spelas inte upp* i Stimma. Stimma är ingen
SCORM-spelare. Importen läser paketets innehåll och bygger vanliga
Stimma-lektioner av det. Paketets egen HTML och JavaScript körs aldrig — det
är ett medvetet säkerhetsval, eftersom främmande JavaScript på samma domän
som Stimma skulle kunna komma åt inloggade användares sessioner.

Så här gör du:

1. Gå till **Kurser**
2. Klicka **"Importera SCORM"**
3. Välj paketets `.zip`-fil (max 100 MB)
4. Välj hur innehållet ska hanteras (se nedan)
5. Klicka **"Ladda upp och bearbeta"**

#### Kopiera innehållet (standard)

Originalet följer med så oförändrat som formatet tillåter:

- **Texten kopieras ordagrant** — ingen AI skriver om något
- **Alla bilder** hamnar i den lektion de hörde till
- **Alla filmer** kopieras in i Stimma och spelas direkt i lektionen
- Frågor och svarsalternativ följer med som text
- Avsnittsindelningen i paketet blir kursens lektioner

Ingen AI är inblandad, inga tokens förbrukas och kursen är klar på några
sekunder. Det här är rätt val när kursen ska likna originalet.

Exempel: MSB:s utbildning Disa (Storyline, 88 MB) blir 11 lektioner med
11 filmer, 27 bilder och drygt 18 000 tecken originaltext.

#### Låt AI skriva om innehållet

Texten skickas till AI-pipelinen som återförfattar den till Stimmas
lektionsformat och genererar nya quizfrågor och eventuell AI-tutor. Tar
1–3 minuter och förbrukar tokens. Välj det här när du vill ha ett nytt,
mer Stimma-likt material snarare än en kopia.

#### Det här följer aldrig med

- **Paketets provlogik och poängsättning.** Frågorna följer med som text i
  kopieringsläget, men rättas inte automatiskt. Behöver någon kunna visa
  godkänt resultat på *just det provet* krävs en riktig SCORM-spelare.
- **Interaktioner, animationer, förgreningar och grafisk design**
- **Text som ligger som bild** (vanligt i iSpring och Captivate)

#### Kvaliteten beror på källverktyget

SCORM standardiserar bara förpackningen, inte innehållet:

- **Articulate Storyline** — läses direkt ur paketets egen datamodell. Scener
  blir lektioner, filmen i varje scen blir lektionens video, och texten hämtas
  från paketets alternativtexter (som är renare än den grafiska texten).
- **Vanliga HTML-paket** — sidans HTML städas och sparas som lektionstext med
  bilderna kvar.
- **Rise 360, iSpring, Captivate** — innehållet ligger i JavaScript-data och
  skördas därifrån. Fungerar, men strukturen blir enklare.

**Kontrollera rättigheterna innan du importerar.** Köpta SCORM-paket är
normalt licensierade för uppspelning, inte för kopiering till eget
redigerbart material. Disa är fri att använda under CC BY-SA 4.0.

Kursen skapas **inaktiv** så du kan granska och justera innan publicering.

### Kopiera en befintlig kurs

I kurslistan finns en kopia-knapp (📁-ikon, grön outline) per kursrad.
Klicka den och bekräfta i dialogrutan — en kopia skapas i din
organisation som inaktiv så du kan justera innan publicering.

Kopian inkluderar alla lektioner, quiz, avslutningsinnehåll och
inställningar. Du blir automatiskt redaktör på kopian (om du inte
redan är admin).

### Exportera/Importera kurser

**Exportera:**
1. Öppna kursen
2. Klicka **"Exportera JSON"**
3. Filen laddas ned

**Importera:**
1. Gå till **Kurser**
2. Klicka **"Importera"**
3. Välj JSON-filen
4. Kursen skapas (inaktiv som standard)

### Hantera taggar

1. Gå till **Taggar** i adminmenyn
2. Skapa nya taggar med **"Ny tagg"**
3. Taggar är organisationsspecifika
4. Använd taggar för att kategorisera kurser

---

## Guide för administratörer

Som admin har du utökade behörigheter inom din organisation.

### Användarhantering

1. Gå till **Användare** i adminmenyn
2. Du ser alla användare i din organisation (baserat på e-postdomän)
3. För varje användare kan du:
   - **Göra till admin** - Ger fulla adminbehörigheter
   - **Göra till redaktör** - Ger behörighet att skapa kurser
   - **Ta bort användare** - Raderar användaren och all progress

### Tilldela kursredaktörer

1. Öppna kursen för redigering
2. Scrolla till **"Kursredaktörer"**
3. Sök efter användare med e-post
4. Klicka **"Lägg till"** för att tilldela dem
5. Redaktörer kan nu redigera just den kursen

### Användarsynkronisering

Istället för att lägga till användare manuellt kan du synka en komplett
användarlista per domän — antingen automatiskt från ett externt system
(HR, katalogtjänst/AD, elevregister) via REST-API, eller manuellt via det
inbyggda synkverktyget.

Synk skapar och uppdaterar användare automatiskt och kan markera de som
inte längre finns med i listan som inaktiva. **Inloggning påverkas aldrig** —
även en inaktiv användare kan fortfarande logga in med magic link.

#### Steg 1 – Aktivera synk och skapa API-nyckel

1. Gå till **API-nycklar** i adminmenyn.
2. I sektionen **Synkronisering per domän** högst upp: klicka **Aktivera synk**
   för organisationens primärdomän. (API-anrop avvisas om synk inte är aktiverad.)
   Underdomäner ärver inställningen och har därför ingen egen knapp.
3. Klicka **Skapa ny nyckel**, ange en beskrivning (t.ex. "AD-synk") och välj
   domän. Vanlig admin ser sin egen domän; superadmin kan välja valfri.
4. Nyckeln visas **bara en gång** i ett popup-fönster — kopiera den direkt och
   lägg in den i ditt externa system. Tappar du bort den får du regenerera en ny.
   - Nyckeln har formatet `stm_` följt av 60 tecken.
   - **En aktiv nyckel per organisation**, utfärdad på primärdomänen. Underdomäner
     kan inte få egna nycklar — de täcks av primärdomänens. *Regenerera* skapar en
     ny och inaktiverar den gamla omedelbart; *Inaktivera* pausar utan att radera;
     *Radera* tar bort.

#### Steg 2 – Anropa synk-API:t

Det externa systemet skickar hela användarlistan med ett `POST`-anrop:

```
POST https://stimma.sambruk.se/api/sync_users.php
Authorization: Bearer stm_din_nyckel_här
Content-Type: application/json

{
  "users": [
    { "email": "anna@dindoman.se", "name": "Anna Andersson", "role": "användare" },
    { "email": "bo@dindoman.se",   "name": "Bo Bengtsson",   "role": "redaktör" }
  ],
  "deactivate_missing": true
}
```

**Fält per användare:**

| Fält | Krav | Värden |
|---|---|---|
| `email` | Obligatoriskt | Måste tillhöra **nyckelns domän eller någon av dess underdomäner** |
| `name` | Obligatoriskt | För- och efternamn |
| `role` | Valfritt | `användare` (standard), `redaktör` eller `admin` |
| `delete` | Valfritt | `true` **raderar kontot permanent**. Då behövs bara `email` på posten |

> **Läsbehörighet sätts inte via API:et.** Läsbehörig är ingen roll utan en
> separat behörighet, och `role` har därför inget sådant värde. Behörigheten
> delas ut i användarlistan, eller för många konton i taget via
> **Admin → Synkverktyg → Läsbehörighet i batch**. En synk mot API:et lämnar
> läsbehörigheten orörd — den kan alltså varken sättas eller råka tas bort
> därifrån.

> **Äldre rollvärden:** `student` och `teacher` accepteras fortfarande och betyder
> samma sak som `användare` respektive `redaktör`. Ni behöver alltså inte ändra en
> synk som redan fungerar.

**Domänomfång — nyckeln gäller hela organisationen:**

En API-nyckel utfärdas alltid för organisationens **primärdomän**, och gäller då
även alla **underdomäner**. En nyckel för `kommun.se` får alltså synka både
`anna@kommun.se` och `bo@utb.kommun.se`. Underdomäner har inga egna nycklar och
ingen egen synkinställning — allt styrs på primärdomänen.

> **Viktigt:** eftersom nyckeln omfattar hela organisationen måste en synk innehålla
> *alla* användare i den. Skickas bara `kommun.se`-användare markeras användarna på
> `utb.kommun.se` som inaktiva eftersom de saknas i listan. Sätt
> `"deactivate_missing": false` om ni behöver synka en del i taget.

Matchningen kräver punkt före domänen, så en nyckel för `kommun.se` ger ingen
åtkomst till `storkommun.se`.

**Övriga inställningar:**

- `deactivate_missing` (standard `true`): synkade användare som **saknas** i
  listan markeras som inaktiva. Sätt `false` för att enbart skapa/uppdatera
  utan att inaktivera någon.
- Max **10 000** användare per anrop.
- Max **10 anrop per timme** per nyckel (annars svar `429`).

**Vad händer med användarna:**

| Situation | Resultat |
|---|---|
| Finns i listan, ny | Skapas som aktiv |
| Finns i listan, fanns redan | Uppdateras (namn, roll), sätts aktiv |
| Fanns inaktiv, nu med i listan | Återaktiveras |
| Saknas i listan (och `deactivate_missing=true`) | Markeras inaktiv |
| Märkt med `"delete": true` | **Raderas permanent** |

#### Radera en användare

Normalt räcker det att låta personen falla ur listan — då markeras kontot som
inaktivt och historiken finns kvar. Behöver ni verklig radering, till exempel vid
en begäran om radering enligt GDPR, märker ni posten:

```json
{ "users": [ { "email": "anna.svensson@dindoman.se", "delete": true } ] }
```

> ⚠️ **Raderingen går inte att ångra, och personens diplom raderas med kontot.**
> Genomförd utbildning går därefter inte att styrka. Vill ni behålla
> utbildningsbeviset ska ni inaktivera i stället för att radera.

Med kontot försvinner även framsteg, quizsvar, kursanmälningar, org-taggar och
märken. Signerade **PUB-avtal raderas inte** — de är organisationens handling med
egen rättslig grund för bevarande, och innehåller alla uppgifter de behöver även
utan användarkontot.

Att tänka på:

- Adressen måste tillhöra nyckelns domän eller en underdomän, precis som annars.
- Att radera någon som redan är borta ger inget fel. Synken kan köras om.
- **Superadmin-konton kan inte raderas via API.** Sådana begäranden nekas och
  räknas i `deletes_refused` i svaret, så ni ser att de inte gick igenom.
- En payload som *bara* innehåller raderingsposter inaktiverar inte era övriga
  användare, trots att `deactivate_missing` är `true` som standard.
- Flaggan finns bara i API:et. Adminpanelens synkverktyg ignorerar den — där
  raderar ni via knappen på Användare-sidan.

Superadmin-användares roll ändras aldrig av en synk.

**Svar vid lyckad synk:**

```json
{
  "success": true,
  "sync_id": 123,
  "summary": { "total_in_payload": 250, "created": 4, "updated": 245, "deactivated": 1, "reactivated": 0 }
}
```

#### Synkverktyget (manuell synk)

Behöver du inte automatisera kan du använda **Synkverktyg** i adminmenyn. Det är
ett grafiskt gränssnitt för att bygga upp en användarlista och köra synken direkt
mot databasen — **ingen API-nyckel behövs och det finns ingen timgräns**.

**Vem kan använda det?**
- En **admin på organisationens huvuddomän** kan synka till *alla* domäner i
  organisationen i ett enda anrop.
- En **superadmin** kan synka mot alla organisationers domäner.
- En admin på en **underdomän** kan inte använda verktyget (blockeras).

**Bygga listan:**

1. **Lägg till en i taget** — fyll i e-post, namn, roll (Användare/Redaktör/Admin)
   och organisation, eller
2. **Importera en CSV-fil** — klicka **CSV** (import), välj fil och avgränsare
   (`;`, `,` eller tab). En förhandsvisning visas innan import, och du kan välja
   att *ersätta* listan eller *lägga till*.
3. Vet du inte hur filen ska se ut? Klicka **Exempelfil** — den laddar ner en
   färdig CSV med instruktioner och exempelrader (se nedan om organisationstaggar).
4. Listan **sparas i din webbläsare** mellan besök. Du kan söka, redigera rader,
   markera och ta bort flera, samt **exportera** den aktuella listan till CSV.

**Flera organisationstaggar:** i organisations-kolumnen anger du en eller flera
taggar separerade med snedstreck `/`. Varje del blir en egen tagg — t.ex.
`Kommun/IT-avdelningen/Support` ger de tre taggarna *Kommun*, *IT-avdelningen* och
*Support*. Tomma delar ignoreras och blanksteg trimmas bort.

**Köra synken:**

5. Välj om **Inaktivera saknade** ska vara på. Av som standard (säkert läge —
   inga användare inaktiveras); på = användare som inte finns i listan markeras
   inaktiva (se beteendetabellen ovan).
6. Klicka **Synka nu**. Resultatet (skapade/uppdaterade/inaktiverade/
   reaktiverade) visas direkt i loggen.

**Domänspärr:** verktyget synkar bara e-postadresser vars domän tillhör din
organisation (för superadmin: någon registrerad organisationsdomän). Adresser med
andra domäner **hoppas över** och redovisas som överhoppade — du kan alltså inte
av misstag skapa användare på en domän du inte har behörighet till.

#### Följa upp synkar

Under **Synkloggar** i adminmenyn ser du historiken: tidpunkt, domän,
nyckel-prefix, antal i listan, antal skapade/uppdaterade/inaktiverade/
reaktiverade, status (OK/Delvis/Fel), körtid och käll-IP.

### Diplomhantering

Hantera diplom för din organisation:

1. Gå till **Diplom** i adminmenyn
2. Se alla utfärdade diplom i organisationen
3. För varje diplom kan du:
   - Se vem som erhållit diplomet
   - Vilken kurs det gäller
   - Datum för utfärdande
   - Ladda ner diplomet som PDF

**Anpassa diplommall:**
1. Gå till **Diplom** > **Inställningar**
2. Ladda upp organisationens logotyp
3. Anpassa diplomtexten
4. Förhandsgranska resultatet

### Lärvägsstatistik

Följ upp hur deltagarna ligger till i en lärväg: klicka på stapeldiagram-ikonen i lärvägslistan, eller **Statistik** från redigeringsvyn.

**Vyn visar:**

- **Nyckeltal:** antal användare i urvalet, hur många som genomfört hela lärvägen, hur många som påbörjat minst en kurs, och genomsnittlig andel
- **Matris:** en rad per användare, en kolumn per kurs i lärvägen (kolumnrubriken länkar till kursens egen statistik)
- **Summeringsrad:** per kurs, antal genomförda / antal som åtminstone registrerats

**Symboler i matrisen:**

| Symbol | Betydelse |
|--------|-----------|
| ✔ (grön) | Genomförd — diplom utfärdat, datum visas vid hovring |
| Procentbadge | Påbörjad — andel avklarade lektioner |
| Person-ikon (gul) | Registrerad, ingen lektion klar ännu |
| – | Ej påbörjad |

**Filter:**
- **Domänfilter** — begränsa till valda medlemsdomäner (visas när organisationen har flera). Valet skärs alltid mot din behörighet.
- **Visa alla användare** — som standard döljs användare som inte påbörjat något. Slå på för hela listan.
- Listan pagineras vid fler än 200 användare.

Till skillnad från deltagarvyn döljs inga kurser här — du ser lärvägen som den är definierad. Externa deltagare i publika kurser ingår inte, eftersom urvalet bygger på e-postdomän.

### E-postlogg för stegvisa kurser

Följ upp e-postutskick för stegvisa kurser via kursstatistiken:

1. Gå till **Statistik** i adminmenyn
2. Välj en stegvis kurs
3. Scrolla ned till kortet **"E-postlogg"**
4. Här ser du:
   - **Kö-status** — Antal väntande och pågående e-post i kön (visas om det finns väntande utskick)
   - **Utskickstabell** — Historik grupperad per datum och typ:
     - **Ny lektion** (grön badge) — Mail om ny tillgänglig lektion
     - **Påminnelse** (gul badge) — Automatisk påminnelse
     - **Manuell** (blå badge) — Manuellt skickad påminnelse
   - Antal **skickade** och **misslyckade** per rad

**Tips:** Kontrollera loggen efter att du startat en kurs för att verifiera att alla e-post skickats korrekt. Misslyckade utskick markeras i rött.

### Dashboard - Översikt

När du loggar in som admin ser du en dashboard med nyckeltal:

**Rad 1:**
- **Användare** - Antal registrerade användare i organisationen
- **Kurser** - Antal tillgängliga kurser
- **Slutförda lektioner** - Totalt antal lektioner som slutförts
- **Genomsnittlig slutförandegrad** - Hur stor andel av påbörjade kurser som slutförs

**Rad 2:**
- **Fullt genomförda kurser** - Totalt antal gånger en användare slutfört alla lektioner i en kurs
- **Genomförda kurser/användare** - Genomsnittligt antal slutförda kurser per aktiv användare
- **Lektioner** - Totalt antal lektioner i systemet

Dashboarden visar också:
- Aktivitetsgraf för senaste 7 dagarna
- Kursstatistik
- Senaste aktiviteter

### Statistik

1. Gå till **Statistik** i adminmenyn
2. Du ser detaljerad kursstatistik:
   - Progress per kurs
   - Aktivitet per användare
   - Slutförandegrad per kurs
3. Välj en specifik kurs för att se:
   - Vilka användare som påbörjat kursen
   - Vilka lektioner varje användare slutfört
   - Procentuell progress per användare
4. Klicka **"Exportera till Excel"** för att ladda ner statistiken

### Aktivitetsloggar

1. Gå till **Loggar** i adminmenyn
2. Se alla händelser i systemet:
   - Inloggningar
   - Kursändringar
   - Användaråtgärder
3. Filtrera på e-postadress för specifik användare

### Ta bort kurs med alla lektioner

1. Gå till **Kurser**
2. Klicka på **"Ta bort"** för kursen
3. Bekräfta att du vill ta bort kursen och alla dess lektioner
4. Kursen och alla tillhörande lektioner raderas

### Påminnelseinställningar

Konfigurera automatiska påminnelser till användare som inte slutfört sina kurser:

1. Gå till **Påminnelseinställningar** i adminmenyn
2. Aktivera eller inaktivera påminnelser
3. Konfigurera:
   - **Dagar efter kursstart** - Antal dagar innan första påminnelsen skickas
   - **Max antal påminnelser** - Hur många påminnelser som skickas totalt
   - **Dagar mellan påminnelser** - Intervall mellan påminnelser
4. Anpassa e-postämne och meddelandetext

**Tillgängliga variabler i e-postmallen:**
- `{{course_title}}` - Kursens titel
- `{{completed_lessons}}` - Antal slutförda lektioner
- `{{total_lessons}}` - Totalt antal lektioner
- `{{course_url}}` - Länk till kursen
- `{{abandon_url}}` - Länk för att avsluta kursen
- `{{user_name}}` - Användarens namn
- `{{user_email}}` - Användarens e-post
- `{{deadline}}` - Slutdatum (t.ex. "15 februari 2026")
- `{{days_remaining}}` - Antal dagar kvar till deadline
- `{{deadline_info}}` - Komplett mening om deadline (t.ex. "Kursen ska vara genomförd senast 15 februari 2026 (14 dagar kvar).")

### Skicka testmail

Innan du aktiverar påminnelser, testa att e-postinställningarna fungerar:

1. Gå till **Påminnelseinställningar**
2. Hitta sektionen **"Skicka testmail"**
3. Ange din e-postadress (din egen e-post är förifylld)
4. Välj om du vill använda den sparade e-postmallen eller en enkel testmall
5. Klicka **"Skicka testmail"**
6. Kontrollera din inkorg (och skräppost)

**Tips:** Testmailet visar exempelvärden för alla variabler så du kan se hur det slutgiltiga mailet kommer att se ut.

---

## Guide för superadministratörer

Superadministratörer har fullständig systemåtkomst.

### AI-leverantörskonfiguration

Stimma stödjer flera AI-leverantörer. Så här konfigurerar du:

1. Gå till **AI-inställningar** i adminmenyn
2. Under **"AI-leverantör & API-konfiguration"**:
   - **Leverantör** - Välj din AI-leverantör:
     - OpenAI (GPT-4, GPT-4o, etc.)
     - Anthropic (Claude)
     - Google AI (Gemini)
     - Azure OpenAI
     - OpenRouter
     - Anpassad/Lokal
   - **API-nyckel** - Ange din API-nyckel från leverantören
   - **Server-URL** - Sätts automatiskt baserat på leverantör (kan anpassas)
   - **Modell** - Välj AI-modell från dropdown-listan
   - **Max tokens** - Begränsa svarslängden
   - **Temperatur** - Justera kreativitetsnivå (0.0-1.0)

3. Klicka **"Spara inställningar"**

**Tillgängliga modeller per leverantör:**

| Leverantör | Modeller |
|------------|----------|
| OpenAI | GPT-4o, GPT-4o Mini, GPT-4 Turbo, GPT-4, GPT-3.5 Turbo |
| Anthropic | Claude Sonnet 4, Claude 3.5 Sonnet, Claude 3 Opus, Claude 3 Haiku |
| Google | Gemini 1.5 Pro, Gemini 1.5 Flash, Gemini 1.0 Pro |
| Azure | Konfigureras manuellt |
| OpenRouter | Alla tillgängliga modeller via OpenRouter |

### Testa AI-anslutning

Innan du börjar använda AI-funktioner, verifiera att anslutningen fungerar:

1. Gå till **AI-inställningar**
2. Scrolla till **"Testa AI-anslutning"**
3. Klicka på **"Testa anslutning"**
4. Systemet skickar en testförfrågan till AI-leverantören
5. Resultatet visas:
   - ✅ **Grön** - Anslutningen fungerar
   - ❌ **Röd** - Fel uppstod (felmeddelande visas)

**Vanliga fel:**
- "API-nyckel saknas" - Ange API-nyckel och spara först
- "Unauthorized" - Kontrollera att API-nyckeln är korrekt
- "Rate limit exceeded" - Vänta och försök igen
- "Model not found" - Välj en annan modell

### AI-guardrails

1. Gå till **AI-inställningar** i adminmenyn
2. Under **"AI Guardrails & Promptinställningar"** konfigurera:
   - **Guardrails aktiverat** - Säkerhetsbegränsningar för AI-svar
   - **Systemprompt-prefix** - Text som läggs till före alla AI-förfrågningar
   - **Blockerade ämnen** - Ämnen AI:n inte får diskutera
   - **Svarsriktlinjer** - Regler för hur AI:n ska svara
   - **Ämnesbegränsningar** - Begränsa AI till specifika ämnen
   - **Anpassade instruktioner** - Ytterligare instruktioner

### Bästa praxis för guardrails

- **Aktivera guardrails** i produktionsmiljö
- Definiera tydliga **blockerade ämnen** för er verksamhet
- Använd **svarsriktlinjer** för att säkerställa lämplig ton
- Testa AI-svar regelbundet

### Inställningar för stegvisa kurser

Superadministratörer kan konfigurera hur stegvisa kursers e-postutskick hanteras. Dessa inställningar är viktiga för organisationer med många användare för att undvika att e-post fastnar i spamfilter.

1. Gå till **AI-inställningar** i adminmenyn
2. Scrolla ned till kortet **"Stegvisa kurser"**
3. Konfigurera:

| Inställning | Beskrivning | Standard |
|-------------|-------------|----------|
| **Cron-timme (0-23)** | Vilken timme på dygnet det nattliga utskicket körs. Cron-jobbet hittar nya tillgängliga lektioner, köar påminnelser och bearbetar e-postkön. | 8 |
| **Batch-storlek** | Antal e-post som skickas i varje batch. En lägre siffra minskar risken för att e-post markeras som spam. | 10 |
| **Batchfördröjning (sekunder)** | Paus i sekunder mellan varje batch. Sprider utskicken över tid så att e-postservern inte överbelastas. | 30 |

4. Klicka **"Spara stegvisa inställningar"**

**Hur throttling fungerar:**

Istället för att skicka alla e-post på en gång, använder systemet en kö med batchar:

1. Det nattliga cron-jobbet hittar alla nya tillgängliga lektioner och påminnelser
2. Dessa läggs i en e-postkö (`sequential_email_queue`)
3. Kön bearbetas i batchar — t.ex. 10 e-post åt gången
4. Mellan varje batch pausas utskicket — t.ex. 30 sekunder
5. Detta innebär att 100 e-post tar ~5 minuter istället för att skickas på en sekund

**Rekommendationer:**

| Organisationsstorlek | Batch-storlek | Fördröjning |
|---------------------|---------------|-------------|
| Liten (< 50 användare) | 20 | 10 sekunder |
| Medel (50-500 användare) | 10 | 30 sekunder |
| Stor (500+ användare) | 5 | 60 sekunder |

**Tips:** Om ni upplever att e-post hamnar i skräppost, prova att minska batch-storleken och öka fördröjningen. Kontrollera även att er SMTP-server har korrekta SPF- och DKIM-poster.

### Domänhantering

Hantera vilka e-postdomäner som får tillgång till systemet:

1. Gå till **Domäner** i adminmenyn
2. Se lista över tillåtna domäner
3. Lägg till ny domän med **"Lägg till domän"**
4. Ta bort domäner som inte längre ska ha tillgång

**Tips:** Endast användare med e-postadresser från tillåtna domäner kan logga in.

---

## PUB-avtal (Personuppgiftsbiträdesavtal)

Ett PUB-avtal reglerar hur personuppgifter behandlas mellan en organisation och Sambruk. Enligt GDPR krävs avtalet innan personuppgifter behandlas i systemet. Organisationer utan tecknat avtal ser en varningsruta i gränssnittet, och PUB-status visas som en badge i sidhuvudet (✅ tecknat / ⚠️ saknas).

### Räckvidd: organisation eller domän?

Ett tecknat PUB-avtal gäller antingen en **hel organisation** eller en **enskild e-postdomän** — beroende på hur domänen är upplagd:

| Situation | Vad avtalet gäller |
|---|---|
| **Domänen är grupperad i en organisation** | Avtalet lyfts till organisationsnivå. **En enda signering gäller för samtliga domäner** i organisationen — ingen domän behöver teckna separat. |
| **Domänen är ogrupperad** | Avtalet gäller **endast den domänen**. Varje fristående domän tecknar sitt eget avtal. |

En superadmin grupperar domäner under en organisation via **Organisationer** i adminmenyn.

**Exempel:** En kommun som använder både `kommun.se` och `utbildning.kommun.se` kan gruppera båda under en organisation. Då räcker det att en behörig person tecknar avtalet en gång, och det gäller automatiskt för alla användare på samtliga domäner.

**Legacy-fall:** Om en domän tecknade PUB-avtal *innan* den lades in i en organisation fortsätter avtalet att gälla — systemet känner igen detta och kräver ingen ny signering.

### Teckna PUB-avtal

En behörig person i organisationen tecknar avtalet digitalt:

1. **Granska avtalet** — läs igenom PUB-avtalets PDF. Avtalet måste först vara kontrasignerat av Sambruk.
2. **Fyll i uppgifter och verifiera med SMS** — ange namn, titel och e-post, verifiera identiteten med en 6-siffrig SMS-kod och intyga behörighet att teckna avtal för organisationen.
3. **Ange organisationsuppgifter och signera** — fyll i organisationsnamn och organisationsnummer och signera. Ett stämplat PDF-avtal skapas och skickas per e-post.

Efter signering skickas det stämplade avtalet automatiskt till undertecknaren, organisationens registrator och Sambruk.

**Spårbarhet:** Oavsett räckvidd sparas ett signeringsbevis som knyts till **både** domänen som tecknade och organisationen. Beviset innehåller undertecknare, tidsstämpel, IP-adress, SMS-verifiering och en SHA-256-hash av det signerade PDF-dokumentet.

---

## Tips och bästa praxis

### För användare
- Ta en lektion i taget - microlearning fungerar bäst i korta pass
- Använd AI-tutorn aktivt om du fastnar
- Repetera lektioner vid behov

### För redaktörer
- Håll lektioner korta (5-10 minuter)
- Inkludera alltid en quiz för att förstärka lärandet
- Använd AI-bildgenerering för konsekvent visuellt uttryck
- Testa kursen själv innan du aktiverar den

### För administratörer
- Granska loggar regelbundet
- Följ upp statistik för att identifiera problem
- Kommunicera med redaktörer om innehållskvalitet

---

## Felsökning

### Problem: Inloggningslänken fungerar inte
- Kontrollera att länken inte är äldre än 15 minuter
- Länken kan endast användas en gång
- Begär en ny länk

### Problem: Kan inte se kurser
- Kontrollera att kursen är aktiverad
- Kursen kanske tillhör en annan organisation

### Problem: AI-bildgenerering misslyckas
- Kontrollera att OpenAI API-nyckeln är konfigurerad
- Försök igen om det är tillfälligt serverfel

### Problem: Quiz sparas inte
- Se till att du fyllt i alla fält (fråga, tre svar, rätt svar)
- Kontrollera att rätt svar är 1, 2 eller 3

---

## Support

Vid frågor eller problem, kontakta din organisations administratör eller skicka en supportförfrågan.

---

*Stimma - Lär dig i små steg*
