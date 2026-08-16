-- Rollback for migration 039: the Deckelauszug leaves the schema again.
--
-- Order matters here in a way it usually does not. The DELETE runs *before* the
-- MODIFY, because `MODIFY COLUMN … ENUM(…)` is a replacement: a row holding a
-- value the new list omits is silently truncated to the empty string rather
-- than refused, and a queue full of empty-string kinds is worse than one that
-- refused to roll back. Unlike migration 036's equivalent DELETE, this one can
-- genuinely match rows — an installation that had the cadence switched on has
-- statements in its outbox — so rolling back does lose them. That is the
-- correct trade: they announce nothing and prove nothing, which is the same
-- reason ADR-0039 decision 6 prunes the delivered ones at 90 days.

DELETE FROM mail_outbox WHERE kind = 'deckel_statement';

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'admin_email_changed'
) NOT NULL;

ALTER TABLE mail_config DROP COLUMN statement_cadence;
