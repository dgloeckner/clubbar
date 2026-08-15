-- =============================================================================
-- 028_sepa_mandate_template_url.sql — the externally hosted registration form
-- =============================================================================
-- ADR-0007 / ADR-0011. #360 moved the blank SEPA mandate form out of the app:
-- the club now maintains a statically hosted registration form elsewhere, and
-- the admin panel only needs to know where it lives so it can link to it and
-- warn when nobody has said yet.
--
-- Nullable and unvalidated for format, matching the mail_config precedent
-- (website_url, logo_url in migration 024) rather than the encrypted IBAN
-- columns beside it — this is a link, not a secret.
--
-- Rollback: db/rollback/028_sepa_mandate_template_url.down.sql
-- =============================================================================

ALTER TABLE sepa_config
    ADD COLUMN mandate_template_url VARCHAR(255) NULL AFTER payment_reference_prefix;
