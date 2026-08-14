-- =============================================================================
-- 027_cron_heartbeat_extensions.sql — what the drain's interpreter was missing
-- =============================================================================
-- ADR-0038 / #403. Migration 025 already records the drain's PHP *version*,
-- because the panel's CLI PHP is frequently not the web PHP. Version alone does
-- not explain the failure that actually happens, though: the two interpreters
-- also load different extensions from different ini files, and a cron that runs
-- under a PHP without `openssl` cannot open a TLS connection to the mail server
-- while the same code, reached through the browser, can.
--
-- That failure is otherwise invisible. The run starts, claims its batch, fails
-- every send with a transport error and writes a heartbeat saying it ran — so
-- the install gate (#405) is satisfied and the monitoring (#406) sees liveness.
-- Recording the gap makes the answer readable in the one place somebody is
-- already looking.
--
-- The column holds the extensions this application requires that the *drain's*
-- interpreter did not have, comma-separated. NULL means the run predates this
-- column; the empty string means the run checked and found nothing missing —
-- which is a different statement and worth keeping apart.
--
-- Rollback: db/rollback/027_cron_heartbeat_extensions.down.sql
-- =============================================================================

ALTER TABLE cron_heartbeat
    ADD COLUMN missing_extensions VARCHAR(255) NULL AFTER php_version;
