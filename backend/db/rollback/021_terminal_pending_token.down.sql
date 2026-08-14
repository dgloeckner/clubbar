-- Rollback of 021_terminal_pending_token.sql (#395).
--
-- Dropping these columns discards any rotation that is mid-flight: a pending
-- token an admin has already written down but not yet entered at the terminal
-- stops existing, and they have to rotate again after the rollback. The active
-- credential is untouched, so no terminal loses access.

DROP INDEX idx_terminals_pending_token_hash ON terminals;

ALTER TABLE terminals
    DROP COLUMN pending_token_expires_at,
    DROP COLUMN pending_token_issued_at,
    DROP COLUMN pending_token_hash;
