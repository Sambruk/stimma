# Behörighet per admin-sida och ajax-anrop (läge 2026-10-13)

Centrala funktioner i `include/functions.php`:

| Funktion | Regel |
|---|---|
| `canEditCourse($id)` | Superadmin; admin i den organisation som äger kursen; tilldelad redaktör (`course_editors`, även över org-gränsen); kursens författare inom egen org |
| `canEditLesson($id)` | `canEditCourse` för den kurs lektionen ligger i |
| `canViewCourseAdmin($id)` | `canEditCourse`, eller admin/redaktör/läsbehörig i kursens organisation (effektivt scope) |
| `statsCanViewCourse($id)` | Superadmin; admin/läsbehörig: kurser i organisationens statistikscope; redaktör: kurser hen får ändra, inte som extern redaktör |
| `statsVisibleUserClause()` | Identitet i statistik: användare i aktivt domänscope + publika deltagare utan organisation. Andra organisationer: bara antal |
| `denyCourseAccess()` | Nekar med samma svar som för id som inte finns |

Grindar: `admin/include/auth_check.php` (admin, redaktör, läsbehörig, kursredaktör till vitlistade sidor) och `admin/include/ajax_auth_check.php` (admin, redaktör, kursredaktör till vitlistade anrop).

## Kurser, lektioner, quiz

| Fil | Id-parametrar | Kontroll | Ändrad 2026-10-13 |
|---|---|---|---|
| edit_course.php | GET/POST `id` | `userCanModifyCourse` + `isExternalCourseEditor` för publicering/taggar/delning | – |
| edit_lesson.php | GET `id`, `course_id`; POST `course_id`, `belongs_to_lesson_id` | `canEditLesson` vid öppning; `canEditCourse` på nuvarande och mål-kurs vid POST, före filradering; infosidans förälder måste ligga i samma kurs | ja |
| lessons.php | GET `course_id` | `canViewCourseAdmin` | ja |
| edit_quiz.php | GET `lesson_id`, `edit_id`; POST `question_id` | org-scope eller tilldelad redaktör; fråga bunden till lektionen | – |
| delete_course.php | POST `id` | `userCanModifyCourse`; extern redaktör nekas | – |
| delete_lesson.php | POST `id` | `userCanModifyCourse` på lektionens kurs | – |
| delete_module.php | – | **borttagen** (tabellen `modules` finns inte) | ja |
| update_course_order.php | POST `courses[].id` | `userCanModifyCourse` per kurs | – |
| update_lesson_order.php | POST `course_id`, `lessons[].id` | `userCanModifyCourse` + `AND course_id = ?` | – |
| export.php | GET `id` | `canEditCourse`; extern redaktör nekas | ja |
| copy_course.php | POST `course_id` | admin/redaktör; aktiva kurser från alla org, inaktiva bara egen org (**beslutspunkt**) | – |
| certificates.php | POST `course_id`; GET `preview` | `canEditCourse` för diplombild, `canViewCourseAdmin` för förhandsvisning; filändelse från MIME-typ | ja |
| upload_image/audio/video.php | – | ajax-grind | – |

## Ajax

| Fil | Id-parametrar | Kontroll | Ändrad |
|---|---|---|---|
| add_course_editor.php, remove_course_editor.php | POST `course_id` | `userCanModifyCourse` + `isExternalCourseEditor` | – |
| toggle_public_course.php, regenerate_public_token.php, delete_public_participants.php | POST `course_id` | admin/redaktör + org-scope | – |
| enroll_user_sequential.php, trigger_sequential_start.php, send_manual_sequential_reminder.php, send_sequential_test_email.php | POST `course_id` | `canEditCourse` och inte extern redaktör | ja |
| ai_generate_lesson.php, generate_course_image.php, generate_lesson_image.php, plan_course_images.php | POST `course_id`/`lesson_id` | `userCanModifyCourse` / org-scope | – |
| update_info_page_owner.php | POST `lesson_id` | `userCanModifyCourse` eller kursens författare | – |
| update_learning_path_order.php | POST `paths[].id` | `userCanModifyLearningPath` | – |
| ai_generate_course.php | `job_id` | `AND user_id = ?` | – |
| search_users.php | GET `search`, `lage` | admin/redaktör/kursredaktör; extern exakt träff utan namn | ja |
| trigger_ai_processor.php | – | admin/redaktör + CSRF | ja |
| run_cron_job.php | POST `job_id` | bara superadmin; skriptrot rättad | ja |
| import_pptx.php, import.php | – | admin/redaktör; ny kurs i egen domän | – |
| import_scorm.php, test_ai_connection.php | – | superadmin | – |
| set_viewer_batch.php, sync_users_direct.php | `entries[].email` | admin på primärdomän; domän mot org | – |
| dismiss_announcement.php, send_test_reminder.php | – | admin/redaktör, per egen användare | – |

## Statistik och användare

| Fil | Id-parametrar | Kontroll | Ändrad |
|---|---|---|---|
| index.php (dashboard) | – | org-scope; per-kurs-siffror är antal (inkl. andra org:s deltagare) | – |
| statistics.php | GET `course_id` | `statsCanViewCourse`; användare via aktivt scope för alla roller | ja |
| course_stats.php | GET `course_id` | `statsCanViewCourse`; identitet via `statsVisibleUserClause`; andra org som antal | ja |
| export_statistics.php | GET `course_id` | `statsCanViewCourse`; användare via aktivt scope för alla roller | ja |
| public_participants.php | GET `course_id` | `statsCanViewCourse`; andra org som antal | ja |
| certificates.php (senaste diplom) | – | e-post bara för eget scope + deltagare utan org | ja |
| users.php, export_users.php | POST `user_id` m.fl. | effektivt scope; CSRF för alla POST (förr saknades för rollväxlingar) | ja |
| learning_paths.php, edit_learning_path.php, learning_path_stats.php | `path_id`/`id` | `userCanModifyLearningPath` | – |
| tags.php | POST `tag_id` | effektivt scope | – |
| impersonate.php, stop_impersonate.php | POST `user_id` | superadmin | – |
| api_keys.php | POST `key_id` | egen domän om inte superadmin | – |
| ai_usage.php, order_tokens.php, sync_logs.php, reminders.php, organization_icon.php | – | egen org/domän | – |
| logs.php, cron_jobs.php | – | bara superadmin (förr varje org-admin via adressen) | ja |
| ai_quotas, ai_settings, announcements, domains, maintenance, organizations, lesson_view, token_orders, pub_documents (POST) | – | superadmin | – |

Testas av `tests/test_770.php` och `tests/test_781.php` (kör `tests/run.sh`).
