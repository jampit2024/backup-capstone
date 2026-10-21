<?php

/**
 * Export local SQLite (after migrate:fresh --seed) to pure UTF-8 MySQL SQL for Hostinger / phpMyAdmin.
 * Usage: php database/export_mysql_seed.php
 */

$sqlitePath = __DIR__.'/database.sqlite';
if (! file_exists($sqlitePath)) {
    fwrite(STDERR, "Missing database/database.sqlite. Run: php artisan migrate:fresh --seed --force\n");
    exit(1);
}

$pdo = new PDO('sqlite:'.$sqlitePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function q(string $value): string
{
    return "'".str_replace(["\\", "'"], ["\\\\", "''"], $value)."'";
}

function sqlValue(mixed $v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v) && ! preg_match('/^0\d+/', $v) && strlen($v) < 20)) {
        // Keep numeric-looking strings
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }

    return q((string) $v);
}

function exportTable(PDO $pdo, string $table, &$out): void
{
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return;
    }

    $columns = array_keys($rows[0]);
    $colList = '`'.implode('`, `', $columns).'`';

    foreach (array_chunk($rows, 50) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $vals[] = sqlValue($row[$col]);
            }
            $values[] = '('.implode(', ', $vals).')';
        }
        $out .= "INSERT INTO `{$table}` ({$colList}) VALUES\n".implode(",\n", $values).";\n\n";
    }
}

