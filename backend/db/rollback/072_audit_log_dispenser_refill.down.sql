-- Undo 072: drop the refill action.
--
-- Rows carrying it are deleted first; a plain MODIFY would truncate them to ''.
-- Safe here and only here — the value can only exist if 072 had been applied.
--
-- What is lost with those rows is the drift record: an audit entry is the only
-- place the estimate before a refill and the count after it are written down
-- together. Rolling back 071 alongside this removes the estimate itself, so the
-- pair goes together.

DELETE FROM audit_log WHERE action = 'terminal_dispenser_refilled';

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
    'jugendschutz_violation',
    'jugendschutz_violation_acknowledged',
    'invitation_sent',
    'invitation_accepted',
    'registration_approved',
    'registration_rejected',
    'registration_edited',
    'registration_printed',
    'registration_secret_rotated',
    'registration_enabled',
    'registration_disabled',
    'registration_document_url_changed',
    'registration_link_sent'
) NOT NULL;
