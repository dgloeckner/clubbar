# One Canonical Settlement Identifier

**Status**: Implemented (M1–M7 done and each verified)

**Branch**: `claude/abrechnungsid-readability-npi2fl`

---

## Context

A settlement had no identifier a person could use, and four that a machine could
— which disagreed with each other:

| Where | Before | Form |
|---|---|---|
| Admin URL, API, audit `entityId` | `settlements.id` | UUID |
| pain.008 `MsgId` | `SEPA-<12 random hex>` in `sepa_message_id` | random, unrelated to the UUID |
| pain.008 `PmtInfId` | `PMT-<first 16 hex of the UUID>` | truncated UUID |
| pain.008 `EndToEndId` | `E2E-<12 hex settlement>-<12 hex member>` | two truncated UUIDs |
| `Ustrd` — the member's bank statement | `<prefix> <settlement_date>` | **no identifier at all** |
| Vorabankündigung / cancellation mail | — | **no identifier at all** |
| CSV and XML downloads | `settlement-<uuid>.csv`, `sepa-<uuid>.xml` | raw UUID |

The two places a human actually looks named nothing, and the three places a
machine looks named the same settlement three different ways. A member asking
*"what was this €23.40 for?"* had no string to quote, and the Kassenwart had
nothing to look the question up by.

`use-cases/sepa/uc-sepa-07-settlement-finalize.md` had specified a readable
`SET-{YYYY}-{NNN}` since 2025, and `api/admin.yaml` still showed it in examples.
It was never built.

## Decision

**One canonical identifier: `settlements.id`, rendered as 32 lowercase hex
digits (hyphens stripped), used everywhere.** Not a new number, not a sequence.
The payoff is paste-matching: the string a member reads off their bank statement
finds the run in the admin panel, character for character.

Chosen over a per-year running number (`A-2026-0007`) in discussion —
consistency was worth more than brevity. No Mitgliedsnummer was introduced.

`EndToEndId` is the **single documented exception** and is unchanged. It must
name a member as well as a run *and* fit 35 characters; two 32-character
references are 64. It stays persisted, because a bank return arriving months
later resolves against the exact string that was sent (ADR-0032 §8, #150).

## Milestones

- [x] **M1 — The canonical rendering, in one place.** New
  `Settlements\Domain\SettlementReference` (`of()`, `normalise()`) beside
  `EndToEndId`, whose docblock now names itself as the exception. 5 unit tests,
  including one that fails if somebody tries to "finish the consolidation" by
  putting two references in an `EndToEndId`.
  *Verified*: `phpunit --filter SettlementReference` — 5/5.

- [x] **M2 — pain.008 uses it.** `MsgId` and `PmtInfId` both become the
  reference. The Verwendungszweck becomes `<prefix> <period> <reference>`,
  spending its 140 characters tail-first so the prefix truncates rather than the
  reference; the period falls back to `settlement_date` when
  `period_start`/`period_end` are null. Previously only the prefix was
  sanitised, with a 70-character cap, and the result was never capped at all.
  *Verified*: `phpunit --filter SepaExport` — 26/26; `api-tests` F7.

- [x] **M3 — `sepa_message_id` dropped.** Migration `052` + rollback. The
  column stored a value derivable from the primary key — the duplication
  ADR-0032 §1 refuses for `status` — and was the last place a divergent form
  could reappear. `SettlementDto::toArray()` emits `reference` in its place, so
  the API is the one authority for the rendering and the frontend never
  re-derives it.
  *Verified*: full backend suite, 2802 tests.

- [x] **M4 — The return lookup accepts it.** `findCollectionsByReference()`
  gains a third arm matching the hyphen-free settlement id, so a treasurer
  holding the Verwendungszweck rather than the EREF lands on the run instead of
  on "no match". The hyphen-stripped needle is separate from the existing one —
  stripping hyphens from that would stop `E2E-<a>-<b>` matching.
  *Verified*: `phpunit --filter findCollectionsByReference` — 10/10.

- [x] **M5 — The mail names the run.** The Vorabankündigung and the
  cancellation notice carry the reference, in both the HTML and the text part.
  The cancellation notice previously identified the retracted announcement by
  amount and date alone, which stops distinguishing two runs the moment a member
  is announced the same figure twice.
  *Not changed*: the Deckelauszug still says "aus Abrechnung 08/2026".
  ADR-0039 §91 deliberately keeps collection identifiers off that mail, and a
  32-hex string inside a line item is noise for a member who is not being
  collected from. Raised as a separate question.
  *Verified*: mail unit tests; `mail-chain` 10/10, asserting on what Mailpit
  actually delivered (E2E pattern 010).

- [x] **M6 — Treasurer-facing surfaces.** `SettlementReferenceTag` renders the
  reference abbreviated with a copy button in the list (a third line in the
  existing date cell, so no seventh column) and unabbreviated in the expanded
  row. Downloads become `<kind>-<date>-<reference>.<ext>` — date first so a
  folder of them sorts chronologically. The per-member CSV gains a leading
  `Settlement` column.
  *Verified*: vitest 464/464, `type-check` and `lint` clean,
  `admin-chromium`.

- [x] **M7 — Docs and specs.** The never-built `SET-{YYYY}-{NNN}` spec and its
  filename examples corrected in `use-cases/sepa/`, `api/admin.yaml`,
  `docs/erm-master.md` and `docs/datamodel.md`, with an explicit *"do not add a
  column for it"* note beside the settlements table.

## Open item, needs approval

**ADR-0008 §"Identifier Strategy" (`:52-55`, `:266-271`) is now stale.** It
already was on `EndToEndId` — it documents the `PMT-…-<n>` loop-index form that
`EndToEndId.php` replaced in #150 — and this change makes it stale on `MsgId`
and `PmtInfId` too. Project rules forbid amending an ADR without explicit
confirmation, so it is deliberately untouched. The amendment should also record
that **re-exporting a settlement reuses its `MsgId`** (true before this change
too, since the id was assigned at creation rather than export): if a bank runs a
duplicate-file check, that is why.
