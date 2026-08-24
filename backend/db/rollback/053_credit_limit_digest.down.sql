-- =============================================================================
-- Rollback for 053_credit_limit_digest.sql
-- =============================================================================
-- Order matters. The cadence column goes first, so that nothing can enqueue a
-- fresh digest between the two statements and make the second one fail on rows
-- it did not expect to find.
--
-- Queued and sent `credit_limit_digest` rows are DELETED rather than left to be
-- refused by the narrowed enum. That is safe here in a way it would not be for
-- an announcement: a digest is a convenience notice about a condition that is
-- still true and still visible on the dashboard, it proves nothing under § 7
-- Abs. 3, and re-enabling the feature re-queues the next window's digest
-- anyway. An announcement would never be deleted by a rollback.
-- =============================================================================

ALTER TABLE mail_config DROP COLUMN credit_limit_digest_cadence;

DELETE FROM mail_outbox WHERE kind = 'credit_limit_digest';

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
    'jugendschutz_violation'
) NOT NULL;
