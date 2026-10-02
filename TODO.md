# Stimma - Utvecklingsuppgifter

## Pågående

### Bilder till hela kursen + panelens linjering (2026-10-02)
- [x] C2: innehållspanelen linjerar med kortets överkant (låg ovanför på höga skärmar). Scenen centrerar panel+lektion
      som en rad (flex-wrap + align-content), alignSide() mäter med offsetTop (kortets intoning sl-fade flyttar 4 px)
- [x] Admin → Lektioner → "Generera bilder": ajax/plan_course_images.php föreslår ett eget motiv per lektion (ett
      textanrop, varierar miljö/avstånd/antal personer/ljus), redaktören granskar/ändrar, sedan genereras bilderna två
      åt gången via generate_lesson_image.php / generate_course_image.php (samma kvot, loggning, kursstil)
- [x] aiImageBuildPrompt: med önskemål styr motivet bildutsnittet i stället för fast "centred"
- [x] Testat t.o.m. motivförslagen på kurs 161 (6 tydligt olika motiv). Själva bildgenereringen ej körd skarpt (kostar)
- [x] Committad och pushad 2026-10-02

### Kurser med quiz görs i ordning (2026-10-02)
- [x] lessonOrderBlocker() + userBypassesLessonOrder() i include/functions.php: i kurs med quiz öppnas en lektion
      när alla tidigare LEKTIONER är klara (infosidor inget krav — 9 fall i datan där senare lektion är klar men
      infosidan före inte är markerad). Klara lektioner alltid öppna. Admin/redaktör/förhandsgranskning undantas.
- [x] lesson.php: spärr även för direktlänkar → omdirigering till första ej klara lektionen (dess ingångssida) med meddelande
- [x] Låsmarkering: klassiska sidomenyn i lesson.php, lektionslistan i course.php, innehållspanelen i C2
- [x] Testat som deltagare 172 (kurs 65) och 132 (kurs 25, stegvis): spärr, omdirigering, klara lektioner öppna,
      inga skrivningar i deras data. 29 av 32 aktiva kurser har quiz; 10 deltagare har redan hoppat i 3 kurser.
- [ ] Känd kant: i stegvisa kurser kan omdirigeringen landa på en lektion som väntar på schemat → startsidan
- [x] Committad och pushad 2026-10-02

### Nedladdningsbar instruktion "Skapa kurser med AI" (2026-10-02)
- [x] public/admin/kursinstruktion.php — .md-nedladdning för admin och redaktör (läsbehörig får 403, testat).
      Del A: vägledning för redaktören (uppdragstext till Claude, import, granskning, bilder, aktivering).
      Del B: docs/KURSFORMAT.md utan avsnitt markerade <!-- intern --> … <!-- /intern --> (kommandoradsverktyget,
      gatewayen, källkodsbilagan). Formatet underhålls alltså bara i KURSFORMAT.md.
- [x] Markerat interna avsnitt i docs/KURSFORMAT.md; "en admin" → "redaktör eller admin" där redaktörer kan göra det själva
- [x] Ruta med nedladdningsknapp överst på Admin → Importera kurs; formattexten länkar dit i stället för docs/KURSFORMAT.md
- [x] Committad och pushad 2026-10-02

### "Kopiera kursen till min organisation" gjorde ingenting (2026-10-02)
- [x] Orsak: kurstiteln skrevs med json_encode rakt in i onclick="…" — dubbelfnuttarna bröt attributet
      ("Unexpected end of input"), funktionen anropades aldrig. Samma fel på raderingsknappen när kursen
      ingår i en lärväg. Båda escapas nu med htmlspecialchars (public/admin/courses.php).
- [x] Kopian tappade innehåll: quiz_questions (69 kurser har sådana), infosidornas lektionstyp och koppling
      (belongs_to_lesson_id), videoposition, AI-chatt, quiztyp, svar 4–5, bildalternativ. copy_course.php
      kopierar nu alla lektionskolumner, frågorna, resurser och pekar om infosidorna. Även image_style och
      allow_quiz_retry på kursen.
- [x] Testat: kurs 147 (infosidor bundna till lektion), 25 och 161 i webbläsaren — lika många lektioner och
      frågor som källan. Testkopior 163–165 och deras kopierade mediafiler raderade.
