-- =============================================================================
-- 007_critical_remediation.down.sql — rollback for the Phase 0 migration (#151)
-- =============================================================================
-- Lives outside db/migrations/ deliberately: MigrationRunner globs that
-- directory and would otherwise apply this as a migration in its own right.
--
-- Usage:
--   docker compose exec -T database mysql -u root -proot clubbar \
--     < backend/db/rollback/007_critical_remediation.down.sql
--   DELETE FROM _migrations WHERE file = '007_critical_remediation.sql';
--
-- The second statement matters: MigrationRunner records what it has applied and
-- refuses to re-run a file it believes is already in place.
--
-- What this restores and what it cannot
-- -------------------------------------
-- Structure is restored exactly. Data added under the new shapes is not, and
-- cannot be — there is nowhere on the old schema to put it:
--
--   * `settlement_reversals` rows are dropped with the table. The old schema
--     had no concept of a bank return.
--   * `settlement_items.end_to_end_id` and the submission/collection-hold and
--     retention-expiry columns are dropped with their columns.
--   * A member who acquired more than one mandate keeps only the active one;
--     the superseded records are dropped, since `members` holds a single IBAN.
--   * `settlements.method` collapses back onto `manual_reason`, which had no
--     `direct_debit` case: those rows become NULL, which is what "not a manual
--     settlement" meant on the old schema.
--   * `transactions.received_at` is dropped. `occurred_at` becomes `created_at`
--     again, which is the value the old code read anyway.
--
-- Pre-launch this is acceptable: the rollback exists so a bad deploy can be
-- undone, not so the two schemas can be run alternately.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 7 + 6 (reverse order). members and mandates
-- ---------------------------------------------------------------------------
ALTER TABLE members
    DROP FOREIGN KEY fk_members_held_by,
    DROP FOREIGN KEY fk_members_cleared_by;

ALTER TABLE members
    DROP INDEX idx_members_collection_hold,
    DROP INDEX idx_members_retention_expires,
    DROP COLUMN collection_hold,
    DROP COLUMN collection_hold_reason,
    DROP COLUMN held_at,
    DROP COLUMN held_by_admin_id,
    DROP COLUMN cleared_at,
    DROP COLUMN cleared_by_admin_id,
    DROP COLUMN retention_expires_at;

ALTER TABLE members
    ADD COLUMN iban VARCHAR(34) NULL AFTER preferred_language,
    ADD COLUMN mandate_reference VARCHAR(35) NULL UNIQUE AFTER account_holder_name,
    ADD COLUMN mandate_signed_at DATE NULL AFTER mandate_reference;

UPDATE members m
  JOIN mandates md ON md.active_member_id = m.id
   SET m.iban = md.iban,
       m.mandate_reference = md.reference,
       m.mandate_signed_at = md.signed_at;

DROP TABLE mandates;

-- ---------------------------------------------------------------------------
-- 5. settlement_reversals
-- ---------------------------------------------------------------------------
DROP TABLE settlement_reversals;

-- ---------------------------------------------------------------------------
-- 4. settlements
-- ---------------------------------------------------------------------------
ALTER TABLE settlements
    DROP CONSTRAINT chk_settlements_manual_is_single_member;

ALTER TABLE settlements
    ADD COLUMN manual_reason ENUM('cash','bank_transfer','write_off','other') NULL AFTER id;

UPDATE settlements SET manual_reason = 'bank_transfer' WHERE method = 'bank_transfer';
UPDATE settlements SET manual_reason = 'write_off' WHERE method = 'write_off';

ALTER TABLE settlements
    DROP INDEX idx_settlements_method,
    DROP COLUMN method;

ALTER TABLE settlements
    DROP FOREIGN KEY fk_settlements_submitted_by;

ALTER TABLE settlements
    DROP COLUMN submitted_by_admin_id,
    DROP COLUMN submitted_at;

-- ---------------------------------------------------------------------------
-- 3. settlement_items
-- ---------------------------------------------------------------------------
-- Restoring the UNIQUE on transaction_id requires that no transaction is held
-- by more than one item, which is exactly what the new shape permits. Any
-- history rows beyond the live claim are dropped so the constraint can install.
DELETE si FROM settlement_items si
  JOIN settlement_items keep
    ON keep.transaction_id = si.transaction_id
   AND keep.id < si.id;

ALTER TABLE settlement_items
    DROP FOREIGN KEY fk_settlement_items_active_transaction;

ALTER TABLE settlement_items
    DROP INDEX uq_settlement_items_active_transaction,
    DROP COLUMN active_transaction_id,
    DROP COLUMN end_to_end_id,
    ADD UNIQUE INDEX transaction_id (transaction_id);

-- ---------------------------------------------------------------------------
-- 2 + 1. transactions
-- ---------------------------------------------------------------------------
ALTER TABLE transactions
    DROP CONSTRAINT chk_transactions_storno_is_linked,
    DROP INDEX uq_transactions_related_transaction;

ALTER TABLE transactions
    MODIFY COLUMN transaction_type
        ENUM('purchase','correction','storno','payout') NOT NULL DEFAULT 'purchase';

-- A payout has no representation on the old schema; `correction` is the only
-- non-purchase it knew.
UPDATE transactions SET transaction_type = 'correction' WHERE transaction_type IN ('storno', 'payout');

ALTER TABLE transactions
    MODIFY COLUMN transaction_type ENUM('purchase','correction') NOT NULL DEFAULT 'purchase';

ALTER TABLE transactions
    DROP INDEX idx_transactions_member_occurred,
    DROP INDEX idx_transactions_received_at,
    DROP COLUMN received_at,
    CHANGE COLUMN occurred_at created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD INDEX idx_transactions_member_created (member_id, created_at);
