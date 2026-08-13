-- ADR-0035: IBAN encryption at rest with libsodium sealed boxes.
-- Key METADATA only — the database never contains a private key. The public
-- key is stored raw (32 bytes); the SHA-256 fingerprint identifies a key to
-- humans and validates a supplied private key against its registered public
-- half.
--
-- Lifecycle: pending -> active -> retiring -> retired, plus revoked and
-- compromised. Exactly one operational ACTIVE key at a time (enforced in
-- EncryptionKeyService, not by schema — MariaDB has no partial unique index).
-- expires_at = activated_at + 365 days; there is no extend operation.

CREATE TABLE IF NOT EXISTS encryption_keys (
    id CHAR(36) PRIMARY KEY,
    key_identifier VARCHAR(100) NOT NULL UNIQUE,
    algorithm VARCHAR(50) NOT NULL DEFAULT 'SODIUM_CRYPTO_BOX_SEAL',
    public_key VARBINARY(32) NOT NULL,
    fingerprint_sha256 CHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'active', 'retiring', 'retired', 'revoked', 'compromised') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at DATETIME NULL,
    expires_at DATETIME NULL,
    retired_at DATETIME NULL,
    created_by_admin_id CHAR(36) NULL,
    INDEX idx_encryption_keys_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
