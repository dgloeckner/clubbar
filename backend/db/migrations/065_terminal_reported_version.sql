-- =============================================================================
-- 065_terminal_reported_version.sql — terminals report what they are running
-- (#318, ADR-0054)
-- =============================================================================
-- ADR-0054 has a terminal install exactly the version its backend reports, and
-- blacklist a version whose update failed. Those two rules together mean a
-- terminal can stop updating *for good*: exact-match makes the only candidate
-- the tag it just blacklisted, so it sits on the last working version until a
-- release rolls the backend forward. Nothing on the Pi says so, and nobody
-- walks past a kiosk asking what build it runs.
--
-- So reporting is part of the mechanism, not a nice-to-have. Every
-- terminal-authenticated request carries `X-Terminal-Version`, and a terminal
-- that has blacklisted a tag also carries `X-Terminal-Blocked-Version`. Both
-- land here, beside `last_sync_at`, which the same middleware already writes.
--
-- Three columns rather than a `terminal_versions` history table: the admin
-- panel asks one question — *what is this terminal running right now, and is it
-- stuck* — and a history would be a second thing to prune (ADR-0031 has no
-- cron on shared hosting) for an answer nobody has asked for.
--
-- `reported_version_at` is not redundant with `last_sync_at`. The header is
-- fail-open: a terminal that omits it, or sends something unparseable, syncs
-- normally and leaves these columns untouched — so `last_sync_at` can be
-- minutes old while the version stamp is from the last release the terminal
-- was new enough to report. Reading a stale version as current would be worse
-- than reading no version at all.
--
-- VARCHAR(64) holds `v<major>.<minor>.<patch>` with room for a pre-release
-- suffix; anything longer is not a tag this project has ever cut, and the
-- backend refuses to store what it cannot parse.
--
-- DATETIME for the same reasons as 011 and 021: no auto-update behaviour, no
-- 2038 wall.
--
-- No backfill. A NULL here means "has not reported yet", which is exactly true
-- of every terminal until it next syncs with a build that carries the header,
-- and `TerminalVersionState::UNKNOWN` is what the panel shows for it.
--
-- Rollback: db/rollback/065_terminal_reported_version.down.sql
-- =============================================================================

ALTER TABLE terminals
    ADD COLUMN reported_version    VARCHAR(64) NULL COMMENT 'Last X-Terminal-Version this terminal sent (ADR-0054)' AFTER last_sync_at,
    ADD COLUMN reported_version_at DATETIME NULL COMMENT 'When that version was last seen; NULL while nothing parseable has arrived' AFTER reported_version,
    ADD COLUMN blocked_version     VARCHAR(64) NULL COMMENT 'Tag whose update failed on this terminal and will never be retried there' AFTER reported_version_at;
