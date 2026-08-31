-- =============================================================================
-- 059_self_registration.sql — a member fills in their own details
-- =============================================================================
-- Onboarding is a transcription exercise today: an applicant writes an IBAN on
-- paper and a Kassenwart types it back in (UC-A11), with a mod-97 checksum as
-- its only defence against a misread digit. This pair of tables lets the person
-- who owns the account type it once, from the card in their hand, behind a
-- secret printed on a poster (ADR-0052).
--
-- ## `pending_registrations` is not a member
--
-- No foreign key points at `members`, and that absence is the design. A pending
-- registration creates no member row and no mandate, so `GET /api/sync/members`
-- cannot return it and no terminal can recognise the person — not by a filter
-- somebody has to remember to write, but because there is nothing to find. The
-- member and the mandate come into existence only through the admin's approve
-- action, which is their attestation that the signed paper is on hand.
--
-- ## The IBAN is sealed exactly as `mandates` seals it
--
-- Same four columns, same sealed box, same active key: `iban_ciphertext`,
-- `iban_last4`, `iban_fingerprint`, `encryption_key_id`. ADR-0036 gets no
-- exception for the pending state, and approval moves the ciphertext across
-- **verbatim** rather than re-sealing it — the server never opens an IBAN here,
-- because it cannot. There is deliberately no plaintext column.
--
-- ## Why the personal columns are NOT NULL, unlike `members`
--
-- On `members` they are nullable so that a GDPR erasure can empty them in place.
-- Nothing here is ever anonymised: every exit deletes the whole row — approval
-- copies it into `members`/`mandates` and deletes it, rejection deletes it at
-- once, and the TTL purge deletes it unseen. So there is nothing to empty, and a
-- row that exists is a row that is complete.
--
-- This store is also **not** Beleg-bearing. No money has moved and no contract
-- has been performed, so ADR-0029's ten-year accounting retention does not
-- attach to any of it. Data about somebody who never joined must not
-- accumulate, and a queue nobody empties is exactly how it would.
--
-- Rollback: db/rollback/059_self_registration.down.sql
-- =============================================================================

