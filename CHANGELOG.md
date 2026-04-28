# Changelog

Alla större ändringar i Stimma dokumenteras här.

Formatet följer [Keep a Changelog](https://keepachangelog.com/sv/1.1.0/) och projektet använder semantisk versionshantering.

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
