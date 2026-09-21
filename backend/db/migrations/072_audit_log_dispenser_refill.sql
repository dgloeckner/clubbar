-- Recording a hopper refill (#955).
--
-- `audit_log.action` is an ENUM, so a case added to `AuditAction` and not added
-- here is not a missing label: MariaDB in strict mode refuses the INSERT and
-- the write throws — the same trap migrations 060-062 record.
--
-- A refill is a **fact about the hopper**, not an acknowledgement of an alert.
-- Nothing on any surface can clear a dispenser fault (owner decision 3), and
-- this entry must not be read as somebody having done so. What it carries is
-- the pair that makes drift visible over time: the estimate as it stood just
-- before the refill, and the number the admin counted into the hopper. The
-- difference between the two is everything the arithmetic failed to see —
-- tokens that coasted out after a motor stop, a dispense billed while
-- `count_reliable` was false, a hopper somebody topped up without telling
-- anyone. It is the only place that difference is ever written down.

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
    'registration_link_sent',
    'terminal_dispenser_refilled'
) NOT NULL;
