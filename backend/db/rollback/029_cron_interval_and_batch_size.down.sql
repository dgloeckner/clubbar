-- Rollback for migration 029: the declared cron interval, the batch dial and
-- the observed-gap column (ADR-0039 decision 5).
--
-- Dropping these loses tuning and diagnosis, not state. The retry ladder falls
-- back to the code default (`hourly`), the batch size to the environment
-- variable or the compiled default, and the self-check loses its
-- declared-versus-observed row — no queued message changes status, and no
-- evidence of a sent announcement lives in any of the three.

ALTER TABLE mail_config
    DROP COLUMN drain_batch_size,
    DROP COLUMN cron_interval;

ALTER TABLE cron_heartbeat
    DROP COLUMN previous_run_at;
