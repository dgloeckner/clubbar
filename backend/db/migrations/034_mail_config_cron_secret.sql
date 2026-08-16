-- =============================================================================
-- 034_mail_config_cron_secret.sql — the URL-trigger secret becomes rotatable
-- =============================================================================
-- ADR-0038 rule 3, #473. `cron.secret` was config.php-only since #403: a
-- dedicated secret for the URL drain trigger, generated once by the installer
-- and never touched again short of hand-editing a file the "relocated data
-- directory" layout (ADR-0031 decision 2) may not even expose to a web-facing
-- editor.
--
-- That is a real gap once the secret can leak into a webserver's access log —
-- exactly what happens on a host whose panel offers only a bare URL field with
-- no custom header, so the degraded query-string form is what gets used. An
-- operator who concludes it leaked has no way to rotate it without file
-- access.
--
-- So a second home for it joins `config.php`, on the same terms as the rest of
-- `mail_config`'s operational dials: admin-editable, not editable through the
-- generic PATCH. Unlike `drain_batch_size`, this column *is* a secret, which
-- is why it does not go through `MailConfigRepository::UPDATABLE_COLUMNS`
-- (that generic path also diffs old/new values into the audit log — fine for
-- a batch size, not for anything that authenticates a request) — only a
-- dedicated rotate endpoint ever writes it, and only a SHA-256 hash is stored,
-- matching how terminal API tokens are kept (`TokenService`, ADR-0036 §Terminal
-- tokens): the plaintext is generated, shown once, and never persisted.
--
-- ## Precedence, not replacement
--
-- `config.php`'s `cron.secret` keeps working indefinitely as a fallback for an
-- installation that never rotates from the panel — nothing here invalidates an
-- existing external scheduler's configuration. Once `cron_secret_hash` is set,
-- it takes over as the only accepted credential: rotating supersedes the file
-- value rather than adding a second live one, the same way rotating a terminal
-- token retires the one it replaces after the overlap.
--
-- Rollback: db/rollback/034_mail_config_cron_secret.down.sql
-- =============================================================================

ALTER TABLE mail_config
    ADD COLUMN cron_secret_hash CHAR(64) NULL
        COMMENT 'SHA-256 (hex) of the URL-trigger secret. NULL = none rotated yet; config.php cron.secret is the fallback'
        AFTER drain_batch_size,
    ADD COLUMN cron_secret_rotated_at DATETIME NULL
        COMMENT 'When the admin panel last generated this secret'
        AFTER cron_secret_hash;
