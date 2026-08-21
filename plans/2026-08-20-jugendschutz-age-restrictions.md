# Jugendschutz: Age-Restricted Products

**Epic**: [#582](https://github.com/dgloeckner/clubbar/issues/582)
**Design**: [ADR-0045](../adr/0045-age-restricted-products.md)
**Status**: **M1–M9 implemented and verified.** Open as a stacked queue of PRs, each based on the
milestone before it and retargeting automatically as the lower ones merge.
**Branch**: one PR per milestone. This mirrors the Tiered Admin Roles plan, which stacked
PRs #526–#536 into `feature/tiered-admin-roles`.

| Milestone | PR | Branch |
|---|---|---|
| M1 + M2 — design of record, and the member's birth date | [#606](https://github.com/dgloeckner/clubbar/pull/606) | `claude/next-slice-tdd-x547l3` |
| M3 — the product's minimum age | [#607](https://github.com/dgloeckner/clubbar/pull/607) | `claude/jugendschutz-m3-product-min-age` |
| M4 — the terminal contract | [#608](https://github.com/dgloeckner/clubbar/pull/608) | `claude/jugendschutz-m4-terminal-contract` |
| M5 — the admin product form | [#610](https://github.com/dgloeckner/clubbar/pull/610) | `claude/jugendschutz-m5-product-form` |
| M6 — the terminal refuses, offline | [#616](https://github.com/dgloeckner/clubbar/pull/616) | `claude/jugendschutz-m6-terminal-gate` |
| M7 — the server records and flags | [#621](https://github.com/dgloeckner/clubbar/pull/621) | `claude/jugendschutz-m7-violation` |
| M8 — documentation, ERM and legal mapping | this plan | `claude/jugendschutz-m8-docs` |
| M9 — the overview highlights restricted products | — | `claude/age-restricted-products-highlight-x6pluh` |

M1 and M2 shipped together: M1 is documentation with no production code, and splitting the ADR
from the first migration that obeys it would have put a decision on `main` with nothing
implementing it.

---

## What this buys

The bar serves alcohol. **JuSchG § 9** sets hard limits — beer, wine and sparkling wine from 16,
spirits from 18 — and nothing in the system enforces them. A member's card opens the product
grid and every product on it is bookable.

The gap is recorded, not newly discovered.
`research/175-onboarding-form-datenschutz.md:348`:

> "For a bar serving alcohol this is not a formality — JuSchG limits are a separate constraint
> on the product, and a minor's onboarding must not produce an alcohol-purchasing card."

There is no date-of-birth field on members and no age attribute on products. Grep for `birth`,
`dob`, `min_age`, `age_restricted` returns only false positives (`RESTRICT` FK clauses, HSTS
`max-age`, the `beer-alcohol-free` icon name).

**After this plan**: a member carries a date of birth; a product may carry a minimum legal age;
the terminal refuses the combination offline; and a sale that slipped through a stale terminal
is recorded and raised to the Kassenwart rather than silently absorbed.

**Not contained here**: the club-website onboarding form (separate repo, separate issue), and
any form of identity verification. The system trusts the birth date on file.

---

## Decisions taken

| # | Decision | Consequence |
|---|---|---|
| 1 | Terminal sync carries the **raw birth date**; the terminal computes age locally | Correct on any day, arbitrarily long offline. Cost: a real birth date now lives on each kiosk, contradicting `docs/erm-frontend.md`'s "Data NOT cached (privacy/data minimization)" list — that list must be amended and ADR-0045 must own the trade-off |
| 2 | Date of birth is **mandatory** for members (product is not live; no backfill) | No fail-open branch, no "unknown age" path, no settings surface |
| 3 | Terminal **blocks** preventively; server **records and flags**, never rejects | Follows ADR-0020's established two-layer split rather than departing from it — see below |
| 4 | Product field is a **free nullable integer** `min_age`, 1–99, NULL = unrestricted | Not a German-law enum; this is self-hosted open source, not only German clubs |

### Why the server records rather than rejects

An earlier draft of this plan had the server reject underage transactions. Reading
[ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md) in full showed that exact
rejection was **already tried and deliberately removed** ([#162](https://github.com/dgloeckner/ruderbar/issues/162)):

> "The server must therefore store and flag such transactions, never reject them. Rejecting at
> sync destroys the record of a sale that actually happened — revenue lost silently,
> unrecoverable, because the beer is gone either way."

That reasoning transfers to Jugendschutz with **more** force, not less:

1. **The drink is gone.** A terminal decides from its last sync. By the time the row reaches the
   server the sale physically happened. Rejecting it does not un-serve the minor — it only
   deletes the club's knowledge that it occurred.
2. **§ 146 Abs. 1 AO requires Einzelaufzeichnung with no exemption available to us**
   (`docs/legal-requirements-and-how-we-meet-them.md` §1.3). An unrecorded sale is its own legal
   problem. Trading a youth-protection incident for a bookkeeping violation is not a trade worth
   making.
3. **A silent rejection is the worst outcome**: the terminal believes the sale succeeded, the
   member was served, and nobody is told. Youth protection needs a human to *find out*, which
   is the opposite of dropping the row.

So the server records the transaction and raises a **Jugendschutz violation** that an admin must
acknowledge. The club learns it happened and can act on the actual problem — a stale terminal, a
wrong birth date, or a member who needs a conversation.

### The tension worth naming

Decision 2 says mandatory; GDPR erasure says deletable. `MembersRepository::anonymize()` nulls
every column that says something about the human, and
`docs/legal-requirements-and-how-we-meet-them.md:58` already lists **DOB among the deleted
fields** (OLG Dresden 4 U 1278/21), caveated at line 69 as holding *only because the club issues
no invoices*.

**Resolution**: `DATE NULL` in the DDL, `required` in the create rules. Not a new pattern —
`first_name`/`last_name` are `VARCHAR(100) NULL` in `001_initial_schema.sql` and get `required`
appended in `store()` today. A NULL birth date therefore means exactly one thing: **an
anonymised member**, who cannot book anyway.

---

## Milestones

Ordered by dependency. Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed
(test verified) · `[!]` failed.

### M1 — Design of record ✅

No production code. Establishes the binding decisions before anything is built.

- [x] `adr/0045-age-restricted-products.md` — four decisions, six invariants, the alternatives
      table and a sequence diagram of the two enforcement layers
- [x] `adr/README.md` — the 0045 row **and** the missing 0044 row (the index stopped at 0043)
- [x] `research/juschg-age-limits.md` — JuSchG § 9 basis, Abs. 1 vs Abs. 2, and why the *product*
      carries the limit rather than the category
- [x] `CONTEXT.md` — the **Jugendschutz** term, with the `_Avoid_` list
- [x] `CONTEXT.md` — one sentence in **Getränkewart**: they set a product's legal age and still
      see no member and no birth date
- [x] Use cases: `UC-A11`/`UC-A12` (required Date of Birth row), `UC-A41`/`UC-A42` (optional
      Minimum Age row), error case **E7** in `UC-T12-error-scenarios.md`, and a row in the
      **Terminal Access Requirements** table of `use-cases/terminal/README.md`
- [x] The onboarding-form follow-up issue — [#591](https://github.com/dgloeckner/clubbar/issues/591)
- [x] `plans/INDEX.md` — this plan listed (done in M8, with the plan file itself)

### M2 — Members: date of birth (backend) ✅

- [x] `backend/db/migrations/049_age_restrictions.sql` + rollback — `date_of_birth DATE NULL
      AFTER phone`, no index, `idx_sync_combined` untouched
- [x] `Members/Controllers/AdminController.php` — `date_of_birth` in `FIELD_RULES`, appended to
      `store()`'s required list, and in `BLANK_MEANS_NULL`
- [x] A shared `past_date` rule in `Shared/Validation/Validator.php` rather than a one-off
      service check. `date` and `past_date` were made to share one `parseDate()`, so a malformed
      value is reported once instead of twice
- [x] `MembersRepository::create()` INSERT, `updateById()` `$allowed`
- [x] **`MembersRepository::anonymize()` nulls `date_of_birth`** — the load-bearing GDPR line of
      the whole change, asserted explicitly
- [x] `MembersService::createMember()` named param threaded controller → service → repository.
      `'date_of_birth'` also had to go in the service's `$updatable` allow-list, which silently
      dropped a corrected birth date until an API test caught it — the unit test mocked the
      service and could not see it
- [x] `MemberAdminDto` exposes `date_of_birth`
- [x] `backend/db/reset_test_data.sql` — birth dates on every fixture member. `seed.sql` needed
      none: it carries no members at all
- [x] **On update, naming the field means giving a value.** `PATCH {"date_of_birth": ""}` is a
      422, not a silent erasure — the only path that may empty it is anonymization
- [x] Tests: `ValidatorPastDateTest`, `AdminControllerDateOfBirthTest`,
      `member-date-of-birth.spec.ts`, plus the existing member specs updated for a now-mandatory
      field

### M3 — Products: minimum age (backend) ✅

- [x] Migration 049 (same file as M2) — `min_age TINYINT UNSIGNED NULL AFTER requires_dispenser`
- [x] `Products/Controllers/AdminController.php` — `['nullable','integer','gte:1','lte:99']` on
      both the create and update paths. The update branch is keyed on **`array_key_exists`**,
      not `isset`: `isset` reads an explicit null as absent and would skip the bounds check on
      exactly the clearing write
- [x] `ProductsRepository::create()` INSERT, `updateById()` `$allowed`
- [x] `ProductDto` exposes `min_age` — which also puts it on the terminal sync, shared DTO
- [x] `reset_test_data.sql` — beers and Äppler at 16, a Kräuterlikör at 18
- [x] Tests: `product-min-age.spec.ts` (create with and without, update to null, reject 0, 100
      and a fractional value)

### M4 — Contracts ✅

- [x] `api/admin.yaml` — `Member`, `MemberCreateRequest` (in `required`), `MemberUpdateRequest`,
      `Product`, `ProductCreateRequest`, `ProductUpdateRequest`. `MemberListItem` deliberately
      does **not** get the birth date
- [x] `api/admin.yaml` — the CSV member-import column list
- [x] `api/terminal.yaml` — `Member` gains `date_of_birth` (and the "contains only
      non-sensitive data" claim was rewritten rather than left to rot); `Product` gains `min_age`
- [x] `api/terminal.yaml` prose appendix — both sync SELECT column lists
- [x] `terminal-frontend/openapi/terminal.yaml` kept a faithful copy
- [x] Regenerated `admin-frontend/src/api/generated/` with **orval 8.5.3**, the pinned version.
      A newer orval rewrites all 285 files with `.ts` import extensions; and
      `terminal-frontend/lib/generated/terminal.swagger.dart` via build_runner
- [x] Tests: `MemberDtoTest` pins the terminal payload's entire key set;
      `sync-jugendschutz.spec.ts` asserts both fields over the wire, including that an erasure
      takes the birth date back off the kiosks on the ordinary delta

> **Finding, reported not fixed:** `TerminalOasValidator` is dead code. It is mounted only under
> `APP_ENV=test`, which nothing sets, and the spec file is not mounted into the container, so it
> would throw `OAS spec not found` if it ever ran. The terminal contract is held up by tests, not
> by middleware — which is why `MemberDtoTest` pins the key set.

### M5 — Admin frontend ✅

- [x] `src/pages/MembersPage.tsx` — a required date input with `max={toIsoDate(new Date())}`,
      copied from the mandate-date control (shipped with M2, where the field became mandatory)
- [x] `src/pages/ProductsPage.tsx` — a number input beside the price, empty meaning
      unrestricted, and `min_age` **always present in the update payload** so clearing it
      actually clears it
- [x] `public/locales/de.json`, `en.json` — labels, the "unrestricted" hint, validation messages
- [x] Role check: no change needed. Both fields ride existing endpoints, so `RouteRoleMap` and
      `SECTION_ROLES` are untouched
- [x] Tests: `e2etests/utils/members.ts` threads `dateOfBirth` through every caller;
      `role-flows.spec.ts` has the Getränkewart set, correct and clear a legal age and still take
      403 from the member roster

### M6 — Terminal: the gate ✅

- [x] `members_cache.dart` gains `dateOfBirth`; `products_cache.dart` gains `minAge`
- [x] `database.dart` — `schemaVersion` bumped with an `onUpgrade` rung using
      `_addColumnIfNotExists`, so an existing kiosk converges rather than being wiped
- [x] `members_repository.dart` / `products_repository.dart` — both fields in the Companion
      mapping
- [x] **`cart_service.dart` `validateCartBeforeCheckout()`** — the enforcement point, after
      `cartEmpty` and **before** the credit-limit check, so a refusal on legal grounds is never
      masked by a money message
- [x] `minAge` threaded onto `CartItem` through `cartProvider.addItem()`, the way
      `requiresDispenser` already is — which keeps the check synchronous and pure
- [x] `lib/utils/age.dart` — `hasReachedAge`, `parseCachedDateOfBirth`, `mayBuyAtAge`. One
      definition of "old enough", shared by the authority and by the tile that greys out, so the
      two cannot disagree
- [x] `terminal_error.dart` — a new key; `terminal_error_messages.dart` + `app_de.arb` +
      `app_en.arb`. The message names the **required** age and never the member's
- [x] `checkout_action.dart` — the no-Retry branch. Age is a standing condition, not a transient
      failure
- [x] `product_card.dart` gains a restricted state, driven from `product_selection_screen.dart`
- [x] Tests: `age_test.dart`, `cart_service_test.dart` (under age blocked, **exactly of age
      today allowed**, over age allowed, unrestricted always allowed, NULL birth date blocked),
      `cart_provider_test.dart`, `database_migration_test.dart`

### M7 — Server: record and flag ✅

The transaction is stored; the violation is raised.

- [x] `TransactionsService::processBatch` — the flag runs after the insert and **only for a row
      this call actually inserted**, so a replayed batch does not write the record twice and a
      row the database refused is never named in an append-only table it does not appear in
- [x] `Shared/Utils/Age.php` — `yearsAt()` derives the age at **the transaction's own
      timestamp**, and returns null rather than a guess when the date cannot be read
- [x] The transaction is stored unchanged. No rejection, no auto-storno — asserted in every test
      that raises a violation, because the whole risk of the flag is it quietly becoming a
      refusal
- [x] Migration `050_jugendschutz_violation.sql` + rollback widens `audit_log.action`; the new
      `jugendschutz_violation` entry is filed under the **transaction** and carries ids,
      `min_age` and `age_at_sale` — never a name and never a birth date, so a later erasure
      leaves nothing behind
- [x] The record does not clear when the product is later un-restricted (invariant 4)
- [x] Tests: `AgeTest` (15 cases, boundary and leap day), `jugendschutz-violation.spec.ts`
      (9 tests), and unit tests over `processBatch` for the same paths — the E2E suite
      contributes to no clover report, so without them the patch-coverage gate saw the whole
      method as untested

> **Deviation from the plan, argued rather than assumed.** The plan said to reuse the
> `terminal_anomalies` channel (#550). Three properties of that table make it the wrong home: its
> terminal FK is `ON DELETE CASCADE`, so retiring a till would erase the violations recorded
> through it; it coalesces repeats into one row with an `occurrence_count`, which loses the
> per-sale record § 146 AO wants; and it is built to be closed via `acknowledged_at`, which is
> exactly what invariant 4 forbids. The audit log has none of those. The **notification** half —
> a violation reaching a human without them opening the panel — is deferred to
> [#622](https://github.com/dgloeckner/clubbar/issues/622) rather than half-built: a violation's
> subject is a *transaction*, and `MailSubject` has no case for one.

### M8 — Documentation ✅

- [x] `docs/erm-master.md` — `members` and `products` tables, the mermaid ER diagram, the GDPR
      Anonymization Mapping table, the erasure table, and the `audit_log` action list
- [x] Both pre-existing drifts fixed rather than inherited: the products table and diagram were
      missing `requires_dispenser`, `deleted_at` and `deleted_by_admin_id`, and the mapping table
      claimed `preferred_language → NULL`, which `anonymize()` does not do and could not do — the
      column is `NOT NULL`. `account_holder_name`, `collection_hold_reason` and
      `deleted_by_admin_id` were missing from the mapping too
- [x] `docs/erm-frontend.md` — `members_cache` including the **"Data NOT cached (privacy/data
      minimization)" list**, which now states the trade instead of a claim that stopped being
      true; `products_cache`; and the stale duplicate SQL block, replaced by a pointer to the
      Drift schema that generates it
- [x] `docs/datamodel.md` — `members`, `products`, `members_cache`, `products_cache`
- [x] `docs/legal-requirements-and-how-we-meet-them.md` — new **§4 Jugendschutz** (eight rows,
      JuSchG § 9 to the audit entry), Gemeinnützigkeit renumbered to §5, and rows 3.10 and 3.11
      for the new processing purpose and the kiosk-cache minimisation, cross-linked to
      [#591](https://github.com/dgloeckner/clubbar/issues/591)
- [x] `docs/role-based-access.md` — the Getränkewart sets a legal age and still sees no member
- [x] This plan and `plans/INDEX.md`

### M9 — The product list says which drinks are restricted ✅

M5 put the age on the *form*. That left the number invisible from the list, so the only way to
learn whether a drink was restricted — or to spot a wrong age among fifty products — was to open
each one in turn. The overview is where a Getränkewart actually works.

- [x] `admin-frontend/src/utils/ageRestriction.ts` — `ageRestrictionOf()`, with the unit test.
      Not an inline `product.min_age && …`: `min_age` is a number, and a truthiness test reads a
      stored 0 as unrestricted. The column cannot hold 0 today, which is exactly why the inline
      version would look correct until the bound moved
- [x] `ProductsPage.tsx` — a `warning`-variant `Badge` beside the product name, matching the
      existing `requires_dispenser` badge. Orange rather than the dispenser badge's blue: the two
      appear on the same row and mean different things
- [x] The **mobile card too**, beside the category — on a phone the card *is* the overview, so a
      desktop-only badge would hide the restriction on the smaller screen
- [x] `products.minAgeBadge` in `de.json` / `en.json` — "ab 16" and "16+", the two idioms; the
      digits, not the wording, are what the test asserts on
- [x] Tests: the existing `product minimum age` flow now asserts the badge at each of its four
      states (default, set, corrected, cleared) alongside the stored row — a badge that disagrees
      with the column is a wrong answer given confidently
- [x] `use-cases/admin/UC-A40-list-products.md` — the badge in the columns table and in the test
      derivation

Not in scope: filtering or sorting the list by minimum age. Nothing asked for it, and a fourth
filter earns its place only once a club has enough restricted products to need one.

---

## Follow-up issue: the club-website onboarding form

Filed in M1. The form is hosted on the club website — different codebase, out of scope here. But
it is **not merely "add a field"**:

`research/175-onboarding-form-datenschutz.md` §2.2 already lists **DOB under purpose 1**
(membership administration, **Art. 6(1)(b)**, confidence *Very High*). The field is likely
already collected and the basis for holding it is settled.

**What changes is the purpose.** Using that birth date to gate alcohol sales is a *further
processing purpose* with a *different basis* — **Art. 6(1)(c) i.V.m. § 9 JuSchG**, a legal
obligation rather than contract performance. Art. 13 requires each purpose to be named with its
basis, so the Datenschutzhinweis needs a new row **even if the input field already exists**.

The software will begin enforcing before the notice catches up. That gap is the reason this is
tracked rather than assumed.

---

## ADR-0045

Written in M1 and now the design of record:
[`adr/0045-age-restricted-products.md`](../adr/0045-age-restricted-products.md). The outline that
stood here has been removed rather than kept beside the thing it described — a plan that
paraphrases an ADR is a second copy that drifts.

It carries the four decisions, the alternatives that were rejected and why (derived threshold
dates per member, a precomputed `age_years`, category-level limits, **server-side rejection** —
which #162 already built once and removed — and auto-storno), and the consequences with their
mitigations: personal data on kiosks, a violation channel somebody has to watch, and a mandatory
field that erasure must still be able to clear.

---

## Rules that hold across every milestone

1. **The cart service is the authority.** Greyed-out tiles are a courtesy. Every refusal path
   must hold with the UI bypassed.
2. **Age is computed at the moment that matters** — the terminal at checkout, the server at the
   transaction's own timestamp. Never at sync time, never cached as a number.
3. **A NULL birth date means an anonymised member**, never "unknown, allow anyway".
4. **A recorded violation never clears itself.** Unlike a derived SEPA flag, a past sale to a
   minor stays true regardless of what changes afterwards.
5. **The Getränkewart gains no member data.** They set a number on a product; they still see no
   name, no Deckel, no birth date.
6. **Never state a member's age or birth date on the terminal screen.** The refusal names the
   required age, not the member's.
7. **Regenerated clients are regenerated, not edited** — orval for admin, build_runner for the
   terminal; `terminal-frontend/openapi/terminal.yaml` stays a faithful copy.
8. **No migration is edited once applied** — `MigrationRunner` checksums them and fails hard.

---

## Non-goals

· The **club-website onboarding form and its Art. 13 notice** — separate repository, separate
issue · ID verification of any kind: the system trusts the birth date on file, it does not check
a Personalausweis · Per-category or per-terminal age rules · Time-of-day serving restrictions ·
A "guardian present" override (JuSchG § 9 Abs. 2 — real law, deliberately not modelled) ·
Backfilling birth dates for existing members (product is not live) · Blocking terminal *access*
on age — an underage member may still buy unrestricted products · Extracting a shared
`FIELD_RULES` const in the Products controller

---

## Verification

```bash
scripts/dev-setup.sh --with-frontend          # daemon, deps, stack, migrate, seed, browsers

# backend unit + feature — INSIDE the container; host PHP lacks bcmath
docker compose exec -w /app backend ./vendor/bin/phpunit

cd e2etests
npx playwright test --project=api-tests
npx playwright test --project=admin-chromium
node scripts/report-failures.mjs              # the report CI prints

cd ../terminal-frontend && flutter test
```

Everything above was run per milestone before its commit. The backend suite reports one
pre-existing failure unrelated to this work — `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`,
which fails identically on the base commit.

Manual proof for M6, against the real kiosk UI:

1. Seed a member born 17 years ago and a product with `min_age = 18`.
2. Scan in, tap the product → tile shows the restricted state.
3. Force it into the cart past the UI and press Buy → refusal modal, **no Retry button**.
4. Repeat with a member born **exactly 18 years ago today** — must be allowed.

Screenshots go in `docs/reviews/`, matching the existing
`docs/reviews/2026-08-10-terminal-ux/proof-*.png` convention.
