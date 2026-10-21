# Database Audit Report

Date: 2026-09-29
Scope: `masterexam-pro/api` Laravel application and `mobileapptcc_frontend`
Audit posture: No tables were deleted or altered.

## Executive conclusion

No table can be proven safe to remove from the evidence available in this workspace. The configured MySQL server refused the connection during the live inventory check, so production row counts, table sizes, triggers, views, and the deployed schema cannot be certified. The application and migration scan shows that every table in the current migration lineage is either actively used, required by Laravel/infrastructure, or cannot be proven unused.

The most likely source-only legacy artifact is `examination_violations`: migration `2026_09_23_190000_remove_violation_fields_and_tables.php` explicitly removes it, while older SQL exports still recreate it. This is schema/export drift, not a safe deletion candidate. `test_items` looks like scaffold/demo functionality, but it has a live API route, controller, policy, seeder, factory, and feature tests; it must remain until that feature is intentionally retired and its references are removed.

## 1. Database summary

### Intended current tables from migrations

`migrations`, `roles`, `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`, `test_items`, `applicants`, `examination_schedules`, `examination_rooms`, `examination_registrations`, `activity_logs`, `question_banks`, `exam_subjects`, `exam_questions`, `exam_sessions`, `exam_session_joins`, `result_email_batches`, `examination_answers`, `examination_passkey_email_logs`, `course_grading_settings`, and `examination_settings`.

`examination_violations` is not part of the intended current schema after the September 23 migration. It remains present in older exports and therefore requires deployment-drift verification.

### Live inventory status

| Check | Result |
|---|---|
| Laravel runtime | Laravel 13.16.1, PHP 8.5.8 |
| Configured DB | MySQL settings are configured in `api/.env` |
| Live connection | BLOCKED: MySQL returned `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it` |
| Row counts | Not available; do not infer from dump files |
| Table sizes | Not available; do not infer from dump files |
| Indexes and constraints | Verified from migrations and SQL artifacts only; deployed state still requires `SHOW CREATE TABLE` |
| Triggers and views | Not verified; require live MySQL access |
| Applied migrations | Not verified; `migrations` table could not be queried |

The SQL exports contain sample rows, not authoritative production counts. Their row counts must not be used for deletion decisions.

## 2. Dependency map

```text
roles
  -> users
      -> examination_schedules
          -> examination_rooms
              -> exam_sessions
                  -> exam_session_joins
                  -> examination_registrations
                      -> examination_answers -> exam_questions -> exam_subjects -> question_banks
                      -> examination_passkey_email_logs
                      -> result_email_batches (via result_email_batch_id)
      -> exam_questions (created_by)
      -> result_email_batches (requested_by)
      -> activity_logs
      -> test_items

applicants
  -> examination_registrations

Laravel infrastructure
  migrations                migration ledger
  sessions                  database session driver when configured
  cache, cache_locks        database cache driver when configured
  jobs, job_batches         database queue and batch storage
  failed_jobs               failed queue storage
  password_reset_tokens     password reset broker storage
  personal_access_tokens    Sanctum token storage

Configuration/domain roots
  course_grading_settings   grading configuration used by grading services/controllers
  examination_settings      exam behavior and exam-day pack configuration
```

Foreign-key relationships found in migrations include roles -> users; users -> schedules, rooms, questions, result batches, logs, and test items; schedules -> rooms, sessions, and registrations; rooms -> sessions; question banks -> subjects -> questions; applicants -> registrations; sessions -> registrations and session joins; registrations -> answers, passkey logs, and result batches.

## 3. Table usage analysis

Status meanings: ACTIVE means current application code executes against the table. SYSTEM REQUIRED means Laravel/Sanctum/queue infrastructure depends on it. FUTURE/UNKNOWN means proof is incomplete and the table is retained. LEGACY means source artifacts indicate it is retired, but deployment state is unknown.

