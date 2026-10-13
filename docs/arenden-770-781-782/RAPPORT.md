# Stimma – ärende 770, 781 och 782

*Rapport till Thomas Wennersten, 13 oktober 2026. Underlag: Magnus Nilsson (AIPL), 13 oktober 2026.*

Ändringarna är gjorda, testade och committade (1723cbe, 8074ca0, 48a8072). Koden är i drift: produktionen kör direkt från repots katalog. Två migreringar och två beslut väntar på dig, se slutet.

**Testning.** Testerna körs i en ny, fristående testmiljö i `tests/`. Den har en egen databas, som skapas tom vid varje start, och rör aldrig produktionsdatabasen. Testdatan består av två organisationer (orga.test och orgb.test) och Sambruk. Varje organisation har en admin, en redaktör, en vanlig användare och en läsbehörig, och kurserna omfattar vanliga, publika och kopierade kurser, lektioner med och utan quiz, och deltagare över organisationsgränsen.

Kör med `tests/run.sh`. Utfall: 178 tester, alla godkända.

---

## 770 – Man kan redigera andras kurser genom att byta kurs-id

**Orsak.** Flera admin-sidor kontrollerade att användaren var administratör, men inte vilken organisation kursen tillhör. Några sidor saknade kontroll helt.

- **Lektioner:** redigeringsläget visade vilken lektion som helst, även för läsbehöriga, och lektionslistan visade vilken kurs som helst.
- **Export, diplombild och stegvisa utskick:** varje administratör kom åt alla organisationers kurser. I testet startade admin A org B:s stegvisa kurs, och fyra mejl till B:s användare köades.
- **Logg och cronjobb:** org-admins nådde aktivitetsloggen och de globala cronjobben via adressen.
- **Användarsökning:** det räckte att vara inloggad.
- **Rollväxling:** att göra någon till admin eller redaktör saknade skydd mot förfalskade anrop (CSRF).

**Ändring.**
- Nya centrala funktioner i `include/functions.php`: `canEditCourse`, `canEditLesson`, `canViewCourseAdmin` och `denyCourseAccess`. De används vid både GET och POST.
- När behörighet saknas nekas användaren med samma svar som när kursen inte finns.
- Ändrade filer: `edit_lesson.php`, `lessons.php`, `export.php`, `certificates.php`, de fyra stegvis-anropen, `logs.php`, `cron_jobs.php`, `run_cron_job.php`, `search_users.php`, `trigger_ai_processor.php` och `users.php`. `delete_module.php` är borttagen.
- Alla admin-sidor och anrop med respektive kontroll finns i `BEHORIGHETER.md`.

**Test.** `tests/test_770.php`: 20 fel före ändringen, 74 av 74 godkända efter. Testerna visar att det som ska fungera fortfarande fungerar: egna kurser, extern kursredaktör och superadmin.

**Hittat på vägen.** "Kör nu" för cronjobb har inte fungerat sedan webbroten flyttades 17 augusti, eftersom skriptet letade under `public/`. Det är rättat.

---

## 781 – Statistikmenyn visar mer än den egna organisationen

**Orsak.**
- Redaktörsvyerna i statistiken och statistikexporten saknade filter på användarens organisation. De visade namn, e-post och framsteg för alla deltagare i alla organisationer.
- Kursstatistiken och statistikexporten hoppade över kurs-id-kontrollen för administratörer.
- "Publika deltagare" och "Senaste diplom" visade andra organisationers användare med namn och e-post.

**Ändring.** Enligt ditt beslut 13 oktober visas andra organisationers deltagare bara som antal.
- Nya funktioner: `statsCanViewCourse`, `statsVisibleUserClause` och `statsHiddenParticipantCounts`.
- Identitet visas bara för användare i det egna domänscopet, och för publika deltagare som inte tillhör någon organisation (till exempel gmail-konton).
- Övriga deltagare redovisas som "N deltagare från andra organisationer, varav X klara".
- En extern kursredaktör ser ingen statistik. Superadmin ser allt.
- Ändrade filer: `course_stats.php`, `statistics.php`, `export_statistics.php`, `public_participants.php` och `certificates.php`.
- Allt går via `getStatsDomainScope`. En framtida sektorsadmin, som bara ser sina organisatoriska enheter, kan därför läggas till på ett enda ställe.

**Test.** `tests/test_781.php`: 14 fel före ändringen, 74 av 74 godkända efter. Testet täcker alla statistikvyer och exporter för admin, redaktör, läsbehörig och användare.

**Medvetet oförändrat.** Dashboardens siffror per kurs räknar med andra organisationers deltagare. Den visar bara antal och inga identiteter, vilket stämmer med beslutet.

---

## 782 – Framsteg verkar inte registreras

**Orsak.** Den viktigaste först:

