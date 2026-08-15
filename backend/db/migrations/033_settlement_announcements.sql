-- =============================================================================
-- 033_settlement_announcements.sql — the proof that survives the queue
-- =============================================================================
-- #408. The outbox is a work queue that happens to hold a member's address, and
-- ADR-0029 puts that address in the **operational** tier: erased with the rest
-- of a member's contact data, and pruned once the message has been delivered.
--
-- That leaves a hole. #361 promises the treasurer a *per-member sent timestamp
-- visible in the settlement detail*, and today that line is rendered from the
-- outbox row itself — so the moment pruning does its job, the settlement detail
-- would start claiming that forty members were never announced to. The fact of
-- delivery is retention-tier; the address it was delivered to is not. They
-- cannot live in the same row, because they do not expire together.
--
-- So this table holds the half that has to survive: **who was announced to,
-- about which settlement, and when** — and no address, which is the entire
-- reason it can be kept.
--
--   settlement_id + member_id + kind, and a `sent_at`
--       Nothing else. Recipient, error text, attempt count, message id and
--       claim state are all queue mechanics; none of them is evidence of
--       anything once the message has left.
--
-- ## Why a table and not a column on `settlement_items`
--
-- ADR-0029 words this as "the `settlement_items`-side sent timestamp", which
-- reads as a column on that table. It cannot be one, and ADR-0032 §6 already
-- explains why in its own words — it rejected exactly this shape for reversals:
--
--     "An event table rather than columns on `settlement_items`, because a
--      reversal *is* an event with its own metadata … Columns would repeat that
--      across every item of a member's reversal, with no single row
--      representing 'the return'."
--
-- An announcement is the same shape. `settlement_items` is one row per settled
-- *transaction*, so a member with thirty bookings would carry thirty copies of
-- one timestamp, with no row that means "this member was told" and thirty
-- chances for them to disagree. `settlement_reversals` is the precedent this
-- follows; the ADR's phrase is read as "settlement-side", which is what rule 2
-- below the table in ADR-0029 actually calls it.
--
-- ## Why the foreign keys differ from each other
--
--   settlement  ON DELETE CASCADE   The application never deletes a settlement
--                                   (ADR-0032 makes it append-only), so this is
--                                   a statement about a hand-cleaned database:
--                                   an announcement about a settlement that is
--                                   not there is not evidence of anything.
--   member      ON DELETE RESTRICT  Matching `settlement_items`. A member is
--                                   anonymised, never deleted, and the retention
--                                   tier is precisely what anonymisation must
--                                   not be able to take with it.
--
-- ## Backfill
--
-- Every outbox row that has already reached the transport gets its durable
-- counterpart, so pruning on the first run after this migration cannot destroy
-- a fact that was never copied out. Rows addressed to an admin are excluded:
-- their `subject_id` is a key or a terminal, not a settlement.
--
-- Rollback: db/rollback/033_settlement_announcements.down.sql
-- =============================================================================

CREATE TABLE settlement_announcements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    settlement_id CHAR(36) NOT NULL,
    member_id CHAR(36) NOT NULL,

    -- Only the member-addressed settlement kinds can appear here. An expiry
    -- warning is about a credential and is addressed to an admin; it is
    -- operational from end to end and has nothing to leave behind.
    kind ENUM('sepa_prenotification', 'cancellation_notice', 'payment_request') NOT NULL,

    -- When the transport accepted it. Copied from the outbox row rather than
    -- stamped with NOW(), so the two never disagree while both exist.
    sent_at DATETIME NOT NULL,

    -- One announcement of one kind per member per settlement, mirroring the
    -- outbox's own `UNIQUE (kind, subject_id, dedup_key)` for these kinds. It is
    -- what lets the drain write here with `INSERT … ON DUPLICATE KEY UPDATE`
    -- and stay idempotent across a retried send.
    CONSTRAINT uq_settlement_announcements UNIQUE (settlement_id, member_id, kind),

    CONSTRAINT fk_settlement_announcements_settlement FOREIGN KEY (settlement_id)
        REFERENCES settlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_settlement_announcements_member FOREIGN KEY (member_id)
        REFERENCES members(id) ON DELETE RESTRICT,

    -- The settlement detail's read: every member's announcement state for one
    -- run, in one query.
    INDEX idx_settlement_announcements_settlement (settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve what the outbox already knows before anything is allowed to prune it.
INSERT INTO settlement_announcements (settlement_id, member_id, kind, sent_at)
SELECT o.subject_id, o.member_id, o.kind, o.sent_at
  FROM mail_outbox o
  JOIN settlements s ON s.id = o.subject_id
 WHERE o.status = 'sent'
   AND o.sent_at IS NOT NULL
   AND o.member_id IS NOT NULL
   AND o.kind IN ('sepa_prenotification', 'cancellation_notice', 'payment_request')
ON DUPLICATE KEY UPDATE settlement_announcements.sent_at = settlement_announcements.sent_at;
