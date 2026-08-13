-- =============================================================================
-- 020_drop_legacy_plaintext_iban.sql — remove the plaintext IBAN column (ADR-0036)
-- =============================================================================
-- 018 kept `mandates.iban` as a nullable column so an existing install could
-- run the batch encryption and seal what it already held. There is no such
-- install: the system has not shipped, so nothing anywhere holds a plaintext
-- IBAN that needs migrating, and the batch encryption it existed for has been
-- removed along with this column.
--
-- Dropping it is what makes the central claim structural rather than
-- conditional. While the column existed, "the server holds no private key and
-- cannot read an IBAN" was true only for as long as the column stayed empty —
-- and one code path (the SEPA export's legacy fallback) would have returned
-- its contents without asking for a key at all. With the column gone there is
-- no shape a row can take that yields a readable IBAN without the private half
-- of the club's keypair.
--
-- Any value still in the column is discarded. That is intentional and was
-- confirmed: this is pre-release test data.
--
-- Rollback: db/rollback/020_drop_legacy_plaintext_iban.down.sql — restores the
-- column, empty. The values are not recoverable, which is the point.
-- =============================================================================

ALTER TABLE mandates
    DROP COLUMN iban;
