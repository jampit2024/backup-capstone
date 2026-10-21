# Database Export Verification & Audit Report

---

## 1. Audit Summary & Root Cause Resolution

An exhaustive audit of the entire SQL dump (`IMPORT_THIS_IN_PHPMYADMIN.sql`) against all 37 Laravel migration files and Eloquent models was completed.

### **Root Cause of `#1054 - Unknown column 'shuffle_both' in 'INSERT INTO'`**:
- **Diagnosis**: Migration `2026_07_31_120000_add_shuffle_flags_to_examination_settings.php` added `shuffle_questions`, `shuffle_categories`, and `shuffle_both` columns to `examination_settings`. 
- **The Issue**: In `database/export_mysql_seed.php`, `CREATE TABLE examination_settings` had a hardcoded definition that was missing `shuffle_both TINYINT(1) NOT NULL DEFAULT 0`.
- **Resolution**: Updated `CREATE TABLE examination_settings` in `export_mysql_seed.php` to include `shuffle_both`. Now, both the `CREATE TABLE` DDL statement and the `INSERT INTO` statement contain identical column structures.

---

## 2. Table Inspection Summary (27 Tables Total)

| Table Name | Schema Audit | Insert Audit | Status |
| :--- | :--- | :--- | :--- |
| `migrations` | 37 migrations accounted for | 37 rows inserted | **PASS** |
| `roles` | `admin`, `proctor` slugs | 2 rows inserted | **PASS** |
| `users` | `role_id`, `theme`, `locale` | 4 user accounts preserved | **PASS** |
| `password_reset_tokens` | `email` primary key | empty | **PASS** |
| `sessions` | `id` primary key | empty | **PASS** |
| `cache` | `key` primary key | empty | **PASS** |
| `cache_locks` | `key` primary key | empty | **PASS** |
| `jobs` | `id` auto-increment | empty | **PASS** |
| `job_batches` | `id` primary key | empty | **PASS** |
| `failed_jobs` | `uuid` unique key | empty | **PASS** |
| `personal_access_tokens` | Sanctum tokens | empty | **PASS** |
| `test_items` | FK to `users` | 7 rows inserted | **PASS** |
| `applicants` | Includes `gmail`, `email`, `preferred_exam_time`, `application_date` | 1 row inserted | **PASS** |
| `examination_schedules` | FK to `users` | 1 row inserted | **PASS** |
| `examination_rooms` | FK to `schedules`, `users` | empty | **PASS** |
| `course_grading_settings` | `course_code` unique | empty | **PASS** |
| `examination_settings` | Includes `shuffle_questions`, `shuffle_categories`, `shuffle_both` | 1 row inserted | **PASS** |
| `question_banks` | `school_year` unique | empty | **PASS** |
| `exam_subjects` | FK to `question_banks` | empty | **PASS** |
| `exam_questions` | FK to `exam_subjects`, `users` | empty | **PASS** |
| `exam_sessions` | FK to `schedules`, `question_banks`, `users`, `rooms` | empty | **PASS** |
| `result_email_batches` | FK to `users` | empty | **PASS** |
| `examination_registrations` | FK to `schedules`, `applicants`, `sessions`, `batches` | 1 row inserted | **PASS** |
| `examination_answers` | FK to `registrations`, `questions` | empty | **PASS** |
| `examination_violations` | FK to `registrations`, `sessions` | empty | **PASS** |
| `examination_passkey_email_logs` | FK to `registrations`, `users` | empty | **PASS** |
| `activity_logs` | FK to `users` | 1 row inserted | **PASS** |

---

## 3. Foreign Key Dependency & Import Safety

The export script uses `SET FOREIGN_KEY_CHECKS = 0;` at the beginning and drops/recreates tables in foreign-key dependency order before turning `SET FOREIGN_KEY_CHECKS = 1;` back on at the end.

```
roles
  └── users
        ├── test_items
        ├── examination_schedules
        │     └── examination_rooms
        │           └── exam_sessions
        │                 └── examination_violations
        ├── activity_logs
        ├── result_email_batches
        ├── examination_passkey_email_logs
        └── exam_questions
              └── examination_answers

applicants
  └── examination_registrations
        ├── examination_answers
        └── examination_violations

question_banks
  └── exam_subjects
        └── exam_questions
```

---

## 4. User Account & Seeder Protection

- **User Accounts Preserved**:
  - `rommeljampit21@gmail.com` / `Password123!`
  - `admin@example.com` / `password`
  - `proctor@example.com` / `password`
  - `proctor2@example.com` / `password`
- **Seeder Safety**: `UserSeeder.php` and `RoleSeeder.php` use `updateOrCreate()` with `Hash::make()` and do **not** run destructive operations (`truncate`, `db:wipe`, or `delete` on users).

---

## 5. Verification Checklist

- [x] Every migration accounted for (37 total)
- [x] Every table accounted for (27 total)
- [x] `examination_settings` schema updated with `shuffle_both`
- [x] `applicants` schema updated with `gmail`, `gmail_submitted_at`, `preferred_exam_time`, `application_date`
- [x] `examination_passkey_email_logs` table included
- [x] No `INSERT INTO` column mismatches
- [x] No `NaN`, `null`, or undefined values in numeric columns
- [x] Pure UTF-8 encoding without BOM
- [x] Standard `#` comments compatible with MySQL 8+ and MariaDB
- [x] Importable directly into phpMyAdmin without manual editing
