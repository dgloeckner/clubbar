-- =============================================================================
-- Rollback for 055_backups.sql
-- =============================================================================
-- Additive migration, so the rollback is a clean drop: nothing outside these
-- three tables references them, and no existing table was altered.
--
-- What the drop costs is worth naming, because it is not nothing: `backup_runs`
-- is the record of which private keys still open archives that exist. Dropping
-- it does not delete an archive — those are files on the webspace and on the
-- remote — but it does throw away the only thing that says which key opens
-- which. Roll back before archiving the retired private keys, not after.
-- =============================================================================

-- The audit vocabulary goes back first, so no row can be written naming an
-- action the narrowed enum would then refuse. Rows already carrying one of the
-- five are DELETED rather than left to become '' — a truncated enum value in
-- MariaDB is the empty string, which is worse than absence: it reads as a
-- corrupted audit entry rather than a missing one, and § 147 AO evidence must
-- never be silently mangled. A backup key event is operational and proves
-- nothing under § 7 Abs. 3, so deleting it is the honest choice; a settlement
-- or a member action would never be.
DELETE FROM audit_log WHERE action IN (
    'bank_codes_imported',
    'backup_key_added',
    'backup_key_verified',
    'backup_key_removed',
    'backup_key_marked_compromised'
);

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
    'jugendschutz_violation_acknowledged'
) NOT NULL;

DROP TABLE IF EXISTS backup_config;
DROP TABLE IF EXISTS backup_keys;
DROP TABLE IF EXISTS backup_runs;
