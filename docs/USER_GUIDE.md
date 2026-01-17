# Stimma - Användarhandbok

Denna handbok beskriver hur du använder Stimma e-learning plattform. Stimma är en svensk mikroutbildningsplattform som gör det enkelt att lära sig nya saker i korta, fokuserade lektioner.

---

## Innehållsförteckning

1. [Översikt över användarroller](#översikt-över-användarroller)
2. [Kom igång - Logga in](#kom-igång---logga-in)
3. [Guide för studenter](#guide-för-studenter)
   - [Din dashboard](#din-dashboard)
   - [Gamification - XP och nivåer](#gamification---xp-och-nivåer)
   - [Certifikat](#certifikat)
4. [Guide för redaktörer](#guide-för-redaktörer)
   - [Ange slutdatum för en kurs](#ange-slutdatum-för-en-kurs)
   - [Förhandsgranska lektioner](#förhandsgranska-lektioner)
5. [Guide för administratörer](#guide-för-administratörer)
   - [Dashboard - Översikt](#dashboard---översikt)
   - [Certifikathantering](#certifikathantering)
   - [Påminnelseinställningar](#påminnelseinställningar)
   - [Skicka testmail](#skicka-testmail)
6. [Guide för superadministratörer](#guide-för-superadministratörer)
   - [AI-leverantörskonfiguration](#ai-leverantörskonfiguration)
   - [Testa AI-anslutning](#testa-ai-anslutning)

---

## Översikt över användarroller

Stimma har fyra användarroller med olika behörigheter:

| Roll | Beskrivning |
|------|-------------|
| **Student** | Kan ta kurser och spåra sin progress |
| **Redaktör** | Kan skapa och redigera kurser som tilldelats dem |
| **Admin** | Kan hantera alla kurser, användare och inställningar inom sin organisation |
| **Superadmin** | Fullständig systemåtkomst inklusive AI-inställningar |

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
- Välj "Kom ihåg mig" för att slippa logga in varje gång

---

## Guide för studenter

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
   - Antal intjänade certifikat

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
- 🏆 **Certifierad** - Erhåll ditt första certifikat

### Certifikat

När du slutför en kurs får du automatiskt ett certifikat:

1. Slutför alla lektioner i kursen
2. Certifikatet genereras automatiskt
3. Hitta dina certifikat på din dashboard under **"Mina certifikat"**
4. Ladda ner certifikatet som PDF
5. Dela eller skriv ut certifikatet

**Certifikatet innehåller:**
- Ditt namn
- Kursens namn
- Slutförandedatum
- Unikt certifikatnummer
- Organisationens logotyp

---

## Guide för redaktörer

Som redaktör kan du skapa och hantera utbildningsinnehåll.

### Åtkomst till adminpanelen

1. Logga in med ditt redaktörskonto
2. Gå till `/admin` eller klicka på "Admin" i menyn
3. Du ser en översikt med dina tilldelade kurser

### Skapa en ny kurs

1. Gå till **Kurser** i adminmenyn
2. Klicka på **"Ny kurs"**
3. Fyll i:
   - **Titel** - Kursens namn
   - **Beskrivning** - Vad kursen handlar om
   - **Svårighetsgrad** - Nybörjare, Medel eller Avancerad
   - **Längd** - Uppskattad tid i minuter
   - **Förkunskaper** - Vad deltagaren bör kunna innan
   - **Taggar** - Välj relevanta taggar
   - **Slutdatum** - Ange ett datum om kursen ska vara genomförd senast ett visst datum (valfritt)
4. Ladda upp en kursbild eller klicka **"Generera AI-bild"**
5. Klicka **"Spara"**

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

### Skapa lektioner

1. Öppna kursen du vill lägga till lektioner i
2. Klicka på **"Ny lektion"**
3. Fyll i:
   - **Titel** - Lektionens namn
   - **Innehåll** - Lektionstexten (stödjer HTML-formatering)
   - **Längd** - Uppskattad tid i minuter
   - **Video-URL** - Länk till video (valfritt)
   - **Resurslänkar** - Externa länkar för fördjupning
4. Ladda upp en lektionsbild eller klicka **"Generera AI-bild"**
5. Lägg till quiz (valfritt):
   - Skriv en fråga
   - Ange tre svarsalternativ
   - Markera rätt svar
6. Klicka **"Spara"**

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

### Förhandsgranska lektioner

Innan du publicerar kan du förhandsgranska hur en lektion ser ut för studenter:

1. Gå till **Kurser** och välj en kurs
2. I lektionslistan, klicka på **ögon-ikonen** (👁️) bredvid lektionen
3. Lektionen öppnas i förhandsgranskningsläge
4. En orange banner visas längst upp: "FÖRHANDSVISNING"
5. Testa lektionen - quiz, innehåll, video etc.
6. **Ingen data sparas** - din progress påverkas inte

**Tips:** Använd förhandsgranskning för att:
- Kontrollera att quiz fungerar korrekt
- Verifiera att videolänkar fungerar
- Se hur innehållet presenteras för studenter
- Testa AI-tutorn om den är aktiverad

### Skapa AI-genererad kurs

1. Gå till **Kurser**
2. Klicka på **"Skapa AI-kurs"**
3. Fyll i:
   - Kursnamn och beskrivning
   - Antal lektioner (1-20)
   - Svårighetsgrad
   - Om quiz ska inkluderas
   - Om AI-tutor ska aktiveras
4. Klicka **"Generera"**
5. Kursen skapas i bakgrunden - följ statusen på kurslistan

### Kopiera en befintlig kurs

1. Gå till **Kopiera kurs**
2. Välj en kurs från listan
3. Klicka **"Kopiera"**
4. En kopia skapas i din organisation (inaktiv som standard)
5. Redigera och aktivera kursen

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

### Certifikathantering

Hantera certifikat för din organisation:

1. Gå till **Certifikat** i adminmenyn
2. Se alla utfärdade certifikat i organisationen
3. För varje certifikat kan du:
   - Se vem som erhållit certifikatet
   - Vilken kurs det gäller
   - Datum för utfärdande
   - Ladda ner certifikatet som PDF

**Anpassa certifikatmall:**
1. Gå till **Certifikat** > **Inställningar**
2. Ladda upp organisationens logotyp
3. Anpassa certifikattexten
4. Förhandsgranska resultatet

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

### Domänhantering

Hantera vilka e-postdomäner som får tillgång till systemet:

1. Gå till **Domäner** i adminmenyn
2. Se lista över tillåtna domäner
3. Lägg till ny domän med **"Lägg till domän"**
4. Ta bort domäner som inte längre ska ha tillgång

**Tips:** Endast användare med e-postadresser från tillåtna domäner kan logga in.

---

## Tips och bästa praxis

### För studenter
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
