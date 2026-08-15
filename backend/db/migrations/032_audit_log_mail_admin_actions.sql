-- =============================================================================
-- 032_audit_log_mail_admin_actions.sql — the two mail actions a person takes
-- =============================================================================
-- ADR-0013 / #407. Every mail action in the log so far is the *drain* recording
-- what a mail server answered. These two are different: a human decided them,
-- from the admin panel, and that is exactly what an audit log is for.
--
--   mail_retried    An admin put a failed announcement back in the queue. The
--                   only queue transition the UI is allowed to make (ADR-0038
--                   rule 4), and the only one with a person behind it.
--
-- Numbered 032 rather than 030: #470 (ADR-0041) took 030 and 031 while this was
-- in review, and 031 rewrites this very enum. `MODIFY COLUMN … ENUM(...)` is a
-- full replacement rather than an addition, so this migration has to list every
-- value 031 left behind *plus* the two below — otherwise applying it would drop
-- the terminal-anomaly actions and invalidate any row already written with one.
--
--   mail_test_sent  An admin sent themselves a test mail. Audited because it is
--                   the single place in this application that opens an SMTP
--                   connection from a web request instead of from the
--                   scheduler. It carries no member data and can only be
--                   addressed to the requesting admin's own mailbox — the log
--                   entry is what makes that checkable rather than merely
--                   claimed.
--
-- Rollback: db/rollback/032_audit_log_mail_admin_actions.down.sql
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
    'terminal_anomaly_acknowledged'
) NOT NULL;
