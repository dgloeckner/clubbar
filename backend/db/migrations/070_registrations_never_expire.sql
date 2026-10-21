-- =============================================================================
-- 070_registrations_never_expire.sql — a pending registration waits for an admin
-- =============================================================================
-- Migration 059 gave every pending registration an `expires_at` of submission
-- plus `self_registration_config.retention_days` (30), and the cron tick deleted
-- every row past it. In practice that deleted registrations the club still
-- wanted: the applicant had filled the form and been told to hand in signed
-- paper, and the paper took longer than thirty days to reach a Kassenwart. The
-- row then vanished from the review inbox with nothing to recover it from but a
-- backup.
--
-- ADR-0052 decision 10, as amended: a pending registration is deleted only when
-- an admin approves it (it becomes a member) or rejects it (it is gone at once).
-- Nothing deletes one by itself, so neither the deadline nor the window it was
-- computed from has a reader any more.
--
-- Existing rows keep everything else and simply stop having a deadline.
--
-- Rollback: db/rollback/070_registrations_never_expire.down.sql
-- =============================================================================

ALTER TABLE pending_registrations
    DROP INDEX idx_pending_registrations_expires,
    DROP COLUMN expires_at;

ALTER TABLE self_registration_config
    DROP COLUMN retention_days;
