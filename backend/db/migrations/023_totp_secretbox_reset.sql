-- =============================================================================
-- 023_totp_secretbox_reset.sql — reset TOTP secrets for the secretbox cutover (#437)
-- =============================================================================
-- TotpService moved from a hand-rolled AES-256-CBC path to SymmetricSecretBox
-- (libsodium secretbox), the shared primitive it now has in common with
-- IbanSealedBox (ADR-0038). The two ciphertext formats are incompatible and
-- there is no dual-read path: no production database exists yet, so rather
-- than carry two decrypt branches indefinitely, every existing TOTP secret is
-- reset here.
--
-- This has the same effect as AdminUsersRepository::clearTotp() — the
-- mandatory-2FA login flow (ADR-0026) already turns totp_enabled = 0 into a
-- forced re-enrollment on the affected admin's next login, so no application
-- code change is needed to make this land safely.
--
-- Rollback: db/rollback/023_totp_secretbox_reset.down.sql (irreversible — see
-- that file for why).
-- =============================================================================

UPDATE admin_users
SET totp_secret = NULL, totp_enabled = 0, totp_last_timestep = NULL, updated_at = NOW()
WHERE totp_secret IS NOT NULL;

ALTER TABLE admin_users
    MODIFY COLUMN totp_secret VARCHAR(255) NULL
    COMMENT 'libsodium secretbox encrypted TOTP secret (v1:base64(nonce . ciphertext), #437)';
