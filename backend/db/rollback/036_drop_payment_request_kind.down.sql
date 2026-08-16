-- Rollback for migration 036: put `payment_request` back in both enums.
--
-- Restores the schema, not the decision. Nothing writes this kind — the code
-- that could have has been removed with it — so a rolled-back installation gets
-- a value in the list and no way to reach it, exactly as it was before 034.
--
-- Rolling this back deletes nothing: 034 could only ever have deleted rows that
-- cannot exist, so there is nothing to restore.

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning'
) NOT NULL;

ALTER TABLE settlement_announcements MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request'
) NOT NULL;
