-- ADR-0036: audit actions for the IBAN encryption key lifecycle and the
-- privileged decryption operations. Follows the 008/009/010 pattern of
-- restating the full ENUM — MariaDB has no ADD VALUE for enums.
--
-- Without this, the first KEY_* audit write dies with "Data truncated for
-- column 'action'" and takes the key registration down with it.

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
    'key_registered',
    'key_activated',
    'key_rotation_started',
    'key_rotation_batch_completed',
    'key_rotation_completed',
    'key_retired',
    'key_revoked',
    'key_marked_compromised',
    'sepa_export',
    'iban_full_view'
) NOT NULL;
