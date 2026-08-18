-- Rollback for migration 044: drop the indices again.

ALTER TABLE audit_log DROP INDEX idx_audit_entity_action_created;
ALTER TABLE transactions DROP INDEX idx_transactions_occurred_at;
