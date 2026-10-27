# Stimmas funktioner – underlag för en presentationskurs

Det här dokumentet beskriver vad Stimma kan, på en övergripande nivå. Det är skrivet för att Claude
ska kunna bygga en **kurs i Stimma som presenterar Stimma** för organisationer som funderar på att
använda plattformen. Själva kursformatet (kurspaket, lektionsfält, quiztyper, bilder) står i
[`KURSFORMAT.md`](KURSFORMAT.md) – följ det när kursen byggs.

Underlaget är sammanställt 2026-10-27 från koden, användarhandboken (`public/admin/user_guide.php`)
och produktsidan (`public/stimmainfo.html`). README.md är delvis inaktuell; där de skiljer sig
gäller det här dokumentet.

---

## 1. Så ska underlaget användas

**Målgrupp för kursen:** beslutsfattare, HR, utbildningsansvariga, kommunikatörer och
IT-/informationssäkerhetsfolk i kommuner, regioner och kommunala bolag. De kan offentlig sektor men
känner inte Stimma. De vill veta *vad det löser*, *hur det fungerar i vardagen* och *vad det kräver
av dem*.

**Ton:** saklig och konkret, inte säljig. Visa hellre ett exempel än ett adjektiv. Kursen är själv
ett bevis på produkten – den ska vara kort, tydlig och använda Stimmas egna funktioner (quiz av
olika typer, AI-chatt, bilder, sidbrytningar).

**Påstå bara det som står här.** Lägg inte till funktioner, siffror eller löften. Se särskilt
avsnitt 12, *Det här ska kursen inte påstå*.

---

## 2. Stimma i korthet

- **Nanoutbildning:** korta lektioner (några minuter) med direkt quiz, så att kunskapen
  kontrolleras medan den är färsk.
- **Byggt för offentlig sektor**, utvecklat inom Sambruk (kommunal samverkansorganisation) och
  används av medlemsorganisationerna. Kontakt för den som är intresserad: **info@sambruk.se**.
- **Öppen källkod** (GPL v2). Namnet "Stimma" är ett varumärke.
- **Lösenordsfritt:** man loggar in med en engångskod som skickas till e-postadressen.
- **AI i hela kedjan:** AI hjälper redaktören att skapa kurser och bilder, och deltagaren kan
  ställa frågor till en AI-handledare i lektionen.
- **Namnet:** vi lär oss tillsammans, som fiskar i ett stim – det blir lättare och roligare när
  man rör sig framåt som grupp.

---

## 3. Roller

| Roll | Vad rollen gör | Räckvidd |
|---|---|---|
| **Användare** | Går kurser, svarar på quiz, chattar med AI-handledaren, ser sin egen progress, laddar ner sina diplom | Sina egna kurser |
| **Redaktör** | Skapar och redigerar kurser, bjuder in medredaktörer och granskare, ser statistik för sina kurser | Egna kurser och kurser hen tilldelats |
| **Admin** | Hanterar organisationens användare, roller, påminnelser, diplom, varumärke, API-nycklar, PUB-dokument och statistik | Hela den egna organisationen |
| **Superadmin** | Drift och inställningar på systemnivå: organisationer, domäner, AI-inställningar och kvoter | Alla organisationer (Sambruk) |

Därtill:
- **Läsbehörig** – en flagga (inte en roll) som ger läsrätt till statistik utan att kunna ändra.
- **Medredaktör** – flera redaktörer kan arbeta i samma kurs, även från olika organisationer.
- **Granskare** – en person som bjuds in att läsa en kurs i förhandsgranskning och lämna
  synpunkter, utan att kunna ändra något. Inga svar eller framsteg sparas för granskaren.

---

## 4. Deltagarens upplevelse

- **Inloggning med kod:** ange e-post → få en sexsiffrig kod → skriv in den. Koden fungerar en gång
  och bara i den webbläsare där den begärdes (mejlet kan läsas på mobilen). Utan "Kom ihåg mig" är
  man inloggad en arbetsdag (8 timmar); med "Kom ihåg mig" i 30 dagar från senaste besöket.
- **Startsida och översikt** med pågående kurser, progress, lärvägar och kurser att granska.
- **Lektioner** med text i formaterade rutor (tips, info, exempel, sammanfattning), bilder, video
  (YouTube eller uppladdad film) och ljud. Långa lektioner delas upp i sidor.
- **Quiz direkt i lektionen**, med återkoppling per fråga.
- **AI-handledare** i lektionen: deltagaren kan ställa frågor och få svar utifrån lektionens
  innehåll, inom ramar (guardrails) som organisationen och Sambruk satt.
- **Motivation (gamification):** XP-poäng för avklarade lektioner, rätta svar och avslutade
  kurser, nivåer, streaks (dagar i följd) och badges.
