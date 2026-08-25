# Stimma - Utvecklingsuppgifter

## Pågående
- [ ] 🔴 Personuppgifter i det PUBLIKA GitHub-repot — historiken rensad 2026-08-21, EXPONERINGEN KVARSTÅR
  - [x] `import_users.sql`, `allowed_domains.txt` och `docs/pdf/*.pdf` rensade ur hela historiken med
        git filter-repo och force-pushade (132 commits bevarade, .git 97 MB → 11 MB, tagg v2.0.0 bevarad).
        Säkerhetskopia före rewrite: /opt/app/stimma-git-backup-20260821/ (bundle + filerna)
  - [ ] **Gamla objekt går fortfarande att hämta anonymt på GitHub via commit-SHA** — verifierat HTTP 200 på
        raw.githubusercontent.com för både PUB-avtalet (ae362aa) och import_users.sql (689db8f).
        Bara GitHub Support kan städa bort dem: begär "purge unreachable objects" för Sambruk/stimma
  - [ ] **Två forkar har egna kopior**: joakimbergros/stimma och ereffner/stimma. Be ägarna radera dem —
        annars lever datan kvar i fork-nätverket oavsett vad Support gör med huvudrepot
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
- [ ] Uppföljning: migrera index.php:214-276 till buildCourseVisibilityClause() (kräver före/efter-regressionstest av kurskatalogen för huvuddomänadmin, sub-domänanvändare med org-taggar och public_only)
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
- [ ] statistics.php listar ALLA kurser i hela systemet för admin, inte bara den egna
      organisationens. Pre-existerande; läsbehörig fick en domänavgränsad gren i stället.

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
