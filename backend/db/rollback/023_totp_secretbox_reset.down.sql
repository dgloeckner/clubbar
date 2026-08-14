-- =============================================================================
-- Rollback for 023_totp_secretbox_reset.sql
-- =============================================================================
-- The data reset is irreversible: the AES-256-CBC ciphertexts the migration
-- cleared are gone, and TOTP secrets cannot be re-derived from anything the
-- database still holds (unlike an IBAN, there is no offline key that could
-- ever recover them — they were random to begin with).
--
-- A release that needs the pre-cutover column comment back can restore it;
-- the data itself only comes back through re-enrollment.
-- =============================================================================

ALTER TABLE admin_users
    MODIFY COLUMN totp_secret VARCHAR(255) NULL
    COMMENT 'AES-256-CBC encrypted TOTP secret (base64(iv):base64(ciphertext))';
