-- =============================================================================
-- 008_settlement_submission_audit.sql — the submitted state becomes auditable (#81)
-- =============================================================================
-- 007 added `settlements.submitted_at` / `submitted_by_admin_id`; ruling #142
-- makes submission the moment cancellation ends, which is exactly the kind of
-- irreversible step the audit log exists for. The action enum has to carry it.
--
-- Rollback: db/rollback/008_settlement_submission_audit.down.sql
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'settlement_submit',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;
