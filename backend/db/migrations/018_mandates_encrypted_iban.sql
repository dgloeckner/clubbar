-- ADR-0036: sealed-box IBAN storage on the mandate record.
--
-- New writes fill iban_ciphertext (libsodium sealed box, 'v1:' + base64),
-- iban_last4 (routine display without decryption), iban_fingerprint (keyed
-- BLAKE2b — bank-change detection without decryption capability),
-- encryption_key_id (which key generation sealed this row) and bank_name
-- (resolved from the BLZ at write time — the list view can no longer derive
-- it from a stored plaintext IBAN).
--
-- The legacy plaintext `iban` column becomes nullable and stays until every
-- install has run the admin "encrypt existing IBANs" batch action, which
-- seals each row and NULLs its plaintext. Dropping the column is a later
-- release (tracked as a follow-up), after which no plaintext IBAN exists in
-- the database at all.

ALTER TABLE mandates
    MODIFY iban VARCHAR(34) NULL,
    ADD COLUMN iban_ciphertext VARBINARY(512) NULL AFTER iban,
    ADD COLUMN iban_last4 CHAR(4) NULL AFTER iban_ciphertext,
    ADD COLUMN iban_fingerprint CHAR(64) NULL AFTER iban_last4,
    ADD COLUMN encryption_key_id CHAR(36) NULL AFTER iban_fingerprint,
    ADD COLUMN bank_name VARCHAR(255) NULL AFTER encryption_key_id,
    ADD CONSTRAINT fk_mandates_encryption_key
        FOREIGN KEY (encryption_key_id) REFERENCES encryption_keys(id);
