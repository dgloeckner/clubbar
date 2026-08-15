-- Rollback for migration 032: the two admin-initiated mail actions (#407).
--
-- Restores the enum to the state migration 031 left it in — the terminal-anomaly
-- actions (ADR-0041) stay, because they are not this migration's to remove.
--
-- Rows already written with these actions would become invalid enum values, so
-- they are rewritten to the nearest truthful thing first rather than deleted:
-- an audit entry is evidence, and dropping the column's ability to spell a
-- value is not a reason to lose the record that something happened.
--
-- `update` is the honest fallback for both — an admin changed something about a
-- queued message, or about the mail configuration — and the original action
-- survives in the entry's stored values.

UPDATE audit_log
   SET action = 'update'
 WHERE action IN ('mail_retried', 'mail_test_sent');

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
    'terminal_anomaly_detected',
    'terminal_anomaly_acknowledged'
) NOT NULL;
