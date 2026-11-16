# PUB-avtal Stimma – ändringar till nästa version (bilaga 1 version 1.5)

Underlag 2026-11-16. Utgår från PDF:en med SHA-256 `114f2ff6…` (bilaga 1 version 1.4, 2026-11-13).
Avtalstexten har kontrollerats mot hur Stimma faktiskt fungerar (kod, `.env`, databas).
Själva PUB-avtalet (avsnitt 1–22) behöver inte ändras. Alla ändringar gäller bilaga 1 och bilaga 2.

Markeringar: **[BESLUT]** = Thomas väljer. **[KONTROLLERA]** = uppgift från leverantören som ska stämmas av mot deras DPA.

---

## A. Bilaga 1 – Instruktion

### A1. Punkt 9.1, raden "Säker autentisering" (fel sedan 2026-10-16)

Nuvarande: *E-postbaserad inloggning med tidsbegränsade engångslänkar*

Ny text:
> Inloggning med en engångskod som skickas till användarens e-postadress. Koden gäller i högst tre timmar, kan bara användas en gång och bara i den webbläsare som begärde den.

(Giltighetstiden är `AUTH_TOKEN_EXPIRY_MINUTES=180`. Tre timmar är långt för en engångskod. Sänks värdet ska texten ändras.)

### A2. Punkt 9.1, raden "Säkerhetskopiering"

Nuvarande: *Daglig backup av databas med krypterad lagring*

Ny text:
> Säkerhetskopia av databas och uppladdade filer varje natt. Säkerhetskopian krypteras innan den lämnar servern och lagras hos Scaleway i Amsterdam, Nederländerna (se bilaga 2). Säkerhetskopior sparas i upp till tre månader.

(restic: 7 dagliga, 4 veckovisa och 3 månatliga. Raderade personuppgifter kan alltså finnas kvar i säkerhetskopior i upp till tre månader. Det bör stå i 3.2, se A4.)

### A3. Punkt 9.1, raden "Separerade miljöer"

Nuvarande: *Separering av utvecklings-, test- och produktionsmiljöer*

Påståendet stämmer bara delvis. Testerna körs i en egen testmiljö med egen databas och fiktiva data, men det finns ingen separat utvecklingsmiljö: ändringar görs i produktionens filträd. Ny text som stämmer:
> Automatiska tester körs i en separat testmiljö med egen databas och fiktiva testdata. Produktionsdata används inte vid test.

### A4. Punkt 3.2 Lagringstider

Två rader i tabellen stämmer inte med Stimma i dag, eftersom **inget gallringsjobb finns**:
- *Aktivitetsloggar 12 månader*: de äldsta loggarna är från 2025-12-02 och passerar 12 månader den 2026-12-02.
- *Påminnelsehistorik 6 månader*: 74 rader är redan äldre än 6 månader (124 totalt sedan 2026-03-03).

**[BESLUT]** Rekommendation: behåll texten och bygg gallringen (ett nattligt jobb i schemaläggaren, cirka en timmes arbete). Ändra annars texten till de tider som faktiskt gäller.

Lägg till en rad:

| Uppgiftstyp | Lagringstid | Motivering |
|---|---|---|
| Säkerhetskopior | Upp till 3 månader | Återställning vid incident |

Lägg också till en rad om AI-användningsloggen (se A5) med en lagringstid, till exempel *24 månader – underlag för fakturering av AI-tokens*. **[BESLUT]**

**Gallringen är byggd (2026-11-16)** och körs varje natt kl 02:00, före backupen. Texten i 3.2 kan behållas med två förtydliganden, eftersom jobbet gör så här för att inte ändra hur tjänsten fungerar:
- Påminnelsehistorik, ny text i kolumnen Lagringstid: *6 månader. Den senaste påminnelsen per deltagare och kurs sparas så länge deltagaren är inskriven på kursen, så att samma påminnelse inte skickas igen.*
- Aktivitetsloggar, ny text i kolumnen Lagringstid: *12 månader. Uppgift om att en kurs har kopierats sparas därefter utan personuppgifter (bara kurs och mottagande organisation).*
- AI-användningslogg: *24 månader*.

