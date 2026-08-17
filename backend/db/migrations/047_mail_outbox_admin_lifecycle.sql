-- =============================================================================
-- 047_mail_outbox_admin_lifecycle.sql — account creation and role changes announce themselves
-- =============================================================================
-- ADR-0044 rule 2 says granting a role is minting authority; rule 3 says the
-- alarm has to survive a single admin. #520 gave role changes a step-up and
-- their own audit actions — this gives them a message, on the same reasoning
-- ADR-0043 applied to terminal credentials: the signal must be **out of band**
-- with respect to the credential that would have been compromised. Whoever
-- holds one admin's session, password and TOTP code clears the step-up on the
-- grant path and still cannot reach the other admins' inboxes, or the club's.
--
-- Two kinds rather than one `admin_lifecycle`, for the reason migration 045
-- kept `role_granted` and `role_revoked` apart: they are read for different
-- reasons, and a reader filtering the queue should not have to open a message
-- to learn which kind of event it describes.
--
-- `admin_account_created` is the event ADR-0044 §"Granting a role is minting
-- authority" names as the *loud* path — the one that already fires mail — and
-- until now it did not actually have any. The quiet path (promotion) is
-- `admin_role_changed`.
--
-- Rollback: db/rollback/047_mail_outbox_admin_lifecycle.down.sql
-- =============================================================================

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'terminal_token_issued',
    'admin_email_changed',
    'deckel_statement',
    'encryption_key_registered',
    'encryption_key_activated',
    'encryption_key_revoked',
    'admin_account_created',
    'admin_role_changed'
) NOT NULL;
