-- ADR-0036 / #395: audit actions for the terminal-token lifecycle. Follows the
-- 008/009/010/019 pattern of restating the full ENUM — MariaDB has no ADD VALUE.
--
-- Without this the first terminal_token_* write dies with "Data truncated for
-- column 'action'" and takes the operation it was recording down with it —
-- including the promotion that happens inside an ordinary terminal sync.
--
-- Restating the whole list means this migration carries every value any earlier
-- one added. It runs after 019, so its list is 019's plus the five below;
-- dropping one here would silently un-add it.

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
    'terminal_token_expired'
) NOT NULL;
