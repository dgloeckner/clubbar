-- Rollback for 051_jugendschutz_violation_acks.sql
--
-- Drops only the acknowledgement state. The `jugendschutz_violation` audit
-- entries are untouched, because they were never this migration's to own — the
-- record predates it (050) and survives it. Rolling back loses the knowledge of
-- *who had already looked*, which puts every recorded violation back into the
-- dashboard's unacknowledged count. That is the safe direction to fail in.
DROP TABLE IF EXISTS jugendschutz_violation_acks;

-- And narrow the action ENUM back. Any `jugendschutz_violation_acknowledged`
-- row would become unreadable, which is why this is a rollback and not a
-- routine operation: the violations themselves are untouched either way.
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
