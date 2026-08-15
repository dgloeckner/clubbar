-- Rollback for migration 036: the admin credentials epoch
--
-- Dropping this stops sessions being refused after a credential change; it
-- loses no transactional state. Sessions already issued stay valid for their
-- normal 2h idle / 24h absolute lifetime.
ALTER TABLE admin_users DROP COLUMN credentials_changed_at;
