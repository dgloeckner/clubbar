-- =============================================================================
-- Rollback for 055_mail_outbox_backup_secret_expiry.sql
-- =============================================================================
-- Queued and sent `backup_secret_expiry_warning` rows are DELETED rather than
-- left to be refused by the narrowed enum, on the same reasoning 054 gave: this
-- is a convenience notice about a condition that is still true and still
-- readable — the expiry date is in `config.php` and the nightly run reports the
-- failure once the secret actually lapses. It proves nothing, collects nothing,
-- and re-applying the migration means the next tick queues the current tier
-- again. An announcement would never be deleted by a rollback; this is not one.
-- =============================================================================

DELETE FROM mail_outbox WHERE kind = 'backup_secret_expiry_warning';

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
    'encryption_key_revoked',
    'admin_account_created',
    'admin_role_changed',
    'jugendschutz_violation',
    'credit_limit_digest'
) NOT NULL;
