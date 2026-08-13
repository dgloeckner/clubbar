-- Rollback of 016_mandates_encrypted_iban.sql.
--
-- SAFE ONLY while no row has been sealed (iban_ciphertext still NULL
-- everywhere): dropping the ciphertext columns destroys any IBAN whose
-- plaintext was already NULLed by the batch encryption. After the batch has
-- run, the only rollback is restoring the pre-upgrade database backup
-- (ADR-0035).

ALTER TABLE mandates
    DROP FOREIGN KEY fk_mandates_encryption_key,
    DROP COLUMN bank_name,
    DROP COLUMN encryption_key_id,
    DROP COLUMN iban_fingerprint,
    DROP COLUMN iban_last4,
    DROP COLUMN iban_ciphertext,
    MODIFY iban VARCHAR(34) NOT NULL;