Säkerhetskopiorna gallras också: 7 dagliga, 4 veckovisa och 3 månatliga, alltså upp till tre månader. Den gallringen fungerade inte före 2026-11-16 och är nu rättad.

### A5. Punkt 5.1 Kategorier av personuppgifter – ny rad

Stimma loggar varje AI-anrop med användarens e-postadress (`ai_usage_log`). Det saknas i tabellen.

| Kategori | Personuppgifter | Källa | Obligatorisk |
|---|---|---|---|
| AI-användning | E-postadress, kurs, funktion (AI-tutor/kursgenerering), tidpunkt och antal token. Inte frågornas eller svarens innehåll. | System | Ja, om AI-funktionerna används |

### A6. Punkt 2.2 och 4.2 – e-post går via Scaleway

Punkt 2.2, raden "Påminnelser". Ny text:
> Skicka automatiska e-postpåminnelser om ej slutförda kurser. E-post skickas via Scaleway inom EU.

Punkt 2.2, raden "Autentisering". Ny text:
> Verifiera användaridentitet med engångskod som skickas via e-post

Punkt 4.2, ersätt *Utlämnande genom överföring (e-postpåminnelser)* med:
> Utlämnande genom överföring (inloggningskoder och påminnelser via e-posttjänsten hos Scaleway)

Lägg till punkten:
> Lagring av krypterade säkerhetskopior hos Scaleway

### A7. Punkt 11.1 Nuvarande status – komplettera första stycket

Lägg till efter "AI-tutorns behandling hos Berget AI sker i Sverige.":
> E-post från Stimma skickas via Scaleways e-posttjänst i Paris, Frankrike, och krypterade säkerhetskopior lagras hos Scaleway i Amsterdam, Nederländerna. Scaleway är etablerat i Frankrike och anlitar inga underbiträden utanför EU/EES för dessa tjänster.

**[KONTROLLERA]** Kontrollera den sista meningen mot Scaleways lista över underbiträden. Scaleway valdes för att lagringen inte har någon amerikansk underleverantör.

### A8. Punkt 12.1 Dataexport vid avtalets slut (kryssrutorna)

Kryssrutorna kan inte fyllas i vid digitalt tecknande, så i alla tecknade avtal står de tomma. **[BESLUT]** Två sätt att lösa det:

**Alternativ 1 (rekommenderas): valet görs vid uppsägningen.** Ersätt hela punkten med:
> 12.1 Dataexport vid avtalets slut
> Den Personuppgiftsansvarige anger i samband med uppsägningen om personuppgifterna ska återlämnas eller raderas. Vid återlämnande väljer den Personuppgiftsansvarige format: JSON, CSV eller SQL-dump. Har inget val gjorts inom trettio (30) dagar från uppsägningen raderas personuppgifterna enligt punkt 17.6 i PUB-avtalet. Radering omfattar även säkerhetskopior, som gallras inom tre månader.

**Alternativ 2: valet görs i formuläret.** Stimma lägger till ett obligatoriskt val i formuläret och stämplar ett kryss i PDF:en. Det kräver en databaskolumn, ändring i formuläret och nya positioner i stämplingen. Det låser också valet redan när avtalet tecknas, vilket sällan är vad en kommun vill.

### A9. Punkt 13.2 Versionshistorik – ny rad

| Version | Datum | Ändring | Godkänd av |
|---|---|---|---|
| 1.5 | 2026-11-__ | Bilaga 2: Scaleway tillagt (e-post och säkerhetskopior). Punkt 9.1: inloggning med engångskod, säkerhetskopiering och testmiljö beskrivna som de är. Punkt 3.2: lagringstid för säkerhetskopior och AI-användningslogg. Punkt 5.1: AI-användning. Punkt 11.1: Scaleway. Punkt 12.1: val om återlämnande eller radering görs vid uppsägning. | Thomas Wennersten |

---

## B. Bilaga 2 – Underbiträden

### B1. Ny rad: Scaleway

