-- =============================================================================
-- Rollback for 020_drop_legacy_plaintext_iban.sql
-- =============================================================================
-- Restores the column so an older release can start against this schema. It
-- comes back empty: the plaintext the column used to hold was discarded when it
-- was dropped and cannot be recovered from `iban_ciphertext` without the club's
-- private key, which the server never has.
--
-- A release that needs this column populated has to re-derive it from a SEPA
-- export taken with the private key, by hand.
-- =============================================================================

ALTER TABLE mandates
    ADD COLUMN iban VARCHAR(34) NULL AFTER reference;