$out = <<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `examination_passkey_email_logs`;
DROP TABLE IF EXISTS `examination_violations`;
DROP TABLE IF EXISTS `examination_answers`;
DROP TABLE IF EXISTS `examination_registrations`;
DROP TABLE IF EXISTS `result_email_batches`;
DROP TABLE IF EXISTS `exam_sessions`;
DROP TABLE IF EXISTS `exam_questions`;
DROP TABLE IF EXISTS `exam_subjects`;
DROP TABLE IF EXISTS `question_banks`;
DROP TABLE IF EXISTS `examination_rooms`;
DROP TABLE IF EXISTS `examination_schedules`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `applicants`;
DROP TABLE IF EXISTS `course_grading_settings`;
DROP TABLE IF EXISTS `examination_settings`;
DROP TABLE IF EXISTS `test_items`;
DROP TABLE IF EXISTS `personal_access_tokens`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `migrations`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `password` VARCHAR(255) NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'active',
  `phone` VARCHAR(255) NULL,
  `address` TEXT NULL,
  `profile_photo_path` VARCHAR(255) NULL,
  `theme` VARCHAR(20) NOT NULL DEFAULT 'system',
  `locale` VARCHAR(10) NOT NULL DEFAULT 'en',
  `google_id` VARCHAR(255) NULL,
  `remember_token` VARCHAR(100) NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_google_id_unique` (`google_id`),
  KEY `users_role_id_foreign` (`role_id`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` VARCHAR(255) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `payload` LONGTEXT NOT NULL,
  `last_activity` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` VARCHAR(255) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  `expiration` BIGINT NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` VARCHAR(255) NOT NULL,
  `owner` VARCHAR(255) NOT NULL,
  `expiration` BIGINT NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED NULL,
  `available_at` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `total_jobs` INT NOT NULL,
  `pending_jobs` INT NOT NULL,
  `failed_jobs` INT NOT NULL,
  `failed_job_ids` LONGTEXT NOT NULL,
  `options` MEDIUMTEXT NULL,
  `cancelled_at` INT NULL,
  `created_at` INT NOT NULL,
  `finished_at` INT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(255) NOT NULL,
  `connection` TEXT NOT NULL,
  `queue` TEXT NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personal_access_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` VARCHAR(255) NOT NULL,
  `tokenable_id` BIGINT UNSIGNED NOT NULL,
  `name` TEXT NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `abilities` TEXT NULL,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`, `tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `test_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `test_items_user_id_foreign` (`user_id`),
  CONSTRAINT `test_items_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `applicants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_code` VARCHAR(255) NOT NULL,
  `reference_number` VARCHAR(60) NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NULL,
  `gmail` VARCHAR(255) NULL,
  `gmail_submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `course_applied` VARCHAR(100) NULL,
  `preferred_exam_time` VARCHAR(40) NULL,
  `application_date` DATE NULL DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'registered',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `applicants_applicant_code_unique` (`applicant_code`),
  UNIQUE KEY `applicants_reference_number_unique` (`reference_number`),
  KEY `applicants_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_schedules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `exam_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NULL,
  `time_slot` VARCHAR(30) NULL,
  `venue` VARCHAR(120) NULL,
  `batch_code` VARCHAR(50) NULL,
  `course` VARCHAR(100) NULL,
  `proctor_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'scheduled',
  `expected_examinees` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `examination_schedules_exam_date_status_index` (`exam_date`, `status`),
  KEY `examination_schedules_proctor_id_foreign` (`proctor_id`),
  CONSTRAINT `examination_schedules_proctor_id_foreign` FOREIGN KEY (`proctor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_schedule_id` BIGINT UNSIGNED NOT NULL,
  `room_name` VARCHAR(80) NOT NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 100,
  `proctor_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `examination_rooms_examination_schedule_id_index` (`examination_schedule_id`),
  KEY `examination_rooms_proctor_id_foreign` (`proctor_id`),
  CONSTRAINT `examination_rooms_examination_schedule_id_foreign` FOREIGN KEY (`examination_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_rooms_proctor_id_foreign` FOREIGN KEY (`proctor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_grading_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_code` VARCHAR(100) NOT NULL,
  `course_name` VARCHAR(150) NOT NULL,
  `passing_percentage` DECIMAL(5,2) NOT NULL DEFAULT 75.00,
  `failing_grade_point` DECIMAL(3,2) NOT NULL DEFAULT 5.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_grading_settings_course_code_unique` (`course_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 90,
  `allow_late_entry` TINYINT(1) NOT NULL DEFAULT 0,
  `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 1,
  `shuffle_categories` TINYINT(1) NOT NULL DEFAULT 0,
  `shuffle_both` TINYINT(1) NOT NULL DEFAULT 0,
  `violation_limit` INT UNSIGNED NOT NULL DEFAULT 3,
  `disconnect_grace_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  `tab_switch_grace_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `room_student_limit` INT UNSIGNED NOT NULL DEFAULT 60,
  `default_start_at` DATETIME NULL DEFAULT NULL,
  `default_end_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `question_banks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year` VARCHAR(20) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `question_banks_school_year_unique` (`school_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_subjects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_bank_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(40) NULL,
  `description` TEXT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `selection_limit` INT UNSIGNED NOT NULL DEFAULT 5,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_subjects_question_bank_id_code_unique` (`question_bank_id`, `code`),
  CONSTRAINT `exam_subjects_question_bank_id_foreign` FOREIGN KEY (`question_bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_subject_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `stem` TEXT NOT NULL,
  `question_type` VARCHAR(40) NOT NULL DEFAULT 'multiple_choice',
  `options` JSON NULL,
  `correct_answer` VARCHAR(255) NULL,
  `difficulty` VARCHAR(20) NOT NULL DEFAULT 'medium',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `is_selected_for_exam` TINYINT(1) NOT NULL DEFAULT 0,
  `selected_at` TIMESTAMP NULL DEFAULT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exam_questions_exam_subject_id_is_selected_for_exam_index` (`exam_subject_id`, `is_selected_for_exam`),
  KEY `exam_questions_created_by_foreign` (`created_by`),
  CONSTRAINT `exam_questions_exam_subject_id_foreign` FOREIGN KEY (`exam_subject_id`) REFERENCES `exam_subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_questions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_schedule_id` BIGINT UNSIGNED NOT NULL,
  `question_bank_id` BIGINT UNSIGNED NULL,
  `proctor_id` BIGINT UNSIGNED NOT NULL,
  `examination_room_id` BIGINT UNSIGNED NULL,
  `exam_code` VARCHAR(20) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'lobby_open',
  `wifi_ssid` VARCHAR(120) NULL,
  `local_server_ip` VARCHAR(45) NULL,
  `sync_mode` VARCHAR(30) NULL DEFAULT 'offline_lan',
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `ended_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_sessions_exam_code_unique` (`exam_code`),
  KEY `exam_sessions_examination_schedule_id_status_index` (`examination_schedule_id`, `status`),
  KEY `exam_sessions_proctor_id_foreign` (`proctor_id`),
  KEY `exam_sessions_examination_room_id_foreign` (`examination_room_id`),
  CONSTRAINT `exam_sessions_examination_schedule_id_foreign` FOREIGN KEY (`examination_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_sessions_question_bank_id_foreign` FOREIGN KEY (`question_bank_id`) REFERENCES `question_banks` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_sessions_proctor_id_foreign` FOREIGN KEY (`proctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_sessions_examination_room_id_foreign` FOREIGN KEY (`examination_room_id`) REFERENCES `examination_rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `result_email_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requested_by` BIGINT UNSIGNED NOT NULL,
  `total` INT UNSIGNED NOT NULL DEFAULT 0,
  `queued` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `filters` JSON NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `result_email_batches_requested_by_foreign` (`requested_by`),
  CONSTRAINT `result_email_batches_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_registrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_schedule_id` BIGINT UNSIGNED NOT NULL,
  `exam_session_id` BIGINT UNSIGNED NULL,
  `previous_schedule_id` BIGINT UNSIGNED NULL,
  `applicant_id` BIGINT UNSIGNED NOT NULL,
  `participation_token` CHAR(36) NULL,
  `lobby_status` VARCHAR(30) NULL,
  `joined_lobby_at` TIMESTAMP NULL DEFAULT NULL,
  `violation_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `attendance_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `result_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `score` DECIMAL(5,2) NULL,
  `grade_point` DECIMAL(3,2) NULL,
  `items_correct` INT UNSIGNED NULL,
  `items_total` INT UNSIGNED NULL,
  `sync_status` VARCHAR(20) NULL,
  `synced_at` TIMESTAMP NULL DEFAULT NULL,
  `sync_error` TEXT NULL,
  `passkey_email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `passkey_email_sent_at` TIMESTAMP NULL DEFAULT NULL,
  `passkey_email_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `passkey_email_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `passkey_email_error` TEXT NULL,
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `email_sent_at` TIMESTAMP NULL DEFAULT NULL,
  `email_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `email_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `email_error` TEXT NULL,

  `result_email_batch_id` BIGINT UNSIGNED NULL,
  `exam_passkey` VARCHAR(20) NULL,
  `reschedule_reason` TEXT NULL,
  `rescheduled_at` TIMESTAMP NULL DEFAULT NULL,
  `rescheduled_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_reg_unique` (`examination_schedule_id`, `applicant_id`),
  UNIQUE KEY `examination_registrations_participation_token_unique` (`participation_token`),
  KEY `examination_registrations_attendance_status_index` (`attendance_status`),
  KEY `examination_registrations_result_status_index` (`result_status`),
  KEY `examination_registrations_applicant_id_foreign` (`applicant_id`),
  KEY `examination_registrations_previous_schedule_id_foreign` (`previous_schedule_id`),
  KEY `examination_registrations_rescheduled_by_foreign` (`rescheduled_by`),
  KEY `examination_registrations_exam_session_id_foreign` (`exam_session_id`),
  KEY `examination_registrations_result_email_batch_id_foreign` (`result_email_batch_id`),
  CONSTRAINT `examination_registrations_examination_schedule_id_foreign` FOREIGN KEY (`examination_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_registrations_applicant_id_foreign` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_registrations_previous_schedule_id_foreign` FOREIGN KEY (`previous_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `examination_registrations_rescheduled_by_foreign` FOREIGN KEY (`rescheduled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `examination_registrations_exam_session_id_foreign` FOREIGN KEY (`exam_session_id`) REFERENCES `exam_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `examination_registrations_result_email_batch_id_foreign` FOREIGN KEY (`result_email_batch_id`) REFERENCES `result_email_batches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_answers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_registration_id` BIGINT UNSIGNED NOT NULL,
  `exam_question_id` BIGINT UNSIGNED NOT NULL,
  `selected_answer` VARCHAR(10) NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_answer_unique` (`examination_registration_id`, `exam_question_id`),
  KEY `examination_answers_exam_question_id_foreign` (`exam_question_id`),
  CONSTRAINT `examination_answers_examination_registration_id_foreign` FOREIGN KEY (`examination_registration_id`) REFERENCES `examination_registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_answers_exam_question_id_foreign` FOREIGN KEY (`exam_question_id`) REFERENCES `exam_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_violations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_registration_id` BIGINT UNSIGNED NOT NULL,
  `exam_session_id` BIGINT UNSIGNED NULL,
  `type` VARCHAR(60) NOT NULL,
  `message` VARCHAR(500) NULL,
  `violation_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `occurred_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `examination_violations_exam_session_id_occurred_at_index` (`exam_session_id`, `occurred_at`),
  KEY `examination_violations_examination_registration_id_foreign` (`examination_registration_id`),
  CONSTRAINT `examination_violations_examination_registration_id_foreign` FOREIGN KEY (`examination_registration_id`) REFERENCES `examination_registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_violations_exam_session_id_foreign` FOREIGN KEY (`exam_session_id`) REFERENCES `exam_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `examination_passkey_email_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_registration_id` BIGINT UNSIGNED NOT NULL,
  `sent_by` BIGINT UNSIGNED NULL,
  `email` VARCHAR(255) NULL,
  `status` VARCHAR(20) NOT NULL,
  `error_message` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `examination_passkey_email_logs_examination_registration_id_foreign` (`examination_registration_id`),
  KEY `examination_passkey_email_logs_sent_by_foreign` (`sent_by`),
  CONSTRAINT `examination_passkey_email_logs_examination_registration_id_foreign` FOREIGN KEY (`examination_registration_id`) REFERENCES `examination_registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_passkey_email_logs_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `subject_type` VARCHAR(80) NULL,
  `subject_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_created_at_index` (`created_at`),
  KEY `activity_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 0;

SQL;

$tables = [
    'migrations',
    'roles',
    'users',
    'password_reset_tokens',
    'sessions',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'personal_access_tokens',
    'test_items',
    'applicants',
    'examination_schedules',
    'examination_rooms',
    'course_grading_settings',
    'examination_settings',
    'question_banks',
    'exam_subjects',
    'exam_questions',
    'exam_sessions',
    'result_email_batches',
    'examination_registrations',
    'examination_answers',
    'examination_violations',
    'examination_passkey_email_logs',
    'activity_logs',
];

foreach ($tables as $table) {
    try {
        exportTable($pdo, $table, $out);
    } catch (Throwable $e) {
        // skip silently
    }
}

$out .= "SET FOREIGN_KEY_CHECKS = 1;\n";

$targetFile = __DIR__.'/../IMPORT_THIS_IN_PHPMYADMIN.sql';
file_put_contents($targetFile, $out);
echo "Successfully written pure UTF-8 SQL to {$targetFile} (".strlen($out)." bytes)\n";
