-- =============================================================================
-- Rollback for 073_mail_outbox_dispenser_attention.sql
-- =============================================================================
-- Queued and sent `dispenser_attention` rows are DELETED rather than left to be
-- refused by the narrowed enum, on the same reasoning 054, 055 and 056 gave.
--
-- This notice proves nothing and collects nothing. What it reports is a
-- condition that is still true and still readable after the delete: the
-- `dispenser_status` document on the terminal row still says the machine is
-- jammed, and the refill anchor beside it still says how the hopper stood. Re-
-- applying the migration means the next tick queues the notice again, from the
-- same reading of the same two columns.
--
-- An announcement would never be deleted by a rollback. This is not one.
-- =============================================================================

DELETE FROM mail_outbox WHERE kind = 'dispenser_attention';

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
    'admin_invitation',
    'jugendschutz_violation',
    'credit_limit_digest',
    'backup_secret_expiry_warning',
    'backup_health_warning',
    'member_welcome',
    'member_card_replaced',
    'member_email_changed',
    'member_email_activated',
    'registration_link',
    'admin_password_changed',
    'admin_totp_enrolled',
    'admin_totp_reset'
) NOT NULL;
