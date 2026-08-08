-- =============================================================================
-- 010_settlement_reversal_audit.down.sql — rollback (#196)
-- =============================================================================
-- Drops `settlement_reverse`, `collection_hold_placed` and
-- `collection_hold_cleared` from the audit action enum.
--
-- ⚠️ Destructive: any audit row already carrying one of them loses its action,
-- because MariaDB truncates values outside the enum. Those rows are the record
-- of which collections the bank clawed back and who released a held member back
-- into the next run. Check before running:
--
--   SELECT action, COUNT(*) FROM audit_log
--    WHERE action IN ('settlement_reverse','collection_hold_placed','collection_hold_cleared')
--    GROUP BY action;
--
-- If that returns anything, keep the enum values — the column is wider than the
-- code needs, which costs nothing.
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'settlement_submit','transaction_storno',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;