- **Diplom** vid avslutad kurs, med unikt ID som kan verifieras publikt via en länk.
- **Lärvägar:** flera kurser i en rekommenderad ordning, med status per kurs och total procent.
- **Fungerar i mobilen** och har en tillgänglighetsredogörelse.

---

## 5. Skapa innehåll

Det finns flera vägar in, och alla kurser skapas **inaktiva** så att de kan granskas innan de
publiceras.

| Väg | Kort beskrivning |
|---|---|
| **Manuellt** | Redigerare med formatering, bilder i texten, video, ljud, drag-och-släpp för lektionsordning. |
| **AI-genererad kurs** | Ange ämne, beskrivning, målgrupp, antal lektioner, svårighetsgrad, textlängd, ton och språkstil. AI:n ställer 2–4 följdfrågor och bygger sedan hela kursen i bakgrunden – lektioner, quiz, AI-handledare och bilder. |
| **AI-lektion** | Lägg till en enskild AI-skriven lektion i en befintlig kurs. |
| **PowerPoint-import** | Läs in en befintlig PPTX och låt AI utveckla texten till en Stimma-kurs. |
| **SCORM-import** | Gör om ett SCORM-paket (Rise, Storyline, iSpring, Captivate m.fl.) till en vanlig Stimma-kurs. Standard är att **kopiera** text, bilder och filmer ordagrant; AI-omskrivning är ett aktivt val. Paketets egen kod körs aldrig, och provlogiken följer inte med. |
| **Kurspaket (ZIP)** | Importera en färdig kurs i Stimmas kursformat, med bilder. Används bl.a. när kurser tas fram med AI utanför Stimma. |
| **Kopiera från andra organisationer** | Bläddra bland andra organisationers aktiva kurser och kopiera dem till den egna organisationen för anpassning. En permanent ursprungsetikett visar vem som skapade originalet. |

**AI-bilder:** kursbild, lektionsbilder och diplombild kan genereras med AI, med en bildstil per
kurs så att bilderna hänger ihop.

**Granskning före publicering:** bjud in granskare som läser kursen i förhandsgranskning – även
personer i andra organisationer.

---

## 6. Quiz

Elva frågetyper:

Enkelval · Flerval · Sant/falskt · Lucktext · Bildval · Ordna i rätt ordning · Matcha par ·
Kategorisera · Siffersvar · Klicka i bild (hotspot) · Kort textsvar

Frågorna ger direkt återkoppling, och resultaten räknas in i deltagarens progress och XP.
(Kurspaketet stöder ett urval av typerna – se `KURSFORMAT.md`, avsnitt 6.)

---

## 7. Nå rätt deltagare

- **Domänstyrd åtkomst:** anställda med organisationens e-postdomän kommer in direkt. Kurser kan
  delas med hela organisationen eller riktas till vissa domäner.
- **Organisationstaggar:** gruppera användare efter förvaltning, enhet eller roll (t.ex. BUN, SOC)
  och styr vilka kurser varje grupp ser. Utan taggar är allt öppet inom organisationen.
- **Organisationer med flera domäner** (t.ex. `kommun.se` och `edu.kommun.se`) kan grupperas så att
  de delar kurser, administration och PUB-avtal.
- **Publika kurser:** en unik registreringslänk gör att vem som helst – oavsett e-postdomän – kan
  anmäla sig till just den kursen, och bara den. Passar medborgare, externa partner, föreningar
  och evenemang. Länken kan förnyas, och deltagaren kan själv radera sin registrering och all sin
  data.
- **Stegvisa kurser:** en lektion låses upp i taget med valbart intervall, med e-post när nästa
  lektion öppnar och påminnelse om den inte gjorts. Två lägen: **gemensam start** (alla börjar
  samma datum) eller **eget startdatum** per deltagare (bra för introduktion av nyanställda).
- **Lärvägar:** paketera flera kurser i en rekommenderad ordning, t.ex. "Introduktion för
  nyanställda".
- **Påminnelser:** automatiska e-postpåminnelser till den som inte slutfört, med egna mallar och
  slutdatum. Admin kan också skicka en personlig påminnelse direkt från statistiken.

---

## 8. Följa upp

- **Admin-översikt** med nyckeltal: användare, kurser, slutförda lektioner, slutförandegrad.
- **Kursstatistik** per kurs, per organisationstagg och per person, inklusive publika deltagare.
- **Lärvägsstatistik** som matris: deltagare × kurser.
- **Export till Excel/CSV** av statistik och användare.
- **Diplom** med unikt ID och publik verifiering, plus översikt över utfärdade diplom.
- **Aktivitetsloggar** över inloggningar och ändringar.

---

