-- =============================================================================
-- Migration 015: Instance identity for terminal-backend pairing (ADR-0035)
-- =============================================================================
-- A terminal must be able to tell "the backend I've always synced with" apart
-- from "a backend with the same URL but a different, discontinuous history"
-- (e.g. after a reset/reseed/restore) -- see #380 and ADR-0035.
--
-- instance_id is added as NOT NULL DEFAULT '' so the ALTER succeeds against
-- the already-seeded singleton row from migration 013, then backfilled with a
-- fresh random UUID, then the default is dropped so any future re-seed of
-- this table is forced to supply its own value rather than silently reusing
-- the empty-string sentinel.
-- =============================================================================

ALTER TABLE instance_config
    ADD COLUMN instance_id CHAR(36) NOT NULL DEFAULT '' AFTER instance_name;

UPDATE instance_config SET instance_id = UUID() WHERE instance_id = '';

ALTER TABLE instance_config
    ALTER COLUMN instance_id DROP DEFAULT;
