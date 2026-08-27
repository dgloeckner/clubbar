-- =============================================================================
-- 056 — mail_outbox learns that the backup job can stop (#693)
--
-- The companion to 055, and the one that covers what a date cannot. That
-- migration added the warning for a backup client secret *running out*; this one
-- adds the warning for the backup having *already stopped*.
--
-- The two failures are unrelated in cause and identical in outcome. 055's is a
-- credential ageing towards a known day. This one has no day attached to it at
-- all: a cron never added to the hosting panel, a cron dropped in a tariff
-- migration, a job dying before it writes, an upload that quietly stopped
-- reaching the store while the local archive still looks perfectly healthy.
-- None of those produces an error anybody sees, and until #721 nothing in the
-- application even measured them.
--
-- ## Why the outbox, and not a backup table
--
-- Because for a club **this mail is the alarm**. `backup.heartbeat_url` (#712)
-- is better where somebody has set one up, and it is optional; most clubs will
-- not have an external monitor, and the mechanism that assumes nothing beyond an
-- address is the one that has to work.
--
-- It is **not** backup state in the database, and ADR-0049 decision 8 still
-- holds: no backup table, no backup row, no backup id, no audit vocabulary. What
-- is stored is one outbox row per admin per day recording that somebody was
-- *told*. The record of the backup job itself remains where the ADR puts it —
-- the archives on disk and the journal beside them — and this notice is derived
-- from reading those, never from a table.
--
-- Its subject is the installation (`subject_id` = 1, `MailSubject::INSTANCE_CONFIG`),
-- as 055's is. Restoring an archive cannot revert any of it.
--
-- Rollback: db/rollback/056_mail_outbox_backup_health.down.sql
-- =============================================================================

-- Restating the whole list, as every predecessor did: MariaDB has no ADD VALUE,
-- and a MODIFY COLUMN is a replacement rather than an amendment. Omitting an
-- existing value here is how one is removed, so the list is the interface and
-- it has to be read as one.
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

-- `settlement_announcements` deliberately does NOT gain this value, for the
-- reason 039 first gave and 054 and 055 repeated: that table is the durable
-- proof that a § 7 Abs. 3 announcement was made about a settlement. A warning
-- that the backup has stopped announces nothing, proves nothing and collects
-- nothing.
