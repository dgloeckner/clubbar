-- Rollback for migration 070: the deadline and its window come back.
--
-- Every surviving row is given `submitted_at` + 30 days, which is what 059
-- would have written. A row submitted more than thirty days ago therefore comes
-- back already expired, and the first cron tick of the older code deletes it —
-- the very loss 070 exists to stop. Before rolling back on anything but a
-- scratch database, either deal with those rows in the review inbox or push the
-- deadline out by hand afterwards:
--
--   UPDATE pending_registrations SET expires_at = '2099-12-31 00:00:00';
--
-- Code from before 070 writes and reads both columns again.

ALTER TABLE self_registration_config
    ADD COLUMN retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30
        AFTER secret_rotated_at;

ALTER TABLE pending_registrations
    ADD COLUMN expires_at DATETIME NULL AFTER submitted_at;

UPDATE pending_registrations
    SET expires_at = DATE_ADD(submitted_at, INTERVAL 30 DAY);

ALTER TABLE pending_registrations
    MODIFY COLUMN expires_at DATETIME NOT NULL,
    ADD INDEX idx_pending_registrations_expires (expires_at);
