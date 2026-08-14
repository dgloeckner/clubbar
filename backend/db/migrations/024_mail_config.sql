-- =============================================================================
-- Migration 024: Mail configuration (ADR-0038)
-- =============================================================================
-- Singleton table for the club-editable half of outgoing mail, following the
-- sepa_config (ADR-0007) and instance_config (ADR-0034) precedent.
--
-- What is here and what is deliberately not:
--
--   here          sender name/address, reply-to (Kassenwart), header variant,
--                 the footer's Impressum block, the logo URL — all club data,
--                 all editable by an admin. The 2026-08-11 decision on #361
--                 explicitly rejects hardcoding these the way the upstream
--                 FRGS template does (Mailvorlage::ABSENDERNAME).
--
--   not here      the SMTP DSN. It carries a password, so it lives in
--                 config.php in the data directory next to the DB password and
--                 the TOTP key (ADR-0031 decision 2), and changing the mail
--                 server is an installer or file operation.
--
-- Every column is nullable or defaulted: an installation that has not
-- configured mail must still migrate, and the absence shows up as a self-check
-- finding rather than a broken deployment.
-- =============================================================================

CREATE TABLE mail_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    sender_name VARCHAR(120) NOT NULL DEFAULT '',
    sender_address VARCHAR(255) NOT NULL DEFAULT '',
    reply_to_address VARCHAR(255) NULL,
    -- Mirrors MailLayout::HEADER_* — 'paper' is the settlement-mail default
    -- (decision of 2026-08-11: most letterhead, least newsletter).
    header_style ENUM('red', 'petrol', 'paper') NOT NULL DEFAULT 'paper',
    footer_org_name VARCHAR(200) NOT NULL DEFAULT '',
    footer_address_line VARCHAR(255) NULL,
    website_url VARCHAR(255) NULL,
    logo_url VARCHAR(255) NULL,
    updated_by_admin_id CHAR(36) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_config_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize singleton row. The empty sender is intentional: it is what makes
-- "mail was never configured" a state the self-check can report, rather than a
-- plausible-looking default that sends from an address nobody owns.
INSERT INTO mail_config (id) VALUES (1);
