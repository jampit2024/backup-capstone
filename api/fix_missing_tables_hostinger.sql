-- METCC missing production tables
-- Run in phpMyAdmin → SQL tab for database: u881832908_repo_metcc
-- Safe to re-run: uses IF NOT EXISTS / checks via information_schema where needed

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- users preference columns (ignore error if already exist) ----------
ALTER TABLE `users`
  ADD COLUMN `theme` VARCHAR(20) NOT NULL DEFAULT 'system' AFTER `profile_photo_path`;
ALTER TABLE `users`
  ADD COLUMN `locale` VARCHAR(10) NOT NULL DEFAULT 'en' AFTER `theme`;

-- ---------- core examination domain ----------
CREATE TABLE IF NOT EXISTS `applicants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_code` VARCHAR(255) NOT NULL,
  `reference_number` VARCHAR(60) NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `course_applied` VARCHAR(100) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'registered',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `applicants_applicant_code_unique` (`applicant_code`),
  UNIQUE KEY `applicants_email_unique` (`email`),
  UNIQUE KEY `applicants_reference_number_unique` (`reference_number`),
  KEY `applicants_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `examination_schedules` (
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
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `examination_schedules_exam_date_status_index` (`exam_date`, `status`),
  KEY `examination_schedules_proctor_id_foreign` (`proctor_id`),
  CONSTRAINT `examination_schedules_proctor_id_foreign`
    FOREIGN KEY (`proctor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `examination_registrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_schedule_id` BIGINT UNSIGNED NOT NULL,
  `previous_schedule_id` BIGINT UNSIGNED NULL,
  `applicant_id` BIGINT UNSIGNED NOT NULL,
  `attendance_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `result_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `score` DECIMAL(5,2) NULL,
  `reschedule_reason` TEXT NULL,
  `rescheduled_at` TIMESTAMP NULL,
  `rescheduled_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_reg_unique` (`examination_schedule_id`, `applicant_id`),
  KEY `examination_registrations_attendance_status_index` (`attendance_status`),
  KEY `examination_registrations_result_status_index` (`result_status`),
  KEY `examination_registrations_applicant_id_foreign` (`applicant_id`),
  KEY `examination_registrations_previous_schedule_id_foreign` (`previous_schedule_id`),
  KEY `examination_registrations_rescheduled_by_foreign` (`rescheduled_by`),
  CONSTRAINT `examination_registrations_examination_schedule_id_foreign`
    FOREIGN KEY (`examination_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_registrations_applicant_id_foreign`
    FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_registrations_previous_schedule_id_foreign`
    FOREIGN KEY (`previous_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `examination_registrations_rescheduled_by_foreign`
    FOREIGN KEY (`rescheduled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `examination_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examination_schedule_id` BIGINT UNSIGNED NOT NULL,
  `room_name` VARCHAR(80) NOT NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 100,
  `proctor_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `examination_rooms_examination_schedule_id_index` (`examination_schedule_id`),
  KEY `examination_rooms_proctor_id_foreign` (`proctor_id`),
  CONSTRAINT `examination_rooms_examination_schedule_id_foreign`
    FOREIGN KEY (`examination_schedule_id`) REFERENCES `examination_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examination_rooms_proctor_id_foreign`
    FOREIGN KEY (`proctor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `subject_type` VARCHAR(80) NULL,
  `subject_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_created_at_index` (`created_at`),
  KEY `activity_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `activity_logs_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- question bank ----------
CREATE TABLE IF NOT EXISTS `exam_subjects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(40) NULL,
  `description` TEXT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_subjects_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_banks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_subject_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `question_banks_exam_subject_id_name_unique` (`exam_subject_id`, `name`),
  CONSTRAINT `question_banks_exam_subject_id_foreign`
    FOREIGN KEY (`exam_subject_id`) REFERENCES `exam_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_bank_id` BIGINT UNSIGNED NOT NULL,
  `exam_subject_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `stem` TEXT NOT NULL,
  `question_type` VARCHAR(40) NOT NULL DEFAULT 'multiple_choice',
  `options` JSON NULL,
  `correct_answer` VARCHAR(255) NULL,
  `difficulty` VARCHAR(20) NOT NULL DEFAULT 'medium',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `is_selected_for_exam` TINYINT(1) NOT NULL DEFAULT 0,
  `selected_at` TIMESTAMP NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `exam_questions_question_bank_id_is_selected_for_exam_index` (`question_bank_id`, `is_selected_for_exam`),
  KEY `exam_questions_exam_subject_id_is_selected_for_exam_index` (`exam_subject_id`, `is_selected_for_exam`),
  KEY `exam_questions_created_by_foreign` (`created_by`),
  CONSTRAINT `exam_questions_question_bank_id_foreign`
    FOREIGN KEY (`question_bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_questions_exam_subject_id_foreign`
    FOREIGN KEY (`exam_subject_id`) REFERENCES `exam_subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_questions_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Mark migrations as done so future `php artisan migrate` won't re-run them
INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
('2026_07_24_000001_add_preferences_to_users_table', 99),
('2026_07_24_010000_create_examination_domain_tables', 99),
('2026_07_24_012000_add_rooms_and_capacity_to_examinations', 99),
('2026_07_24_020000_add_reschedule_fields_to_examination_registrations', 99),
('2026_07_24_030000_create_subject_question_bank_tables', 99),
('2026_07_24_040000_add_reference_number_to_applicants', 99);
