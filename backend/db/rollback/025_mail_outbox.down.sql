-- Rollback for migration 025: transactional mail outbox and cron heartbeat (ADR-0038)
--
-- The audit_log action enum is deliberately left as it is. Narrowing it back
-- would either fail or silently truncate rows that already record a real
-- enqueue, and an audit entry is the one thing a rollback may not destroy.
DROP TABLE IF EXISTS mail_outbox;
DROP TABLE IF EXISTS cron_heartbeat;
