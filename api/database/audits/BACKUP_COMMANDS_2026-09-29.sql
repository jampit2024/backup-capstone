-- Database audit backup evidence commands.
-- Run against the deployed production database after replacing placeholders.
-- These statements do not modify data.

-- Shell command:
-- mysqldump --single-transaction --routines --events --triggers --hex-blob \
--   -h <host> -P <port> -u <user> -p <database> \
--   > masterexam_pre_cleanup_YYYYMMDD_HHMMSS.sql

SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY,
       COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SHOW TRIGGERS;
SHOW FULL TABLES WHERE Table_type = 'VIEW';
