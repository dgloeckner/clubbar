-- =============================================================================
-- 058_admin_user_invitations.sql — onboarding an admin by invitation link
-- =============================================================================
-- Creating an admin account used to mint a random password, show it once to
-- whoever pressed the button, and leave the two of them to move a live
-- credential between themselves — by chat, by note, by reading it aloud. The
-- password reached the new admin through a channel this system neither chose
-- nor can see, and it was *their* password before they had ever touched it.
--
-- This table replaces that with a one-time invitation link, mailed to the
-- address the account is being created for. The invitee sets their own
-- password, and then walks the ordinary first-login path: no second factor
-- yet, so `totp_setup_required` sends them straight into Authenticator
-- enrolment (AuthController::login branch 2). Nothing about that gate changes
-- here; the invitation only replaces how the *first* factor comes into being.
--
-- ## Why the token is stored twice
--
-- `token_hash` is what a presented link is looked up by: SHA-256 of the raw
-- token, so a stolen database dump yields no working link, exactly as
-- `terminals.api_token_hash` does for a POS.
--
-- `token_cipher` exists because of ADR-0038 rule 5. The drain renders a message
-- at send time, from live data, minutes or hours after the row was queued — and
-- the one thing it cannot re-derive is a secret that was deliberately hashed.
-- The alternatives were worse in both directions: minting the token inside the
-- mail builder gives a renderer a side effect and breaks the link when a
-- delivered message is recorded as failed; storing the raw token makes the dump
-- above sufficient. So the token is sealed with `SymmetricSecretBox` — the same
-- primitive, the same key and the same trust model as an admin's TOTP secret,
-- which is likewise something the server must be able to read back.
--
-- ## Lifetime and single use
--
-- A link is valid while `expires_at` is in the future and both `accepted_at`
-- and `revoked_at` are NULL. Accepting stamps the first; issuing a replacement
-- stamps the second on every predecessor, so a resend cannot leave two live
-- links to one account. Rows are kept after either — "who invited this account,
-- when, and when did they accept" is the onboarding half of the audit trail
-- ADR-0044 asks for around account creation, and a deleted row answers none of
-- it.
--
-- The invitation is deliberately **not** a password-reset channel. It is
-- issuable only while an account has never accepted one (`AdminInvitationService`),
-- because an email link that can re-credential an established admin is a second
-- way past the step-up that guards `POST /admin-users/{id}/reset-password`.
--
-- Rollback: db/rollback/058_admin_user_invitations.down.sql
-- =============================================================================

CREATE TABLE admin_user_invitations (
    id            CHAR(36)     NOT NULL PRIMARY KEY,

    admin_user_id CHAR(36)     NOT NULL,

    -- SHA-256 hex of the raw token. UNIQUE because it is the lookup key, and
    -- because two rows sharing one would make "which invitation is this?"
    -- ambiguous at exactly the moment it decides who gets an account.
    token_hash    CHAR(64)     NOT NULL,

    -- SymmetricSecretBox ciphertext (`v1:` + base64) of the same token — see
    -- the header. Never returned by any endpoint; read only by the mail
    -- builder, to put the link in the message.
    token_cipher  TEXT         NOT NULL,

    expires_at    DATETIME     NOT NULL,
    accepted_at   DATETIME     NULL,
    -- Stamped when a replacement invitation is issued for the same account.
    revoked_at    DATETIME     NULL,

    -- Who invited them. NULL once that admin's own account is removed: the
    -- invitation still happened, the same reasoning every other actor column
    -- in this schema carries.
    created_by    CHAR(36)     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_admin_user_invitations_token UNIQUE (token_hash),

    CONSTRAINT fk_admin_user_invitations_admin FOREIGN KEY (admin_user_id)
        REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_user_invitations_creator FOREIGN KEY (created_by)
        REFERENCES admin_users (id) ON DELETE SET NULL,

    -- "Does this account have a live invitation, and has it ever accepted one?"
    -- — the two questions the list endpoint and the resend gate both ask.
    INDEX idx_admin_user_invitations_admin (admin_user_id, accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- The message that carries the link
-- -----------------------------------------------------------------------------
-- Addressed to one account at one address, like `admin_email_changed` and
-- unlike every other `admin_*` kind, which fan out to an office. Restating the
-- whole list because MariaDB has no ADD VALUE: a MODIFY COLUMN replaces rather
-- than amends, so an omission here is a removal.
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
    'member_email_activated'
) NOT NULL;

-- -----------------------------------------------------------------------------
-- Issuing and accepting are both audited acts
-- -----------------------------------------------------------------------------
-- `invitation_sent` is minting authority under ADR-0044 rule 2 — the link is
-- what turns a row in `admin_users` into an account somebody can sign in to —
-- and `invitation_accepted` is the only entry written by a request that carries
-- no session at all, which is precisely why it needs to be in the log.
--
-- A revocation gets no action of its own: it is always the side effect of
-- issuing a replacement, and the `invitation_sent` entry beside it already says
-- so. An action nobody can produce on purpose is an action nobody can search
-- for.
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
    'invitation_accepted'
) NOT NULL;
