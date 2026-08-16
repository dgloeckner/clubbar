-- Rollback for migration 041: the issuance notice leaves the schema again.
--
-- The DELETE runs *before* the MODIFY, for the reason 039's rollback states:
-- `MODIFY COLUMN … ENUM(…)` is a replacement, and a row holding a value the new
-- list omits is silently truncated to the empty string rather than refused. A
-- queue full of empty-string kinds is worse than one that refused to roll back.
--
-- Rolling back does lose any queued or delivered issuance notices. That is the
-- correct trade here: the durable record of every minting is the
-- `terminal_token_created` / `terminal_token_rotated` audit entry, which this
-- does not touch — an outbox row is the copy that was mailed, not the record
-- that it happened.

DELETE FROM mail_outbox WHERE kind = 'terminal_token_issued';

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'admin_email_changed',
    'deckel_statement'
) NOT NULL;