CREATE TABLE pending_registrations (
    id                  CHAR(36)      NOT NULL PRIMARY KEY,

    first_name          VARCHAR(100)  NOT NULL,
    last_name           VARCHAR(100)  NOT NULL,
    email               VARCHAR(255)  NOT NULL,
    phone               VARCHAR(20)   NULL,
    date_of_birth       DATE          NOT NULL,
    preferred_language  VARCHAR(10)   NOT NULL,

    -- The Kontoinhaber case: set when whoever signs the mandate is not the
    -- applicant — a parent paying for a child. A name is all the payment needs;
    -- no second data subject is modelled (ADR-0052 decision 7).
    account_holder_name VARCHAR(70)   NULL,

    -- Minted at submission, from this row's own UUID in ADR-0006's format,
    -- because it has to be printed on the paper before the mandate exists
    -- anywhere. Approval carries it into `mandates.reference` unchanged, so the
    -- paper and the stored mandate name the same UMR — which is what makes a
    -- returned collection matchable months later.
    mandate_reference   VARCHAR(35)   NOT NULL,

    iban_ciphertext     VARBINARY(512) NOT NULL,
    iban_last4          CHAR(4)       NOT NULL,
    iban_fingerprint    CHAR(64)      NOT NULL,
    encryption_key_id   CHAR(36)      NOT NULL,
    -- Resolved from the BLZ at submission — the last moment the plaintext IBAN
    -- exists. A sealed row can never answer this question again.
    bank_name           VARCHAR(255)  NULL,

    -- Which document the applicant was pointed at, and when the page carrying
    -- that link was submitted. Not a version: this system neither hosts the
    -- document nor fetches it for the link, so a version is something it cannot
    -- observe and would be recording as a guess. Not an acknowledgement either
    -- — there is no checkbox on screen (ADR-0052 decision 6); the Kenntnisnahme
    -- box is ticked by hand on the paper.
    privacy_notice_url  VARCHAR(500)  NOT NULL,
    privacy_notice_shown_at DATETIME  NOT NULL,

    submitted_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- `submitted_at` + self_registration_config.retention_days. The cron tick
    -- deletes rows past this and logs a count, never an identity.
    expires_at          DATETIME      NOT NULL,

    CONSTRAINT uq_pending_registrations_reference UNIQUE (mandate_reference),

    CONSTRAINT fk_pending_registrations_key FOREIGN KEY (encryption_key_id)
        REFERENCES encryption_keys (id),

    -- The purge scan.
    INDEX idx_pending_registrations_expires (expires_at),
    -- The duplicate flags at review. The fingerprint is precisely the
    -- comparison ADR-0036 designed for: it answers "is this the same account?"
    -- without a key, because a sealed box is randomized and its ciphertext is
    -- not comparable.
    INDEX idx_pending_registrations_email (email),
    INDEX idx_pending_registrations_fingerprint (iban_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- The switch and the poster secret
-- -----------------------------------------------------------------------------
-- Single-row table, the same shape as `sepa_config`, `instance_config` and
-- `credit_limit_config`.
--
-- **Fails closed twice over.** The shipped state is `enabled = FALSE` with no
-- secret, and switching it on additionally requires the club's document URL
-- (`sepa_config.mandate_template_url`) to be configured — the onboarding page
-- must be able to discharge Art. 13 before it may collect anything. A fresh
-- installation and a half-finished configuration both answer the same uniform
-- refusal as a wrong secret.
CREATE TABLE self_registration_config (
    id                  TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,

    enabled             BOOLEAN       NOT NULL DEFAULT FALSE,
    -- Member-facing text shown when the secret is right but registration is
    -- switched off. A blank refusal to somebody standing at a poster the club
    -- printed reads as a bug, not as a policy. Stored and served as plain text;
    -- escaped where it is rendered.
    disabled_reason     VARCHAR(500)  NULL,

    -- SHA-256 hex of the poster secret — the lookup key, so a database dump
    -- yields no working poster, exactly as `terminals.api_token_hash` does for
    -- a POS and `admin_user_invitations.token_hash` for an invitation.
    secret_hash         CHAR(64)      NULL,
    -- The same secret sealed with SymmetricSecretBox, so an admin can reprint
    -- the poster without rotating and invalidating the one already on the
    -- clubhouse wall. Same primitive and trust model as an admin's TOTP secret:
    -- something the server must be able to read back.
    secret_cipher       TEXT          NULL,
    secret_rotated_at   DATETIME      NULL,

    -- How long an unapproved registration lives before the purge deletes it.
    retention_days      SMALLINT UNSIGNED NOT NULL DEFAULT 30,

    updated_by_admin_id CHAR(36)      NULL,
    created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_self_registration_config_single_row CHECK (id = 1),

    CONSTRAINT fk_self_registration_config_admin FOREIGN KEY (updated_by_admin_id)
        REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO self_registration_config (id, enabled) VALUES (1, FALSE);

-- -----------------------------------------------------------------------------
-- Guessing the poster secret costs what guessing a password costs
-- -----------------------------------------------------------------------------
-- Its own table rather than a share of `login_attempts`, for the reason the
-- terminal surface has its own: these are different budgets protecting
-- different things, and one flooding the other is a denial of service on the
-- surface that did nothing wrong.
--
-- Two meters live here, distinguished by `outcome`. The login surface counts
-- only *failures*, which is the wrong meter on its own for this endpoint: a
-- caller holding the real poster secret can flood the treasurer's queue with
-- perfectly valid submissions, so accepted ones are counted too.
CREATE TABLE registration_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45)  NOT NULL,
    outcome      ENUM('refused', 'accepted') NOT NULL,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_registration_attempts_ip (ip_address, outcome, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
