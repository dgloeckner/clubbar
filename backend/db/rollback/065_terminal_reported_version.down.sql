-- Rollback for migration 065: stop recording what each terminal runs.
--
-- Nothing else holds a copy of these values, and nothing needs to: the terminals
-- re-report on their next sync, so re-applying 065 refills the columns within a
-- sync interval rather than needing a dump.

ALTER TABLE terminals
    DROP COLUMN blocked_version,
    DROP COLUMN reported_version_at,
    DROP COLUMN reported_version;
