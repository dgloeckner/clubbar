-- =============================================================================
-- 042_mail_outbox_encryption_key_events.sql — key lifecycle leaves by mail
-- =============================================================================
-- ADR-0036. A key swap is the one change to this installation that nothing
-- announces. It is audited, and nothing reads the audit log. The dashboard
-- alert speaks only when a key is missing, expiring or expired, so a key
-- activated today with 365 days on it is silent by construction — and where
-- that banner does speak it names the key by `key_identifier`, which is free
-- text chosen by whoever registered it. An admin who did not perform the
-- action has no way to find out that the key sealing every member's bank
-- details changed.
--
-- Mail is the channel somebody holding an admin session cannot suppress. That
-- is the same argument migration 038 made for telling a former address that it
-- had been replaced, and these three values are the key-shaped version of it.
--
-- Three values rather than one with a state column: the subject line is what
-- gets read, and "a key was registered", "a key is now sealing your members'
-- bank details" and "a key was withdrawn" are three different things to say.
--
-- Restating the whole list, as 026, 030, 036, 038, 039 and 041 did: MariaDB has
-- no ADD VALUE, and MODIFY COLUMN is a replacement rather than an amendment, so
-- omitting an existing value here is how one gets removed. The list is the
-- interface and has to be read as one. `payment_request` stays absent — 036
-- removed it and 039 confirmed the removal; nothing can write it.
--
-- The list below is **041's**, plus the three new values. This file was written
-- as 041 against 039's list, before ADR-0043's `terminal_token_issued` landed
-- under that number; had it kept both the number and that list, applying it
-- would have quietly deleted a value a shipped feature writes — which is the
-- failure the paragraph above describes, arriving by rebase rather than by
-- carelessness. Renumbered to 042 and restated from 041.
--
-- `settlement_announcements` is deliberately untouched: that table is the
-- durable proof that a § 7 Abs. 3 announcement was made about a settlement,
-- and a key notice announces nothing about one.
--
-- Rollback: db/rollback/042_mail_outbox_encryption_key_events.down.sql
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
    'encryption_key_revoked'
) NOT NULL;
