-- =============================================================================
-- 007_critical_remediation.sql — Phase 0 of the critical remediation (#151)
-- =============================================================================
-- One consolidated migration rather than each money/sync/reversal fix inventing
-- its own. Every subsequent phase of plans/2026-08-06-critical-remediation.md
-- depends on the shapes established here.
--
-- Pre-launch: there is no production data. The few data statements below exist
-- so the migration also applies cleanly to a dev database seeded from
-- db/reset_test_data.sql; they are not a backfill anyone depends on.
--
-- Rollback: db/rollback/007_critical_remediation.down.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. transactions — timestamp authority (#144)
-- ---------------------------------------------------------------------------
-- `created_at` conflated two different facts. A drink sold yesterday on an
-- offline terminal and synced today occurred yesterday but was received today,
-- and settlement must be able to tell the two apart: filtering on a
-- terminal-supplied value is what makes backdating profitable, filtering on
-- server time misdates genuine offline sales.
ALTER TABLE transactions
    CHANGE COLUMN created_at occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER occurred_at;

-- Reporting queries move to occurred_at (when the bar sold it); audit and sync
-- queries to received_at (when we learned of it).
ALTER TABLE transactions
    DROP INDEX idx_transactions_member_created,
    ADD INDEX idx_transactions_member_occurred (member_id, occurred_at),
    ADD INDEX idx_transactions_received_at (received_at);

-- ---------------------------------------------------------------------------
-- 2. transactions — the storno contract (#158, #141 §4, ADR-0028 §4)
-- ---------------------------------------------------------------------------
-- `correction` implied a free-amount adjustment that no longer exists: a
-- correction *is* a storno of one specific transaction, in full, with its
-- amount derived rather than typed. `payout` is the separate legal act of
-- sending money back out to settle a credit balance. `topup` is not added —
-- it was ruled out by design, not merely left unbuilt.
ALTER TABLE transactions
    MODIFY COLUMN transaction_type
        ENUM('purchase','correction','storno','payout') NOT NULL DEFAULT 'purchase';

UPDATE transactions SET transaction_type = 'storno' WHERE transaction_type = 'correction';

-- Pre-launch cleanup. A storno without a linked original cannot be made legal
-- after the fact — there is no way to infer which booking it reversed — and
-- the CHECK below would refuse to install while such rows exist. Only dev rows
-- from the old free-amount correction path can match.
DELETE FROM settlement_items
 WHERE transaction_id IN (
    SELECT id FROM (
        SELECT id FROM transactions
         WHERE transaction_type = 'storno' AND related_transaction_id IS NULL
    ) AS orphaned_stornos
 );
DELETE FROM transactions
 WHERE transaction_type = 'storno' AND related_transaction_id IS NULL;

ALTER TABLE transactions
    MODIFY COLUMN transaction_type
        ENUM('purchase','storno','payout') NOT NULL DEFAULT 'purchase';

-- A transaction is stornoed at most once. MariaDB permits many NULLs in a
-- unique column, so purchases and payouts — which carry no linkage — are
-- unaffected.
ALTER TABLE transactions
    ADD UNIQUE INDEX uq_transactions_related_transaction (related_transaction_id);

-- The linkage is a legal requirement, not a convenience, so it is enforced
-- here rather than left to the service that happens to write the row.
ALTER TABLE transactions
    ADD CONSTRAINT chk_transactions_storno_is_linked
        CHECK (transaction_type <> 'storno' OR related_transaction_id IS NOT NULL);

-- ---------------------------------------------------------------------------
-- 3. settlement_items — separating the claim from the record (#142, #148)
-- ---------------------------------------------------------------------------
-- Cancelling a settlement used to DELETE its items, which is what returned the
-- transactions to the unsettled pool — and what destroyed the record of what
-- the cancelled settlement contained. Splitting the column in two lets the row
-- survive while the claim is released: `transaction_id` is the history,
-- `active_transaction_id` is the live claim.
--
-- MariaDB has no partial indexes, but permits many NULLs in a unique column,
-- so nulling the claim frees the transaction for re-settlement while the
-- database still prevents two live settlements claiming it.
ALTER TABLE settlement_items
    ADD COLUMN active_transaction_id CHAR(36) NULL AFTER transaction_id,
    ADD COLUMN end_to_end_id VARCHAR(35) NULL AFTER amount_cents;

UPDATE settlement_items si
  JOIN settlements s ON s.id = si.settlement_id
   SET si.active_transaction_id = si.transaction_id
 WHERE s.is_cancelled = 0;

-- `transaction_id`'s UNIQUE index was auto-named after the column. The plain
-- idx_settlement_items_transaction index remains and covers the foreign key.
ALTER TABLE settlement_items
    DROP INDEX transaction_id,
    ADD UNIQUE INDEX uq_settlement_items_active_transaction (active_transaction_id),
    ADD CONSTRAINT fk_settlement_items_active_transaction
        FOREIGN KEY (active_transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------------
-- 4. settlements — submission, and one method field (#142, #163)
-- ---------------------------------------------------------------------------
-- Cancellation is permitted while no money has moved, which needs a recorded
-- moment at which it did.
ALTER TABLE settlements
    ADD COLUMN submitted_at TIMESTAMP NULL AFTER exported_at,
    ADD COLUMN submitted_by_admin_id CHAR(36) NULL AFTER submitted_at,
    ADD CONSTRAINT fk_settlements_submitted_by
        FOREIGN KEY (submitted_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT;

-- `settlement_type` was validated at the controller and never stored — there
-- was no such column — while `manual_reason` was stored as an unvalidated free
-- string. One field replaces both. `notes` stays, carrying no meaning to the
-- system.
ALTER TABLE settlements
    ADD COLUMN method ENUM('direct_debit','bank_transfer','write_off') NOT NULL
        DEFAULT 'direct_debit' AFTER id,
    ADD INDEX idx_settlements_method (method);

UPDATE settlements SET method = 'bank_transfer' WHERE manual_reason IN ('cash', 'bank_transfer', 'other');
UPDATE settlements SET method = 'write_off' WHERE manual_reason = 'write_off';

ALTER TABLE settlements DROP COLUMN manual_reason;

-- Only a direct debit is a batch. A bank transfer is one member paying one
-- tab, and a write-off is a decision about one member — so neither may be
-- exported, and neither may quietly cover a group.
--
-- A pre-existing manual settlement spanning several members has no honest
-- representation under that rule, and relabelling it `direct_debit` would be
-- worse than useless: it never went to a bank, yet it would become exportable.
-- Such a row is cancelled instead — it collects nothing, which is the truth
-- about it — and its claims are released so the transactions can be settled
-- properly. Only dev data can be affected; nothing has launched.
UPDATE settlement_items si
  JOIN settlements s ON s.id = si.settlement_id
   SET si.active_transaction_id = NULL
 WHERE s.method <> 'direct_debit' AND s.member_count <> 1;

UPDATE settlements
   SET is_cancelled = 1,
       cancelled_at = COALESCE(cancelled_at, CURRENT_TIMESTAMP),
       method = 'direct_debit'
 WHERE method <> 'direct_debit' AND member_count <> 1;

ALTER TABLE settlements
    ADD CONSTRAINT chk_settlements_manual_is_single_member
        CHECK (method = 'direct_debit' OR member_count = 1);

-- ---------------------------------------------------------------------------
-- 5. settlement_reversals — the bank undoing a collection (#148)
-- ---------------------------------------------------------------------------
-- Distinct from cancellation: that is the club choosing to undo a settlement,
-- this is the bank clawing one back without asking. Append-only events, one
-- per member per settlement.
CREATE TABLE settlement_reversals (
    id CHAR(36) NOT NULL PRIMARY KEY,
    settlement_id CHAR(36) NOT NULL,
    member_id CHAR(36) NOT NULL,
    reason ENUM('bank_return','club_error') NOT NULL,
    amount_cents BIGINT NOT NULL,
    bank_reference VARCHAR(35) NULL,
    notes VARCHAR(1000) NULL,
    created_by_admin_id CHAR(36) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settlement_reversals_settlement_member (settlement_id, member_id),
    INDEX idx_settlement_reversals_member (member_id),
    CONSTRAINT fk_settlement_reversals_settlement
        FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_reversals_member
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_reversals_created_by
        FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. mandates — one append-only record per mandate (#164, #165)
-- ---------------------------------------------------------------------------
-- Banking data on `members` was freely mutable, so a return arriving after a
-- bank change quoted an MREF+ that no longer existed anywhere and could not be
-- matched. A mandate is now its own record: a bank change or a revocation ends
-- the current mandate and creates a new one, never mutates in place.
--
-- `signed_at` is deliberately NULLable here. Phase 0 relocates the existing
-- data without changing the eligibility predicate; making a signature date a
-- precondition of SEPA validity is #164's implementation, and doing it in a
-- schema migration would silently fabricate or discard signature dates.
-- MariaDB has no partial indexes, and rejects control-flow functions inside a
-- generated column, so "at most one active mandate per member" is expressed the
-- same way settlement_items expresses its live claim: a nullable column that
-- holds the member id while the mandate is in force and NULL once it has ended.
-- Many NULLs are permitted in a unique column, so ended mandates accumulate
-- freely. `is_active` is derived from it rather than stored beside it, so the
-- flag and the constraint cannot drift apart.
CREATE TABLE mandates (
    id CHAR(36) NOT NULL PRIMARY KEY,
    member_id CHAR(36) NOT NULL,
    active_member_id CHAR(36) NULL,
    reference VARCHAR(35) NOT NULL UNIQUE,
    iban VARCHAR(34) NOT NULL,
    signed_at DATE NULL,
    document_id CHAR(36) NULL,
    ended_at TIMESTAMP NULL,
    ended_reason ENUM('bank_change','revoked','offboarded') NULL,
    created_by_admin_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) AS (active_member_id IS NOT NULL) VIRTUAL,
    UNIQUE KEY uq_mandates_active_member (active_member_id),
    INDEX idx_mandates_member (member_id),
    CONSTRAINT chk_mandates_active_member_is_owner
        CHECK (active_member_id IS NULL OR active_member_id = member_id),
    CONSTRAINT fk_mandates_member
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mandates_document
        FOREIGN KEY (document_id) REFERENCES mandate_documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_mandates_created_by
        FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Move whatever banking data exists onto mandate records. Members holding an
-- IBAN but no reference had nothing that could be collected against anyway.
INSERT INTO mandates (id, member_id, active_member_id, reference, iban, signed_at, created_at)
SELECT UUID(), id, id, mandate_reference, iban, mandate_signed_at, created_at
  FROM members
 WHERE iban IS NOT NULL AND mandate_reference IS NOT NULL;

ALTER TABLE members
    DROP COLUMN iban,
    DROP COLUMN mandate_reference,
    DROP COLUMN mandate_signed_at;

-- ---------------------------------------------------------------------------
-- 7. members — collection hold and retention expiry (#148, #165, #173)
-- ---------------------------------------------------------------------------
-- A hold is what stops the next sweep re-debiting a member who just had a
-- collection returned. The retention expiry is stamped at offboarding —
-- 31.12. of the year of the last transaction plus ten years (§ 147 AO) —
-- because without a stored date the retained residual is never deleted.
ALTER TABLE members
    ADD COLUMN collection_hold TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN collection_hold_reason VARCHAR(500) NULL,
    ADD COLUMN held_at TIMESTAMP NULL,
    ADD COLUMN held_by_admin_id CHAR(36) NULL,
    ADD COLUMN cleared_at TIMESTAMP NULL,
    ADD COLUMN cleared_by_admin_id CHAR(36) NULL,
    ADD COLUMN retention_expires_at DATE NULL,
    ADD INDEX idx_members_collection_hold (collection_hold),
    ADD INDEX idx_members_retention_expires (retention_expires_at),
    ADD CONSTRAINT fk_members_held_by
        FOREIGN KEY (held_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_members_cleared_by
        FOREIGN KEY (cleared_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL;
