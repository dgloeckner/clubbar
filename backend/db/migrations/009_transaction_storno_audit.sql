-- =============================================================================
-- 009_transaction_storno_audit.sql — a storno becomes auditable (#169)
-- =============================================================================
-- 007 replaced the free-amount `correction` with `storno` in the transaction
-- enum, but a storno was written with no audit entry at all: `AuditService` was
-- never injected into `TransactionsService`. #169 makes the entry mandatory —
-- it is the record of who reversed what, and why — so the action enum has to
-- carry it.
--
-- Storno is the *only* admin-initiated transaction. Manual purchase, the last
-- surviving free-amount booking, was rejected (UC-A21, 2026-08-08), so no
-- further transaction actions are expected here.
--
-- Rollback: db/rollback/009_transaction_storno_audit.down.sql
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'settlement_submit','transaction_storno',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;
