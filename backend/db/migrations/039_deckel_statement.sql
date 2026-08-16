-- =============================================================================
-- 039_deckel_statement.sql — the periodic Deckelauszug (ADR-0039, #462)
-- =============================================================================
-- A Deckelauszug is a periodic statement of a member's Deckel: sent to every
-- active member on a fixed calendar boundary regardless of what they owe,
-- itemised, announcing nothing and collecting nothing. It is not a
-- Vorabankündigung and must never be mistaken for one — see CONTEXT.md, which
-- carries both terms.
--
-- It rides ADR-0038's outbox as a new `kind`, which is the point: a new
-- notification type should be a value here and a builder there, not a second
-- queue and a second scheduler. What it adds to the schema is therefore small.
--
-- ## The new kind and its subject
--
-- `deckel_statement` is the first kind whose `subject_id` is a **member** and
-- not a settlement, a key or a terminal (`MailSubject::MEMBER`). Its
-- `dedup_key` is the **period** — `2026-08`, `2026-Q3` — and that pairing is
-- the whole of its idempotency: `UNIQUE (kind, subject_id, dedup_key)` means a
-- scheduled scan that runs ten times in August produces one August statement
-- per member, without a lookup and therefore without a race. Nothing else was
-- needed to make a *time-triggered* enqueue safe, which is ADR-0039 decision 1.
--
-- ## statement_cadence
--
-- Club-wide, `off | monthly | quarterly`, and the only control there is. There
-- is deliberately no per-member opt-out (ADR-0039 decision 3): this system has
-- no member login, so an opt-out would mean a public endpoint, a token, an
-- unsubscribe link and a preference record — a larger feature than the
-- statement. `weekly` is absent for a gentler reason than it is absent from
-- `cron_interval`: nothing breaks at weekly, it simply stops being predictable
-- and starts being relentless.
--
-- ## Why the column defaults to `monthly` and the row is then set to `off`
--
-- These are not in conflict; they answer different questions.
--
-- The DEFAULT states the design's intent — ADR-0039 decision 3 wants this
-- feature *on*, because a statement that a club has to discover and enable is
-- one most clubs never enable, and the harm it addresses (a member turned away
-- at the bar by the €100 credit limit, offline, with a queue behind them) is
-- suffered by the members of exactly those clubs.
--
-- The UPDATE states what may happen when this file runs. Running a migration
-- must never, by itself, cause an existing installation to mail its entire
-- membership on the next 1st — before anybody has read a release note, and
-- with no admin having chosen it. ADR-0039 names this as the one part of the
-- design deliberately left for confirmation, and #462 step 11.4 flags it.
--
-- The UPDATE reaches a fresh installation too, because `mail_config`'s
-- singleton row is created by migration 024 and every install runs every
-- migration. That is the more conservative reading of the two, and it is the
-- right one *for this release*: the content builder for this kind lands in P12
-- (#463), so an installation that started mailing now would queue statements
-- nothing can render. #463 owns turning it on, together with the admin-facing
-- switch that makes it a choice rather than a surprise.
--
-- Rollback: db/rollback/039_deckel_statement.down.sql
-- =============================================================================

-- Restating the whole list, as 026, 030, 036 and 038 did: MariaDB has no ADD
-- VALUE, and a MODIFY COLUMN is a replacement rather than an amendment.
-- Omitting an existing value here is how one is removed, so the list is the
-- interface and it has to be read as one.
--
-- **`payment_request` is absent, and that is a correction rather than an
-- oversight.** 036 removed it, with a ruling: a `bank_transfer` settlement is
-- the record of money that already arrived (ADR-0032 §4), so a mail asking for
-- it would ask twice. 038 then restated the list from 030's copy — which
-- predates 036 — and put the value back. Nothing can write it (the enum case,
-- the builder arm and the API value were all deleted with #476), so no row is
-- at risk either way; leaving it in the column would just keep a value alive
-- that a merged decision removed, until the next MODIFY inherited it again.
ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'admin_email_changed',
    'deckel_statement'
) NOT NULL;

-- `settlement_announcements` deliberately does NOT gain this value. That table
-- is the durable proof that a § 7 Abs. 3 announcement was made about a
-- settlement; a statement announces nothing and proves nothing, which is also
-- why ADR-0039 decision 6 prunes its delivered rows at 90 days instead.

ALTER TABLE mail_config
    ADD COLUMN statement_cadence ENUM('off', 'monthly', 'quarterly') NOT NULL DEFAULT 'monthly'
        COMMENT 'How often a Deckelauszug goes to every member; club-wide, no per-member opt-out'
        AFTER drain_batch_size;

UPDATE mail_config SET statement_cadence = 'off' WHERE id = 1;
