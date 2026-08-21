-- =============================================================================
-- 051_jugendschutz_violation_acks.sql — a human has seen this one
-- =============================================================================
-- #622, ADR-0045 §3. M7 (migration 050) made a sale to a minor a permanent,
-- append-only `jugendschutz_violation` audit entry. What it did not do is tell
-- anybody: finding one means opening the audit log and filtering by action,
-- which requires already suspecting it happened — and that log is `admin`-only
-- under ADR-0044, so the **Kassenwart**, the office the epic names as the
-- recipient, cannot reach it at all.
--
-- This table is the acknowledgement half of the fix. The dashboard alert counts
-- violations that have no row here.
--
-- -----------------------------------------------------------------------------
-- Does this contradict 050?
-- -----------------------------------------------------------------------------
-- 050's own header rejected `terminal_anomalies` partly *because* it is "built
-- to be closed": `acknowledged_at` takes a row out of the open set, "which is
-- what ADR-0045 invariant 4 forbids". Adding an acknowledgement now looks like
-- walking that back. It is not, and the difference is the whole design:
--
--   In `terminal_anomalies`, acknowledging mutates **the record itself** — the
--   row that *is* the anomaly gets a timestamp and drops out of `listOpen()`.
--   There is one object, and closing it changes what the club is able to say
--   happened.
--
--   Here there are **two objects**. The audit entry is the record: immutable,
--   append-only, never touched by anything in this file. This table is state
--   *about* the record, added beside it. Acknowledging writes a new row; it
--   does not alter, hide or soften the violation, and no query that asks "did
--   this happen?" is affected by it.
--
-- So invariant 4 holds exactly as 050 stated it. A past sale to a minor stays
-- true no matter what changes afterwards — including this. What changes is only
-- whether the dashboard is still asking somebody to look at it, and that
-- distinction is what keeps the badge meaningful: an alert that can never be
-- dismissed is one people learn to stop seeing, which would leave the next
-- violation as invisible as it is today.
--
-- -----------------------------------------------------------------------------
-- Why the audit id is the key
-- -----------------------------------------------------------------------------
-- No second copy of the incident. A `jugendschutz_violations` table mirroring
-- the audit payload would be two records of one fact, free to drift, and 050
-- already settled where the fact lives. `audit_log.id` is BIGINT UNSIGNED
-- AUTO_INCREMENT and stable, so it makes a clean key — and unlike
-- `mail_outbox.subject_id`, which is polymorphic and can carry no constraint,
-- this one points at exactly one table and gets a real foreign key.
--
-- `ON DELETE CASCADE` is safe in the direction that matters: it only fires if
-- the audit row itself is deleted, and nothing in the application deletes audit
-- rows. Should retention ever prune the log, the acknowledgement of a pruned
-- record is meaningless and should go with it.
-- -----------------------------------------------------------------------------

CREATE TABLE jugendschutz_violation_acks (
    audit_log_id             BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    acknowledged_at          DATETIME     NOT NULL,
    -- Nullable for the same reason every other actor column here is: an admin
    -- account can be removed, and the acknowledgement still happened.
    acknowledged_by_admin_id CHAR(36)     NULL,
    -- Free text, deliberately optional. Somebody dealing with an incident may
    -- want to record "spoke to the Getränkewart, card withdrawn" — and may
    -- equally have nothing to add. It is never required, because a required
    -- field on a dismissal turns into "." and teaches people to lie.
    note                     VARCHAR(500) NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jugendschutz_acks_acknowledged_at (acknowledged_at),
    CONSTRAINT fk_jugendschutz_acks_audit
        FOREIGN KEY (audit_log_id) REFERENCES audit_log (id) ON DELETE CASCADE,
    CONSTRAINT fk_jugendschutz_acks_admin
        FOREIGN KEY (acknowledged_by_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- The acknowledgement is itself an audited act
-- -----------------------------------------------------------------------------
-- Matching `terminal_anomaly_acknowledged` (030): clearing a compliance alert is
-- an admin decision, and "who decided this one had been dealt with, and when"
-- belongs in the log that answers such questions by filtering.
--
-- The ack row already carries actor, timestamp and note, so this is not the only
-- copy — but that row is alert state, while the audit entry sits permanently
-- beside the violation it concerns. A club asked later how an incident was
-- handled reads the log, not the alert table.
--
-- Restated in full because MariaDB has no ADD VALUE, and generated from
-- information_schema rather than transcribed — 050 was written the same way,
-- after a hand-written list turned out to include a kind that had been withdrawn.
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
    'jugendschutz_violation_acknowledged'
) NOT NULL;
