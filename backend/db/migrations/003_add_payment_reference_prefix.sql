-- =============================================================================
-- Migration: Add payment_reference_prefix to sepa_config
--
-- Adds a configurable prefix for the SEPA "Verwendungszweck" (remittance info).
-- The settlement date will be appended automatically by the system.
-- Example: "Ruderbar Abrechnung" -> "Ruderbar Abrechnung 2024-01-15"
-- =============================================================================

ALTER TABLE sepa_config
ADD COLUMN payment_reference_prefix VARCHAR(100) NULL
AFTER creditor_address_country;
