-- =============================================================================
-- 038_mail_outbox_admin_email_changed.sql — telling the address it moved from
-- =============================================================================
-- ADR-0038. Migration 026 generalised the outbox so a message could be
-- addressed to an admin rather than a member — nullable `member_id`, an
-- `admin_user_id` beside it, a polymorphic `subject_id`. ADR-0041's anomaly
-- warning (migration 030) was the first kind to use that. This is the first
-- whose *subject* is an admin account, and the first whose recipient is
-- deliberately an address the system no longer holds.
--
-- That is the whole point of the message. An admin's email is their login
-- identifier, so changing it changes who can sign in; the address it was
-- changed *from* is the only channel that still reaches the previous owner if
-- the change was not theirs. `mail_outbox.recipient` is already a snapshot
-- rather than a join ("proof of who was announced to"), so the row can carry
-- an address `admin_users` has forgotten — no schema change needed beyond the
-- ENUM.
--
-- The list below is migration 030's, plus the new value: `MODIFY COLUMN`
-- restates the whole ENUM, so an older list would drop
-- `terminal_anomaly_warning`.
--
-- Rollback: db/rollback/038_mail_outbox_admin_email_changed.down.sql
-- =============================================================================

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'admin_email_changed'
) NOT NULL;
