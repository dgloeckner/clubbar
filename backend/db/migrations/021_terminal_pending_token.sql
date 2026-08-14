-- =============================================================================
-- 021_terminal_pending_token.sql — overlap rotation for terminal tokens (#395)
-- =============================================================================
-- Rotating a terminal token used to be a cut: the moment an admin pressed the
-- button the old token stopped working, and the bar could not sell again until
-- somebody walked to the device and typed the new one in. With the lifetime now
-- 365 days (ADR-0036) that cut lands once a year on a device nobody is standing
-- next to, so rotation has to be something an admin can prepare in advance.
--
-- Overlap rotation: the new token is issued PENDING alongside the ACTIVE one.
-- Both are live. The first successful authentication with the pending token
-- promotes it — it becomes the active hash and the old one is overwritten, and
-- therefore retired, in the same statement.
--
-- Three columns on `terminals`, not a `terminal_tokens` child table. A terminal
-- has at most two live credentials at any instant — the one in the field and
-- the one being rolled out — so a child table would buy a generality nobody
-- needs and turn the single indexed lookup that authenticates every sync into a
-- join. The mirrored column names keep promotion a straight column copy.
--
-- DATETIME for the same reasons as 011: no auto-update behaviour, no 2038 wall.
--
-- Rollback: db/rollback/021_terminal_pending_token.down.sql
-- =============================================================================

ALTER TABLE terminals
    ADD COLUMN pending_token_hash       VARCHAR(255) NULL COMMENT 'SHA-256 of a token issued but not yet used; promoted on first successful auth (#395)' AFTER token_expires_at,
    ADD COLUMN pending_token_issued_at  DATETIME NULL COMMENT 'When the pending token was minted' AFTER pending_token_hash,
    ADD COLUMN pending_token_expires_at DATETIME NULL COMMENT 'Lifetime the pending token carries into its promotion' AFTER pending_token_issued_at;

-- Authentication falls through to this column on every request that the active
-- hash did not match, so it needs the same indexed lookup the active hash has.
CREATE INDEX idx_terminals_pending_token_hash ON terminals (pending_token_hash);
