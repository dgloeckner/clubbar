-- Rollback for 053_drop_settlement_sepa_message_id.sql
--
-- Restores the column, empty. The original values are not recoverable and are
-- not worth recovering: each was a random string with no relationship to the
-- settlement it named, and the export's own fallback (`MSG-` + 31 hex of the
-- settlement UUID) already covered a NULL. Existing rows therefore keep
-- exporting under an id derived from the settlement, which is what the forward
-- migration made permanent.
ALTER TABLE settlements ADD COLUMN sepa_message_id VARCHAR(35) NULL UNIQUE AFTER period_end;
