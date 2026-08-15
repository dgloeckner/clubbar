-- ADR-0041: audit actions for terminal credential anomaly detection. Follows the
-- 016/019/022/025 pattern of restating the full ENUM — MariaDB has no ADD VALUE.
--
-- Without this the first terminal_anomaly_* write dies with "Data truncated for
-- column 'action'". For `terminal_anomaly_detected` that would take the cron
-- tick's detector down with it; for `terminal_anomaly_acknowledged` it would
-- fail the admin's acknowledgement after the anomaly row had already been
-- cleared.
--
-- Restating the whole list means this migration carries every value any earlier
-- one added. It runs after 025, so its list is 025's plus the two below;
-- dropping one here would silently un-add it.
--
-- `terminal_anomaly_detected` is written by the cron tick, which has no admin
-- user — `audit_log.admin_user_id` is nullable and there is precedent on this
-- exact path (PairingService writes terminal_repair with an explicit null, and
-- the token authenticator writes activation and expiry with none).

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
