-- Rollback for migration 047: the admin lifecycle kinds leave the queue.
--
-- Destructive in the same way migration 045's rollback is: queued or sent rows
-- carrying these kinds hold values the narrowed ENUM cannot express. They are
-- deleted rather than rewritten, because unlike an audit action there is no
-- neighbouring kind that means the same thing — a message rendered as the wrong
-- kind is a message about the wrong event. Delivered mail is unaffected; it has
-- already left.
DELETE FROM mail_outbox WHERE kind IN ('admin_account_created', 'admin_role_changed');

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
    'encryption_key_revoked'
) NOT NULL;
