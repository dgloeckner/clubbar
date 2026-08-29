-- =============================================================================
-- Rollback for 057_mail_outbox_member_lifecycle.sql
-- =============================================================================
-- The four member lifecycle rows are DELETED rather than left to be refused by
-- the narrowed enum, on the reasoning 054, 055 and 056 gave.
--
-- None of them is an announcement. A welcome, a card replacement and the two
-- halves of an address change report facts that remain fully readable after the
-- delete: `members.card_uid` still says which card is assigned, `members.email`
-- still says which address is on file, and the audit log still carries the
-- change that moved either one. Nothing about the club's § 7 Abs. 3 position
-- depends on these rows.
--
-- What is lost is the record that a particular *message* was queued. That
-- matters most for the welcome, whose constant dedup key is what makes it
-- fire once and only once: re-applying the migration leaves a previously
-- welcomed member eligible for a welcome again, should their card be
-- reassigned afterwards. That is the correct trade for a rollback — a
-- duplicate greeting is recoverable, a schema that refuses writes is not — but
-- it is a real consequence and not a silent one.
--
-- An announcement would never be deleted by a rollback. None of these is one.
-- =============================================================================

DELETE FROM mail_outbox
 WHERE kind IN (
    'member_welcome',
    'member_card_replaced',
    'member_email_changed',
    'member_email_activated'
 );

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
    'backup_health_warning'
) NOT NULL;
