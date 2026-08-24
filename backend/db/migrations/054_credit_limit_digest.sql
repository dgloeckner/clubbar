-- =============================================================================
-- 054_credit_limit_digest.sql — the Kassenwart hears about full Deckel
-- =============================================================================
-- Who is close to their credit limit is already computed, already correct and
-- already invisible to anybody not looking at it. The dashboard's near-limit
-- panel (#385, ADR-0047) answers the question the moment somebody opens the
-- panel and asks; a treasurer who does not open the panel learns that a member
-- has been refused at the bar when the member tells them.
--
-- This is the same list, pushed instead of pulled: one aggregate mail naming
-- every member in the warning band, what their Deckel stands at, and the
-- ceiling it is measured against.
--
-- It rides ADR-0038's outbox as a new `kind`, which is the point of that ADR:
-- a new notification type is a value here and a builder there, not a second
-- queue and a second scheduler. So the schema gains a `kind` and a cadence,
-- and nothing else.
--
-- ## The new kind and its subject
--
-- `credit_limit_digest` is the first kind whose `subject_id` is neither a
-- person nor a piece of infrastructure but a **setting**: the singleton
-- `credit_limit_config` row, id `1` (`MailSubject::CREDIT_LIMIT_CONFIG`). That
-- is the honest answer to "what is this message about" — it is about the
-- club's ceiling and who is up against it, and it names no single member
-- because it is *about* all of them. `EntityType::CREDIT_LIMIT_CONFIG` already
-- exists, so the audit entry for a queued digest files next to the entries for
-- changing the ceiling itself, which is where somebody asking "why did this go
-- out, and against what limit" would look.
--
-- Its `dedup_key` is `<window>:<adminUserId>` — `2026-W35:8f3c…` — built by
-- `AdminNotifier::warnAdmins()` like every other admin fan-out. `UNIQUE (kind,
-- subject_id, dedup_key)` is then the whole of its idempotency: a scheduler
-- ticking every fifteen minutes produces one digest per recipient per window,
-- with no lookup and therefore no race between two overlapping ticks. Exactly
-- the argument ADR-0039 makes for the Deckelauszug's period, applied to a
-- window instead of a calendar boundary.
--
-- ## credit_limit_digest_cadence
--
-- Club-wide, `off | daily | weekly | monthly`.
--
-- `weekly` is present here and deliberately absent from `statement_cadence`
-- (migration 039), and the difference is who reads it. A Deckelauszug goes to
-- the whole membership, where fifty-two a year turns "predictable" into
-- "relentless". This goes to the handful of people who run the club, about an
-- operational condition that changes within a week — a tab that crossed 80 %
-- on Tuesday is refused at the bar on Saturday, and a monthly-only digest
-- would report it after the member was turned away.
--
-- ## Why this one defaults to on, when 039 defaulted to off
--
-- Migration 039 set its seeded row to `off` and said why: running a migration
-- must never, by itself, mail an entire membership. That reasoning is about
-- *who receives it*, and it does not carry here.
--
--   * The recipients are the active `admin` and `kassenwart` accounts — the
--     people who already see this list on the dashboard, and whose job it is.
--     Nobody's members are mailed by this, ever.
--   * The digest **suppresses itself when the list is empty**, which is the
--     ordinary case for a club whose members settle up. An installation where
--     nobody is near their ceiling gets no mail at all, so "on by default"
--     costs a quiet club nothing.
--   * 039 also had a mechanical reason: its content builder landed a release
--     later, so an installation that started mailing would have queued rows
--     nothing could render. This kind's builder ships in the same change.
--
-- A club that would rather not hear from it sets the cadence to `off`, which is
-- one select in Settings → Mail.
--
-- Rollback: db/rollback/054_credit_limit_digest.down.sql
-- =============================================================================

-- Restating the whole list, as 026, 030, 036, 038, 039, 041, 042, 048 and 050
-- did: MariaDB has no ADD VALUE, and a MODIFY COLUMN is a replacement rather
-- than an amendment. Omitting an existing value here is how one is removed, so
-- the list is the interface and it has to be read as one.
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
    'credit_limit_digest'
) NOT NULL;

-- `settlement_announcements` deliberately does NOT gain this value, for the
-- same reason 039 gave: that table is the durable proof that a § 7 Abs. 3
-- announcement was made about a settlement. A digest announces nothing, proves
-- nothing and collects nothing.

ALTER TABLE mail_config
    ADD COLUMN credit_limit_digest_cadence ENUM('off', 'daily', 'weekly', 'monthly')
        NOT NULL DEFAULT 'weekly'
        COMMENT 'How often the near-limit digest goes to admins and the Kassenwart; club-wide (ADR-0047)'
        AFTER statement_cadence;
