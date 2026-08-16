-- =============================================================================
-- 041_mail_outbox_terminal_token_issued.sql — announcing a minted credential
-- =============================================================================
-- ADR-0043. Issuing a terminal token is the hardest-gated write in the admin
-- API — step-up password, fresh TOTP, rate limited, audited — and until now it
-- was the only credential event that created a secret and told nobody. The
-- audit entry is *pull*: somebody has to decide to go and look.
--
-- This adds the kind that pushes. One row per active admin per issuance, so the
-- signal is out of band with respect to the credential that would have been
-- compromised: whoever holds one admin's session, password and TOTP code
-- clears the mint gate and still cannot reach the other admins' inboxes.
--
-- No column changes. Migration 026 already made the outbox polymorphic
-- (`subject_id` + `dedup_key`) and admin-addressable (`admin_user_id`), and the
-- kind reuses both exactly as `terminal_anomaly_warning` does: `subject_id` is
-- the terminal, and the `dedup_key` carries `enrolled:<stamp>:<adminId>` or
-- `rotated:<stamp>:<adminId>`. The stamp is the token's `token_issued_at` to
-- the second, which is what makes a second rotation of the same terminal a
-- second message rather than one the unique index swallows.
--
-- The list below is migration 039's, plus the new value. `MODIFY COLUMN`
-- restates the whole ENUM rather than amending it — MariaDB has no ADD VALUE —
-- so omitting an existing value here is how one is removed. It is the
-- interface, and it has to be read as one. `payment_request` stays absent for
-- the reason 039 gives at length: #476 deleted the case, the builder arm and
-- the API value, and restating it from an older copy is how it came back once
-- already.
--
-- `settlement_announcements` does not gain this value either, for 039's reason:
-- that table is the durable proof that a § 7 Abs. 3 announcement was made about
-- a settlement, and a credential notice announces nothing about one.
--
-- Rollback: db/rollback/041_mail_outbox_terminal_token_issued.down.sql
-- =============================================================================

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'terminal_token_issued',
    'admin_email_changed',
    'deckel_statement'
) NOT NULL;