1. **Lektioner utan quiz kunde aldrig bli klara.** Följden var att kursen aldrig blev klar och att senare lektioner låstes. I produktionen gäller det 18 aktiva lektioner, och 6 aktiva kurser saknar quiz helt.
2. **Sparandet nekades tyst** om någon tidigare sida i kursen inte var klar. Även inaktiva lektioner och infosidor räknades, trots att spärren när en lektion öppnas hoppar över dem. Den klassiska vyn visade ändå "Alla rätt!".
3. **Quizläget hölls i sessionen.** Om frågorna besvarades i olika inloggningar blev lektionen aldrig klar.
4. **Kursavslutet kontrollerades bara på kursens sista sida.** `course_enrollments` fick aldrig status "klar".
5. **API:t läste fel tabell.** Det läste `user_progress`, som aldrig skrevs, så framstegen var alltid 0.
6. **Kurs-, statistik-, start- och lärvägsvyerna räknade olika lektioner.**

**Ändring.** Ny fil `include/progress.php` med en regel för hela appen:

- En lektion är klar när quizet är klarat. Det läses ur de sparade svaren, inte ur sessionen.
- En lektion utan quiz är klar när den öppnas.
- En kurs är klar när alla aktiva lektioner är klara. Infosidor krävs inte.
- Ordningen i kursen styr bara vad som går att öppna, inte vad som sparas.
- Kursavslutet kontrolleras efter varje framsteg. Det sätter inskrivningen till klar och utfärdar diplom om kriterierna för x % rätt är uppfyllda.

Regeln används i kursavslutet, kursstatistiken, statistiken, startsidan, lärvägarna och API:t.

**Test.** `tests/test_782.php`: 14 fel före ändringen, 25 av 25 godkända efter. Testerna gick igenom både före och efter migreringen. De täcker:
- lektion med quiz
- lektion utan quiz
- flera frågor
- fel svar följt av rätt svar
- ny session
- inaktiv lektion
- infosida först och sist
- lektion som läggs till
- lektion som flyttas
- stegvis kurs
- kurs klar med diplom
- radering

**Migreringar.** De körs inte automatiskt, och standardläget är en torrkörning som inte ändrar något. Båda kan köras om.
- `migrations/050_progress_single_table.php [--apply]`: ger `progress` ett unikt index och avvecklar `user_progress`. I produktionen finns inga dubbletter och `user_progress` är tom, så ingen data flyttas.
- `migrations/backfill_course_completion.php [--apply]`: rättar status för deltagare som redan har gått klart en kurs.

**Antal i produktionen.** Frågan finns i `782_antal_berorda.sql` och visar bara antal.
- 311 deltagare har gjort alla lektioner i en kurs. Ingen av dem har status "klar" på inskrivningen, och 13 saknar diplom. Inget av de 13 beror på diplomkriteriet.
- Ingen deltagare står fast bakom en lektion utan quiz i en kurs som också har quiz.

---

## Att besluta

1. **Köra migreringarna i produktionen** (782). Föreslagen ordning:
   1. ta backup
   2. kör `050 --apply`
   3. kör `backfill_course_completion.php --apply`

   Det ger 13 nya diplom och 311 inskrivningar markerade som klara.
2. **Påminnelserna har aldrig skickats.** `cron/send_reminders.php` läser kolumnen `progress.created_at`, som inte finns. Databasfunktionen sväljer felet, så inga användare hittas. Påminnelser är påslagna för 15 domäner, och `reminder_log` är tom.
   - Rättningen finns färdig i `forslag_paminnelser.patch`, men den är inte aktiverad. Den skulle göra att Stimma börjar skicka påminnelser för första gången.
   - Kör backfill först, annars påminns deltagare som i själva verket är klara.
3. **Kopiering mellan organisationer** (770). Alla administratörer och redaktörer kan kopiera *alla aktiva* kurser från andra organisationer, även kurser som inte är publika. Det används: 41 kurser i produktionen är kopior, och ingen av de källor som gick att identifiera var publik.
   - Ska det vara så, eller ska bara publika och globala kurser och kurser delade med organisationen gå att kopiera?
4. **När en lektion utan quiz blir klar** (782). Nu räknas den som klar när den öppnas. Alternativet är när användaren nått sista sidan, vilket kräver ändringar i båda lektionsvyerna.

## Övrigt som noterades men inte ändrades

- `query()` i `include/database.php` returnerar en tom lista vid SQL-fel utan att logga. Det dolde påminnelsefelet och kan dölja fler fel. Felen bör loggas.
- `userCanModifyCourse` ger en admin på en underdomän rätt till kurser på hela organisationens domäner, medan listorna bara visar den egna underdomänen. Det gäller inom samma organisation, inte mellan organisationer.
- `api/course_status.php` röjer titeln på en kurs i en annan organisation om man anger dess id. Användardata är korrekt begränsad.
- Den som bara är kursredaktör får sin rätt till infosidan via författarundantaget i `update_info_page_owner.php`. Undantaget gäller bara inom samma organisation.
