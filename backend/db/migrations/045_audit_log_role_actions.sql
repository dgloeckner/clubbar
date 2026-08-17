-- =============================================================================
-- 045_audit_log_role_actions.sql — granting a role is its own event
-- =============================================================================
-- ADR-0044 rule 2: *granting a role is minting authority*. The audit log is
-- where that has to be findable, and folding it into the generic `update` row
-- that `PATCH /admin-users/{id}` already writes would make the most
-- security-relevant event in the whole feature unfilterable — visible only to
-- somebody who already knew to open every admin-user update and read its JSON.
--
-- Two actions rather than one `role_changed`, because a grant and a revocation
-- are read for opposite reasons. "Who gained `admin` last quarter" is the
-- escalation question; "who lost `kassenwart` in March" is the handover
-- question. One action would answer both only after filtering on a value inside
-- the JSON, which is the situation this migration exists to avoid.
--
-- Rollback: db/rollback/045_audit_log_role_actions.down.sql
-- =============================================================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create',
    'update',
    'delete',
    'anonymize',
    'login',
    'logout',
    'login_failed',
    'export',
    'settlement_create',
    'settlement_cancel',
    'settlement_export',
    'settlement_submit',
    'settlement_reverse',
    'transaction_storno',
    'transaction_price_divergence',
    'collection_hold_placed',
    'collection_hold_cleared',
    'activate',
    'deactivate',
    'reorder',
    'totp_enrolled',
    'totp_reset',
    'mandate_document_upload',
    'mandate_document_delete',
    'terminal_repair',
    'key_registered',
    'key_activated',
    'key_rotation_started',
    'key_rotation_batch_completed',
    'key_rotation_completed',
    'key_retired',
    'key_revoked',
    'key_marked_compromised',
    'sepa_export',
    'iban_full_view',
    'terminal_token_created',
    'terminal_token_activated',
    'terminal_token_rotated',
    'terminal_token_revoked',
    'terminal_token_expired',
    'mail_enqueued',
    'mail_superseded',
    'mail_retried',
    'mail_test_sent',
    'terminal_anomaly_detected',
    'terminal_anomaly_acknowledged',
    'cron_secret_rotated',
    'password_changed',
    'email_changed',
    'role_granted',
    'role_revoked'
) NOT NULL;
