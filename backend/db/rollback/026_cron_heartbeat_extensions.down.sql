-- Rollback for migration 026: the drain's missing-extension report (ADR-0038)
--
-- Dropping this loses diagnosis, not state: nothing keys on the column, and the
-- next run rewrites it.
ALTER TABLE cron_heartbeat DROP COLUMN missing_extensions;
