-- Rollback for migration 034: the admin-rotatable cron secret (#473).
--
-- Dropping these loses only a rotation record, never queue state: the URL
-- trigger falls back to whatever `config.php`'s `cron.secret` says, exactly as
-- it behaved before this migration.

ALTER TABLE mail_config
    DROP COLUMN cron_secret_rotated_at,
    DROP COLUMN cron_secret_hash;
