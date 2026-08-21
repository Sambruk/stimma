# Changelog

Alla större ändringar i Stimma dokumenteras här.

Formatet följer [Keep a Changelog](https://keepachangelog.com/sv/1.1.0/) och projektet använder semantisk versionshantering.

## [2.2.0] – 2026-08-20

### Lagts till

#### SCORM-import — kopieringsläge (standard)
- `include/scorm_course_builder.php`: bygger kurs och lektioner direkt ur paketet, utan AI och utan tokenförbrukning. Originaltexten kopieras ordagrant, alla bilder hamnar i rätt lektion och alla filmer kopieras in som lokala lektionsvideor
- `include/scorm_storyline.php`: Articulate Storyline läses ur paketets egen datamodell (`html5/data/js/*.js`). Scen → lektion, scenens film → lektionens video, text ur objektens `altText` (renare än den grafiska vektortexten, som tappar ligaturer). Svarsalternativ under radioknappar följer med, medan navigation och menysidor sållas bort på förekomstfrekvens
- Generiska HTML-paket: sidans HTML städas med `cleanHtml()` och sparas som lektionstext med bilderna ompekade till Stimmas uppladdningskatalog
- Valet mellan kopiering och AI-omskrivning görs i importdialogen; AI-inställningarna visas bara när AI-läget är valt

#### SCORM-import — AI-läge
- Importera SCORM-paket (`.zip`, SCORM 1.2 och 2004) och låt AI skriva om innehållet till en vanlig Stimma-kurs. Paketets HTML/JS körs eller serveras aldrig — bara text och media plockas ut
- `include/scorm_extractor.php`: läser `imsmanifest.xml` (namnrymdsokänsligt, stöd för `xml:base` och `isvisible`), extraherar sidtext, väljer största bilden per avsnitt och kopierar ut MP4/WebM
- Fallback för JS-drivna paket (Rise 360, Storyline, iSpring, Captivate): när HTML-skalet är tomt skördas text ur paketets JSON/XML-payload
- `public/admin/ajax/import_scorm.php`: uppladdning (max 100 MB), zip-bombsskydd, diskutrymmeskontroll och jobbskapande
- Avsnittsstruktur: ≥3 SCO:er ger en lektion per avsnitt, ett enda SCO delas upp i 3–12 lektioner
- Bilder och videor mappas till rätt lektion efter generering; videor sätts som `video_type = 'local'`

### Ändrat
- `process_ai_jobs.php` känner igen både PPTX-markören (`SLIDE N:`) och SCORM-markören (`SCO N:`) via `detectImportMarker()`, och mappar nu även `VIDEOFIL:` utöver `BILDFIL:`
- SCORM-fas 2 får bara sin egen del av källtexten (`buildImportSourceSlices()`) istället för hela underlaget i varje lektionsprompt — annars växer både kostnad och brus med kursens längd

### Fixat
- **AI-jobb startade aldrig från webbgränssnittet.** `trigger_ai_processor.php` skrev processorloggen till `/var/www/html/upload`, som efter webbrots-omläggningen 2026-08-17 är en tom root-ägd katalog. Shell-omdirigeringen nekades, bakgrundsprocessen dog direkt och jobben blev liggande som `pending`. Loggen skrivs nu till `public/upload` med fallback till `sys_get_temp_dir()`. Påverkade all AI-kursgenerering, inte bara SCORM
- Den synkrona reservvägen i samma trigger var verkningslös: processorn blockerar webbåtkomst och dog tyst vid `include`. Den släpps nu igenom via konstanten `STIMMA_JOB_PROCESSOR_INTERNAL`; ett direkt HTTP-anrop mot processorn ger fortfarande 403
- SCORM-paket med ett enda avsnitt (Rise 360, iSpring, Storyline) klipptes vid 8 000 tecken. Textbudgeten fördelas nu över avsnitten, med 60 000 tecken totalt
- Typsnitts- och assetdefinitioner (`Poppins SemiBold ChBold1D9B48A7`) filtreras bort ur textskörden, och ligaturer (`ﬁ`, `ﬂ`, `ﬀ`) expanderas — de gjorde orden obegripliga i paket vars text kommer från ett PDF-original

## [2.1.0] – 2026-08-13

### Lagts till

#### Lärvägar
- Lärvägar: paketera flera kurser i en namngiven, ordnad grupp. Ordningen är en rekommendation — inga kurser låses
- Deltagarvy `learning_paths.php` med status per kurs (genomförd / påbörjad / registrerad / ej påbörjad) och samlad procent
- Sektionen "Mina lärvägar" på deltagarens översikt, med länk till fullständig vy
- Adminvy för att skapa lärvägar, koppla kurser med dra-och-släpp och begränsa delning per domän
- Lärvägsstatistik: matris över genomförda, påbörjade och registrerade per kurs och användare, med domänfilter och paginering
- Varning vid kursradering när kursen ingår i en eller flera lärvägar

### Ändrat
- Kursernas synlighetsfilter finns nu även som återanvändbar helper `buildCourseVisibilityClause()` i `include/functions.php`
- Ny batchad progressberäkning `getCourseProgressForUsers()` för M användare × N kurser på två queries

### Prestanda
- Nya index på `progress(user_id, lesson_id)` och `progress(lesson_id)` (tabellen saknade index utöver primärnyckeln)

## [2.0.0] – 2026-04-28

Stort funktionssläpp. Stimma har vuxit från en enkel mikroutbildningsplattform till en fullfjädrad LMS med fokus på svensk kommunal sektor — flexibel kursutrullning, fler kursskapande-vägar, utökad frågetypsuppsättning och säkerhetshärdning.

### Lagts till

#### Kursskapande
- PowerPoint-import: importera PPTX-filer och låt AI utveckla texten till en Stimma-kurs
- AI-genererad enstaka lektion till befintlig kurs
- Fri idé som startpunkt för AI-kursgenerering (innan: bara titel/beskrivning)
- Höjda gränser: råidé 4 000 → 15 000 tecken, lektionsbeskrivning 2 000 → 10 000 tecken
- Versionshantering för AI-promptar
- AI-stöd för 9 nya frågetyper

#### Kursutrullning och åtkomst
- Stegvisa kurser med e-postmallar, kö, autostart och logg
- Manuell inskrivning även i bulk_start-kurser
- Domänbaserad kursdelning: publik, hela organisationen eller specifika domäner
- Publika kurser med org-gruppering och kopieringsetikett
- Kursens landningssida med kopiera-länk-knapp i kurslistan
- Lektionsnavigeringsmeny till vänster om kursinnehållet
- Informationssidor i lektionsflödet
- Kursavslutssida (course_complete.php)
- Stegvis/dynamisk-badges i kurslistan
- Kopiera kurs-knapp direkt i kurslistan, inkl. completion_content

#### Quiz och frågor
- Per-lektion quiz-läge med live tally
- Per-fråga-bedömning via AJAX ("Svara"-knapp per fråga)
- Drag-and-drop-sortering av quiz-frågor
- Flera quizfrågor per lektion
- 9 nya frågetyper inkl. hotspot
- Hotspot-frågor: klickkoordinater, uppladdning utan reload, koordinatrutnät med axelmarkering
- Direktlänk till quizfrågor från lektionslistan

#### Innehåll och media
- ZIP-baserad kursexport/import med bilder
- Bildinfogning i lektionsredigeraren med inline-uppladdning
- Ljuduppladdning till lektioner (tillgänglighetsfunktion)
- Videouppladdning (upp till 100 MB) utöver YouTube

#### Användare och organisation
- Användarsynk via REST API mot HR-system
- Synkverktyg: admin på primärdomän kan synka alla organisationens domäner
- API-nycklar
- CSV-export av användare
- Anpassningsbar headertext per organisation/domän med platshållare
- Organisationsikon i top-nav
- Terminologi-byte till "Användare"

#### Kursredigering
- Flikbaserad layout i Redigera kurs
- Kompaktare två-kolumn-layout
- Innehåll-fliken: Titel/Beskrivning/Bild/Slutdatum på en rad
- Bootstrap-tooltips på info-ikoner
- Allmänt-flik med Kursredaktörer
- Förtydligad skillnad: aktiv inskrivning vs passiv synlighet

#### Diplom och PUB
- Diplom: filtrera på kursåtkomst, visa kurs-ID
- Sambruks kontrasignering av PUB-avtalsmall
- Klickbara PUB-avtal, sökfält för domäner, digital signering, diplom-namnbyte
- SMS-verifiering, PDF-stämpling och e-postbilaga för PUB-avtal

#### Dashboard och UI
- Omskriven, kompaktare dashboard med vit/neutral header
- Scrollbar admin-sidebar
- Sortering av kurslistan på ID
- Tydlig info om max bildstorlek och tillåtna format

#### Drift
- Ofelia-scheduler som docker-native cron
- Cron-admin
- Databaslösenord flyttat från docker-compose.yml till .env

#### Tillgänglighet
- Tillgänglighetsredogörelse enligt DOS-lagen och WCAG 2.1 AA

### Ändrat
- Kompaktare och tydligare layouter genomgående
- Quiz-sidans tillbaka-länk går nu till lektionslistan
- AI-genererings-prompt förstärkt mot upprepningar; deduplicering av quiz-svar

### Säkerhet
- Åtgärdade kritiska och höga säkerhetsbrister (fas 1 + fas 2: HIGH-001, HIGH-003, HIGH-009)
- Åtgärdade HIGH- och MEDIUM-fynd från beroendegranskningen
- Sanering av completion_content vid rendering (audit-fynd H-2)
- CSRF-skydd och korrigerad include-ordning i sync_users_direct.php
- Robustare CSRF-validering för upload_image.php

### Fixat
- Inskrivna-räkning
- Drag-and-drop-ordning för quiz-frågor
- Quiz-svar-dubblering vid edit roundtrip
- Diverse UI-buggar i edit_quiz.php
- 404 efter sparad fråga (admin/-prefix saknades i redirect)
- Osynliga flikrubriker i Redigera kurs
- Odefinierad `$currentUserDomain` i edit_course.php
- Hotspot-formuläret: step=any för X/Y/radie

## [1.1.0]

Tidigare större version. Lade till gamification, diplom, dashboard, multi-AI-leverantörsstöd, förhandsvisning och PUB-dokumentation.

## [1.0.0]

Initial version: AI-genererade kurser, AI-bildgenerering, kurs-/lektionshantering, quiz, AI-tutor, tagghantering, rollbaserad åtkomst och organisationsbaserad separation via e-postdomän.
