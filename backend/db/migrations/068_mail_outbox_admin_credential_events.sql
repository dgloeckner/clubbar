-- Three personal security notices (#892): a password change, a TOTP
-- enrollment and a TOTP reset, each mailed to the account they are about —
-- the same shape `admin_email_changed` already has, and for the same reason:
-- a hijacked session that moves one of these credentials must not also be
-- able to keep the legitimate owner from hearing about it.
--
-- No new table and no new column. `mail_outbox.kind` is the only thing that
-- has to widen; `audit_log.action` already carries `password_changed`,
-- `totp_enrolled` and `totp_reset` from migrations 004/037 — this feature
-- reuses those rows rather than adding a fourth kind of entry for the same
-- event.
--
-- Same MariaDB caveat as every enum migration before it: there is no ADD
-- VALUE, so MODIFY COLUMN replaces the whole list rather than amending it,
-- and an omission below is a removal. A case in `MailKind` with no value here
-- is refused at write time, which for a queued message means the drain's own
-- INSERT fails silently on the enqueue path.

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
