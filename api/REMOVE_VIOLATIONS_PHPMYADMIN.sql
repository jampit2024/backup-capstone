-- =============================================================================
-- MasterExam: Remove Violation Tracking & Strike Columns
-- Reason: Mandatory Android Screen Pinning (startLockTask) and FLAG_SECURE
--         physically prevent cheating at the operating system level.
-- Target DB: u881832908_exampro (or local masterexam MySQL)
-- =============================================================================

-- 1. Drop the examination_violations table if it exists
DROP TABLE IF EXISTS `examination_violations`;

-- 2. Remove violation_limit from examination_settings
SET @exist_check := (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'examination_settings' 
      AND COLUMN_NAME = 'violation_limit'
);
SET @sql := IF(@exist_check > 0, 'ALTER TABLE `examination_settings` DROP COLUMN `violation_limit`', 'SELECT "Column violation_limit does not exist"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Remove tab_switch_grace_seconds from examination_settings
SET @exist_check := (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'examination_settings' 
      AND COLUMN_NAME = 'tab_switch_grace_seconds'
);
SET @sql := IF(@exist_check > 0, 'ALTER TABLE `examination_settings` DROP COLUMN `tab_switch_grace_seconds`', 'SELECT "Column tab_switch_grace_seconds does not exist"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Remove violation_count from examination_registrations
SET @exist_check := (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'examination_registrations' 
      AND COLUMN_NAME = 'violation_count'
);
SET @sql := IF(@exist_check > 0, 'ALTER TABLE `examination_registrations` DROP COLUMN `violation_count`', 'SELECT "Column violation_count does not exist"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Record the migration in migrations table (if using Laravel migration tracking)
INSERT IGNORE INTO `migrations` (`migration`, `batch`) 
VALUES ('2026_09_23_190000_remove_violation_fields_and_tables', (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM (SELECT `batch` FROM `migrations`) AS m));

SELECT 'Successfully removed all violation tables and columns!' AS `status`;

