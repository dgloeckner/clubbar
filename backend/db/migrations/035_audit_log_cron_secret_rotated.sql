-- =============================================================================
-- 035_audit_log_cron_secret_rotated.sql — one admin action, no secret in it
-- =============================================================================
-- #473. `cron_secret_rotated` is audited the same way `terminal_token_rotated`
-- and `mail_retried` are: who did it and when, never the credential itself —
-- the entry carries no plaintext and no hash, only the fact that an admin
-- generated a new one.
--
-- Numbered 035 rather than reusing 032/034: `MODIFY COLUMN … ENUM(...)` is a
-- full replacement, so this migration lists every value 032 left behind plus
-- the one below — dropping any of them would invalidate audit rows already
-- written with it.
--
-- Rollback: db/rollback/035_audit_log_cron_secret_rotated.down.sql
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
    'cron_secret_rotated'
) NOT NULL;
