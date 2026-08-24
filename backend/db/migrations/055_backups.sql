-- =============================================================================
-- 055_backups.sql — encrypted off-site backups (ADR-0049)
-- =============================================================================
-- Three additive tables. No existing table is altered, and nothing here is on
-- the path of any request the club makes: a backup is a scheduled job that
-- reads everything and writes only to itself.
--
-- ## Why `backup_runs` rows are never deleted
--
-- Pruning an artifact stamps `pruned_at` and keeps the row, because the row is
-- what answers *"which private keys do we still need?"* after the file is gone.
-- A rotation retires a key by *waiting* rather than by deleting (ADR-0049
-- decision 3): archives sealed to the old key age out on their own, 30 days
-- local and 90 remote, and until the last of them has gone the old private key
-- must not be discarded or those archives die silently. `key_fingerprints` on a
-- pruned row is what lets the panel say "nothing needs key A any more" instead
-- of leaving a volunteer to guess.
--
-- The row also outlives the artifact by a factor of about a million, so keeping
-- it costs nothing that matters.
--
-- ## Why `backup_keys` is a projection and not a second truth
--
-- The configured keys live in `config.php` (`backup.public_keys`), off the
-- database, for the same reason the SMTP DSN does. This table records what was
-- *observed*: a fingerprint appears the first time an archive is sealed to it,
-- and gains `verified_at` when somebody proves they can open a real archive
-- with the matching private half.
--
-- The one column that is not a projection is `compromised_at`, and it
-- deliberately **outranks `config.php`** — the same precedence
-- `mail_config.cron_secret_hash` already has over `cron.secret` (#473). A key
-- marked compromised from the panel must stop being sealed to *now*, without
-- editing a file over FTP, and must not come back because the file still names
-- it.
--
-- ## `backup_config`, and what is deliberately not in it
--
-- Retention and the run's budget are club policy, so they are editable and
-- live here, mirroring `mail_config`. The **minimum interval** between runs is
-- not: it exists only to stop a public endpoint being called in a loop until
-- the webspace quota is full, it is not a schedule anybody should tune, and a
-- club that could raise it could disable the guard. It is compiled in.
-- =============================================================================

CREATE TABLE backup_runs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL DEFAULT NULL,

    -- `local` is a complete, sealed archive on the webspace; `uploaded` is one
    -- that also reached the remote. They are separate states because the split
    -- is real: a snapshot is atomic and a transfer is resumable, so a run can
    -- legitimately end at `local` and be finished by a later one (#691).
    status ENUM('running', 'local', 'uploaded', 'failed') NOT NULL DEFAULT 'running',
    trigger_source ENUM('cli', 'url') NOT NULL,

    filename VARCHAR(255) NULL,
    bytes BIGINT UNSIGNED NULL,
    -- Of the sealed archive as it sits on disk, so "is this the file we wrote"
    -- is answerable without a private key.
    sha256 CHAR(64) NULL,

    -- Which keys open this archive, and what was in it. Both outlive the file.
    key_fingerprints JSON NULL,
    table_manifest JSON NULL,

    remote_path VARCHAR(1024) NULL,
    upload_session_url VARCHAR(2048) NULL,
    uploaded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP NULL DEFAULT NULL,

    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP NULL DEFAULT NULL,
    last_error VARCHAR(1000) NULL,

    -- Set when the artifact is removed. The row stays.
    pruned_at TIMESTAMP NULL DEFAULT NULL,

    -- "The most recent successful run" and "what is still on disk" are the two
    -- questions asked on every tick.
    KEY idx_backup_runs_started_at (started_at),
    KEY idx_backup_runs_status_started (status, started_at),
    KEY idx_backup_runs_pruned_at (pruned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_keys (
    -- SHA-256 of the 32-byte public key, as the archive header carries it.
    fingerprint CHAR(64) NOT NULL PRIMARY KEY,
    label VARCHAR(64) NOT NULL,
    first_seen_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,

    -- Set when a holder has actually opened a real archive with the private
    -- half. Until then the panel says the key is unverified, because a
    -- recipient nobody has ever decrypted with is a belief, not a recipient.
    verified_at TIMESTAMP NULL DEFAULT NULL,

    -- A blocklist that outranks config.php. See the header.
    compromised_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,

    -- Off by default, and this is not timidity: an installation that has not
    -- configured a recipient key cannot write an archive at all (ADR-0049
    -- decision 2 fails closed), so defaulting to on would produce a nightly
    -- failure rather than a nightly backup.
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    cadence ENUM('daily', 'weekly') NOT NULL DEFAULT 'daily',

    local_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    -- 1 GiB. Refused rather than exceeded: a full webspace quota breaks
    -- logging and mandate storage, which are not the backup's to break.
    local_max_bytes BIGINT UNSIGNED NOT NULL DEFAULT 1073741824,
    remote_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,

    -- The whole 60 s abort minus room to finish and record. The mail drain's
    -- separate budget is why this one does not have to be a slice of anything.
    budget_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 45,

    updated_by_admin_id CHAR(36) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_backup_config_admin FOREIGN KEY (updated_by_admin_id)
        REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO backup_config (id) VALUES (1);

-- -----------------------------------------------------------------------------
-- Audit vocabulary for the backup key lifecycle, and for the one post-restore
-- action a shared host cannot otherwise perform.
-- -----------------------------------------------------------------------------
-- The enum is respelled in full because MODIFY COLUMN replaces the definition
-- rather than extending it — an abbreviated list would silently drop every
-- action added since. Same reason 037 gives.
--
-- Four key actions (pattern-016). Two of them have no caller yet: the panel
-- that verifies and retires a key is #693's. They are declared here anyway, so
-- that slice adds a route rather than a second migration, and so a compromise
-- recorded by hand in the meantime has a name the audit log accepts.
--
-- `bank_codes_imported` is not about keys. `bank_codes` is SCHEMA_ONLY, so a
-- restored installation comes back with an empty lookup table and no way to
-- refill it: `install.php` 403s once `storage/.installed` exists, and
-- `bin/import-bank-codes.php` needs a shell the reference host does not have.
-- Refilling it is therefore an admin action, and an admin action that reaches
-- out to the Bundesbank and rewrites a table is one worth recording.

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create',
    'update',
    'delete',
    'anonymize',
    'login',
    'logout',
    'login_failed',
    'export',
    'settlement_create',
    'settlement_cancel',
    'settlement_export',
    'settlement_submit',
    'settlement_reverse',
    'transaction_storno',
    'transaction_price_divergence',
    'collection_hold_placed',
    'collection_hold_cleared',
    'activate',
    'deactivate',
    'reorder',
    'totp_enrolled',
    'totp_reset',
    'mandate_document_upload',
    'mandate_document_delete',
    'terminal_repair',
    'key_registered',
    'key_activated',
    'key_rotation_started',
    'key_rotation_batch_completed',
    'key_rotation_completed',
    'key_retired',
    'key_revoked',
    'key_marked_compromised',
    'sepa_export',
    'iban_full_view',
    'terminal_token_created',
    'terminal_token_activated',
    'terminal_token_rotated',
    'terminal_token_revoked',
    'terminal_token_expired',
    'mail_enqueued',
    'mail_superseded',
    'mail_retried',
    'mail_test_sent',
    'terminal_anomaly_detected',
    'terminal_anomaly_acknowledged',
    'cron_secret_rotated',
    'password_changed',
    'email_changed',
    'role_granted',
    'role_revoked',
    'jugendschutz_violation',
    'jugendschutz_violation_acknowledged',
    'bank_codes_imported',
    'backup_key_added',
    'backup_key_verified',
    'backup_key_removed',
    'backup_key_marked_compromised'
) NOT NULL;
