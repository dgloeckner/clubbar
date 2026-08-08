-- =============================================================================
-- 010_settlement_reversal_audit.sql — reversals become auditable (#196)
-- =============================================================================
-- Ruling #148's schema shipped in 007: `settlement_reversals` and the
-- `collection_hold` columns on `members` have been there all along. #196
-- implements them, and the one thing that migration could not anticipate is
-- what the audit log has to be able to say about the result.
--
-- Three actions, because three distinct things happen and each needs its own
-- record of who did it:
--
--   settlement_reverse       money that already moved has come back
--   collection_hold_placed   the next run must not re-debit this member
--   collection_hold_cleared  an admin released them back into the next run
--
-- The hold actions are separate from the reversal that causes one because a
-- hold outlives its reversal: it is cleared by a later, unrelated admin action,
-- and "who let this member be collected from again?" is a question the audit
-- log must answer on its own.
--
-- Rollback: db/rollback/010_settlement_reversal_audit.down.sql
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'settlement_submit','settlement_reverse','transaction_storno',
    'collection_hold_placed','collection_hold_cleared',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;