| Fält | Text |
|---|---|
| Bolag/organisation | Scaleway SAS, RCS Paris 433 115 904 **[KONTROLLERA]** |
| Adress och kontaktuppgifter | 8 rue de la Ville l'Évêque, 75008 Paris, Frankrike **[KONTROLLERA]** |
| Lokalisering av personuppgifter | E-post: Paris, Frankrike (region fr-par). Säkerhetskopior: Amsterdam, Nederländerna (region nl-ams). Ingen överföring utanför EU/EES. |
| Typer av personuppgifter | E-post: mottagarens e-postadress och namn, innehållet i inloggningskoder och påminnelser (kursnamn). Säkerhetskopior: samtliga uppgifter i Stimmas databas och uppladdade filer, krypterade innan överföring. Scaleway har inte tillgång till krypteringsnyckeln. |
| Ändamål | Skicka e-post från Stimma (inloggningskoder, påminnelser, aviseringar). Lagra krypterade säkerhetskopior för återställning. |
| Behandlingstid | E-post: under leveransen samt Scaleways leveranslogg **[KONTROLLERA lagringstid i TEM]**. Säkerhetskopior: upp till 3 månader. |
| Ytterligare information | Scaleways personuppgiftsbiträdesavtal (DPA) **[KONTROLLERA länk]**. Scaleway är certifierat enligt ISO/IEC 27001 **[KONTROLLERA]**. |

### B2. Raden OpenAI – ÅTGÄRDAT I KODEN 2026-11-16

Stimma anropar sedan 2026-11-16 `eu.api.openai.com` med nyckeln `OPENAI_EU` (projekt med EU-datalagring). Nyckeln nekas mot `api.openai.com` (401 "outside project geography EU"). Lokaliseringen i bilaga 2 stämmer därmed. Ändra bara fältet "Ändamål" så att bildgenereringen nämns (se nedan). Texten nedan är kvar som bakgrund.

(Tidigare:) Raden OpenAI stämde inte med koden

Bilaga 2 säger att behandlingen sker inom *EU/EES vid användning av API-projekt med europeisk datalagring (eu.api.openai.com)*. Stimma anropar i dag **`api.openai.com`**, både för text (`ai_lesson_helper.php`, `functions.php`) och för bilder (`/v1/images/generations`). **[BESLUT]**

- **Alternativ 1 (rekommenderas):** Byt Stimma till `eu.api.openai.com`. Det kräver ett OpenAI-projekt med EU-datalagring, och API-nyckeln måste höra till det projektet. Kontrollera i OpenAI-konsolen innan bytet. Själva bytet är en ändring i `.env` och på ett ställe i koden för bilder, och ett riktigt anrop testas efteråt.
- **Alternativ 2:** Stryk "EU/EES vid användning av … eu.api.openai.com" och skriv: *USA och EU. OpenAI Ireland Ltd är avtalspart; behandling kan ske i USA med stöd av EU-kommissionens standardavtalsklausuler.* Ändra i så fall även punkt 11.1.

Raden bör också nämna **bildgenerering** till kurser. Ny text i fältet "Ändamål":
> Generera utkast till kursinnehåll och kursbilder (inferens med språk- och bildmodell) på uppdrag av redaktörer.

---

## C. Inte underbiträde: SMS vid tecknandet (ClickSend)

SMS-koden vid tecknandet skickas via ClickSend, som är australiskt. Uppgifterna (undertecknarens mobilnummer) behandlar Sambruk som **personuppgiftsansvarig** för sitt eget avtalstecknande, inte för medlemmens räkning. ClickSend ska därför **inte** stå i bilaga 2. Det hör hemma i Sambruks egen registerförteckning och integritetsinformation, som en överföring till tredjeland (Australien). Detta behöver dokumenteras där.

---

## D. Efter ändringarna

1. Rätta i Word-originalet och skapa en PDF.
2. Ladda upp PDF:en. Stimma ger den layouten v8 eftersom den nya hashen är okänd.
3. Klicka på "Förhandsgranska ifyllt". Har partstabellen på sidan 3 flyttats måste en v9-layout läggas in i `pubPartyLayout()`. Lägg in v9-hashen även om tabellen inte har flyttats.
4. Aktivera och kontrasignera. Administratörerna i de tio organisationer som redan har tecknat får då ett besked om att teckna om.
