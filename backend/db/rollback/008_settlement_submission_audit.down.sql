-- Rollback for 008_settlement_submission_audit.sql
-- Rows already carrying 'settlement_submit' would be truncated by the MODIFY,
-- so they are folded into the closest surviving action first.

UPDATE audit_log SET action = 'settlement_export' WHERE action = 'settlement_submit';

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;
