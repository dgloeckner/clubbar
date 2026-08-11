-- =============================================================================
-- 014_totp_replay_protection.sql — reject replayed TOTP codes (#338)
-- =============================================================================
-- TotpService::verifyCode allows a ±1 time-step window (~90s) for clock skew,
-- but nothing recorded which time-step an admin already consumed — a captured
-- code (shoulder-surf, logging proxy, mirrored request) could be replayed as
-- many times as it stayed inside that window.
--
-- `totp_last_timestep` tracks the last TOTP time-step MFA accepted for this
-- admin; AuthController::mfa refuses any code at or before it and records the
-- rejection against the login rate limiter and audit trail.
--
-- Rollback: db/rollback/014_totp_replay_protection.down.sql
-- =============================================================================

ALTER TABLE admin_users
    ADD COLUMN totp_last_timestep BIGINT NULL COMMENT 'Last TOTP time-step accepted by MFA; codes at or before it are rejected as replays';
