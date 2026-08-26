-- =============================================================================
-- 055 — mail_outbox learns about the backup app's client secret (#691)
--
-- The third credential to ride the 90/30/7 expiry tiers ADR-0036 defined, and
-- the one whose expiry is otherwise invisible.
--
-- An encryption key that lapses blocks the SEPA export the same week. A
-- terminal token that lapses locks a till out of the bar while somebody is
-- standing at it. **An expired backup client secret looks exactly like a
-- working one**: Entra sends no notification, the nightly job still writes and
-- still seals its archive, and the only thing that stops is the half that takes
-- the archive off the host. A club can be months into that before anything says
-- so — which is the failure ADR-0049 exists to prevent, arrived at by a
-- credential quietly ageing out.
--
-- ## What this migration is, and what it is not
--
-- It is a change to the **mail outbox's vocabulary**, in the same shape as 026,
-- 030, 036, 038, 039, 041, 042, 048, 050 and 054: every kind of message the
-- system can queue is a value in this enum, and a kind that is not in it cannot
-- be queued.
--
-- It is **not** backup state in the database. ADR-0049 decision 8 forbids that
-- and this respects it: there is still no backup table, no backup row, no
-- backup id and no audit vocabulary. The subject of this notice is the
-- installation itself (`subject_id` = 1, `MailSubject::INSTANCE_CONFIG`), and
-- what it is about is a value in `config.php` — the one place ADR-0049 says the
-- backup's configuration lives. Restoring an archive cannot revert any of it.
--
-- Worth stating plainly all the same: ADR-0049's *Positive* section used to claim
-- the feature "touches the application's schema nowhere". After this line that is
-- true of the backup's own data and no longer true of the mail outbox, whose
-- schema this widens by one enum value so an existing notification mechanism
-- can carry one more kind of warning. That sentence has been corrected to say so.
--
-- Rollback: db/rollback/055_mail_outbox_backup_secret_expiry.down.sql
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
    'backup_secret_expiry_warning'
) NOT NULL;

-- `settlement_announcements` deliberately does NOT gain this value, for the
-- reason 039 first gave and 054 repeated: that table is the durable proof that
-- a § 7 Abs. 3 announcement was made about a settlement. A credential warning
-- announces nothing, proves nothing and collects nothing.
