# Human-Readable Mandate References

**Issue**: [#936](https://github.com/dgloeckner/clubbar/issues/936)
**Status**: Implemented — M1–M7 complete, each verified (counts under *Verification* below).
**Design**: ADR-0006 (amended 2026-09-14), ADR-0052 decision 4 (amended), ADR-0038 (portability)
**Branch**: `claude/gifted-gates-b9upoe`

---

## What this buys

The mandate reference (UMR) is the one identifier a member *reads*. It is the
**Mandatsreferenz** on their own Kontoauszug, the string they read aloud to the
Kassenwart when a collection is queried, and it is printed on the paper a
self-registering member signs. It was 32 hex characters from a UUID, which is
unreadable in all three places.

Nothing in SEPA asked for that: a UMR must be unique **per creditor**, at most
35 characters, in the SEPA character set. One install is one Gläubiger-ID, so a
single counter row is all the uniqueness that is owed.

**After this plan**: a newly opened mandate gets `CB-000042` — the club's prefix
and the next number from a per-install counter. Every existing reference is
untouched.

**Not contained here**:

- re-minting or migrating existing references (they are on signed paper and in
  collections already sent; a return is matched by `MREF+` months later);
- whether an admin may overwrite the reference of an existing mandate at all
  ([#164](https://github.com/dgloeckner/clubbar/issues/164)).

---

## Decisions taken

| # | Decision | Consequence |
|---|---|---|
| 1 | **A counter row drawn with `UPDATE … LAST_INSERT_ID(value + 1)`**, not `CREATE SEQUENCE` and not `SELECT … FOR UPDATE` | A sequence exists only on MariaDB ≥ 10.3 while `docs/deployment.md` promises MySQL 5.7 (ADR-0038), and it is non-transactional — the counter row rolls back with the transaction that drew from it, so the paper printed from a pending registration and the stored row can never name different numbers. `LAST_INSERT_ID()` is connection-scoped, so two overlapping requests each read their own number |
| 2 | **One mint site.** `MembersRepository::create()` no longer pre-mints from the member id; `openMandate()` is the only place a reference is drawn | The admin panel and self-registration draw from the same counter, which is what makes an admin creating a member while a registration lands safe to interleave |
| 3 | **Gaps are harmless** | A rejected or purged registration takes its number with it. Nothing reads a reference as a position |
| 4 | **The honeypot draws no number** (`MandateReferenceMinter::decoy()`) | A consumed number would let a bot read the club's mandate count off a fake receipt, and watch it move by probing — the single thing the trap exists to hide |
| 5 | **A configurable prefix** in `sepa_config`, default `CB`, SEPA charset, ≤ 10 characters | 10 leaves 24 characters for the number, which a BIGINT counter cannot reach — so a valid prefix guarantees a valid reference for every number this install will ever mint. The prefix exists mainly so references an admin types in for mandates carried over from another system cannot collide with the club's own sequence |
| 6 | **Existing references are never re-minted** | Both shapes coexist on an upgraded install, which is fine for the bank and required by [#165](https://github.com/dgloeckner/clubbar/issues/165) |

---

## Milestones

`[ ]` not started · `[~]` in progress · `[x]` passed (test verified) · `[!]` failed.

### M1 — The record [x]

- [x] ADR-0006 amended (status line, a dated amendment note, Core Principles 1 and 2
      struck through, Data Structures and the XML sample updated). History not rewritten
- [x] ADR-0052 decision 4 updated — the reference is no longer "32 hex characters from a
      UUID"; the shrink-to-fit paragraph says both shapes exist
- [x] `docs/erm-master.md`: `mandates.reference`, `pending_registrations.mandate_reference`,
      `sepa_config.mandate_reference_prefix` and a section for `mandate_reference_counter`

### M2 — Schema [x]

- [x] `069_mandate_reference_counter.sql` — counter table seeded at 0, prefix column on
      `sepa_config`; `db/rollback/069_*.down.sql` beside it
- [x] `MandateReferenceCounterSchemaTest` — the counter is a singleton, the prefix column
      defaults to NULL, and a 32-hex reference from before `069` still fits and is left alone
      (3/3 in the container)

### M3 — The minter [x]

- [x] `MandateReferenceCounter` (interface), `MandateReferenceCounterRepository` (the SQL),
      `MandateReferenceMinter` (formatting, prefix, decoy) under `App\Shared\Sepa`
- [x] `MandateReferenceMinterTest` — formatting, prefix fallback, padding as a floor, the 35-char
      bound at the widest prefix and number, the decoy, prefix validation (18/18)
- [x] `MandateReferenceCounterTest` (Feature) — the SQL half: consecutive draws, the row holds
      what was handed out, **a draw rolls back with its transaction**, two connections never
      draw the same number (5/5)

### M4 — Both mint sites call it [x]

- [x] `MembersRepository::openMandate()` mints; `create()` no longer pre-mints from the member
      id. An admin-named reference is still honoured; an explicitly empty one still opens no
      mandate; echoing the superseded mandate's reference still means "unchanged"
- [x] `RegistrationsService` mints via the minter, and the honeypot uses `decoy()`
- [x] Unit: the counter is not advanced by a honeypot submission, and the decoy is
      indistinguishable in shape
- [x] Feature: a minted reference carries prefix and number; two creates are **consecutive**; a
      bank change takes the next number while the ended mandate keeps its own; an admin-named
      reference draws no number

### M5 — The prefix is configurable [x]

- [x] Repository allowlist, DTO field, controller validation (SEPA charset + length), and a
      blank value normalized to NULL so clearing the field asks for the default back
- [x] `SepaConfigTab` field with `settings-sepa-input-mandate_reference_prefix` and siblings;
      client-side validation mirroring the backend's; both locale files

### M6 — The wire and the copy [x]

- [x] `api/admin.yaml`: `mandate_reference_prefix` on all three SEPA config schemas;
      `mandate_reference` examples and descriptions no longer say "UUID without hyphens"
- [x] Copy that described a reference as a UUID: `MemberMandateReferenceField` header,
      `StoredFieldBox` wrap comment, `MandateDocumentFiller`'s shrink-to-fit comment,
      `members.mandateReferenceHint` and `mandateReferenceAssignedNote` in both locales

### M7 — Tests that would have caught the old shape [x]

- [x] `e2etests/tests/admin/members.spec.ts` and `e2etests/tests/api/self-registration.spec.ts`
      assert the new shape instead of `/^[0-9a-f]{32}$/`
- [x] `e2etests/tests/api/mandate-references.spec.ts` — a short readable reference, increasing
      numbers from one counter, a bank change taking a later number, an admin-typed reference
      stored unchanged, and no IBAN meaning no reference
- [x] `SepaExportServiceTest`: both shapes reach `<MndtId>` unchanged, side by side

---

## Deliberately not done

- **No re-minting, and no admin action that could do it.** "Change" (typing a reference by
  hand) stays exactly as it was.
- **Strict consecutiveness is not asserted in E2E.** The counter is shared with every spec
  running in parallel and with self-registration, so a gap between two of a spec's own creates
  is correct behaviour. E2E asserts a later mint is a *larger* number; the exact-next assertion
  lives in the Feature suite, where the database is not shared.

---

## Verification

Run on the branch, against the dev stack:

| Suite | Result |
|-------|--------|
| `php8.3 vendor/bin/phpunit --testsuite Unit` | 3381/3381 |
| Feature suite in the container | 884/884 |
| `npx vitest run` (admin-frontend) | 780/780 |
| `npx tsc --noEmit` (admin-frontend) | clean |
| `api-tests` | see PR |
| `admin-chromium` | see PR |
