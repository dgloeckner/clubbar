-- =============================================================================
-- 016_terminal_repair_audit_action.sql — audit a pairing-mismatch repair (ADR-0035)
-- =============================================================================
-- A terminal that detects it is now talking to a backend with a different
-- history (#380) hard-blocks sync until staff at the bar deliberately
-- confirms it is safe to trust that backend again. That moment must leave a
-- record — "terminal_repair" is the action AuditService writes when it does.
--
-- Rollback: db/rollback/016_terminal_repair_audit_action.down.sql
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'settlement_submit','settlement_reverse','transaction_storno',
    'collection_hold_placed','collection_hold_cleared',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete','terminal_repair'
) NOT NULL;
