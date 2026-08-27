-- =============================================================================
-- Rollback for 056_mail_outbox_backup_health.sql
-- =============================================================================
-- Queued and sent `backup_health_warning` rows are DELETED rather than left to
-- be refused by the narrowed enum, on the same reasoning 054 and 055 gave — and
-- it is even clearer here.
--
-- This notice proves nothing and collects nothing. What it reports is a
-- condition that is still true and still readable after the delete: the archives
-- on disk, the journal beside them and the security self-check all still say the
-- backup has stopped, because none of them lives in this table. Re-applying the
-- migration means the next tick queues the day's warning again, from the same
-- reading of the same directory.
--
-- An announcement would never be deleted by a rollback. This is not one.
-- =============================================================================

DELETE FROM mail_outbox WHERE kind = 'backup_health_warning';

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
    'credit_limit_digest',
    'backup_secret_expiry_warning'
) NOT NULL;
