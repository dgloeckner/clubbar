-- Rollback for migration 030: terminal credential anomaly detection (ADR-0041)
--
-- Dropping these loses every recorded sighting, every cursor high-water mark,
-- and every anomaly an admin has not yet acknowledged. None of it is
-- transactional state — it is observation about credentials, rebuilt from
-- traffic within one lookback window of the tables coming back — but an
-- unacknowledged anomaly is a warning nobody will now see.
--
-- The `mail_outbox.kind` value goes back too. Any queued but unsent
-- `terminal_anomaly_warning` row must be cleared first, or the ALTER truncates
-- it to the empty string and the drain will fail to build a message for it.

DELETE FROM mail_outbox WHERE kind = 'terminal_anomaly_warning';

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request',
    'key_expiry_warning',
    'terminal_token_expiry_warning'
) NOT NULL;

DROP TABLE IF EXISTS terminal_anomalies;
DROP TABLE IF EXISTS terminal_sync_cursors;
DROP TABLE IF EXISTS terminal_ip_sightings;
