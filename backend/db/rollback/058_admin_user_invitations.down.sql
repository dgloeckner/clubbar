-- =============================================================================
-- Rollback for 058_admin_user_invitations.sql
-- =============================================================================
-- The invitation rows go with the table — there is nowhere else to keep them
-- and nothing else reads them. What survives is the part that matters: the
-- `create` and `role_granted` entries for every account that was invited, and
-- the account itself. An admin who had accepted keeps their password; one who
-- had not is back to needing `POST /admin-users/{id}/reset-password`, which is
-- exactly where this feature found them.
--
-- The queued invitation mail is DELETED rather than left for a narrowed enum to
-- refuse, on the reasoning 054–057 gave. It is not an announcement: nothing
-- about the club's § 7 Abs. 3 position depends on it, and an unsent one points
-- at a token whose table is about to be dropped — a link that would resolve to
-- nothing is worse than no link.
-- =============================================================================

DELETE FROM mail_outbox WHERE kind = 'admin_invitation';

DROP TABLE IF EXISTS admin_user_invitations;

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
    'backup_secret_expiry_warning',
    'backup_health_warning',
    'member_welcome',
    'member_card_replaced',
    'member_email_changed',
    'member_email_activated'
) NOT NULL;

-- The two audit actions are removed from the enum, and the entries carrying
-- them with it: MariaDB would otherwise coerce them to '' on the next write to
-- those rows, which is a silently corrupted log rather than a shorter one.
DELETE FROM audit_log WHERE action IN ('invitation_sent', 'invitation_accepted');

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
