-- =============================================================================
-- 057 — mail_outbox learns to write to a member about their own record
--
-- Every member-addressed kind so far has been about money: a Vorabankündigung
-- announcing a collection, the notice that one is called off, a Deckelauszug
-- stating a tab. All three presuppose an address that works, and nothing has
-- ever tested that presupposition.
--
-- `members.email` became mandatory on create in #362 precisely because the
-- Vorabankündigung is a contractual promise (Nutzungsordnung § 7 Abs. 3). It is
-- format-checked and nothing more: not unique, not normalised, never written
-- to. A typo entered in March surfaces in November, seven days before the first
-- collection, as a `failed` row nobody was watching for.
--
-- These four kinds close that, and they are the first mail this system sends a
-- member because of something that happened to *their record* rather than to
-- their money.
--
-- ## The card is the gate
--
-- `member_welcome` fires when a card is assigned, not when the record is
-- created. A member row with no card is paperwork; the card is the moment the
-- member can actually use the bar, and a welcome to a bar they cannot enter
-- announces nothing. The same gate governs the address-change pair: a member
-- who has never heard from the club must not receive an out-of-context notice
-- from an unfamiliar sender.
--
-- The resulting invariant — **the welcome is the first message a member ever
-- receives** — is what makes the rest of these safe to send.
--
-- ## Why four values and not two
--
-- `member_welcome` and `member_card_replaced` are one event with two meanings,
-- and the unique index below is what tells them apart without a lookup: the
-- welcome's dedup key is the constant `welcome`, so an enqueue that comes back
-- as a duplicate *is* the signal that this member has been welcomed before and
-- the assignment is a replacement. No SELECT, therefore no race.
--
-- `member_email_changed` and `member_email_activated` are the two ends of one
-- move, and they are separate kinds for the reason the three
-- `encryption_key_*` values are separate: they are different messages, with
-- different subject lines, read by different people. The first goes to the
-- address the member is losing — the only channel that can reveal a wrong or
-- unauthorised change, exactly as `admin_email_changed` does for an admin. The
-- second goes to the address they are gaining, and its job is to fail loudly
-- when that address does not exist.
--
-- Neither body names the other address (see AdminEmailChangedMail for the same
-- reasoning): with no address in the body, a message sitting in a retry loop
-- while the address moves again cannot render something false. ADR-0038 rule 5
-- costs nothing here.
--
-- Subject for all four is the member (`MailSubject::MEMBER`), as the
-- Deckelauszug's is.
--
-- Rollback: db/rollback/057_mail_outbox_member_lifecycle.down.sql
-- =============================================================================

-- Restating the whole list, as every predecessor did: MariaDB has no ADD VALUE,
-- and a MODIFY COLUMN is a replacement rather than an amendment. Omitting an
-- existing value here is how one is removed, so the list is the interface and
-- it has to be read as one.
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
    'jugendschutz_violation',
    'credit_limit_digest',
    'backup_secret_expiry_warning',
    'backup_health_warning',
    'member_welcome',
    'member_card_replaced',
    'member_email_changed',
    'member_email_activated'
) NOT NULL;

-- `settlement_announcements` deliberately does NOT gain these values, for the
-- reason 039 first gave and every migration since has repeated: that table is
-- the durable proof that a § 7 Abs. 3 announcement was made about a settlement.
-- None of these four is about a settlement, and none of them announces a
-- collection. `NotificationsService::recordAnnouncement()` already refuses to
-- write there for any kind whose subject is not a settlement, so this is the
-- schema agreeing with the code rather than a second rule to keep in step.
