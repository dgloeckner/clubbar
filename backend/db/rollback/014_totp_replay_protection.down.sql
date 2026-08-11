-- =============================================================================
-- 014_totp_replay_protection.down.sql — rollback (#338)
-- =============================================================================
-- Drops the TOTP replay-tracking column. Losing it re-opens the replay window
-- described in #338 (no other consequence — the column carries no other data).
-- =============================================================================

ALTER TABLE admin_users DROP COLUMN totp_last_timestep;
