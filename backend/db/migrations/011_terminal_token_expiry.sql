-- =============================================================================
-- 011_terminal_token_expiry.sql — terminal API tokens expire (#106)
-- =============================================================================
-- `API_TOKEN_TTL_DAYS` has been read into AppConfig since the first release and
-- used nowhere: a terminal token was valid until an admin revoked that one
-- terminal by hand. A token lifted from a decommissioned, resold or stolen POS
-- device therefore kept the member roster and the transaction-write endpoints
-- open indefinitely.
--
-- Two columns, not one. `token_expires_at` is what authentication checks;
-- `token_issued_at` records when the current token was minted, which
-- `updated_at` cannot answer because every sync bumps it.
--
-- DATETIME rather than TIMESTAMP: a deployment with a long TTL would push a
-- TIMESTAMP past its 2038 limit, and these columns want no auto-update
-- behaviour.
--
-- Rollback: db/rollback/011_terminal_token_expiry.down.sql
-- =============================================================================

ALTER TABLE terminals
    ADD COLUMN token_issued_at  DATETIME NULL COMMENT 'When the current api_token_hash was issued' AFTER api_token_hash,
    ADD COLUMN token_expires_at DATETIME NULL COMMENT 'After this instant the token no longer authenticates' AFTER token_issued_at;

-- Backfill from `created_at`, the closest record of when a terminal's token was
-- first handed out — `updated_at` moves on every sync and would hand every
-- active terminal a fresh lifetime. 90 days is the `API_TOKEN_TTL_DAYS`
-- default; SQL cannot read the environment, and a deployment running a shorter
-- TTL is free to rotate early.
--
-- A terminal whose token is already older than that becomes expired the moment
-- this migration runs, which is the point: those are exactly the tokens that
-- have been outliving their devices. Rotating in the admin panel reissues one.
UPDATE terminals
   SET token_issued_at  = COALESCE(created_at, NOW()),
       token_expires_at = COALESCE(created_at, NOW()) + INTERVAL 90 DAY
 WHERE api_token_hash IS NOT NULL;
