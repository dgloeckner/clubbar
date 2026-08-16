-- =============================================================================
-- 036_admin_credentials_epoch.sql — sessions stop outliving the credential
-- =============================================================================
-- ADR-0015 / ADR-0026. Until now, changing a password or resetting an admin's
-- 2FA left every other session on that account untouched: the victim of a
-- hijacked session could rotate their password and the attacker kept working.
-- ADR-0026 recorded that as a deliberate trade-off, on the grounds that
-- invalidating those sessions "would require server-side session enumeration".
--
-- It does not. The `sessions` table that reasoning assumed is never written to
-- — auth uses native PHP sessions and no save handler is installed — so
-- enumeration was never available to begin with. What works instead is an
-- epoch: one timestamp per account, compared in `AdminSessionAuth` against the
-- `authenticated_at` stamp `SessionTimeout::begin()` already writes into every
-- session at login. A session older than the epoch is refused. No enumeration,
-- no session store, one column.
--
-- NULL means "no credential change has been recorded", which every existing
-- row is: a deployment must not sign every admin out. Only a change written
-- after this migration sets it.
--
-- Second granularity is enough because the comparison treats a tie as stale: a
-- session authenticated in the same second as the change is refused along with
-- every older one, so the column's resolution leaves no window. The request
-- that performs the change survives by being re-stamped one second *ahead* of
-- the epoch, which is the only reason it is not caught by its own write.
--
-- Rollback: db/rollback/036_admin_credentials_epoch.down.sql
-- =============================================================================

ALTER TABLE admin_users
    ADD COLUMN credentials_changed_at TIMESTAMP NULL
        COMMENT 'Sessions authenticated before this are refused (password change, email change, 2FA reset)'
        AFTER last_login_at;
