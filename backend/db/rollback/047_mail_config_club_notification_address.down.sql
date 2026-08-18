-- Rollback for migration 046: the club-level notification address goes away.
--
-- Dropping the column silently removes the second pair of eyes on admin
-- lifecycle events — the installation returns to mailing active admins only,
-- which for a single-admin club means the alarm about that admin goes to that
-- admin. Nothing else changes, and nothing queued is lost: the outbox rows this
-- address produced carry their own `recipient` snapshot.

ALTER TABLE mail_config DROP COLUMN club_notification_address;
