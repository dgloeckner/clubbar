-- =============================================================================
-- 040_audit_log_price_divergence.sql — a synced amount that disagrees with the
-- catalogue gets recorded
-- =============================================================================
-- Ruling #144 §3 makes `amount_cents` terminal-owned: it is stored exactly as
-- sent and flagged when it differs from the product's current price. The
-- storing half has always worked; nothing flagged the divergence (#204).
--
-- The claimed amount stands. The terminal charged a price the member saw and
-- accepted, possibly weeks earlier while offline (ADR-0012), so recomputing it
-- server-side would bill something other than the tile showed at the bar — and
-- would fail outright for a product since deleted. This value records the
-- disagreement; it never corrects it.
--
-- Written by the sync path, which has no admin behind it — `admin_user_id` is
-- nullable and every terminal-originated action already relies on that (see
-- migration 031's header, and TerminalTokenAuthenticator).
--
-- The list below is migration 037's plus the one new value. A `MODIFY COLUMN`
-- restates the whole ENUM, so it has to be written against the *latest* one:
-- 038 (mail outbox) and 039 (deckel statement) leave this column alone, so 037
-- is still the head. Dropping any value here would silently un-add it.
--
-- Rollback: db/rollback/040_audit_log_price_divergence.down.sql
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
    'email_changed'
) NOT NULL;
