# Stimma - Utvecklingsuppgifter

## Pågående
_Inga pågående uppgifter_

## Framtida förbättringar
- [ ] Lägg till förhandsvisning av e-postmall
- [ ] Statistik per e-postkampanj

## Slutfört
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
