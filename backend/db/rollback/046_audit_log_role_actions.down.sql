-- Rollback for migration 045: dedicated role-change audit actions
--
-- Destructive by nature, like migration 037's rollback before it: rows already
-- written as 'role_granted' or 'role_revoked' hold values the narrowed ENUM
-- cannot express. They are rewritten to 'update' — the action a role change
-- would have carried before this migration — rather than dropped, so the trail
-- keeps the event and loses only its specific name. What that costs is the
-- ability to filter for escalation, which is the whole reason the actions
-- exist; a rollback here is a real loss of oversight, not a no-op.
UPDATE audit_log SET action = 'update' WHERE action IN ('role_granted', 'role_revoked');

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
    'email_changed'
) NOT NULL;