| Table | Purpose | Referenced in | Dependencies | Status | Recommendation and reason |
|---|---|---|---|---|---|
| `migrations` | Laravel migration ledger | Laravel migration runner; all migrations | None | SYSTEM REQUIRED | KEEP. Removing it breaks migration state management. |
| `roles` | User role definitions | `app/Models/Role.php`, `app/Models/User.php`, `app/Http/Controllers/RoleController.php`, role middleware | Parent of `users` | ACTIVE | KEEP. Authentication and authorization depend on it. |
| `users` | Admin/proctor accounts and profile/auth data | `app/Models/User.php`, auth controllers, proctor controllers, policies, sync pack service | `roles`; parent of many domain tables | ACTIVE | KEEP. Core security and workflow table. |
| `password_reset_tokens` | Password reset broker storage | `config/auth.php`, `AuthController.php`, reset routes | Laravel auth | SYSTEM REQUIRED | KEEP. Reset endpoints depend on it. |
| `sessions` | Database-backed web sessions | `config/session.php` and Laravel session driver | Optional runtime infrastructure | SYSTEM REQUIRED/UNKNOWN | KEEP until deployed session driver is verified. |
| `cache` | Database cache entries | `config/cache.php` and Laravel cache | Paired with `cache_locks` | SYSTEM REQUIRED/UNKNOWN | KEEP until deployed cache driver is verified. |
| `cache_locks` | Atomic database cache locks | Laravel database cache driver | `cache` | SYSTEM REQUIRED/UNKNOWN | KEEP. Removing it can break locks when database cache is enabled. |
| `jobs` | Queued job payloads | `config/queue.php`, `SendExaminationResultEmailJob` | Queue worker | SYSTEM REQUIRED/ACTIVE | KEEP. Result email jobs are dispatched. |
| `job_batches` | Queue batch metadata | Laravel batch infrastructure and result-email batching | Queue subsystem | SYSTEM REQUIRED/ACTIVE | KEEP. Batch progress uses queued batches. |
| `failed_jobs` | Failed queue records | Laravel queue commands/configuration | Queue subsystem | SYSTEM REQUIRED | KEEP. Operational recovery depends on it. |
| `personal_access_tokens` | Sanctum bearer tokens | `config/sanctum.php`, auth/proctor login/logout, `auth:sanctum` routes | `users` polymorphically | ACTIVE/SYSTEM REQUIRED | KEEP. API authentication depends on it. |
| `test_items` | Scaffold/demo resource owned by a user | `routes/api.php`, `TestItemController.php`, `TestItem.php`, `TestItemPolicy.php`, `AppServiceProvider.php`, `UserSeeder.php`, `TestItemFactory.php`, `tests/Feature/TestItemTest.php` | `users` | ACTIVE or TEST | KEEP. It is exposed by a live API resource and seeded/tested; not safe to classify as unused. |
| `applicants` | Applicant/examinee identity and registration source | Applicant model/service/controllers, public exam flow, sync service, frontend API calls | Parent of registrations | ACTIVE | KEEP. Core applicant workflow. |
| `examination_schedules` | Exam dates, batches, venues, proctors | Schedule model/controllers/services/routes/frontend | `users`; parent of rooms, sessions, registrations | ACTIVE | KEEP. Core scheduling workflow. |
| `examination_rooms` | Room capacity and proctor assignment | Room model/controller, session service/routes/frontend | `examination_schedules`, `users`; parent of sessions | ACTIVE | KEEP. Proctor and room workflow uses it. |
| `examination_registrations` | Applicant enrollment, attendance, scores, sync/email state | Registration model/controllers/services, public exam flow, results, sync, frontend | schedules, applicants, sessions, users, result batches; parent of answers/logs | ACTIVE | KEEP. Central exam/result table. |
| `activity_logs` | Audit trail for administrative actions | `ActivityLog.php`, `ExaminationRegistrationController.php`, `ExaminationResultService.php`, dashboard/report paths | `users` | ACTIVE | KEEP. Audit/security requirement. |
| `question_banks` | Versioned exam question packs | QuestionBank model/controller, session service, pack sync, frontend | Parent of subjects and sessions | ACTIVE | KEEP. Explicitly protected exam data. |
| `exam_subjects` | Subject/category hierarchy within packs | ExamSubject model, question-bank controller, analytics, frontend | `question_banks`; parent of questions | ACTIVE | KEEP. Question selection and analytics depend on it. |
| `exam_questions` | Exam question content and answers | ExamQuestion model, question controllers/services, public exam, analytics, answers | `exam_subjects`, `users`; parent of answers | ACTIVE | KEEP. Core exam workflow. |
| `exam_sessions` | Proctor LAN exam session, code, network, status | ExamSession model/controllers/services/command, public exam and sync flows | schedules, question banks, users, rooms; parent of joins/registrations | ACTIVE | KEEP. Core proctor and offline workflow. |
| `exam_session_joins` | Prevents duplicate student/device joins | `ExamSessionJoin.php`, `ExamSessionController.php`, unique-session migration | `exam_sessions` | ACTIVE | KEEP. Security/integrity control. |
| `result_email_batches` | Batch result-email progress and ownership | ResultEmailBatch model, ResultController, ExaminationResultService, email job/frontend | `users`; referenced by registrations | ACTIVE | KEEP. Result reporting/email workflow depends on it. |
| `examination_answers` | Per-registration answers and correctness | ExaminationAnswer model, result service/controller, analytics, public exam | registrations, questions | ACTIVE | KEEP. Exam/result data. |
| `examination_passkey_email_logs` | Passkey delivery attempts and audit | model, ExaminationPasskeyService/controller/mail flow | registrations, users | ACTIVE | KEEP. Authentication and exam-entry audit path. |
| `course_grading_settings` | Course-specific passing/grading rules | model/controller, GradingService, result analytics, frontend | None | ACTIVE | KEEP. Score/result calculation depends on it. |
| `examination_settings` | Exam duration, shuffle, reconnect and room settings | model/controller, public exam, exam-day pack, settings routes/frontend | None | ACTIVE | KEEP. Runtime exam behavior depends on it. |
| `examination_violations` | Historical violation records | Old model/migration/export references; current removal migration | Historically registrations/sessions | LEGACY/UNKNOWN DEPLOYMENT STATE | INVESTIGATE FURTHER. Current migration intentionally drops it, but live state and old exports must be checked before any cleanup. |

