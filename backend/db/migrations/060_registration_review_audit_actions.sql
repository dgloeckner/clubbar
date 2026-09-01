-- Self-registration review actions (#779, ADR-0052 decision 10).
--
-- `audit_log.action` is an ENUM, so a case added to `AuditAction` and not added
-- here is not a missing label: MariaDB in strict mode refuses the INSERT, the
-- audit write throws, and — because approval writes its entry inside the same
-- transaction as the member and the mandate — the whole approval rolls back. A
-- forgotten line in this file surfaces as "approving a registration is broken".
--
-- The three actions an admin can take on somebody else's submission. Everything
-- the pending store does by itself — a stranger submitting, the TTL purge
-- deleting — is deliberately absent: the log follows authority, not activity,
-- and an entry naming an applicant would outlive the very deletion that exists
-- to remove them.

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
    'registration_edited'
) NOT NULL;
