# Stimma - Användarhandbok

Denna handbok beskriver hur du använder Stimma e-learning plattform. Stimma är en svensk mikroutbildningsplattform som gör det enkelt att lära sig nya saker i korta, fokuserade lektioner.

---

## Innehållsförteckning

1. [Översikt över användarroller](#översikt-över-användarroller)
2. [Kom igång - Logga in](#kom-igång---logga-in)
3. [Guide för studenter](#guide-för-studenter)
4. [Guide för redaktörer](#guide-för-redaktörer)
5. [Guide för administratörer](#guide-för-administratörer)
6. [Guide för superadministratörer](#guide-för-superadministratörer)

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
4. Ladda upp en kursbild eller klicka **"Generera AI-bild"**
5. Klicka **"Spara"**

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

### Statistik

1. Gå till **Statistik** i adminmenyn
2. Du ser:
   - Antal användare och slutförda lektioner
   - Progress per kurs
   - Aktivitet per användare
3. Filtrera på specifik kurs för detaljerad vy

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

---

## Guide för superadministratörer

Superadministratörer har fullständig systemåtkomst.

### AI-inställningar

1. Gå till **AI-inställningar** i adminmenyn
2. Konfigurera:
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
