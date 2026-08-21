-- =============================================================================
-- 050_jugendschutz_violation.sql — a sale to a minor is its own event
-- =============================================================================
-- ADR-0045 §3. The terminal is the only layer that can *prevent* an underage
-- sale, because it is the only one present when the drink is poured. By the
-- time the row reaches the server the glass is empty, so the server records the
-- transaction unchanged and raises this instead.
--
-- **Its own action, not a note inside a generic row.** The whole point is that
-- somebody can ask "has this ever happened?" and get an answer by filtering,
-- rather than by opening every stored transaction and reading its JSON. This is
-- the same argument 046 made for `role_granted`, and 040 for
-- `transaction_price_divergence` — which is the closest precedent in every
-- other way too: a sale the server stores and flags rather than refuses.
--
-- **The audit log is the right home, and `terminal_anomalies` is not.** That
-- table was the obvious candidate (#550 already alerts admins from it) and it
-- is wrong here on three counts, each fatal on its own:
--
--   1. It is keyed on `terminal_id NOT NULL ... ON DELETE CASCADE`. Retiring a
--      till would erase the club's record of a sale to a minor.
--   2. It coalesces. One open row per (terminal, kind) with `occurrence_count`
--      is right for "this token keeps appearing on two IPs" and destructive
--      here: two different minors served two different drinks would merge into
--      one row with a 2 on it.
--   3. It is built to be closed. `acknowledged_at` takes a row out of the open
--      set, which is what ADR-0045 invariant 4 forbids — a past sale to a minor
--      stays true regardless of what changes afterwards. `audit_log` is
--      append-only and has no such affordance, which is exactly the property
--      wanted.
--
-- The mail kind goes in beside it. Recording without telling anyone is not
-- "raising" anything: the audit log is `admin`-only under ADR-0044 and nobody
-- browses it unprompted, so the out-of-band notice is what makes a human find
-- out. Restating the whole ENUM list, as 026 and 030 did — MariaDB has no
-- ADD VALUE, and dropping a value here would silently un-add it.
--
-- Rollback: db/rollback/050_jugendschutz_violation.down.sql
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create',
    'update',
    'delete',
    'anonymize',
    'login',
    'logout',
    'login_failed',
    'export',
    'settlement_create',
    'settlement_cancel',
    'settlement_export',
    'settlement_submit',
    'settlement_reverse',
    'transaction_storno',
    'transaction_price_divergence',
    'collection_hold_placed',
    'collection_hold_cleared',
    'activate',
    'deactivate',
    'reorder',
    'totp_enrolled',
    'totp_reset',
    'mandate_document_upload',
    'mandate_document_delete',
    'terminal_repair',
    'key_registered',
    'key_activated',
    'key_rotation_started',
    'key_rotation_batch_completed',
    'key_rotation_completed',
    'key_retired',
    'key_revoked',
    'key_marked_compromised',
    'sepa_export',
    'iban_full_view',
    'terminal_token_created',
    'terminal_token_activated',
    'terminal_token_rotated',
    'terminal_token_revoked',
    'terminal_token_expired',
    'mail_enqueued',
    'mail_superseded',
    'mail_retried',
    'mail_test_sent',
    'terminal_anomaly_detected',
    'terminal_anomaly_acknowledged',
    'cron_secret_rotated',
    'password_changed',
    'email_changed',
    'role_granted',
    'role_revoked',
    'jugendschutz_violation'
) NOT NULL;

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'terminal_token_issued',
    'admin_email_changed',
    'deckel_statement',
    'encryption_key_registered',
    'encryption_key_activated',
    'encryption_key_revoked',
    'admin_account_created',
    'admin_role_changed',
    'jugendschutz_violation'
) NOT NULL;
