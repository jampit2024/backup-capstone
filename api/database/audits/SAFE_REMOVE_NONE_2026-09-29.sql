-- Safety guard for the 2026-09-29 database audit.
-- No table met the zero-impact deletion standard.
-- This script intentionally performs no destructive table-drop operation.

SELECT 'ABORT: no safe-to-remove tables were proven; no destructive SQL executed.' AS audit_result;

-- Before any future removal, require all of the following evidence:
-- 1. Successful live schema, row-count, size, FK, trigger, and view inventory.
-- 2. Zero backend/frontend/migration/job/report/sync references.
-- 3. Tested logical backup and restore.
-- 4. Reviewed staging migration and full workflow verification.
-- 5. Explicit production change approval.
