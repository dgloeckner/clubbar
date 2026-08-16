-- =============================================================================
-- 036_drop_payment_request_kind.sql — a settlement never asks for money
-- =============================================================================
-- `payment_request` was reserved by 025 for #410: a mail telling a member to
-- transfer their Deckel themselves, for settlements collected outside SEPA.
-- #410 is closed unbuilt, because its premise does not hold in this system.
--
-- A `bank_transfer` settlement is the **closing record of money that has
-- already arrived** — ADR-0032 §4 makes it the one method that can never be
-- cancelled, in those words: *"the money already arrived"*, and UC-A35 calls
-- the same thing "payments received by bank transfer". A mail asking for that
-- money would ask the member to pay a second time. A `write_off` moves no money
-- in either direction, so it has nothing to say either.
--
-- The need behind the issue is real and small: SEPA can be closed for one
-- member two ways — no active mandate, or a collection hold after a bank return
-- (ADR-0032 §7) — and both leave a live debt SEPA cannot take. The ruling is
-- that the Kassenwart contacts those members directly, by phone or email. See
-- **Settlement method** in CONTEXT.md, and the amended scope table of ADR-0038.
--
-- So the value goes, rather than staying reserved for something nobody intends
-- to build. A `kind` nothing can produce is a filter option in the admin panel
-- that can never match, a retention tier for a message that does not exist, and
-- a standing invitation to re-propose the thing this ruling refused.
--
-- ## The DELETEs
--
-- They match zero rows on any installation, and that is the point of running
-- them rather than trusting it: `MODIFY COLUMN … ENUM(…)` is a *replacement*,
-- and a row holding a value the new list omits is silently truncated to the
-- empty string rather than refused. Nothing has ever been able to write one —
-- no code path enqueues this kind, and `settlement_announcements` is only ever
-- written by the drain at the moment of delivery — so these are here to make
-- the impossible case explicit instead of leaving it to sql_mode.
-- =============================================================================

DELETE FROM mail_outbox WHERE kind = 'payment_request';

-- Restating the whole list, as 026 and 030 did: MariaDB has no DROP VALUE, and
-- omitting one here is how a value is removed.
ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning'
) NOT NULL;

DELETE FROM settlement_announcements WHERE kind = 'payment_request';

-- The durable proof only ever records what was actually delivered, so it has
-- always been the two member-facing settlement kinds and nothing else.
ALTER TABLE settlement_announcements MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice'
) NOT NULL;
