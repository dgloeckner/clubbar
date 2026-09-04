-- The Anmeldelink (#821): mailing the club's registration link to somebody who
-- is thinking of joining.
--
-- **No table, no column, no index.** The link carries the poster secret, which
-- is already printed on a wall the public walks past, so there is no token to
-- mint, expire, revoke or store, and nothing to remember about the person it
-- was sent to — `mail_outbox.recipient` *is* the invitation history
-- (ADR-0053, ADR-0052 decision 10).
--
-- Two ENUMs still have to be widened, and that is the whole of this migration.
-- Both columns are ENUM rather than VARCHAR, so a case in `MailKind` or
-- `AuditAction` with no value here is refused by MariaDB at write time — which
-- for the mail kind would mean a send that returns 204 and queues nothing.
-- `AuditActionSchemaTest` catches the second half in 0.2s; the first is caught
-- by the API suite the moment a link is sent.
--
-- Same MariaDB caveat as every enum migration before it: there is no ADD VALUE,
-- so MODIFY COLUMN replaces the whole list rather than amending it, and an
-- omission below is a removal.

-- -----------------------------------------------------------------------------
-- 1. The mail kind
-- -----------------------------------------------------------------------------
-- `registration_link` and not `member_invitation`: an Einladung in this system
-- is the one-time credential a new *admin* follows (migration 058), and the two
-- must not drift together — CONTEXT.md's Einladung entry names this collision
-- explicitly. What is sent here is the poster's own URL and nothing else.

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
    'admin_role_changed',
    'admin_invitation',
    'jugendschutz_violation',
    'credit_limit_digest',
    'backup_secret_expiry_warning',
    'backup_health_warning',
    'member_welcome',
    'member_card_replaced',
    'member_email_changed',
    'member_email_activated',
    'registration_link'
) NOT NULL;

-- -----------------------------------------------------------------------------
-- 2. The audited act
-- -----------------------------------------------------------------------------
-- An admin causing the installation to mail a named third party is the shape of
-- everything else in this log, and the address is part of the entry rather than
-- being exempted from it: it ages out with the log (ADR-0053 decision 9).
--
-- Its own action rather than the generic `mail_enqueued` the admin invitation
-- reuses, because the two answer different questions. `mail_enqueued` says a
-- message about an existing subject went into the queue; this says an admin
-- reached outside the club and offered somebody a way in, which is the act a
-- reviewer would search for by name.

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
    'role_revoked',
    'jugendschutz_violation',
    'jugendschutz_violation_acknowledged',
    'invitation_sent',
    'invitation_accepted',
    'registration_approved',
    'registration_rejected',
    'registration_edited',
    'registration_printed',
    'registration_secret_rotated',
    'registration_enabled',
    'registration_disabled',
    'registration_document_url_changed',
    'registration_link_sent'
) NOT NULL;
