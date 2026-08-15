-- Rollback for migration 038: the admin email-change notice
--
-- Queued-but-unsent notices cannot survive the narrowed ENUM. They are deleted
-- rather than rewritten to another kind: every remaining kind means something
-- entirely different, and delivering one of those to a former address would be
-- worse than delivering nothing. Rows already sent are deleted too — the
-- audit_log's `email_changed` entry is the durable record of the change, not
-- the outbox row.
DELETE FROM mail_outbox WHERE kind = 'admin_email_changed';

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning'
) NOT NULL;
