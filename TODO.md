# Stimma - Utvecklingsuppgifter

## Pågående
_Inga pågående uppgifter_

## Framtida förbättringar
- [ ] Lägg till förhandsvisning av e-postmall
- [ ] Statistik per e-postkampanj

## Slutfört
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
