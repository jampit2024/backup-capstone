-- =============================================================================
-- baseline_migrations_hostinger.sql
-- Database: u881832908_exampro  (Hostinger MySQL)
--
-- PURPOSE
--   Fix: `php artisan migrate` fails with
--     SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists
--
--   Cause: this database was built by importing SQL dumps (NOT via artisan).
--   The physical tables exist, but Laravel's `migrations` ledger is empty, so
--   artisan tries to RE-CREATE tables that are already there.
--
--   This records the 44 migrations ALREADY reflected in the current schema
--   (matching IMPORT_THIS_IN_PHPMYADMIN.sql) into `migrations` WITHOUT running
--   them. After this, `php artisan migrate` only runs the new migrations (45-56).
--
-- SAFETY: idempotent (WHERE NOT EXISTS), non-destructive (no DROP/ALTER/data).
--
-- USAGE: run this in phpMyAdmin -> SQL tab (DB: u881832908_exampro), then run
--        `php artisan migrate`.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT t.`migration`, t.`batch` FROM (
    SELECT '0001_01_01_000000_create_users_table' AS `migration`, 1 AS `batch`
    UNION ALL SELECT '0001_01_01_000001_create_cache_table', 1
    UNION ALL SELECT '0001_01_01_000002_create_jobs_table', 1
    UNION ALL SELECT '2026_06_17_000001_create_roles_table', 1
    UNION ALL SELECT '2026_06_17_000002_add_role_id_to_users_table', 1
    UNION ALL SELECT '2026_06_17_000003_create_test_items_table', 1
    UNION ALL SELECT '2026_06_17_024514_create_personal_access_tokens_table', 1
    UNION ALL SELECT '2026_07_24_000001_add_preferences_to_users_table', 1
    UNION ALL SELECT '2026_07_24_010000_create_examination_domain_tables', 1
    UNION ALL SELECT '2026_07_24_012000_add_rooms_and_capacity_to_examinations', 1
    UNION ALL SELECT '2026_07_24_020000_add_reschedule_fields_to_examination_registrations', 1
    UNION ALL SELECT '2026_07_24_030000_create_subject_question_bank_tables', 1
    UNION ALL SELECT '2026_07_24_040000_add_reference_number_to_applicants', 1
    UNION ALL SELECT '2026_07_28_100000_restructure_question_bank_hierarchy', 1
    UNION ALL SELECT '2026_07_28_110000_add_selection_limit_to_exam_subjects', 1
    UNION ALL SELECT '2026_07_28_113055_add_application_profile_and_gmail_to_applicants_table', 1
    UNION ALL SELECT '2026_07_28_113057_add_email_delivery_fields_to_examination_registrations_table', 1
    UNION ALL SELECT '2026_07_28_113058_create_examination_answers_table', 1
    UNION ALL SELECT '2026_07_28_113100_create_result_email_batches_table', 1
    UNION ALL SELECT '2026_07_28_120000_add_is_active_to_question_banks', 1
    UNION ALL SELECT '2026_07_28_120500_add_import_fields_to_applicants_table', 1
    UNION ALL SELECT '2026_07_30_120000_add_email_sent_to_examination_registrations_table', 1
    UNION ALL SELECT '2026_07_30_150000_create_exam_sessions_table', 1
    UNION ALL SELECT '2026_07_30_164500_add_examination_room_id_to_exam_sessions_table', 1
    UNION ALL SELECT '2026_07_30_181000_create_course_grading_settings_table', 1
    UNION ALL SELECT '2026_07_30_201500_ensure_lobby_columns_on_examination_registrations', 1
    UNION ALL SELECT '2026_07_30_211000_create_examination_settings_table', 1
    UNION ALL SELECT '2026_07_31_120000_add_shuffle_flags_to_examination_settings', 1
    UNION ALL SELECT '2026_07_31_124800_add_items_score_to_examination_registrations', 1
    UNION ALL SELECT '2026_07_31_131500_add_result_sync_columns', 1
    UNION ALL SELECT '2026_08_03_160000_add_wifi_reconnect_columns_to_examination_registrations', 1
    UNION ALL SELECT '2026_08_05_100000_add_exam_passkey_to_examination_registrations', 1
    UNION ALL SELECT '2026_08_05_100100_create_examination_violations_table', 1
    UNION ALL SELECT '2026_08_05_110000_drop_unused_applicant_profile_columns', 1
    UNION ALL SELECT '2026_08_05_120000_allow_shared_applicant_emails', 1
    UNION ALL SELECT '2026_08_19_001304_add_network_fields_to_exam_sessions_table', 1
    UNION ALL SELECT '2026_08_28_000001_make_question_bank_id_nullable_on_exam_sessions', 1
    UNION ALL SELECT '2026_09_16_000001_add_wifi_bssid_to_exam_sessions_table', 1
    UNION ALL SELECT '2026_09_18_140000_add_violation_limit_to_examination_settings', 2
    UNION ALL SELECT '2026_09_18_220000_add_room_student_limit_to_examination_settings', 3
    UNION ALL SELECT '2026_09_19_092720_add_agreed_at_to_examination_registrations_table', 3
    UNION ALL SELECT '2026_09_19_120000_add_disconnect_grace_seconds_to_examination_settings', 3
    UNION ALL SELECT '2026_09_19_220000_add_unique_session_join_system_to_exam_sessions', 3
    UNION ALL SELECT '2026_09_21_140000_add_tab_switch_grace_seconds_to_examination_settings', 3
) AS t
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` m WHERE m.`migration` = t.`migration`
);

-- =============================================================================
-- VERIFICATION
-- =============================================================================
-- Expected after this script: 44 rows.
-- The 12 migrations below are NOT inserted here; `php artisan migrate` runs them.
--   2026_09_23_190000_remove_violation_fields_and_tables
--   2026_09_30_120000_rename_exam_domain_tables
--   2026_09_30_130000_normalize_exam_question_choices
--   2026_09_30_140000_enforce_active_session_code_uniqueness
--   2026_09_30_150000_standardize_exam_domain_indexes
--   2026_10_01_000000_add_academic_year_to_exam_schedules_and_applicant_name_index
--   2026_10_02_000001_secure_examination_passkeys
--   2026_10_02_000002_add_encryption_and_last4_to_exam_registrations
--   2026_10_03_000001_add_encrypted_passkey_to_exam_registrations
--   2026_10_03_160000_enforce_active_exam_code_uniqueness
--   2026_10_03_170000_add_pending_device_id_to_exam_registrations
--   2026_10_06_120000_correct_passkey_expiries_to_manila_time

SELECT COUNT(*) AS baselined_migrations FROM `migrations`;
