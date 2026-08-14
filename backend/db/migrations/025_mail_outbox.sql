-- =============================================================================
-- 025_mail_outbox.sql — the transactional outbox and the scheduler heartbeat
-- =============================================================================
-- ADR-0038. Finalizing a settlement writes rows here inside the settlement's
-- own transaction and makes no network call; a scheduler drains them (#403) and
-- is the only sender.
--
-- Two tables in one migration on purpose. `cron_heartbeat` is written by the
-- drain (#403) and read by the install gate (#405) and the monitoring (#406),
-- none of which exist yet — but the schema is one decision and splitting it
-- across three migrations would mean three chances to disagree with itself.
--
-- The three things worth knowing before reading the DDL:
--
--   UNIQUE (kind, settlement_id, member_id)
--       This is the whole of the "finalize retry does not duplicate emails"
--       guarantee. Idempotency lives on the *message*, not on the settlement,
--       and it is enforced by the database rather than by a lookup-then-insert
--       that two concurrent requests can both win.
--
--   No body column
--       Content is rendered from settlement data at send time (ADR-0038 rule
--       5). That is safe because ADR-0032 makes a settlement append-only, so
--       the amount, the line items and the execution date cannot drift between
--       enqueue and send. GoBD asks that the per-member Abrechnung be
--       reproducible from the stored record, not that the email be archived —
--       and storing bodies would multiply the copies of member PII for no
--       evidentiary gain.
--
--   `recipient` is a snapshot, and it is the one exception to the rule above
--       The address is the single fact that is *not* reproducible later:
--       `members.email` can change or be erased. It is proof of who was
--       announced to. That also makes this table a second place a member
--       address lives, which is why ADR-0029 puts it in scope for erasure
--       (#408) — without that, anonymisation would clear members.email and
--       leave this column behind.
--
-- Rollback: db/rollback/025_mail_outbox.down.sql
-- =============================================================================

CREATE TABLE mail_outbox (
    id CHAR(36) NOT NULL PRIMARY KEY,

    -- `payment_request` is reserved for #410 (bank_transfer settlements: amount
    -- and club bank details, no mandate reference). Nothing enqueues it yet.
    kind ENUM('sepa_prenotification', 'cancellation_notice', 'payment_request') NOT NULL,

    settlement_id CHAR(36) NOT NULL,
    member_id CHAR(36) NOT NULL,

    -- Snapshot, not a join. See the header: proof of who was announced to.
    recipient VARCHAR(255) NOT NULL,

    -- From members.preferred_language with a `de` fallback (ADR-0002). Frozen
    -- at enqueue so a language change between enqueue and send cannot make the
    -- announcement arrive in a language the member did not have when the
    -- collection was decided.
    language CHAR(2) NOT NULL DEFAULT 'de',

    -- `superseded` is what a cancellation does to an announcement that never
    -- went out: there is nothing to retract, so nothing is sent and the row is
    -- closed rather than deleted (ADR-0038, cancellation flow).
    status ENUM('pending', 'sent', 'failed', 'superseded') NOT NULL DEFAULT 'pending',

    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- Backoff after a transient failure — greylisting above all, which is
    -- ordinary operation rather than an error. Defaulted to the epoch-ish past
    -- rather than NULL so the claim predicate stays a plain comparison.
    next_attempt_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',

    -- The reason the Kassenwart reads in the settlement detail (#407). Best
    -- effort means visible, not chased.
    last_error TEXT NULL,

    -- Claim by UPDATE, then SELECT by token — deliberately not
    -- `SELECT ... FOR UPDATE SKIP LOCKED`, which needs MariaDB 10.6+, and on
    -- mass hosting the database version belongs to the host (ADR-0038).
    claim_token CHAR(36) NULL,
    claimed_at DATETIME NULL,

    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,

    -- The transport's handle, for correlating with an MTA log.
    message_id VARCHAR(255) NULL,

    CONSTRAINT uq_mail_outbox_message UNIQUE (kind, settlement_id, member_id),

    CONSTRAINT fk_mail_outbox_settlement FOREIGN KEY (settlement_id)
        REFERENCES settlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_outbox_member FOREIGN KEY (member_id)
        REFERENCES members(id) ON DELETE CASCADE,

    -- The drain's claim predicate, in its own column order.
    INDEX idx_mail_outbox_claim (status, next_attempt_at, claimed_at),
    -- The settlement detail (#407) and the cancellation path both read by
    -- settlement.
    INDEX idx_mail_outbox_settlement (settlement_id, status),
    -- Erasure (#408) finds a member's rows; pruning finds old sent ones.
    INDEX idx_mail_outbox_member (member_id),
    INDEX idx_mail_outbox_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- cron_heartbeat — one row, written by every drain run
-- -----------------------------------------------------------------------------
-- Read by three different consumers for three different questions:
--
--   #405  "has a scheduled run ever been observed?" — the install gate keys on
--         `last_run_at IS NOT NULL`, and once true it stays true. A scheduler
--         that dies later warns; it does not lock the treasurer out of a
--         collection.
--   #406  "is the queue moving?" — last run plus the oldest unsent row.
--   #407  the self-check's diagnosis surface.
--
-- `php_version` is here because the panel's CLI PHP is frequently not the web
-- PHP — different version, different extensions, different ini — and that
-- difference should be visible rather than mysterious when a cron works from
-- the browser and not from the crontab.
CREATE TABLE cron_heartbeat (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    last_run_at DATETIME NULL,
    -- How the run was triggered. `cli` is preferred: no gateway timeout, no
    -- secret in a URL.
    source ENUM('cli', 'url') NULL,
    sent INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    php_version VARCHAR(32) NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The singleton exists from the start with `last_run_at` NULL, which is the
-- honest initial state: the row is there, and it says no run has been seen.
-- An absent row and an unobserved scheduler would otherwise be the same thing
-- to every reader.
INSERT INTO cron_heartbeat (id) VALUES (1);

-- -----------------------------------------------------------------------------
-- Audit actions (ADR-0013)
-- -----------------------------------------------------------------------------
-- Enqueueing announcements and enqueueing cancellation notices are both events
-- the club has to be able to reconstruct: the first is the record that a
-- collection was announced at all, the second that the retraction went to
-- exactly the members who had been told.
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
    'mail_superseded'
) NOT NULL;