- [x] Committad och pushad 2026-10-02 (bara knapp-hunken i courses.php, inte SCORM-ändringen)
- [ ] Ny lektionsvy C2 (kortformat) bakom en växel — underlag docs/stimma-c2.zip (SPEC.md, tokens, 5 mockuper) (2026-10-01)
  - [x] Steg 1 (spec): kodbasen utforskad och sammanfattad för Thomas
        lesson.php 1 883 rader: PHP-logik/POST-handlers (rad 1–674) → header.php (sidomeny, Google Fonts) →
        egen <style> → rendering (sidomeny "Innehåll", innehållskort med bild col-md-4 + pagebreak-sidor, video-
        kort, ljud, AI-chatt, infosida-avslut, quizformulär med en fråga per sida, paginator "Sida X av Y") →
        inline JS (AI-chatt, paginator med låsning per quizfråga, piltangenter). Quiz besvaras per fråga via AJAX
        (answer_question_id) i quiz-client.js. Progress registreras i POST-handlerna, inte i vyn.
  - [x] Bugg "två stycken på samma rad" (kurs 35 m.fl., lektion 228): INNEHÅLLET — "<em>…Act.</em>Förslag: …"
        ligger utanför <p>. Inte renderingen.
  - [x] Bugg "Quiz (2) men 1 fråga": admin/lessons.php räknar nya frågor + 1 om legacy-fältet quiz_question finns.
        742 lektioner har legacy-dubblett av samma fråga. ALLVARLIGARE: 210 lektioner har BARA legacy-fråga och
        visas utan quiz för deltagare (13 lektioner i 3 aktiva kurser: 122, 123, 142)
  - [x] Steg 2: plan godkänd 2026-10-01. Växel: global standard + utvalda kurser (ai_settings, ingen schemaändring)
        + ?ui=c2/?ui=klassisk. C2 alltid ljust. Självhostade typsnitt i HELA Stimma. Legacy-frågorna: låt bli (notera)
  - [x] Typsnitt: Fraunces + Inter woff2 (latin, latin-ext) i public/fonts/, header.php byter från Google Fonts. Även certificate.php (Playfair Display +
        Open Sans). Variabla filer: Inter 400–700, Fraunces opsz 9–144 vikt 400–600
  - [x] Växeln: include/lesson_view.php (lessonViewFor, ai_settings-nycklar lesson_view_default/_c2_courses,
        ?ui= minns i session) + admin/lesson_view.php (superadmin, sökbar kurslista, Prova-länkar) + menypost
  - [x] lesson.php: förgrening efter datalogiken → include/lesson_c2.php (eget dokument, ingen sidomeny; banners för
        "Visa som" och PUB tas med som smala rader). header.php behövde ingen flagga
  - [x] C2-mall include/lesson_c2.php: topprad (logga, kurs · lektion, Innehåll-dialog, Stäng), kort med progress-
        segment, navigering, sidtyper text+bild / text / video / AI-läraren / quiz / Lektion klar, förhandsgranskningsrad
  - [x] C2-CSS public/include/css/stimma-lesson-c2.css (tokens + stilar under .stimma-lesson), mobil < 768px
  - [x] C2-JS public/include/js/lesson-c2.js: bläddring, piltangenter, quiz via befintlig AJAX (answer_question_id),
        infosida (mark_info_page_complete), AI-chatt (ai_chat.php) med förslagsknappar, Lektion klar, preview
  - [x] Innehållstolkning include/lesson_c2_content.php: lösa textstumpar i <p> + tappad styckebrytning ("Act.Förslag")
        → rättar bugg 1 i C2; ensam fetstil → underrubrik; "Begrepp:"-listor (strong/em) → numrerade brickor;
        AI-förslag ur "Förslag: Frågor…" flyttas till AI-kortet (bara när AI-läraren finns)
  - [x] Verifierat 2026-10-01:
        * Lektion 228 (text, video, AI, quiz) och 1506 (bild, 6 textsidor, flerval/kategorisera/sant-falskt) + infosida
          1505 i 1440/1024/390: inga JS-fel, ingen #0D6EFD, Lektion klar visas
        * SKARPT (ej preview) på kurs 161 med eget konto: infosida → klar + vidare; 3 rätt → lektion klar; samma rader
          i progress/quiz_answers/course_enrollments som klassiska vyn; gamification orörd. Testraderna borttagna
        * Pixeljämförelse före/efter (översikt, kurs, dashboard, lärvägar, klassisk lektion, admin, adminkurser, diplom):
          0 px skillnad — efter att typsnittsvikterna begränsats till Googles tidigare (Inter 400–600, Fraunces 400–500)
        * Växeln: standard/kurslista/?ui=/?ui=auto, felaktig CSRF ändrar inget. ÅTERSTÄLLD till klassisk, inga kurser
  - [x] Brett test: alla 288 lektioner i aktiva kurser renderas i C2 utan PHP-fel (287 med text, 260 quiz, 212 AI,
        52 video). OBS vid test: /var/log/apache2/*.log i containern är länkar till stdout/stderr — läs dem inte med wc/grep
  - [x] Dra-och-släpp-frågor fungerade inte (rapport Thomas 2026-10-01). Orsak i delade quiz-client.js (även klassisk vy):
        kategorisera hade släppzonen på det inre .cat-bucket-items som är 0 px högt när tomt → släpp mitt på kategorin
        registrerades aldrig. Rättat: hela .cat-bucket är släppzon. NYTT: klicka-för-att-placera (mus, finger, Enter/
        mellanslag) för kategorisera + para ihop, pilknappar ↑↓ för ordna. Instruktionstexterna i quiz.php uppdaterade.
        Testat: båda vyerna, alla tre typerna → "Rätt"; drag mitt på kategorin; mobil med tryck (hasTouch) → "Rätt"
  - [x] Logga: den påhittade trädloggan (från mockupen) ersatt med organisationens riktiga logga via
        getHeaderOrganizationIcon() (Sambruk för sambruk.se), annars Stimmas riktiga logga
  - [x] Fast kortstorlek inom en lektion: bredd per lektion (sl-lesson--wide/narrow), lika höjd via equalize() (mäts på plats, tak 900 px), "Hoppa över frågan" ovanför knapparna, enhetlig bottenpadding — en kortstorlek och en knapposition per lektion på 1440 och 1024 (mobil: klistrad knapprad, höjden följer innehållet)
  - [x] Committad och pushad 2026-10-01 (0ec5c1b), docs/stimma-c2.zip medvetet utanför repot
  - [x] Innehållsförteckningen som nedfällbar panel till vänster om kortet (2026-10-02): bredvid kortet när det finns plats (panel 220–300 px, valet sparas i localStorage), annars över innehållet (Esc/klick utanför stänger). Innehåll-knappen flyttad till vänster i toppraden. Kortets storlek oförändrad i alla lägen (uppmätt 1536/1440/1366/1280/1024/390)
  - [x] Committad och pushad 2026-10-02
  - Kvar/kända begränsningar: Lektion utan quiz kan inte bli klar (som förut) → C2 visar "Tillbaka till kursen".
    background_color per lektion används inte i C2
- [ ] (Låt bli tills vidare, beslut 2026-10-01) 210 lektioner med BARA legacy-quizfråga visas utan quiz (13 i aktiva
      kurser 122, 123, 142). admin/lessons.php räknar legacy-dubbletter → "Quiz (2)"
- [x] Importera kurs i admin ska förstå kurspaket (2026-10-01, beställt av Thomas) — byggt, testat, pushat 2026-10-01
      Bakgrund: kurs 153 ("Förebygg insiderhot") lästes in via admins GAMLA import → inga frågor, ingen infosida,
      inga bilder, alla sort_order=0 → admin visade lektionerna alfabetiskt (sort_order, title) och den ordningen
      sparades 10:01. Claude på Thomas dator har ingen serveråtkomst, så webbimporten är enda vägen.
  - [x] include/kurspaket.php: delad validering + inläsning (flyttad ur tools/las_in_kurspaket.php)
  - [x] Formatet: image_file (färdig bild i ZIP:ens images/) för kurs och lektion, går före image_wish
  - [x] admin/import.php: kurspaket (JSON eller ZIP) → kärnan; gamla exportfiler som förut. Fellista i UI. CSRF-kontroll
        (AJAX-vägen saknade den). Uppladdade bilder städas bort om valideringen fallerar
  - [x] tools/las_in_kurspaket.php: använder kärnan, tar även ZIP (440 → 175 rader)
  - [x] docs/KURSFORMAT.md: avsnitt "Skapa bilderna med fal.ai" i 7A (modell, storlekar, prompt, nedladdning, direkt-API, gateway-skillnaden). Verifierat via gatewayen 2026-10-01: 1536×1024 och 1024×1024
  - [x] docs/KURSFORMAT.md: webbimport som huvudväg (9A), CLI (9B), image_file + bildspecifikationer (7A), image_wish (7B)
  - [x] Testat 2026-10-01: CLI felpaket (JSON med image_file, ZIP med saknade bilder) stoppas; ZIP skarpt → omslag +
        lektionsbild + infosida + frågor + rätt ordning; webb: utan CSRF-token nekas, felpaket → fellista, JSON-paket OK,
        gammal export (export.php) → gammal väg OK; webbläsare: röd/grön ruta, inga JS-fel. Läcka hittad och rättad:
        exit inuti try hoppade över finally → uppackad ZIP blev kvar i /tmp vid valideringsfel. Testkurser 154–158 och
        deras bildfiler borttagna. Exemplet i dokumentet provkört OK
  - [x] ZIP med allt i EN undermapp godtas (kpZipRot: Windows/macOS "Komprimera"), skräpfiler ignoreras; två
        kursmappar eller lösa filer utanför mappen avvisas. Testat 5 varianter + skarp webbimport (kurs 162, borttagen)
  - [x] Insiderhot-kursen återimporterad av Thomas: kurs 161 komplett (omslag + 5 lektionsbilder, infosida, 15 frågor, rätt ordning)
  - [ ] Ta bort de äldre försöken 153 (gammal import), 159 och 160 (utan bilder) — väntar på Thomas OK
- [x] docs/KURSFORMAT.md — beskrivning av kursformatet för att Claude självständigt ska kunna skapa kurser (2026-10-01, beställt av Thomas)
  - [x] Kartlagt: courses/lessons/quiz_questions, 11 frågetyper (include/quiz.php), cleanHtml-regler, pagebreak/[video],
        AI-generatorns konventioner (ai_lesson_helper.php), bildstilar/storlekar
  - [x] Fynd: admin/import.php + export.php är LEGACY (bara gamla enkelfrågan, ingen quiz_questions/infosidor) — oanvändbara här.
        importCourse() i cron/process_ai_jobs.php stöder questions[] men ligger i ett cron-skript. delete_module.php = död kod (tabell finns ej)
  - [x] Verifierat skarpt: kurs + lektion + infosida + frågor via aiLessonInsert() från CLI renderas rätt (testkurs 151 borttagen)
  - [x] tools/las_in_kurspaket.php — läser in ett kurspaket (JSON, format stimma-kurspaket-1) som INAKTIV kurs.
        Validerar allt (fält, HTML mot cleanHtml-reglerna, alla 9 frågetypers quiz_data), --provkor, --bilder.
        Testat skarpt 2026-10-01: felpaket → 12 fel, inget skrivet; testpaket (kurs 152) → 4 lektioner/infosidor,
        9 frågor; Stimmas gradeQuizQuestion() rättar alla 9 typer rätt och underkänner fel svar; webbläsare: kurssida,
        inline-video, AI-chatt, infosidor OK, inga JS-fel. Testkurs 152 borttagen. --bilder EJ testat skarpt (kostar)
  - [x] Committat och pushat 2026-10-01. courses.php och .gitignore lämnades utanför
  - [x] docs/KURSFORMAT.md skriven: arbetsflöde, struktur, alla fält, HTML-regler, block, sidbrytning, video, AI-chatt,
        9 frågetyper med quiz_data och regler, bilder (stil, motiv, kostnad), pedagogiska riktmärken, verktyget,
        fullständigt exempel (provkört OK), rapport, kontrollista, källbilaga
  - [x] YouTube-oEmbed-kontrollen verifierad (200 finns / 400 finns inte) — men 200 garanterar inte uppspelning,
        texten säger det och videor ska listas i rapporten
- [x] Buggar i lektionsredigeraren (2026-09-28, rapporterat av Patrik Hellstrand, IT-strateg) — rättat, verifierat, pushat
  - [x] YouTube-länken sparas inte om man inte klickat igenom flikarna före Spara
        Orsak: video_type sätts bara vid klick på en flik; YouTube-fliken är förvald utan att sätta den.
        Omvänt fall värre: klick på fel flik + Spara kastade den andra videon — en UPPLADDAD fil raderades.
        Fix (edit_lesson.php): servern avgör på vad som är ifyllt; fliken avgör bara när både länk och fil finns
  - [x] "Lägg till svarsalternativ" på Flerval lägger alternativen under Enkelval
        Orsak: renderChoiceFields() ritas för båda typerna → två id="choice-rows"; getElementById tog enkelvals.
        Fix: klass + knappens egen sektion. Följdbugg rättad samtidigt: rätt-svar är radindex och räknades
        inte om efter borttagen rad eller tomt alternativ → fel alternativ kunde markeras rätt. Nu numreras
        raderna om vid sparande och servern flyttar indexen med när tomma alternativ tas bort
  - [x] Verifierat i webbläsare mot inaktiv testkurs 150 / lektion 1465: länk utan flikklick sparas; klick på
        uppladdningsfliken behåller länken; lokal video + klick på YouTube behåller video OCH fil; tömt länkfält
        tar bort; flerval 4 klick → 5 rader i flerval, 1 i enkelval; flerval A,B,C,D,tom med B+D rätt och A
        borttagen → [B,C,D] rätt [0,2]; enkelval X,tom,Z rätt → [X,Z] rätt 1. Inga JS-fel.
        Testdata borttagen (explicita id:n + testvideofilen)
  - [x] Committat och pushat 2026-09-28. courses.php och .gitignore lämnades utanför
  - [x] Återkoppling till Patrik skriven: exports/aterkoppling_patrik_hellstrand_2026-09-28.txt (gitignorerad) — ska in i supportsystemet
- [x] Logga per domän (2026-09-28, beställt av Thomas) — byggt, verifierat, committat och pushat
  - [x] migrations/049: domain_settings.logo_url + logo_alt
  - [x] functions.php: resolveDomainLogo() (domän → överordnad domän → organisation) + storeLogoUpload()
  - [x] getHeaderOrganizationIcon() använder domänloggan först för domänanvändare
  - [x] domains.php: kolumn "Logga" + gemensam modal (ladda upp, alt-text, ta bort) — bara superadmin
  - [x] Verifiera: uppladdning, arv till underdomän, fallback till org, borttagning, visuellt
        Testat mot logotest.example + utb.logotest.example (tillfälligt i allowed_domains.txt, återställd och
        cmp-verifierad): skadlig SVG (onerror) avvisas, okänd domän avvisas, fel CSRF avvisas, uppladdning sparar
        fil + namn, namnbyte utan fil behåller filen, filbyte raderar gamla filen, borttagning raderar filen.
        Underdomänen ärver, vimmerby.se/edu.sater.se/gmail.com faller tillbaka till org som förut.
        Skärmbilder: kolumnen (egen logga full, ärvd blekt + "ärvd"), modalen i båda lägena, inga JS-fel.
        Testdata borttagen (bara logotest-raderna och deras fil). Ingen riktig domän har fått logga
  - [x] Committa och pusha 2026-09-28 (PAT:en fungerar igen — 200, push-behörighet). courses.php och .gitignore lämnades utanför
- [x] Bättre AI-bilder (2026-09-17, beställt av Thomas) — byggt, testat skarpt, committat och pushat
  - [x] Diagnos: prompten bad inte om något konkret motiv, stilanvisningen förbjöd ansiktsdrag
        ("no detailed facial features" → kusliga ansiktslösa personer), allt genererades kvadratiskt
        (1024x1024) trots att omslaget visas som banner (110 px i kort, 340 px på kurssidan), och
        kvaliteten var låst till 'medium'
  - [x] include/ai_image_helper.php är nu navet: aiImageStyles(), aiImageStyleDirective($stil),
        aiImageBuildPrompt($typ,...), aiImageSizeFor(), aiImageQualityFor(), WebP-stöd, kostnadsfunktioner.
        Prompten byggs på ETT ställe i stället för fyra
  - [x] Prompten ber om en konkret vardagsscen i svensk offentlig miljö, vänliga ansiktsdrag och
        crop-säker komposition. Negativlistan utökad (ikonbubblor, hänglås)
  - [x] Kursomslag: 1536x1024 + quality 'high'. Lektionsbild: 1024x1024 + 'medium' (visas i smal spalt)
  - [x] WebP direkt från API:t (output_format webp, compression 82) — 2,8 MB PNG → 212 kB WebP.
        Ingen GD/Imagick behövdes i containern, vilket var det jag först trodde
  - [x] Bildstil per kurs (courses.image_style, migration 048): Varm illustration / Fotografiskt /
        Lugnt grafiskt. Ärvs av lektionsbilderna och av bakgrundsjobbet
  - [x] Timeout 120 → 240 s i alla tre bildvägarna. Mätt: bred bild i hög kvalitet tar ~110 s, alltså
        precis under den gamla gränsen. Nginx släpper igenom 300 s
  - [x] Kostnaden räknas på FAKTISKA bildtokens (migration 048: ai_pricing.image_token_per_1m_cents,
        priser från OpenAI 2026-09-17). Loggades tidigare som fast 8 cent oavsett storlek och kvalitet —
        verkligt pris för en bred bild i hög kvalitet är 17 cent. Kvoten drar fortfarande 10 000 tokens/bild
  - [x] Kostnad visas i gränssnittet före generering (uppskattning per storlek/kvalitet) och faktiskt
        belopp i kvittensen efteråt. Belopp i US-cent — ingen påhittad växelkurs
  - [x] Bakgrundsjobbet: gamla DALL-E-måttet 1792x1024 föll tyst tillbaka till kvadratiskt; använder nu
        aiImageSizeFor() och samma promptbyggare som knapparna i admin
  - [x] Skarpt test mot testkurs 143: 110 s, 212 kB WebP 1536x1024, image_style sparad,
        ai_usage_log: 280 input + 5488 bildtokens, cost_cents 17
  - [x] Testkurs 143 och dess bildfil borttagna (explicit id). Övriga kurser orörda
  - [x] Visuell kontroll: stilväljaren visar tre stilar med hjälptext, vald stil sparas, kostnad och
        tidsåtgång står i hjälptexten. Kostnadstext även i lektionsvyerna (3,2 US-cent). Inga JS-fel
  - [x] Tillägg 2026-09-17 efter Thomas fråga (lektionen frågade ingenting):
        * Fältet "Vad ska bilden visa?" vid BÅDA knapparna (kurs, lektion) och i AI-skapa-lektion.
          Texten läggs först i prompten som "The image must show: …", max 300 tecken, sparas inte
        * Lektionsvyerna visar vilken stil kursen har ("Bilden skapas i kursens stil: Fotografiskt").
          I lektionsredigeraren uppdateras texten när man byter kurs i väljaren
        * Verifierat skarpt: lektionsbild med önskemål, 45 s, 79 kB WebP, 5 cent, stilen ärvdes (foto)
        * Kostnadsuppskattningen justerad efter mätning: 1024x1024 medium är 1756 tokens, inte 1056
  - [x] Testdata borttagen (kurs 144, lektion 1437, bildfilen). Inga riktiga kurser rörda
  - [x] Committat och pushat 2026-09-17 i en commit. public/admin/courses.php (SCORM-import avstängd
        2026-09-04) lämnades utanför som tidigare — separat, äldre ändring
- [x] Ogiltigförklaring av PUB-avtal (2026-09-16, beställt av Thomas) — byggt, verifierat, committat och pushat
  - [x] migrations/047_pub_agreement_invalidation.sql — invalidated_at, invalidated_by, invalidated_by_email,
        invalidation_comment på pub_agreement_artifacts + index (applicerad)
  - [x] functions.php: isPubArtifactInvalidated(), getValidPubAgreementArtifact(), invalidatePubArtifact(),
        revalidatePubArtifact(), slackPubFlaggaOmUtanGiltigtAvtal()
  - [x] 🔴 Ogiltigförklaringen släcker även domänens (och orgens) has_pub_agreement — grinden läser flaggan,
        inte artefakten. Utan det hade markeringen bara varit en etikett. Orgens flagga släcks bara när INGEN
        av orgens domäner har ett giltigt avtal
  - [x] pub_documents.php: statuskolumn i avtalslistan + superadminåtgärd i modalen med OBLIGATORISK kommentar,
        samt "Ångra ogiltigförklaring". CSRF och logActivity som övriga åtgärder
  - [x] domains.php: ogiltigförklarat avtal visas med röd knapp i stället för att falla ner i "inget avtal",
        och modalen visar tidpunkt, vem och motivering
  - [x] Verifierat mot testdata (artefakt-id 12, testdoman-ogiltig.example): ogiltigförklaring → flaggan 1→0,
        datum nollställt, motiveringen i domänens anteckningar, logg skriven; ångra → allt återställt.
        userHasPubAgreement() går true → false, alltså kommer bannern tillbaka och omtecknande tillåts
  - [x] Testdata borttagen (explicit id 12 + domain_settings-raden). 9 skarpa avtal orörda
  - [x] Visuell kontroll i webbläsare: listan visar "Ogiltigt" med datum, modalen visar tidpunkt, vem och
        motivering. domains.php verifierad med testdomänen tillfälligt i allowed_domains.txt (filen återställd
        och verifierad identisk med säkerhetskopian efteråt)
  - [x] Rättat: ångra-knappen var btn-outline-danger mot röd bakgrund och syntes knappt — nu btn-light
  - [x] Committat och pushat 2026-09-16. public/admin/courses.php (SCORM-import avstängd 2026-09-04) lämnades
        utanför som tidigare — separat, äldre ändring
- [ ] Säter-feedback 2026-09-14 (läsbehöriga på edu.sater.se) — punkt 1 och 2 åtgärdade, verifierade och pushade, mail + "7 dagar" kvar
  - [x] Analys: `statistics.php` ("Statistik per användare") ger läsbehörig REDAKTÖRSGRENEN för
        användarlista, kursstatistik, kursdetalj och fullt genomförda (`if ($isAdmin)` på fyra ställen,
        läsbehöriggrenen från 2026-08-25 täckte bara kurslistan och åtkomstkontrollen). Följd: taggfilter och
        domänfilter ignoreras helt — verifierat som både Viktoria (edu) och Anders (sater.se): 17 användare
        i listan och 4 i kurs 89 med och utan tagg. Rubriken "Alla användare i …" på rad 654 är hårdkodad och
        döljer att det är "användare som interagerat med kurserna"
  - [x] Analys: varför Anders inte märker det — han använder `course_stats.php`, där filtret fungerar (kurs 89:
        475 → 22 med taggen). För sub-domänkonton avgränsar course_stats kurslistan på `organization_domain`
        (buildDomainInClause) i stället för buildOrgCourseScopeClause. edu.sater.se äger 0 kurser → "Inga
        kurser hittades", och kurs-id i URL ger 302. De hänvisas alltså till den trasiga sidan
  - [x] Fix statistics.php: `$isAdmin` → `$isAdmin || $isViewer` i de fyra datagrenarna + rubriken på användarlistan
        visar `$userListTitle` i stället för hårdkodat "Alla användare i …"
  - [x] Läckagekontroll: de 17 är 5 edu.sater.se + 12 sater.se (kurs 70, 83, 89) — inga andra organisationer idag,
        men en edu-läsbehörig ser sater.se-användare utanför sitt scope. Latent läcka: redaktörsgrenen saknar
        domänvillkor, så en global eller delad kurs hade listat alla organisationers deltagare (0 globala kurser nu)
  - [x] Fix course_stats.php: kurslista + åtkomstkontroll via buildOrgCourseScopeClause() (som statistics.php)
  - [x] course_stats.php: Inskrivna/Klara/summering räknas via courseStatsCountClause() — följer taggfiltret, och
        utan filter bara scopet + kursens publika deltagare (andra organisationers deltagare räknades in förut)
  - [x] Fix course_stats.php: kurslänkar, "Tillbaka" och Exportera för med båda filtren (`$filterQs`), aktivt filter
        visas i kursdetaljen
  - [x] Önskemål: taggar slår igenom vid klick — admin/include/js/filter_dropdown.js (laddas i admin-footern):
        auto-submit, listan öppnas igen på samma scrollposition, sökfält vid ≥8 poster. Gäller statistics,
        course_stats och users, både tagg- och domänlistor
  - [x] Verifierat 2026-09-14 via HTTP: Viktoria (edu, läsbehörig) 433 → 21 med tagg i statistics.php, kurs 89 → 21;
        course_stats visar 3 kurser (förut 0) och kurs 89 öppnas. Anders och Jonna (admin) 475 → 21, 6 kurser.
        Therese (redaktör) oförändrad. Säters ofiltrerade Inskrivna oförändrade (70: 4, 83: 13, 89: 4). Inga PHP-fel
  - [x] Verifierat i headless Chrome som Viktoria: Tillämpa dold, sökfält (55 taggar, "skola" → 20), klick laddar om
        med listan öppen, sökningen och scrollpositionen kvar; två val i följd → 26 användare. Inga JS-fel
  - [x] Committat och pushat 2026-09-14. public/admin/courses.php (SCORM-import avstängd 2026-09-04) är en
        separat, äldre ocommittad ändring och följde medvetet inte med
  - [ ] Inloggningsmail i skräpposten (nytt enligt Säter, M365-mottagare). Ej på publika blocklistor.
    - [x] Header analyserad 2026-09-14 (mail till anders.eriksson@sater.se 2026-08-21, inklistrad av Jonna):
          SPF pass (psrp.sambruk.se, 91.107.206.3), DKIM pass (d=sambruk.se, s=postal-zJnI1V), DMARC pass,
          compauth pass. Ändå SCL 5, SFV:SPM, CAT:SPM, SFTY 9.25, dest:J (JunkEmail), ämnet fick prefixet
          "Möjlig Spam:" av Säters policy. Alltså INTE ett autentiseringsfel — Microsofts filter bedömer
          avsändaren/innehållet. Stämmer i tid med reläincidenten (91 000 bedrägerimail från samma IP och
          d=sambruk.se, 21 juni–17 aug). Return-path via psrp fungerar (regressionen 2026-08-17 syns inte)
    - [ ] Kortsiktigt hos Säter: rapportera som felklassat i Defender (Submissions) + transportregel som
          sätter SCL -1 för noreply@sambruk.se när DKIM d=sambruk.se passerar
    - [x] BESLUT 2026-09-14 (Thomas): Stimma ska INTE skicka via M365 — Postal behålls
    - [x] Textversion i alla mail (multipart/alternative) — include/mail.php
      - [x] mailHtmlToText() + mailBuildAlternative() + dot-stuffing/QP i sendSmtpMail och sendSmtpMailWithAttachment
        (valfri param $textMessage sist; utelämnad skapas texten ur HTML:en — alla 12 anropsställen får textdel)
      - [x] Handskriven textversion för inloggningsmailet (include/auth.php)
      - [x] Testat 2026-09-14 mot fejk-SMTP i containern: multipart/alternative (text, html) resp. mixed(alternative, pdf),
            HTML identisk efter avkodning, punktrader dubblerade, längsta rad 82, inga ensamma LF. Listpunkter fick
            tom rad emellan i autotexten — rättat (</li> ger ingen radbrytning)
      - [x] Skarpt testmail skickat 2026-09-14 09:12 UTC till thomas.wennersten@sambruk.se (Postal: 250, loggat OK)
      - [ ] Läs headern på testmailet (SCL, SFV, CAT) och jämför med Säters SCL 5 från 2026-08-21
      - [x] Committat och pushat 2026-09-14
  - [x] "7 dagar på inloggningssidan": Jonna bekräftar 2026-09-14 att texten är borta (den skarpa sidan har sagt
        "en gång i månaden" sedan 2026-08-25 — troligen en gammal flik eller cache)
- [x] LICENSE-fil skapad 2026-09-09 — README hänvisade till GPL v2 och en LICENSE-fil som inte fanns
      i repot. Full GPL-2.0-text tillagd i repo-roten så GitHub kan detektera licensen
- [x] Sambruk/stimma är privat igen — verifierat 2026-09-14 (`visibility: private`, anonymt API-anrop 404,
      forks 0). Var publikt 2026-09-09. Blir det publikt igen är de 31 gamla SHA:na åter nåbara, så
      purge-ärendet hos GitHub Support behövs fortfarande
- [ ] 🔴 Personuppgifter i det PUBLIKA GitHub-repot — historiken rensad 2026-08-21, EXPONERINGEN KVARSTÅR
  - [x] `import_users.sql`, `allowed_domains.txt` och `docs/pdf/*.pdf` rensade ur hela historiken med
        git filter-repo och force-pushade (132 commits bevarade, .git 97 MB → 11 MB, tagg v2.0.0 bevarad).
        Säkerhetskopia före rewrite: /opt/app/stimma-git-backup-20260821/ (bundle + filerna)
  - [x] Ärendetext till GitHub Support färdigskriven 2026-09-09 med allt Support ber om (repo, 2 PR,
        first-changed-commit 382e2c4→0531818 ur .git/filter-repo/, ingen LFS, forks 0) samt bilaga med
        de 31 verifierat nåbara SHA:n. Ligger i `.git/github-support-arende.md` (kan inte committas).
        Återstår: sätt repot till private och skicka ärendet på support.github.com
  - [ ] **Gamla objekt går fortfarande att hämta anonymt på GitHub via commit-SHA** — verifierat HTTP 200 på
        raw.githubusercontent.com för PUB-avtalet (ae362aa) och för import_users.sql på 31 av 128 gamla
        commits (382e2c4 m.fl. — 689db8f ger däremot 404, den SHA:n var felnoterad).
        Bara GitHub Support kan städa bort dem: begär "purge unreachable objects" för Sambruk/stimma
  - [x] **Forkarna är borta** (verifierat 2026-09-09): joakimbergros/stimma och ereffner/stimma ger 404
        både anonymt och med token, och syns inte i ägarnas publika repolistor. Sambruk/stimma: forks 0
  - [ ] Bedöm om detta är en personuppgiftsincident att anmäla: 93 namngivna tjänstemän hos ~49 kommuner
        och myndigheter, publikt exponerade sedan 2025-12-04 (import_users.sql) respektive 2026-02-13 (PUB-avtalet)
- [x] SCORM-import: zip-paket → Stimma-kurs (2026-08-20). Utredning: memory/scorm_import.md
  - [x] include/scorm_extractor.php: manifest-parsning, SCO-text, bilder/video, textklump (verifierad mot 4 syntetiska paket)
  - [x] public/admin/ajax/import_scorm.php: uppladdning, validering, zip-bombsskydd, diskkontroll
  - [x] cron/process_ai_jobs.php: detectImportMarker() + mappning av BILDFIL/VIDEOFIL (video_type='local')
  - [x] admin/courses.php: knapp + modal + JS för SCORM-import
  - [x] Verifierat E2E: 4 syntetiska paket (SCORM 1.2, 2004 med xml:base, Rise-likt JS-paket, paket utan manifest),
        HTTP-uppladdning mot ajax-endpointen och full AI-körning → kurs med rätt bild/video per lektion
  - [x] Skarp körning mot MSB DISA-paketet (jobb 67 → kurs 135) avslöjade tre saker, alla åtgärdade:
    - [x] Bakgrundsjobbet startade aldrig: trigger_ai_processor.php loggade till /var/www/html/upload
          (root-ägd sedan webrots-omläggningen 2026-08-17) → shell-omdirigeringen nekades och processen dog.
          Loggen går nu till public/upload med fallback till sys_get_temp_dir()
    - [x] Synkron fallback i triggern var död sedan tidigare (processorns CLI-spärr) — släpps nu igenom
          via konstanten STIMMA_JOB_PROCESSOR_INTERNAL (direkt HTTP-anrop ger fortfarande 403)
    - [x] Enkel-SCO-paket klipptes vid 8000 tecken: textbudgeten fördelas nu per avsnitt (60k totalt),
          fas 2 får bara sin egen del av källtexten, typsnittsskräp och ligaturer städas bort
  - [x] Kopieringsläge byggt efter återkoppling: AI-omskrivning duger inte när kursen ska likna originalet
    - [x] include/scorm_storyline.php: Storyline-paket läses ur html5/data/js (scen → lektion, altText → text,
          assetLib → bilder/film, frekvensfilter mot navigation, svarsalternativ under radioknappar)
    - [x] include/scorm_course_builder.php: kurs + lektioner skapas direkt, media kopieras, generisk HTML saneras med cleanHtml()
    - [x] ajax/import_scorm.php: import_mode=copy (standard) / ai
    - [x] admin/courses.php: lägesval i modalen, AI-inställningar döljs i kopieringsläge
    - [x] Verifierat mot MSB:s riktiga DISA-paket (88 MB, Storyline): kurs 137 = 11 lektioner,
          11 filmer, 27 bilder, 18 475 tecken originaltext. Bild och film verifierat serverade över HTTP
  - [x] SCORM-importen dold för alla utom superadmin (2026-08-20). Thomas underkände även kopieringsläget
        (kurs 137) och sätter sig in i SCORM-formatet innan arbetet fortsätter. Gäller både knappen/modalen
        i admin/courses.php och ajax/import_scorm.php (403 för alla utom role=super_admin)
  - [x] Knappen "Importera SCORM" borttagen ur admin/courses.php (2026-09-04): $scormImportAllowed = false,
        vilket också tar bort modalen. Backend (ajax/import_scorm.php, include/scorm_*.php) ligger kvar orörd
  - [ ] PAUSAD i väntan på Thomas riktning. Öppna trådar när den tas upp igen:
    - [ ] Vad var dåligt i kurs 137? (introduktionslektionen rörig, text utan layout, frågor rättas inte)
    - [ ] Ska paketets quizfrågor konverteras till riktiga Stimma-frågor? Rätt svar finns i Storylines data
    - [ ] Alternativ som inte utretts: bädda in paketet som det är i en sandlådad iframe på egen origin
          (kräver separat domän + SCORM-runtime, men bevarar originalet exakt)
  - [ ] Städa bort testkurserna 135 och 136 när Thomas jämfört
  - [x] Dokumentation: admin/user_guide.php + public/docs/USER_GUIDE.md + CHANGELOG (v2.2.0)
- [x] Lärvägar (learning paths) (2026-08-13). Plan: /root/.claude/plans/vi-ska-skapa-en-swift-candy.md
  - [x] Migration 044: learning_paths, learning_path_courses, learning_path_shared_domains + index på progress
  - [x] include/functions.php: buildCourseVisibilityClause() + getCourseProgressForUsers()
  - [x] include/learning_paths.php: CRUD, delade domäner, behörighet, synlighetsfilter, batchad status
  - [x] admin/learning_paths.php: lista, skapa, radera + menypost i admin/include/header.php
  - [x] admin/ajax/update_learning_path_order.php: drag-and-drop-ordning
  - [x] admin/edit_learning_path.php: formulär + tvåpanels-kurskoppling
  - [x] include/learning_paths.php del 2: batchad status + getLearningPathOverviewForUser()
  - [x] learning_paths.php (student) + länk i include/sidebar.php
  - [x] index.php: sektionen "Mina lärvägar"
  - [x] admin/learning_path_stats.php: matrisvy över genomförda/registrerade
  - [x] Kaskader: delete_course.php + varning i courses.php + kommentarer i users.php/copy_course.php
  - [x] Dokumentation: admin/user_guide.php + docs/USER_GUIDE.md + CHANGELOG.md (v2.1.0)
  - [x] E2E-verifierat: synlighet per org/domän/public_only, IDOR (edit, statistik, sortering, kurssmuggling
        vid spara), kaskad vid kursradering, tomma tillstånd, konstant antal queries (35 oavsett antal lärvägar)
- [x] Uppföljning: migrera index.php:214-276 till buildCourseVisibilityClause() (kräver före/efter-regressionstest av kurskatalogen för huvuddomänadmin, sub-domänanvändare med org-taggar och public_only)
      KLART 2026-08-25. 59 rader inline-logik ersatta med ett anrop. Regressionstest:
      alla 887 användare jämförda gammal mot ny logik → 0 skillnader (461 med org-taggar,
      425 utan, 1 public_only). Syntetiska fall i transaktion med rollback för de grenar
      produktionsdatan saknar: publik registrering för domänanvändare, is_global, kurs
      delad med utvald domän, org-tagg på kurs, public_only med 0/1/2 kurser, okänt
      användar-id → alla lika. Sidan renderad för sex konton, kursinnehållet identiskt.
      OBS: helpern slår upp användaren själv, så index.php gör en extra users-query.
- [x] Statistik: filtrera per domän/organisation, flera samtidigt (2026-06-12)
  - [x] include/functions.php: getStatsDomainScope() + buildDomainFilterQuery(), val skärs alltid mot användarens scope
  - [x] admin/statistics.php: multi-select-dropdown, alla user-frågor mot valda domäner, filter följer med i export-länk
  - [x] admin/course_stats.php: filter i översikt, detaljvy och summeringar; publika deltagare exkluderas vid aktivt filter
  - [x] admin/export_statistics.php: respekterar scope + filter (fixar latent bugg där admin-export bara tog egen domän)
- [x] Synkverktyg + dokumentation (2026-06-08)
  - [x] admin/sync_tool.php: "Exempelfil"-knapp som laddar ner kommenterad CSV (UTF-8 + BOM, flera org-taggar via "/")
  - [x] docs/USER_GUIDE.md + admin/user_guide.php: avsnitt om Synkverktyget (manuell synk) och användarsynk via API
  - [x] admin/user_guide.php: fullständiga API-URL:er härledda från aktuell host
- [x] Tokenbeställningar för superadmin (2026-06-08)
  - [x] Migration 041_token_order_billing: billed_at + billed_by på token_orders
  - [x] include/token_balance.php: getAllTokenOrders(), getTokenOrdersBillingSummary(), setOrderBilled()
  - [x] admin/token_orders.php: superadmin-vy med nyckeltal, filter, markera debiterad + CSV-export av ej debiterade
  - [x] admin/include/header.php: menypost "Tokenbeställningar" (superadmin)
- [x] Enhetlig rollterminologi: Redaktör och Användare (2026-06-08)
  - [x] users.php, export_users.php, sync_tool.php, api_keys.php, user_guide.php, docs/USER_GUIDE.md
  - [x] normalizeRole() känner igen "redaktör/redaktor/användare" vid CSV-import (interna rollvärden oförändrade)
- [x] IDOR-härdning i kurs-/lektions-endpoints (2026-05-13 + 2026-06-08)
  - [x] include/functions.php: kanonisk userCanModifyCourse() (super_admin / org-scopad admin / kursredaktör)
  - [x] edit_course, delete_course, delete_lesson, add/remove_course_editor, update_info_page_owner
  - [x] update_course_order, update_lesson_order, generate_course_image, generate_lesson_image
- [x] Huvuddomän-scope: sub-domän-admins ser bara egen domän (2026-06-08)
  - [x] getEffectiveOrgScopeDomains() + isUserOnPrimaryOrgDomain() i functions.php
  - [x] Kurser, Taggar, Statistik, Användare scopeas per huvuddomän/underdomän
  - [x] Synlighets-/delningskontroller endast för huvuddomän; nya sub-domänkurser låses via course_shared_domains
  - [x] Org/domän/scope-modellen dokumenterad i user_guide.php + docs/USER_GUIDE.md
- [x] AI-kvoter: visa alla scopes (org + vitlistade domäner) med default-rader (2026-06-05)
- [x] Diplom-kriterier, retry-blockering och cert-backfill (2026-06-05)
  - [x] Migration 043: courses.allow_quiz_retry, course_completion_criteria, quiz_answers
  - [x] lesson.php: recordQuizAnswer() + server-side blockering av omtag när allow_quiz_retry=0
  - [x] gamification.php: evaluateCourseCriteria() gatar recordCourseCompletion (grandfathering av historiska completions)
  - [x] admin/edit_course.php: "Diplom-kriterier"-sektion (procenttröskel + retry-switch)
  - [x] migrations/backfill_missing_certificates.php + migration 042 (lesson_video_position)
- [x] Token-ekonomi + AI-säkerhetshärdning (2026-05-13)
  - [x] Migration 040: saldo per organisation, 6 paket, append-only token_transactions
  - [x] cron/monthly_token_refill.php: auto-påfyllning den 1:a (Ofelia), tak 3× paketstorlek
  - [x] admin/order_tokens.php + saldobanner i admin-header
  - [x] Migration 041_image_model_migration + include/ai_image_helper.php (gpt-image-1-mini default, dall-e utfasad)
  - [x] Kvotkontroll före AI-bildgenerering (tidigare billing-bypass), dead-code utan usage-loggning borttagen
- [x] Lektions-UX: multipage, quiz per fråga, navigering (2026-05-13)
- [x] Kurskatalog visar alla kurser + avbrutna kurser i Avslutade-fliken (2026-05-11)
  - [x] index.php: kurskatalogen filtrerar inte längre bort påbörjade/klara kurser
  - [x] index.php: kontextuell knapptext i katalogen ("Börja kursen" / "Fortsätt" / "Gå igenom igen")
  - [x] index.php: status-badge i katalogen ("påbörjad" / "klar" / "avbruten") för både kort- och listvy
  - [x] index.php: pre-bucket räknar abandoned som "Avslutade"
  - [x] index.php: tab-loop inkluderar abandoned-kurser i Avslutade-fliken
  - [x] index.php: "Avbruten"-badge i Mina kurser (kort + lista) med Återuppta-knapp
  - [x] index.php: is_done prioriteras före is_abandoned i visning (100% klar = Slutförd, oavsett abandoned-flagga)
  - [x] resume_course.php: ny POST-endpoint som återställer course_enrollments.status till 'active'
  - [x] admin/include/header.php: "AI-användning"-menypost synlig för admins (inte bara superadmin) — ai_usage.php stödde redan org-scope-filtrering
- [x] Publika kurser (2026-04-22) — stor feature, se /root/.claude/plans/det-finns-ett-nskem-l-quiet-kazoo.md
  - [x] Migration 025: access_mode, is_public, public_registration_token, public_course_access, public_registration_intents
  - [x] include/functions.php: helpers (generate/validateToken, grantAccess, hasAccess, purgeData, maybeDeleteOrphan)
  - [x] include/auth.php: auto-promotion public_only→domain vid domänmatch i verifyLoginToken
  - [x] public_register.php: anonym registreringsform (e-post + namn), sessions-baserad rate-limit, intents-rad
  - [x] verify.php: slå upp intents per verifieringstoken (cross-device), grant access, same-origin-check på redirect
  - [x] index.php: villkorad query för public_only vs domain-användare (union av domänscope + public_course_access)
  - [x] lesson.php: hasPublicCourseAccess-check för public_only
  - [x] include/header.php: dölj PUB-banner + admin-länk + domän-label för public_only
  - [x] admin/edit_course.php: nytt "Publik kurs"-kort med toggle, URL, kopiera, förnya
  - [x] admin/ajax/toggle_public_course.php + regenerate_public_token.php
  - [x] admin/courses.php: "Publik"-badge med länk till deltagarlistan
  - [x] admin/public_participants.php: deltagarlista med multi-select + progress-ikoner för stegvisa + progress-bar för bulk
  - [x] admin/ajax/delete_public_participants.php: bulk-delete med CSRF + bekräftelsemail
  - [x] admin/include/confirm_destructive.js: återanvändbar kryssruta + RADERA-gate
  - [x] leave_public_course.php: deltagarens självradering + bekräftelsemail (två-spärr-UI)
  - [x] abandon_course.php: omdirigera publika till leave_public_course.php
  - [x] admin/users.php: fix komplett delete-cascade (buggfix)
  - [x] cron/send_reminders.php: inkludera publika användare (buggfix)
  - [x] admin/delete_course.php: sweep orphan public-only users efter kursradering
  - [x] E2E-verifierad: registrering, verify, access-filter, leave, orphan-delete, auto-promotion
- [x] Permanent ursprungsetikett på kopierade kurser (2026-04-22)
  - [x] Migration 024: courses.original_organization_domain + backfill + index
  - [x] include/functions.php: getOriginalOrganizationLabel()-helper
  - [x] admin/copy_course.php: sätter original till källans original (eller källans domän)
  - [x] admin/import.php: respekterar original_organization_domain från exportfil, annars importörens domän
  - [x] admin/export.php: inkluderar original_organization_domain + organization_domain i export-JSON
  - [x] admin/edit_course.php: INSERT för nyskapad kurs sätter original = skaparens domän; UPDATE rör inte fältet
  - [x] admin/cron/process_ai_jobs.php: AI-genererade kurser får skaparens domän som original
  - [x] admin/courses.php: info-badge "Ursprung: Org (domän)" i kurslistan när original != nuvarande org
  - [x] admin/edit_course.php: samma badge i formulärhuvudet
  - [x] Badgen visas endast i admin-vyer, aldrig i student-vyer (index.php/lesson.php)
- [x] Superadmin "Visa som"-funktion (2026-04-22)
  - [x] include/functions.php: isImpersonating()-helper
  - [x] admin/impersonate.php: POST-endpoint för att starta (CSRF, superadmin-only, blockar självval + super_admin-mål, nestat impersonation avbryts först)
  - [x] admin/stop_impersonate.php: POST-endpoint för att avsluta (återställer impersonator_*-session)
  - [x] include/header.php: röd banner med återställningsknapp för student-/lektionsvyerna
  - [x] admin/include/header.php: samma banner i admin-layouten om målet råkar vara admin
  - [x] admin/users.php: "Visa som"-knapp per användarrad (superadmin-only, ej mot super_admin eller sig själv)
  - [x] Audit: error_log-notering vid start och stopp
- [x] Domängruppering till organisationer (2026-04-10)
  - [x] Migration 023: organizations + organization_domains tabeller, organization_id på pub_agreement_artifacts
  - [x] include/functions.php: getOrganizationByDomain, getOrgScopeDomains, buildDomainInClause, buildEmailDomainInClause, userHasPubAgreement, createOrganization m.fl.
  - [x] include/functions.php: savePubAgreementArtifact slår upp organization_id automatiskt
  - [x] index.php: student-vy expanderar org-scope
  - [x] admin/index.php, users.php, courses.php, edit_course.php, edit_lesson.php, copy_course.php, certificates.php, course_stats.php, tags.php, reminders.php: org-scope-filtrering
  - [x] admin/ajax/search_users.php: org-scope-filtrering
  - [x] pub_agreement.php: lyfter PUB-avtal till org-nivå (updateOrgPubAgreement) när domänen är grupperad
  - [x] include/header.php: PUB-banner använder userHasPubAgreement, profilpanel visar org-namn
  - [x] admin/organizations.php: nytt superadmin-CRUD (skapa, redigera, ta bort, tilldela domän, primär domän)
  - [x] admin/include/header.php: nytt menyval "Organisationer"
  - [x] admin/domains.php: ny kolumn "Organisation" med klickbar länk
- [x] Dynamiska startdatum för stegvisa kurser — löpande registrering (2026-03-31)
  - [x] Migration 022: enrollment_type ENUM('bulk_start','rolling') på courses
  - [x] functions.php: enrollUserInSequentialCourse() utökad med $startDate + getProjectedEndDate() + getLatestAvailableLessonDate()
  - [x] admin/ajax/enroll_user_sequential.php: AJAX-endpoint för individuell/grupp-inskrivning
  - [x] admin/edit_course.php: registreringsläge-val (bulk/rolling) + inskrivnings-UI med användarsökning och datumväljare
  - [x] admin/course_stats.php: nya kolumner (Startdatum, Senaste lektion, Beräknat slutdatum) + inskrivningsmodal
  - [x] cron/process_sequential_starts.php: exkluderar rolling-kurser från bulk-start
  - [x] admin/export_statistics.php: nya kolumner i Excel-export för rolling-kurser
- [x] Feedback från admin sater.se (2026-03-31)
  - [x] Redaktörssökning filtrerar nu på organisationens domän (admin/ajax/search_users.php)
  - [x] "Aktiv"-reglaget flyttat till toppen av kursformuläret med tydlig visuell indikator (admin/edit_course.php)
  - [x] Excel-exportknapp tillagd i Kursstatistik-detaljvyn (admin/course_stats.php)
  - [x] Diplomhantering filtrerar nu kurser och statistik på organisationens domän (admin/certificates.php)
  - [x] E-postnotifiering skickas nu när någon läggs till som redaktör (admin/ajax/add_course_editor.php)
- [x] Uppgradera lektionsredigeraren till TinyMCE WYSIWYG (2026-03-25)
  - [x] Ladda ner och installera TinyMCE 6 (self-hosted) + svenskt språkpaket
  - [x] Skriv om admin/include/editor.php: contenteditable → TinyMCE textarea
  - [x] Fullständig toolbar för innehållseditorn (formatering, tabeller, bilder, länkar, innehållsblock)
  - [x] Enklare toolbar för AI-instruktion, AI-prompt och quiz-fråga
  - [x] Bilduppladdning via befintligt upload_image.php med storleks/placeringsklasser
  - [x] Innehållsblock-dropdown: Introduktion, Tips, Information, Exempel, Varning, Sammanfattning
  - [x] WYSIWYG-preview med editor-content.css (visar block-stilar i editorn)
  - [x] Uppdaterat cleanHtml() med stöd för a-taggar (http/https), tabeller (td/th med colspan/rowspan)
  - [x] cleanHtml() utökad: inline style-attribut med whitelisting av CSS-egenskaper (färger, typsnitt, storlekar, bakgrunder)
  - [x] Nya tillåtna taggar: span, h2, h5, s, sub, sup, blockquote, hr
  - [x] XSS-skydd: blockerar url(), expression(), parenteser i CSS-värden
  - [x] Utökad toolbar: typsnitt, fontstorlek, textfärg, bakgrundsfärg, radavstånd, justering, genomstruken
  - [x] Bilder: fri placering, storleksändring via drag, width/height-attribut, avancerad bildflik
  - [x] Tabell- och länkstilar i lesson.php frontend
  - [x] TinyMCE script-tag i admin/include/header.php
- [x] Integrera stimma-sync-tool i Stimma admin (2026-03-25)
  - [x] Extrahera performUserSync() till include/functions.php (delad logik)
  - [x] Refaktorera api/sync_users.php att använda performUserSync()
  - [x] Skapa admin/ajax/sync_users_direct.php (AJAX-endpoint med sessionsauth)
  - [x] Skapa admin/sync_tool.php (komplett sync-UI portad från stimma-sync-tool)
  - [x] Lägg till "Synkverktyg" i admin-menyn (header.php)
  - [x] API-endpoint och API-nyckelhantering oförändrade (för externa system)
- [x] API Kursstatus + Synligt kurs-ID (2026-03-04)
  - [x] admin/courses.php: ID-kolumn i kurstabellen
  - [x] admin/edit_course.php: kurs-ID badge i formulärhuvudet
  - [x] api/course_status.php: GET-endpoint med Bearer-auth, email+course_id, certifikatkontroll + progress
  - [x] admin/user_guide.php: nya sektioner (stegvisa kurser, kursstatistik, REST API med endpoint-dokumentation)
  - [x] docs/SYSTEM_DOCUMENTATION.md: REST API-endpoints tabell (sync_users + course_status)
- [x] Fix: "Kom ihåg mig" token matchade inte UI (720h→168h = 7 dagar) (2026-03-04)
  - [x] .env: REMEMBER_TOKEN_HOURS 720→168
  - [x] include/auth.php: fallback-värde och kommentarer uppdaterade
  - [x] admin/user_guide.php: "30 dagar"→"7 dagar" i sessionsinformationen
- [x] Regenerera API-nyckel i domänöversikten (2026-03-04)
  - [x] admin/api_keys.php: "Regenerera nyckel"-knapp + bekräftelsemodal i synk-per-domän-tabellen
  - [x] admin/api_keys.php: kursstatus-endpoint dokumenterad i API-dokumentationssektionen
- [x] Videouppladdning och streaming i lektioner (2026-03-04)
  - [x] Migration 019: video_type ENUM('youtube','local') i lessons + backfill
  - [x] upload/videos/ katalog + .htaccess säkerhet
  - [x] docker-compose.yml: PHP upload-gränser höjda (105M/110M, max_execution_time 300s)
  - [x] admin/upload_video.php: MP4/WebM uppladdning (max 100 MB, finfo MIME-validering)
  - [x] admin/edit_lesson.php: flikbaserat videogränssnitt (YouTube/Ladda upp) med progress bar
  - [x] lesson.php: HTML5 videospelare för lokala videor, YouTube iframe bibehållen
  - [x] admin/delete_lesson.php: raderar lokal videofil vid lektionsborttagning
  - [x] admin/copy_course.php: kopierar lokala videofiler med nytt filnamn
  - [x] admin/export.php: inkluderar video_type i exportdata (lokala videor exporteras ej)
  - [x] admin/import.php: hanterar video_type vid import (bara YouTube importeras)
  - [x] nginx: client_max_body_size 150M för stimma.sambruk.se
- [x] Dynamisk maxgräns för lektioner (superadmin-inställning i ai_settings)
- [x] Textlängd-inställning (kort/mellan/lång) i AI-genereringsmodalen
- [x] Tvåfas-generering: kursstruktur + individuellt lektionsinnehåll (fixar problemet med för få lektioner)
- [x] Höjd maxgräns för lektioner till 50 (var 20)
- [x] Fix: Kursradering blockerad av FK-constraints (certificates, course_tags, resources, ai_course_jobs, user_progress)
- [x] Uppdaterad användarhandbok med AI-kursgenerering, PUB-avtal, import/export, diplom m.m.
- [ ] Säkerhetsåtgärder fas 3: magic link-invalidering, credential rotation (se SECURITY.md)
- [x] Stegvisa lektioner + Kursstatistik med org-tagg-drill-down (2026-03-03)
  - [x] Migration 018: sequential_mode/interval/reminder-kolumner i courses, sequential_lesson_schedule, sequential_reminder_log
  - [x] include/functions.php: enrollUserInSequentialCourse(), unlockNextSequentialLesson(), isLessonAvailableForUser(), getSequentialCourseStatusForUser()
  - [x] admin/edit_course.php: stegvis-checkbox + intervall-/påminnelsefält
  - [x] lesson.php: enrollment vid första lektion, åtkomstkontroll, upplåsning vid avklarad lektion, AJAX-svar med tillgänglighetsinfo
  - [x] index.php: lås-/klock-ikoner, stegvis-badge i kurskatalogen
  - [x] cron/send_sequential_notifications.php: dagligt cron-jobb för ny-lektion och påminnelse-mail
  - [x] admin/course_stats.php: kursöversikt + detaljvy med org-tagg-gruppering
  - [x] admin/ajax/send_manual_sequential_reminder.php: manuell påminnelse med valfritt meddelande
  - [x] admin/include/header.php: nytt menyval "Kursstatistik"
  - [x] cron/send_reminders.php: exkluderar stegvisa kurser (sequential_mode = 0)
  - [x] admin/edit_course.php: ersatt checkbox-lista för organisationstaggar med sökbar klick-lista, valda visas som badges, "Alla"/"Rensa"-knappar, bokstavsordning
- [x] REST API Användarsynk (2026-03-02)
  - [x] Migration 015: api_keys-tabell + sync_enabled i domain_settings
  - [x] Migration 016: is_synced/sync_status/synced_at på users, user_org_tags, sync_log
  - [x] include/api_helpers.php: autentisering, rate limit, validering
  - [x] api/sync_users.php: POST-endpoint med full transaktionslogik
  - [x] admin/api_keys.php: nyckelhantering + synk-toggle per domän
  - [x] admin/sync_logs.php: paginerad synklogg
  - [x] admin/users.php: org-taggar, synk-ikon, synk-statusbadge, synk-filter
  - [x] admin/include/header.php: nya menyval (API-nycklar, Synkloggar)

## Framtida förbättringar
- [ ] Lägg till förhandsvisning av e-postmall
- [ ] Statistik per e-postkampanj
- [x] Kursdistribution per org-tagg + profilpanel (2026-03-03)
  - [x] Migration 017: course_org_tags-tabell
  - [x] include/functions.php: getOrgTagsForDomain(), getUserOrgTags()
  - [x] admin/edit_course.php: org-tagg checkboxar + POST-handler
  - [x] index.php: org-tagg-filtrering i $lessons och $orgCourses
  - [x] include/header.php: profilknapp + offcanvas-panel (namn, domän, roll, org-taggar)
  - [x] admin/courses.php: org-tagg badges i kurslistan
- [x] Statistik per kurs + org-tagg med drill-down till användarnivå

## Slutfört
- [x] Uppdaterad användarhandbok (2026-02-28): Nytt avsnitt AI-kursgenerering (tvåstegsflöde, inställningar, textlängd, bildgenerering), PUB-avtal (steg-för-steg signering, SMS-verifiering), import/export av kurser (ZIP), diplom/certifikat för studenter, domänhantering för superadmin, uppdaterade quiz- och felsökningsavsnitt
- [x] Fix kursradering (2026-02-28): delete_course.php raderar nu alla relaterade data (user_progress, resources, course_tags, certificates, course_enrollments, reminder_log, ai_course_jobs) innan kursen tas bort. Samma fix i delete_lesson.php. Borttagen duplicerad deleteCourse JS-funktion och gammal GET-baserad raderingshanterare.
- [x] Tvåfas AI-generering + höjd maxgräns (2026-02-28): Fas 1 genererar kursstruktur (titlar+quiz), fas 2 genererar innehåll per lektion individuellt. Garanterar korrekt antal lektioner. Max höjt från 20 till 50.
- [x] Dynamisk maxgräns för lektioner + textlängdsval (2026-02-28): Superadmin styr max via ai_settings, validering i UI+backend, kort/mellan/lång textlängd (~5-8/~12-18/~25-35 meningar)
- [x] AI Course Generation Upgrade (2026-02-28): Tvåstegsflöde med AI-frågor, tonalitet/färgtema/målgrupp-inställningar, automatisk bildgenerering (kurs+lektioner+diplom), rikare lektionsformatering (tip/info/example/warning/summary-rutor), cleanHtml stöd för lesson-* CSS-klasser och h3/h4
- [x] Säkerhetsfix fas 2 (2026-02-19): CSRF på edit-formulär, delete→POST, DB-port stängd
- [x] Säkerhetsfix fas 1 (2026-02-19): .htaccess, XSS, CSRF timing-safe, CLI-guards, display_errors, htmlspecialchars
- [x] ZIP-baserad kursexport med bilder (export.php)
- [x] ZIP-baserad kursimport med bildåtermappning (import.php)
- [x] Öka upload-gränser i docker-compose.yml (50M/55M)
- [x] Uppdatera importformulär att acceptera .zip-filer
- [x] Genomförd säkerhetsaudit (SECURITY.md, 2026-02-18) - 7 Critical, 12 High, 10 Medium, 8 Low
- [x] Ta bort Kursbilder-funktionen (PPTX/PDF) – inte responsiv, dålig tillgänglighet
- [x] Klickbara PUB-avtal i superadmins domänlista – modal med avtalsdetaljer (undertecknare, org, datum, IP, SHA-256)
- [x] Klickbara rader i pub_documents.php "Digitalt tecknade PUB-avtal" – modal med fullständiga avtalsdetaljer
- [x] Sökfält för att filtrera domäner i domains.php
- [x] Infoga bilder i lektionsinnehåll med storlek (S/M/L/100%) och placering (vänster/center/höger)
- [x] Domänväljare i användarvyn för superadmin + sortering på domän/e-post
- [x] E-postnotifikation vid rättighetsändringar (admin/redaktör)
- [x] Fixa domänfiltrering i admin/index.php (admin ser endast sin organisations statistik)
- [x] PUB-avtalsfunktion: Superadmin kan markera om domän/organisation har tecknat PUB-avtal
- [x] PUB-avtal visas i admin/redaktörs-header (badge visar status)
- [x] Varningsmeddelande i användarvyn för domäner utan PUB-avtal
- [x] Migration 009_pub_agreements.sql för domain_settings-tabell
- [x] Tillåt flera klick på inloggningslänkar under hela giltighetstiden (15 minuter)
- [x] Ta bort stöd för fill_blank frågetyp (lucktext) - endast single_choice och multiple_choice stöds nu
- [x] Lägg till varierande frågetyper i AI-kursgenerering (single_choice, multiple_choice)
- [x] Gör AI-kursgeneringsprompt redigerbar av superadmin via GUI (admin/ai_settings.php)
- [x] Skapa migration för course_generation_prompt (migrations/008_course_generation_prompt.sql)
- [x] Uppdatera process_ai_jobs.php för att stödja nya frågetyper
- [x] Lägg till informationssida (info.php) med knapp på inloggningssidan
- [x] Lägg till behörighetsinformation i användarhandboken (user_guide.php)
- [x] Lägg till informationsruta om behörigheter i användarvyn (index.php)
- [x] Gör användarhandboken tillgänglig för alla inloggade användare
- [x] Uppdatera version till 2.0 i footer
- [x] Fixa kursfiltrering i admin/courses.php (admin ser endast egen organisation)
- [x] Fixa redaktörsfiltrering i admin/courses.php (redaktör ser egna/tilldelade kurser)
- [x] Verifiera att copy_course.php visar alla kurser från alla organisationer
- [x] Skapa TODO.md för projektet
- [x] Lägg till testmail-funktion i admin/reminders.php
- [x] Skapa AJAX-endpoint för att skicka testmail (admin/ajax/send_test_reminder.php)
- [x] Verifiera PHP-syntax och endpoint-tillgänglighet
- [x] Fixa klickbara länkar i e-postmeddelanden (testmail + påminnelser)
- [x] Lägg till deadline-kolumn i courses-tabellen (migrations/003_course_deadline.sql)
- [x] Uppdatera kursredigeringssidan med deadline-fält
- [x] Uppdatera påminnelsemallen med deadline-variabler ({{deadline}}, {{days_remaining}}, {{deadline_info}})
- [x] Uppdatera cron-skriptet för att inkludera deadline-info i påminnelser
- [x] Uppdatera testmail-funktionen med deadline-variabler
- [x] Lägg till nya statistik-nyckeltal (fullt genomförda kurser, genomsnitt per användare)
- [x] Flytta alla nyckeltal från Statistik till Dashboard/Översikt
- [x] Uppdatera användarhandboken med nya funktioner (deadline, testmail, dashboard)
- [x] Designa om användarhandboken med snyggare layout, ikoner och visuella element

## API-åtkomst Säters kommun (2026-08-24)

Felsökning av att Säter inte får åtkomst till API:et med `sater.se`.

- [x] Verifiera att API-nyckel finns för `sater.se` (aktiv, skapad 2026-08-21)
- [x] Verifiera att endpointen nås utifrån (`/api/sync_users.php` svarar 401/405 korrekt)
- [x] Verifiera att Authorization-headern når PHP genom nginx + Apache (bekräftat: skiljer på "saknad" och "ogiltig" nyckel)
- [x] Konstatera att `last_used_at` är NULL och `sync_log` saknar sater-poster → ingen lyckad autentisering någonsin
- [x] Granska webbserverloggen sedan 2026-08-17 → inget anrop mot `/api/sync_users.php` från Säter har nått fram
- [x] Kontrollera `domain_settings.sync_enabled` för `sater.se` → slogs på 2026-08-24 10:55 (var AV 21–24 aug)
- [x] Beslut taget: endast primärdomänen får API-nyckel, och nyckeln gäller även underdomäner
- [x] `domainCoversEmailDomain()` i `include/api_helpers.php` — exakt match eller suffix föregånget av punkt
- [x] `validateSyncUsers()` använder den nya matchningen
- [x] `api/course_status.php` använder den nya matchningen
- [x] `performUserSync()` fick `$includeSubdomains` — avaktiveringsomfånget följer nu nyckelns omfång
      (RIGHT()/CHAR_LENGTH() i stället för LIKE, eftersom `_` är jokertecken i LIKE)
- [x] `api/sync_users.php` skickar `true` för underdomäner; admin-synkverktyget behåller exakt omfång per domän
- [x] `admin/api_keys.php`: underdomäner kan inte längre få egen nyckel eller egen synk-toggle, och visar
      i stället vilken primärdomän som täcker dem
- [x] Dokumentation uppdaterad (on-page i api_keys.php, USER_GUIDE.md, SYSTEM_DOCUMENTATION.md)
- [x] Testat end-to-end mot skarp endpoint med tillfällig testdomän — alla spår städade efteråt
- [ ] Be Säter testa på nytt med sin befintliga nyckel (gäller nu även edu.sater.se)
- [ ] Om Säter fortfarande får 401: nyckeln visas bara vid skapandet — regenerera och överlämna på nytt
- [ ] Överväg: `ensureAiQuotaRow()` skapar separat AI-kvot per e-postdomän, så edu.sater.se får en egen
      50k-kvot skild från sater.se. Inkonsekvent med att organisationen nu behandlas som en enhet.

## Rollen "Läsbehörig" + org-taggfilter (2026-08-24)

- [x] Kartlägg befintliga roller — behörighet bärs av is_admin/is_editor, inte av users.role
- [x] migrations/045_viewer_role.sql: kolumnen `is_viewer` + index (applicerad)
- [x] auth_check.php släpper in läsbehörig i adminytan
- [x] statistics.php: läsbehörig ser hela kurslistan i sitt DOMÄNSCOPE (admin ser alla kurser i hela
      systemet — den listan är inte domänavgränsad, så läsbehörig fick en egen, snävare gren)
- [x] course_stats.php: samma, plus domänprövning av kurs-id i URL:en för läsbehörig
- [x] certificates.php: läsbehörig får förhandsgranska, skrivande POST stoppas serverside
- [x] users.php: läsbehörig ser listan, alla skrivande POST stoppas serverside, knappar/modal dolda
- [x] export_users.php + export_statistics.php öppnade för läsbehörig
- [x] users.php: ny kolumn + toggle för att sätta läsbehörig (annars går rollen inte att tilldela)
- [x] E-postnotifiering vid rolländring fick svenskt namn för 'viewer'
- [x] Menyn: läsbehörig ser Översikt, Kursstatistik, Diplom, Användare
- [x] BUGGFIX: redaktörer såg "Diplom" i menyn men kastades ut av certificates.php — länken flyttad
- [x] Org-taggfilter (`org_tags[]`) på statistics.php, course_stats.php och users.php,
      för BÅDE läsbehörig och domänadmin. Valfritt; utan val visas allt som förut.
      Erbjuder bara den inloggades egna taggar, och valet skärs mot dem.
- [x] Filtret följer med till CSV-exporterna så filen matchar den lista man tittade på
- [x] Dokumentation: USER_GUIDE.md (roller + filteravsnitt), SYSTEM_DOCUMENTATION.md (schema + helpers)
- [x] Testat: rendering som läsbehörig, förfalskade POST-anrop stoppade, regressionstest som admin,
      taggfiltret snävar in och skalar bort främmande taggar. Testdata städad.

### Kvarstår / noterat
- [ ] Org-taggar lagras platt — "Kommun/Förvaltning/Avdelning" blir tre fristående taggar.
      Ett hierarkiskt filter ("allt under Förvaltningen") går inte att uttrycka förrän
      datamodellen bär vägen. Överväg en `path`-kolumn i user_org_tags.
- [x] export_users.php filtrerar på `$currentUserDomain` medan users.php använder hela
      org-scopet. En admin på primärdomänen med flera domäner exporterar därför färre rader
      än listan visar. FIXAT 2026-08-25: exporten använder nu
      getEffectiveOrgScopeDomains() + buildEmailDomainInClause(), samma som listan.
      ITSAM-admin: 31 rader före → 231 efter, exakt lika många som listan visar.
      Superadmin med okänd domän i ?domain= gav förr tom fil, faller nu tillbaka till alla.
- [x] statistics.php listar ALLA kurser i hela systemet för admin, inte bara den egna
      organisationens. FIXAT 2026-08-25: ny helper buildOrgCourseScopeClause() i
      functions.php avgränsar kurslistan till egna kurser + globala + hitdelade +
      kurser de egna användarna faktiskt läst. Admin och läsbehörig delar nu samma
      gren. ITSAM-admin: 28 kurser före → 6 efter. Fjärde armen (faktiskt läst)
      behövs för sub-domänadmins, som annars tappat allt utom sin enda egna kurs.
- [x] statistics.php: åtkomstkontrollen för ?course_id= hoppades över för admin, så
      ett kurs-id i URL:en nådde vilken kurs som helst förbi kurslistan. Gäller nu
      även admin, mot samma kursscope. (Upptäckt 2026-08-25.)
- [x] statistics.php: users_started och completed_count räknade ALLA organisationers
      läsning, inte bara den egna — users-joinen filtrerade inte progress. Kurs 58 ur
      Åtvidabergs vy: 183 användare före, 75 efter. (Upptäckt 2026-08-25.)

## Bildprompt och lektionsbildernas sökväg (2026-08-28, forts.)

- [x] Ny gemensam stilanvisning: aiImageStyleDirective() i include/ai_image_helper.php.
      Prompten skrevs på FYRA ställen (kursomslag + lektionsbild i admin, samma två i
      bakgrundsjobbet) och drev isär. Nu ett ställe.
- [x] Gamla prompten bad om "clean, professional, minimalist" och "abstract or
      conceptual" — alltså en beställning på just det intetsägande som gjorde
      bilderna trista. Nya ber om konkret motiv, varm palett (cream/terrakotta/
      ockra/salvia/dammblå), illustrerat anslag, och räknar upp vad som ska undvikas
      (stockfoto, 3D, neon, kretskort, robotar). Negativa exempel biter bättre än
      fler positiva adjektiv.
- [x] Verifierat visuellt på två motiv, inklusive ett torrt (Socialtjänstlagen) där
      abstrakta gradienter annars är modellens standardsvar. Provbilder kvar för
      granskning: upload/ai_6a9199cd3fe6b.png, upload/ai_course_6a919a168635d.png
- [x] 🔴 SEPARAT BUGG hittad vid testet: lektionsbilder gick aldrig att spara.
      include/ai_lesson_helper.php använde `__DIR__ . '/../upload/'`, vilket från
      include/ pekar på PROJEKTROTEN och inte public/upload. Rätt före
      webbrotsflytten 2026-08-17, fel efter. Katalogen ägdes av root → www-data fick
      "Kunde inte spara bildfilen" trots att bilden hämtats färdig och betalats för.
      Samma felaktiga djup i functions.php:634, som dessutom SKAPADE katalogen.
      Båda pekar nu på ROOT_PATH . '/public/upload/'. Den tomma felkatalogen borttagen.
- [x] Genomgång av alla elva ställen som skriver till upload/: övriga nio ligger i
      public/ och hade rätt djup hela tiden.
- [x] Verifierat efter fixen: lektionsbild genom nginx 51 s, http=200, fil sparad.

## Bildgenerering: "Nätverksfel" efter modellbyte till gpt-image-2 (2026-08-28)

- [x] Diagnos: modellen FUNGERAR. Bilderna genererades och sparades — filerna
      ai_course_6a9192db2fe2b.png (13:53:31) och ai_course_6a9193195117c.png
      (13:54:33) finns, ai_usage_log säger status=ok, och courses.image_url för
      kurs 25 pekade redan på den senare. Bara webbläsaren fick aldrig veta.
- [x] Orsak: uppmätt gpt-image-2 = ~50 s, gpt-image-1-mini = ~15 s. Stimmas
      server-block i nginx saknade proxy_read_timeout → default 60 s. Anropet låg
      precis på gränsen, och när det tippade över stängde nginx medan PHP körde
      klart. Front-endens .catch() visar då 'Nätverksfel vid generering av bild.'
- [x] Åtgärd: proxy_read_timeout/proxy_send_timeout 300s tillagt i det BEFINTLIGA
      stimma-blocket i vibecoder-sambruk-u917.vm.elestio.app.conf. nginx -t ok,
      reload utan krasch, sajten svarar 200.
- [x] Verifierat skarpt genom nginx efter ändringen: POST till
      generate_course_image.php tog 49,3 s och gav http=200 med giltig JSON.
      Testbilden borttagen och kursens image_url återställd efteråt.
- [x] Felmeddelandet i edit_course.php och edit_lesson.php säger nu att bilden
      ofta blir klar ändå och att man ska ladda om innan man försöker igen —
      annars genereras en bild i onödan, och den kostar.

### Kvarstår / noterat
- [ ] Bildgenereringen hålls i ett enda HTTP-anrop. 300 s räcker med god marginal
      för gpt-image-2, men rätt konstruktion är samma bakgrundsjobb som
      kursgenereringen använder (ai_course_jobs). Blir modellerna långsammare
      eller läggs fler bilder i samma anrop är det den vägen att gå.
- [ ] Prompten för kursomslag är generisk ("Clean, professional, modern style …
      Abstract or conceptual visualization"). Det är den andra spaken om bilderna
      inte blir bra — modellbytet löser inte en tunn prompt. Inte ändrad, eftersom
      det är ett smakval.

## Tre fynd från testet av Läsbehörig (2026-08-28)

- [x] Taggfiltret erbjöd bara den inloggades EGNA taggar. Fel i praktiken: den som
      följer upp hela organisationen bär sällan varje avdelnings tagg. getOrgTagFilter()
      (f.d. getOwnOrgTagFilter) listar nu alla taggar inom domänscopet. Anders gick
      från 3 valbara till 59, superadmin ser Sambruks 10. Avgränsningen ligger kvar
      på domänen — sub-domän ser bara sin egen, ingen ser annan org.
- [x] Läsbehörig kunde inte exportera statistik. export_statistics.php prövade rollen
      mot ägarskap/redaktörskap precis som en redaktör (`if (!$isAdmin)`), och en
      läsande roll äger inga kurser → alltid nekad. Samma bugg som statistics.php hade.
      Prövas nu mot buildOrgCourseScopeClause(), och datagrenen är den domänavgränsade
      (redaktörsgrenen listar ALLA som rört kursen oavsett domän — hade läckt andra
      organisationers användare).
- [x] Felmeddelandet vid Excel-export: filen var en HTML-tabell med filändelsen .xls,
      så Excel varnade för att format och filändelse inte stämmer överens. Ny
      include/xlsx.php skriver ett äkta xlsx-paket via ZipArchive. Verifierat att
      openpyxl öppnar filen utan varningar.
- [x] Exporten läser nu även org_tags[] — länken från statistics.php skickade filtret
      men filen ignorerade det, så CSV:n innehöll fler rader än listan. Filtret skrivs
      dessutom ut som en rad överst i filen.

### Kvarstår / noterat
- [ ] include/xlsx.php är medvetet minimal: ett blad, inline-strängar, sju format.
      Behövs formler, flera blad eller diagram är det dags för ett riktigt bibliotek
      i stället för att bygga vidare i den.
- [ ] export_users.php är fortfarande CSV (semikolon + BOM). Öppnas utan varning i
      Excel, så den är inte akut — men vill man ha samma filtyp överallt finns
      xlsxWrite() nu att luta sig mot.

## Läsbehörighet i batch via synkverktyget (2026-08-26)

- [x] Fråga: går det att välja Roll = Läsbehörig i synkverktyget? Nej. Rullgardinen
      har Användare/Redaktör/Admin, normalizeSyncRole() känner bara student/teacher/
      admin, och performUserSync() skriver role/is_admin/is_editor — is_viewer rörs
      aldrig. Samma sak i JSON-API:et.
- [x] Slutsats: läsbehörig är INGEN roll. users.role är enum('student','teacher',
      'admin','super_admin') och behörigheten bärs av den separata kolumnen
      users.is_viewer, som i användarlistan är en egen på/av-knapp. Alla åtta
      läsbehöriga har roll 'student' plus flaggan.
- [x] Därför INTE gjord till ett fjärde rollvärde: alla åtta är synkade konton, så
      en synk som ägde is_viewer hade tagit bort behörigheten från var och en av dem
      så fort Säters lista kördes utan den kolumnen. Samma fälla som org-taggarnas
      tysta rensning.
- [x] Byggt i stället: eget kort "Läsbehörighet i batch" i sync_tool.php +
      ajax/set_viewer_batch.php. Filformat email;läsbehörig (ja/nej, kolumn
      utelämnad = ja). Enda skrivningen är UPDATE users SET is_viewer.
- [x] parseViewerFlagValue() i functions.php. Tomt värde ger fel, inte gissning.
- [x] Grindar: admin på primärdomän eller superadmin, varje adress skuren mot orgens
      domäner, CSRF, hela listan valideras före första skrivningen.
- [x] Dokumenterat i admin/user_guide.php och docs/USER_GUIDE.md.

- [x] BESLUT 2026-08-26: JSON-API:et ska INTE kunna sätta läsbehörighet.
      Bedömt som ointressant — behörigheten delas ut av Stimma-administratören,
      inte av kundens katalogsystem. API-synken lämnar därmed is_viewer orörd,
      vilket också betyder att den aldrig kan råka nollställas därifrån.
      Dokumenterat på båda ställena där API-fälten listas.

### Kvarstår / noterat
- [ ] Testanteckning: session.use_strict_mode = 1 gör att PHP vägrar sessions-id
      servern inte själv utfärdat, och sessionsfiler måste ägas av www-data. En
      handgjord sessionsfil för test måste alltså skapas med `docker exec -u www-data`.

## Läsbehörig: menyval och behörighetsgrindar (2026-08-25)

- [x] Rapporterat: anders.eriksson@sater.se (is_viewer=1) fick inget menyval till
      adminytan. Orsak: include/sidebar.php byggde $showAdminLink av is_admin ||
      is_editor, och include/header.php slog bara upp de två flaggorna.
      admin/include/auth_check.php släppte in rollen hela tiden — sidorna fanns,
      men ingen väg dit. Åtgärdat i båda filerna, rollmärket "Läsbehörig" tillagt.
- [x] admin/index.php visade AI-widgetens "Se detaljer" för alla, men ai_usage.php
      kräver admin → död länk för läsbehörig och redaktör. Gatead.
- [x] admin/statistics.php var inte länkad från någonstans i hela appen — bara
      nåbar genom att skriva in adressen, trots att sidan underhålls och har en
      egen gren för läsbehörig. Tillagd i adminmenyn som "Statistik per användare"
      för admin/redaktör/läsbehörig. Visar framsteg per användare, vilket
      course_stats.php ("Kursstatistik") inte gör — de överlappar alltså inte.
- [x] 🔴 SÄKERHET: edit_course.php släppte in läsbehörig. userCanModifyCourse()
      ligger inne i `if (isset($_GET['id']))` och skyddar bara redigering av en
      BEFINTLIG kurs — utan ?id= renderades "Skapa ny kurs" med fungerande
      POST-formulär och INSERT-gren utan någon behörighetskontroll. En läsande
      roll kunde alltså skapa kurser. Grind tillagd överst i filen.
- [x] Övriga skrivande sidor kontrollerade: sync_tool.php har egen admin-grind,
      edit_lesson.php har ett skyddsnät på POST (kräver författare eller
      kursredaktör). Båda håller. courses.php visar bara listan för läsbehörig.

### Kvarstår / noterat
- [ ] Rotorsaken kvarstår som mönster: is_viewer lades till i den DELADE
      auth_check.php utan att varje adminsida granskades för vad det öppnade.
      Nästa roll som läggs till där riskerar samma sak. Överväg att dela upp
      auth_check i en läsande och en skrivande grind i stället för att varje
      skrivande sida ska komma ihåg att neka själv.
- [ ] sync_tool.php och edit_lesson.php inkluderar header.php FÖRE sin
      behörighetskontroll, så en obehörig får ~6 kB sidhuvud renderat före
      omdirigeringen. Fungerar, men kontrollen borde ligga före utskrift.

## Inloggningstid — text mot verklighet (2026-08-25)

- [x] Kontrollerat vad som faktiskt gäller: REMEMBER_TOKEN_HOURS=168 (7 dygn), men
      validateRememberToken() anropar createRememberToken() vid varje automatisk
      inloggning → glidande fönster. Produktionsdata bekräftar: user 2 skapades
      2025-12-02 och fick ny token 2026-08-25, 265 dagar senare.
- [x] Utan "kom ihåg mig" gäller INTE 24 timmar. renewSession() anropas aldrig
      (död kod), så SESSION_LIFETIME_HOURS=24 styr ingenting. Sessionen dör när
      PHP:s gc rensar filen i /tmp: gc_maxlifetime=1440 s. Äldsta levande
      www-data-session vid kontrollen var 76 min; allt äldre var borta.
- [x] Texten rättad på tre ställen: public/index.php (kryssrutan),
      admin/user_guide.php och docs/USER_GUIDE.md — de påstod tre olika saker
      (7 dagar / 24 timmar / 30 dagar respektive "tills du stänger webbläsaren").

- [x] Beslut 2026-08-25: kom-ihåg-mig höjs till 30 dygn, glidande utan tak. Utan
      kryss gäller 8 timmar (en arbetsdag). REMEMBER_TOKEN_HOURS=720,
      SESSION_LIFETIME_HOURS=8.
- [x] `session.gc_maxlifetime` sätts nu i config.php till SESSION_LIFETIME_HOURS.
      PHP:s standard 1440 s var kortare än kakan lovade — det var den verkliga
      orsaken till att man åkte ut efter en halvtimme.
- [x] Falsy-buggen borta: SESSION_LIFETIME (dygn) är helt ersatt av
      SESSION_LIFETIME_HOURS, som läses med `=== false` i stället för `?:`.

### Kvarstår / noterat
- [ ] renewSession() i functions.php är fortfarande död kod. Den ser ut som en
      sessionsutgångskontroll men anropas inte från någonstans. Ta bort den eller
      koppla in den — nu när gc_maxlifetime styr utgången fyller den ingen funktion.
- [ ] De 333 befintliga remember-tokens har kvar sina 7-dagarsutgångar. De blir
      30 dagar automatiskt vid nästa besök. Vill man ge alla 30 dagar direkt:
      `UPDATE remember_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
      WHERE expires_at > NOW();` — inte gjort, ändrar inloggningsläget för alla.
- [ ] Glidande fönster utan tak betyder att en aktiv användare aldrig loggas ut.
      Medvetet valt. Vill man ha en bortre gräns krävs ett tak på hur gammal den
      ursprungliga inloggningen får vara, inte bara på token.

## Raderingsflagga i synk-API:et (2026-08-24)

- [x] Beslut: per-användarflagga `"delete": true` i users-listan (inte "radera saknade" —
      ett trasigt AD-utdrag hade då blivit en massradering)
- [x] Beslut: diplom raderas med kontot (CASCADE), ingen blockering
- [x] migrations/046_sync_delete_flag.sql: `sync_log.users_deleted` (applicerad)
- [x] `isUserSyncDeleteRequest()` i api_helpers.php — en enda tolkning av flaggan, delad av
      validering och synk. Accepterar true/1/"true"/"1"
- [x] `deleteUserCompletely()` i functions.php — komplett radering, delad av API och adminpanel
- [x] BUGGFIX: adminpanelens radering missade `quiz_answers` — personuppgifter blev kvar efter
      "radering". Rättat genom att båda vägarna nu använder samma funktion
- [x] `pub_agreement_artifacts` raderas AVSIKTLIGT inte (signerat avtal, egen rättslig grund)
- [x] validateSyncUsers: raderingsposter kräver bara e-post, men domänkontrollen gäller fullt ut
- [x] Superadmins kan inte raderas via API — räknas och rapporteras som `deletes_refused`
- [x] Raderade adresser läggs inte i `$processedEmails`, så en payload med bara raderingar
      inaktiverar inte resten av organisationen
- [x] `sync_users_direct.php` (adminverktyget) strippar flaggan — den är en API-funktion
- [x] Dokumentation: api_keys.php on-page, USER_GUIDE.md, SYSTEM_DOCUMENTATION.md
- [x] Testat skarpt mot endpointen: radering med spårrensning, idempotens, sträng-"true",
      delete:false, främmande domän, superadmin-skydd, ingen massinaktivering. Testdata städad.

### Noterat
- [ ] `certificates` har ON DELETE CASCADE. Om utbildningsbevis behöver överleva en radering
      krävs en avidentifierad kopia (kurs + datum utan person) — inte beslutat.

## Terminologi: "student" → "Användare" (2026-08-24)

- [x] Genomsökning av hela kodbasen (exkl. tinymce-bibliotek)
- [x] API:et tar nu emot `användare` / `redaktör` / `admin` som primära rollvärden.
      `student` och `teacher` accepteras fortfarande — Säter m.fl. har redan integrerat
      mot de gamla värdena och ska inte få sin synk sönder av ett terminologibyte.
- [x] `normalizeSyncRole()` gör översättningen på ETT ställe, vid systemgränsen
- [x] Felmeddelandet vid ogiltig roll listar de nya termerna
- [x] BUGGFIX: `isUserSyncDeleteRequest()` låg i api_helpers.php men anropades från
      `performUserSync()` i functions.php. Adminpanelens synkverktyg laddar inte
      api_helpers.php → fatalt fel vid varje synk därifrån. Båda hjälparna flyttade
      till functions.php, där performUserSync bor.
- [x] api_keys.php (API-dokumentationen i UI:t): rollvärden + delete-flaggan
- [x] admin/user_guide.php: exempel-JSON, rolltabell, delete-raden, CSS-klassnamn
- [x] admin/sync_tool.php: CSV-import, rollmeny, badge-klass, normalizeRole
- [x] admin/domains.php: internt `students` → `regular_users` (kolumnrubriken hette
      redan "Användare")
- [x] USER_GUIDE.md, SYSTEM_DOCUMENTATION.md, PUB_BILAGA_1_INSTRUKTION.md
- [x] include/learning_paths.php: "studentvy" → "användarvy" i kommentarer
- [x] Testat: nya och gamla rollvärden ger rätt lagrad roll, ogiltig roll ger nytt
      felmeddelande, synk via adminverktygets include-set fungerar. Testdata städad.

### Medvetet ORÖRT
- `users.role` är fortfarande ENUM('student','teacher','admin','super_admin').
  Enum-värdet är dekorativt (behörighet bärs av is_admin/is_editor/is_viewer) och ett
  byte hade krävt en migration genom varje query som nämner rollen, utan nytta för
  någon användare eller API-anropare. Översättningen sker vid systemgränsen i stället.
- USER_GUIDE.md rad 249: "Student" är en XP-nivåtitel (Nybörjare → Lärling → Student →
  Utforskare → Expert → Mästare), inte en roll. Ska inte heta "Användare".
- migrations/008: AI-promptens "studenten fyller i" syftar på den som läser kursen.
  Prompten ligger i databasen; migrationsfilen bara seedar den. Inte ändrad.

## 2026-08-24 — org-taggar kom aldrig fram, och ingen sa till

**Rapport:** sater.se skapade användare med org-taggar via API:et, men inga taggar
syntes i adminvyn.

**Utredning:** backend var korrekt hela tiden. Säters två första anrop (13:22 och
13:46) innehöll inget `organization`-fält; deras senare anrop (14:01) gjorde det, och
då skrevs taggarna direkt. Verifierat separat med en testanvändare som därefter togs
bort. Det verkliga felet är att inget i systemet berättar att fältet uteblivit.

### Sluta ignorera tyst
- [x] `collectSyncUserWarnings()` i functions.php — okända fältnamn rapporteras per
      post, och en payload där INGEN post bär organisationsfältet får en egen varning.
      Okända fält avvisas inte: en payload med extrafält från källsystemet ska
      fortsätta fungera. De redovisas som `warnings` i svaret.
- [x] `organisation` (svensk stavning) accepteras som alias för `organization`.
      Vi stavar det själva olika — CSV-mallen i synkverktyget har rubriken
      `organisation` medan API-dokumentationen säger `organization`.
- [x] Sammanfattningen fick `org_tags_satta` och `org_tags_rensade`. Utan dem gick det
      inte att se i svaret om taggarna kom fram.
- [x] Både API:et och adminpanelens synkverktyg returnerar varningarna, från samma
      funktion, så att båda vägarna säger samma sak om samma payload.
- [x] Synkverktygets logg visar varningarna och skriver ut en egen rad när taggar
      rensats — en rensning är nästan alltid en kolumn som saknades i filen.

### Stoppa den tysta taggrensningen
- [x] 🔴 `performUserSync()` raderade ALLTID en användares taggar innan den skrev nya,
      även när `organization` saknades i payloaden. En AD-synk utan den kolumnen hade
      alltså tyst nollställt taggar som satts för hand.
- [x] `readSyncOrganization()` skiljer nu på **fältet saknas** (rör inte taggarna) och
      **fältet är tomt** (ta bort taggarna). Skillnaden är hela poängen.
- [x] En lista accepteras som alternativ till den snedstrecksseparerade strängen.
      AD-flervärdesattribut serialiseras ofta så, och `trim()` på en array är ett
      FATALT fel i PHP 8 — hela synken hade havererat.

### Org-taggar i adminvyn
- [x] Fält för org-taggar i "Lägg till ny användare". Formuläret tog tidigare bara
      e-post, så taggar kunde över huvud taget inte sättas därifrån.
- [x] Ny action `set_org_tags` + modal för att ändra taggar på befintlig användare.
      Tidigare krävdes en omkörning av hela organisationens synk för att rätta EN
      persons avdelning.
- [x] Grindad på `$canManageUsers` (läsbehörig blockeras före action-hanteraren),
      CSRF-validerad, och domänbegränsad till `$adminScopeDomains` — samma gräns som
      rolländringarna. Redigeringsknappen visas bara för användare inom scopet.
- [x] `setUserOrgTags()` delas av adminvyn och synken, så "Kommun/Förvaltning/Avdelning"
      betyder samma sak oavsett väg. Dubbletter och blanksteg städas.
- [x] Funktionen använder RAKA prepared statements, inte `execute()`/`queryOne()` —
      de hjälparna sväljer PDOException och returnerar null. Anropad inifrån synkens
      transaktion hade ett svalt fel betytt att taggarna tyst uteblev i stället för
      att synken rullades tillbaka.

### Enhetlig stavning
- [x] API-dokumentationen: `organization` accepterar sträng eller lista, alias
      `organisation`, och den bärande semantiken (utelämnat fält = orörda taggar).
- [x] Nytt avsnitt "Organisationstaggar" och ett komplett svarsexempel med `warnings`
      och de nya räknarna, plus rådet att kontrollera `org_tags_satta`.
- [x] CSV-mallen och synkverktyget påpekar att kolumnerna läses på POSITION, inte på
      rubriknamn, och att JSON-fältet heter `organization`.

### Verifierat
- [x] 9 kontroller på semantiken: skapa med taggar, synk utan fältet (rör inte),
      svensk stavning, lista i stället för sträng, tomt fält rensar, rensning av redan
      tomt räknas inte, adminvägen, samspelet admin↔synk.
- [x] Skarpt HTTP-test mot `/api/sync_users.php` med en tillfällig nyckel på en
      slaskdomän: korrekt payload, felstavat fält (gav båda varningarna) och svensk
      stavning. **Nyckeln, domänen, användaren och loggraderna raderade efteråt** —
      verifierat att inget ligger kvar.
- [x] users.php renderad som Säter-admin: 48 redigeringsknappar, modal och nytt
      formulärfält på plats, och båda Säter-användarnas taggar syns i listan.

### Rättat samma dag: felaktigt påstående om hierarkin
- [x] Jag skrev i API-dokumentationen att "ett filter på Förvaltningen inte träffar
      avdelningarna under den". **Det stämmer inte.** Varje användare bär ALLA nivåer
      i sin egen väg, så ett filter på en högre nivå träffar dem längre ned.
      Verifierat: filter på "Säters kommun" gav båda testanvändarna, filter på
      "Skolförvaltningen" bara den ena.
- [x] Det som FAKTISKT inte går: skilja två grenar som delar namn på understa nivån.
      `Skolförvaltningen/IT` och `Vårdförvaltningen/IT` ger båda taggen `IT`, och ett
      filter på `IT` träffar båda. `user_org_tags` har bara `(user_id, tag)` — ingen
      förälder, ingen ordning.
- [x] Rättat i api_keys.php och i kodkommentarerna för `getOwnOrgTagFilter()` och
      `splitOrgTags()`.

### Kvarstår
- [ ] Redigeringsmodalen visar taggarna i snedstrecksform men i alfabetisk ordning,
      inte den ursprungliga vägen — ordningen lagras inte. Kräver en kolumn i
      `user_org_tags` för att lösas ordentligt.
- **Äkta hierarki: NEJ, beslutat 2026-08-24.** Att skilja grenar med samma lövnamn,
  visa träd och göra "allt under X" till en relation i stället för ett sammanträffande
  kräver att vägen bärs i datamodellen. Thomas valde bort det — komplexiteten i
  applikationen ska inte öka nu. Platt modell gäller; begränsningarna är dokumenterade
  i api_keys.php och i kodkommentarerna. Ta inte upp det igen utan nytt verksamhetsbehov.
- [ ] `ensureAiQuotaRow()` skapar fortfarande separat AI-kvot per e-postdomän
      (kvarstående punkt sedan domänomfånget).