## 4. Safe-to-remove tables

None proven safe to remove.

No table satisfies all required conditions simultaneously. In particular, the live schema, applied migration list, row counts, triggers, views, and production scheduled jobs were not available because the MySQL endpoint refused the connection. An empty table would still not be safe to remove if its API, FK, migration, queue, or future workflow dependency remains.

## 5. Tables to keep

Keep every intended current table listed in the table analysis, including `test_items` until its API and seed/test references are deliberately retired. Keep all applicant, exam, question, schedule, result, user, role, permission/auth, proctor, sync/offline, audit, settings, cache, session, and queue tables.

The only source-level cleanup identified is export alignment: older SQL exports contain `examination_violations` and omit `exam_session_joins`, while the migration lineage does the opposite. Do not resolve that mismatch by dropping a live table; regenerate exports after connecting to the deployed database and confirming applied migrations.

## 6. Risk assessment

- CRITICAL: Deleting any core table can destroy applicants, registrations, answers, scores, auth, or exam configuration.
- HIGH: Deleting `jobs`, `job_batches`, or `failed_jobs` can lose queued result emails and operational recovery data.
- HIGH: Deleting `sessions`, `cache`, or `cache_locks` without checking deployed drivers can break authentication/session state or distributed locks.
- HIGH: Deleting `personal_access_tokens` invalidates API authentication.
- MEDIUM: Deleting `test_items` requires coordinated removal of route, controller, model, policy, provider registration, seeder, factory, and tests.
- MEDIUM: The stale violation-table export can cause accidental recreation of retired functionality during deployment.
- UNKNOWN: Triggers, views, external reports, scheduled jobs, and integrations could not be checked without the live database and deployment metadata.

## 7. Backup commands

Run only after connecting to the production MySQL server and confirming the target database. Replace placeholders; do not print credentials into shell history.

```sql
-- Logical backup with routines, events, and triggers.
mysqldump --single-transaction --routines --events --triggers --hex-blob \
  -h <host> -P <port> -u <user> -p <database> \
  > masterexam_pre_cleanup_YYYYMMDD_HHMMSS.sql

-- Schema and dependency evidence.
SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SHOW TRIGGERS;
SHOW FULL TABLES WHERE Table_type = 'VIEW';
```

## 8. Rollback commands

No destructive migration was created, so no rollback is required. If a future approved cleanup is performed, restore the full logical backup rather than attempting to reconstruct production data manually:

```bash
mysql --binary-mode=1 -h <host> -P <port> -u <user> -p <database> \
  < masterexam_pre_cleanup_YYYYMMDD_HHMMSS.sql
```

For a future table-specific change, require a reviewed `CREATE TABLE`, index, FK, trigger, and data-restore script before execution. The companion rollback template is intentionally non-destructive until a candidate is approved.

## 9. SQL removal scripts

`database/audits/SAFE_REMOVE_NONE_2026-09-29.sql` intentionally aborts. It contains no `DROP TABLE` statement. This is the correct result of the audit while live deployment evidence is unavailable.

## 10. Post-cleanup verification checklist

- [ ] Connect to the deployed MySQL server successfully.
- [ ] Capture `SHOW CREATE TABLE` for every table and all `SHOW TRIGGERS`/views.
- [ ] Record exact `COUNT(*)`, `DATA_LENGTH`, and `INDEX_LENGTH` for every table.
- [ ] Compare the live `migrations` table to the repository migration files.
- [ ] Scan backend and mobile code again for table names, raw SQL, relationships, jobs, reports, and sync paths.
- [ ] Check external report/analytics/scheduled-job definitions.
- [ ] Take and test a restorable full backup.
- [ ] Obtain explicit approval for any candidate.
- [ ] Apply only a reviewed migration in a staging clone first.
- [ ] Run `php artisan migrate:status`, the full Laravel test suite, route listing, and endpoint smoke tests.
- [ ] Exercise applicant registration, scheduling, proctor session, question delivery, answer submission, result calculation, result email queue, and LAN sync workflows.
- [ ] Re-run row counts, FK checks, and application logs after deployment.
