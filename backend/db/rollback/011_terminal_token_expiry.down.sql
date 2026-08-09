-- =============================================================================
-- 011_terminal_token_expiry.down.sql — rollback (#106)
-- =============================================================================
-- Drops the terminal token lifetime columns.
--
-- ⚠️ Security regression: without `token_expires_at` every issued terminal
-- token is valid again until an admin revokes that terminal by hand — the state
-- #106 was filed about. Roll back only to unblock a failed deployment, and
-- re-apply 011 afterwards.
--
-- The columns carry no data that cannot be reconstructed: rotating a terminal's
-- token reissues both values.
-- =============================================================================

ALTER TABLE terminals
    DROP COLUMN token_expires_at,
    DROP COLUMN token_issued_at;
