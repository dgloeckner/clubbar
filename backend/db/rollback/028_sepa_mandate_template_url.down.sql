-- Rollback for migration 028: the externally hosted registration form's URL
--
-- Dropping this loses the configured link, not any transactional state.
ALTER TABLE sepa_config DROP COLUMN mandate_template_url;
