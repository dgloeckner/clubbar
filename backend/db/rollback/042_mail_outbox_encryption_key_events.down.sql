-- Rollback for migration 042: key lifecycle notices leave the outbox again.
--
-- The list restored below is 041's, `terminal_token_issued` included. Reverting
-- this migration must put the column back as 041 left it, not as 039 did — a
-- rollback that dropped ADR-0043's value would truncate every queued issuance
-- notice to the empty string, which is the same silent damage the forward
-- migration was renumbered to avoid.
--
-- The DELETE runs *before* the MODIFY, for the reason 039's rollback spells
-- out: `MODIFY COLUMN … ENUM(…)` is a replacement, and a row holding a value
-- the new list omits is silently truncated to the empty string rather than
-- refused. A queue full of empty-string kinds is worse than a rollback that
-- declined to run.
--
-- Rolling back does lose any queued or delivered key notices. That is the
-- correct trade: what they announce is durably recorded as `key_registered` /
-- `key_activated` / `key_revoked` in the audit log, which this does not touch,
-- and the mail row is only ever a copy of that reaching somebody who was not
-- the one acting.

DELETE FROM mail_outbox WHERE kind IN (
    'encryption_key_registered',
    'encryption_key_activated',
    'encryption_key_revoked'
);

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'terminal_token_issued',
    'admin_email_changed',
    'deckel_statement'
) NOT NULL;
