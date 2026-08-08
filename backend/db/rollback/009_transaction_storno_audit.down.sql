-- =============================================================================
-- 009_transaction_storno_audit.down.sql — rollback (#169)
-- =============================================================================
-- Drops `transaction_storno` from the audit action enum.
--
-- ⚠️ Destructive: any audit row already carrying `transaction_storno` loses its
-- action, because MariaDB truncates values outside the enum. Those rows are the
-- record of who reversed which booking and why. Check before running:
--
--   SELECT COUNT(*) FROM audit_log WHERE action = 'transaction_storno';
--
-- If that is non-zero, keep the enum value — the column is wider than the code
-- needs, which costs nothing.
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'settlement_submit',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;
