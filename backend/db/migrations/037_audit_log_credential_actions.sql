-- =============================================================================
-- 037_audit_log_credential_actions.sql — credential changes get their own names
-- =============================================================================
-- ADR-0013. Changing your own password and changing your own email were both
-- recorded as a plain `update` on an `admin_user`, which is the same action a
-- display-name edit produces. The two events an investigation actually asks
-- about — "when did this account's password change" and "when did its login
-- address move" — were therefore only findable by reading the JSON payload of
-- every admin-user update.
--
-- Both are their own thing and get their own name. `email_changed` matters
-- most: the email is the login identifier, so a change to it is a change of
-- who can sign in, and that belongs in the log under a name a reader can
-- filter on.
--
-- The list below is migration 035's, plus the two new values. A `MODIFY COLUMN`
-- restates the whole ENUM, so it has to be written against the *latest* one:
-- an older list would silently drop the actions 032 and 035 added.
--
-- Rollback: db/rollback/037_audit_log_credential_actions.down.sql
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
    'terminal_anomaly_acknowledged',
    'cron_secret_rotated',
    'password_changed',
    'email_changed'
) NOT NULL;