## 9. Administration och anpassning

- Användarhantering och roller, inom organisationen.
- **Varumärke:** organisationens logotyp och rubriktext.
- **Diplommallar.**
- **AI-saldo:** AI-funktionerna förbrukar tokens från organisationens saldo. Admin och redaktörer
  ser saldot och var man beställer mer; admin kan beställa påfyllning.
- **Informationsmeddelanden** från Sambruk till admins och redaktörer.

---

## 10. Integrationer

- **Användarsynk via REST-API:** synka användare och organisationstaggar från HR- eller
  katalogsystem, med synkverktyg och synkloggar i admin.
- **Insyns-API:** läsande API för att hämta statistik om genomförande till andra system
  (t.ex. uppföljning eller rapportering), med egna systemnycklar.
- **Export/import av kurser** som ZIP för att flytta eller dela material.

---

## 11. Säkerhet och dataskydd

- **Lösenordsfri inloggning** med engångskod, bunden till webbläsaren, max antal försök och
  begränsning av hur ofta koder kan begäras.
- **PUB-avtal** (personuppgiftsbiträdesavtal) tecknas digitalt i Stimma, med SMS-verifiering av
  undertecknaren och PDF-stämpling. Organisationer utan avtal ser en varning.
- **Separation mellan organisationer:** varje organisation ser bara sina egna användare och sin
  egen statistik.
- **Publika deltagare** kan själva radera sin registrering och sina data.
- **Säkerhetskopiering** av databasen varje natt.
- **AI med ramar:** guardrails för AI-handledaren (blockerade ämnen, svarsriktlinjer).
- **Tillgänglighetsredogörelse** finns i tjänsten.

---

## 12. Det här ska kursen inte påstå

- **Inga priser, kostnader eller exakta tokenmängder.** Hänvisa till info@sambruk.se.
- **Ingen var-ligger-datan-uppgift** (land, leverantör, molntjänst) och inga certifieringar.
  Sådant besvaras av Sambruk.
- **Inte "fullt WCAG-anpassad"** – säg att det finns en tillgänglighetsredogörelse.
- **Inte att Stimma spelar upp SCORM** – Stimma gör om paketen till egna kurser.
- **Inte att AI:n alltid har rätt** – AI-genererat innehåll granskas av redaktören innan publicering.
- **Inga namngivna kunder eller användarsiffror.**
- **Ingen total kurstid och inget "gör en lektion i taget"** – ange tid per lektion
  (se `KURSFORMAT.md`, Omfång).

---

## 13. Förslag på kursupplägg

Förslag, inte krav. 6–8 lektioner om ungefär 5–10 minuter vardera, avslutat med diplom.

1. **Välkommen till Stimma** – vad nanoutbildning är, varför korta lektioner och quiz fungerar,
   och hur kursen själv är byggd i Stimma. Quiz: sant/falskt.
2. **Så upplever deltagaren det** – inloggning med kod, lektioner, AI-handledare, XP, streaks,
   diplom. Quiz: ordna inloggningsstegen i rätt ordning.
3. **Så skapar ni innehåll** – manuellt, AI-kurs, PowerPoint, SCORM, kurspaket, kopiera från
   andra organisationer, granskare. Quiz: matcha par (källa → importväg).
4. **Nå rätt personer** – domäner, organisationstaggar, publika kurser, stegvisa kurser,
   lärvägar, påminnelser. Quiz: kategorisera scenarier (onboarding, medborgarkurs, GDPR-kurs för
   alla) till rätt funktion.
5. **Följ upp och visa resultat** – statistik, export, diplomverifiering, insyns-API. Quiz: flerval.
6. **Säkerhet, dataskydd och AI med ramar** – inloggning, PUB-avtal, separation, publika
   deltagares radering, guardrails, säkerhetskopiering. Quiz: enkelval + kort scenario.
7. **Kom igång** – roller ni behöver (admin, redaktörer), PUB-avtal, första kursen, vem man
   kontaktar (info@sambruk.se). Avsluta med en AI-chatt där deltagaren kan ställa egna frågor om
   Stimma, avgränsad till innehållet i kursen.

**Bildidéer:** ett stim fiskar som rör sig tillsammans (namnet), en kort lektion på en mobil, en
statistikvy, ett diplom med verifieringslänk – i en gemensam bildstil. Sambruks färg är #58A618
(använd #3F7A11 för text och knappar på vit bakgrund).

---

## 14. Fler källor i repot

- `docs/KURSFORMAT.md` – kursformatet och reglerna för AI-skapade kurser
- `public/admin/user_guide.php` – användarhandboken med detaljer per roll och funktion
- `public/stimmainfo.html` – produktsidan
- `docs/API-INSYN.md` – insyns-API:t
