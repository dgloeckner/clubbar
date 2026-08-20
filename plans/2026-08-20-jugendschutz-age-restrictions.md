# Jugendschutz: Age-Restricted Products

**Epic**: [#582](https://github.com/dgloeckner/clubbar/issues/582)
**Design**: ADR-0045 (to be written — highest existing is 0044)
**Branch**: long-lived `feature/jugendschutz`. One PR per milestone, each opened **against
`feature/jugendschutz`**, merged in dependency order. `main` sees a single merge at the end.
This mirrors the Tiered Admin Roles plan, which stacked PRs #526–#536 into
`feature/tiered-admin-roles`.

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

### M1 — Design of record

No production code. Establishes the binding decisions before anything is built.

- [ ] `adr/0045-age-restricted-products.md` — outline under **ADR-0045** below
- [ ] `adr/README.md` — add the 0045 row, **and the missing 0044 row**: the index table stops at
      0043 and `0044-tiered-admin-roles.md` was never listed
- [ ] `research/juschg-age-limits.md` — JuSchG § 9 basis, Abs. 1 vs Abs. 2, and why the *product*
      carries the limit rather than the category
- [ ] `CONTEXT.md` — new **Jugendschutz** term: the limit belongs to the product, the age to the
      member, the refusal happens at checkout and is a standing condition (no retry).
      `_Avoid_: age check, age gate, 18+, adult verification`
- [ ] `CONTEXT.md` — one sentence in **Getränkewart**: they set a product's legal age and still
      see no member and no birth date
- [ ] Use cases: amend `UC-A11`/`UC-A12` (required Date of Birth row), `UC-A41`/`UC-A42`
      (optional Minimum Age row); add error case **E7** to `UC-T12-error-scenarios.md` (has
      E1–E6); add a row to the **Terminal Access Requirements** table in
      `use-cases/terminal/README.md`
- [ ] **File the onboarding-form follow-up issue** — see **Follow-up issue** below
- [ ] `plans/INDEX.md` — add this plan as current

### M2 — Members: date of birth (backend)

- [ ] `backend/db/migrations/049_age_restrictions.sql` + `backend/db/rollback/049_age_restrictions.down.sql`
      — `ALTER TABLE members ADD COLUMN date_of_birth DATE NULL AFTER phone;`. No index: never
      filtered or sorted on, only read per member. Do **not** touch
      `idx_sync_combined (updated_at, deleted_at)` — delta sync rides it unchanged
- [ ] `Members/Controllers/AdminController.php` — add `date_of_birth` to `FIELD_RULES` (covers
      both paths: `store()` clones and appends `required`, `update()` does
      `array_intersect_key`), append it to the `required` list in `store()`, add it to
      `BLANK_MEANS_NULL`
- [ ] Past-date validation — `Shared/Validation/Validator.php` has no `before:`/`after:` rule.
      Add one shared `past_date` rule rather than a one-off service check: `mandate_signed_at`
      carries only `['nullable','date']` today and has the same "not in the future"
      requirement, so one rule fixes two fields
- [ ] `MembersRepository::create()` positional INSERT + `updateById()` `$allowed`
- [ ] **`MembersRepository::anonymize()` — add `date_of_birth = NULL`.** The docblock states the
      rule: *"Every column of the row that says something about the human being is nulled"*
      (#115). This is the load-bearing GDPR line of the whole change
- [ ] `MembersService::createMember()` takes **named scalar params**, not an array — thread
      `dateOfBirth` through controller → service → repository
- [ ] `MemberAdminDto` — expose `date_of_birth`
- [ ] `backend/db/seed.sql`, `backend/db/reset_test_data.sql` — birth dates on every fixture
      member, including one under 16 and one between 16 and 18
- [ ] Tests: `backend/tests/Unit/Modules/Members/` (rejects future dates, rejects missing on
      create, accepts omission on update); `backend/tests/Feature/.../Repositories/`
      (**anonymize nulls it** — assert explicitly); `e2etests/tests/api/members.spec.ts`,
      `admin-members-crud.spec.ts`, `member-blank-fields.spec.ts`, `anonymize-member.spec.ts`

### M3 — Products: minimum age (backend)

- [ ] Migration 049 (same file as M2) — `ALTER TABLE products ADD COLUMN min_age TINYINT UNSIGNED NULL AFTER requires_dispenser;`.
      No index: the terminal filters client-side from its cache
- [ ] `Products/Controllers/AdminController.php` — add `'min_age' => ['nullable','integer','gte:1','lte:99']`
      to `storeProduct()` **and** the `if (isset($body['min_age']))` branch in `updateProduct()`.
      Products has no shared `FIELD_RULES` const, so both sites need it *(extracting one is
      tempting and out of scope — note it, don't do it)*
- [ ] `ProductsRepository::create()` positional INSERT + `updateById()` `$allowed`
- [ ] `ProductDto` — expose `min_age`
- [ ] Seeds: one drink at `min_age = 16`, one at `18`
- [ ] Tests: `backend/tests/Unit/Modules/Products/`, `e2etests/tests/api/products.spec.ts`
      (create with/without, update to null, reject 0 and 100)

### M4 — Contracts

- [ ] `api/admin.yaml` — `Member`, `MemberCreateRequest` (add to `required`),
      `MemberUpdateRequest`, `Product`, `ProductCreateRequest`, `ProductUpdateRequest`. Copy
      `mandate_signed_at`'s shape exactly (`type: string, format: date, nullable: true`) and
      document the `""`-means-cleared semantics the request schemas already spell out.
      **`MemberListItem` deliberately does not get DOB** — the list does not need personal data
- [ ] `api/admin.yaml` — `MemberImportPreview`: CSV member import needs a DOB column
- [ ] `api/terminal.yaml` — `Member` (add `date_of_birth`; **its description currently claims
      "Contains only non-sensitive data" — rewrite it, do not leave it to rot**), `Product`
      (add `min_age`)
- [ ] `api/terminal.yaml` **prose appendix, lines 1659–1730** — restates the sync SELECT column
      lists verbatim; update or the doc lies
- [ ] `terminal-frontend/openapi/terminal.yaml` — a copy; keep in sync
- [ ] Regenerate `admin-frontend/src/api/generated/` (orval) and
      `terminal-frontend/lib/generated/terminal.swagger.dart` (build_runner). Neither is
      hand-edited
- [ ] Tests: `e2etests/tests/api/sync-members.spec.ts` asserts the field is present and typed in
      the delta payload; the products sync schema test in `products.spec.ts`

### M5 — Admin frontend

- [ ] `src/pages/MembersPage.tsx` — `date_of_birth: ''` in `formData` (~line 134); a required
      `<input type="date" data-testid="members-form-dob-input" max={toIsoDate(new Date())}>`.
      **Copy the mandate-date input at ~line 1716** — same control, same `max` guard. No new
      component; the page uses raw inline inputs by design
- [ ] `src/pages/ProductsPage.tsx` — `minAge: ''` in `formData` (~line 103); a number input near
      the price input (~line 965), beside the `requires_dispenser` checkbox. Empty =
      unrestricted, and the label must say so
- [ ] `public/locales/de.json`, `en.json` — labels, the "unrestricted" hint, validation messages
- [ ] Role check: confirm `Auth/Domain/RouteRoleMap.php` and `src/utils/adminRoles.ts` need
      **no** change — both fields ride existing endpoints. A new endpoint would need a
      `SECTION_ROLES` entry or the unit suite fails
- [ ] Tests: `e2etests/utils/members.ts` — add `dateOfBirth` to `CreateMemberOptions` and
      `createMemberViaPage` (**every existing caller now needs a value** — mandatory field);
      page objects gain locators; `tests/admin/members.spec.ts`, `tests/admin/products.spec.ts`,
      `tests/admin-mobile/members-*.spec.ts`

### M6 — Terminal: the gate

- [ ] `lib/database/schema/members_cache.dart` — `dateOfBirth`;
      `lib/database/schema/products_cache.dart` — `minAge`
- [ ] `lib/database/database.dart` — `schemaVersion` 10 → **11**, plus an `if (from < 11)` rung
      on the `onUpgrade` ladder using the existing `_addColumnIfNotExists` helper (~line 48)
- [ ] `lib/repository/members_repository.dart:86-97` and `products_repository.dart:88-104` —
      field-by-field Companion mapping; add both fields
- [ ] **`lib/services/cart_service.dart` `validateCartBeforeCheckout()`** — the enforcement
      point. Its comment already declares it *"the authority, not a duplicate"* of the UI-level
      disable. Add the age check **after** `cartEmpty` and **before** the credit-limit check: a
      refusal on legal grounds must not be masked by a money message
- [ ] Thread `minAge` onto `CartItem` (`lib/models/cart_item.dart`) the same way
      `requiresDispenser` already is, through `cartProvider.addItem()`. Preferred over
      re-reading the products cache at validation time — matches the existing shape and keeps
      the check synchronous
- [ ] `lib/models/terminal_error.dart` — new `TerminalErrorKey` for the refusal
- [ ] `lib/l10n/terminal_error_messages.dart` + `app_de.arb` + `app_en.arb`. The message names
      the **required** age and must **not** state the member's age or birth date — the terminal
      screen is visible to others (`research/art9-rfid-display-retention.md`)
- [ ] `lib/controllers/checkout_action.dart` — route to the **no-Retry** branch. Age is a
      standing condition like `balanceLimitExceeded`, not a transient failure
- [ ] Preventive UI: `lib/widgets/styled_components/product_card.dart` gains a disabled/badged
      state, driven from `product_selection_screen.dart:333-345`. The tile is a courtesy; the
      cart service is the authority
- [ ] Tests: `test/services/cart_service_test.dart` — under-age blocked, **exactly-of-age-today
      allowed** (the birthday boundary, where off-by-one errors live), over-age allowed,
      unrestricted product always allowed, NULL birth date blocked;
      `test/providers/cart_provider_test.dart`

### M7 — Server: record and flag

Per **Why the server records rather than rejects** above. The transaction is stored; the
violation is raised.

- [ ] Locate the transaction ingest path for `POST /api/sync/transactions` —
      `TransactionsService::processBatch` is named in ADR-0020's amendment as the place the old
      `sepa_invalid` rejection lived, so the flagging belongs beside that logic. Verify before
      editing
- [ ] Derive the member's age from `members.date_of_birth` **at the transaction's own
      timestamp**, not at ingest time — a terminal may sync days later, and the question is
      whether the sale was lawful *when it happened*
- [ ] Store the transaction unchanged. No rejection, no auto-storno
- [ ] Raise a **Jugendschutz violation**: an audit entry plus a surfaced item the admin must
      acknowledge. Reuse the existing terminal-anomaly channel (#550) rather than inventing one.
      Unlike ADR-0020's `ineligible_members` bucket this must **not** clear itself — a past
      violation stays true no matter what changes later
- [ ] Tests: `e2etests/tests/api/` — post a transaction for an underage member against a
      restricted product; assert the row **is** created, the violation is raised, and it does
      not clear when the product's `min_age` is later removed

### M8 — Documentation

- [ ] `docs/erm-master.md` — `members` (§~272), `products` (§~348), the **mermaid ER diagram
      (lines 7–80)**, the **GDPR Anonymization Mapping table (~958–985)** with a Before→After
      row for `date_of_birth`, and the erasure table (~312–318).
      *Two pre-existing drifts to fix or file while in there: the products table omits
      `requires_dispenser`/`deleted_at`/`deleted_by_admin_id`, and the mapping table claims
      `preferred_language → NULL` which the code does not do*
- [ ] `docs/erm-frontend.md` — `members_cache` (~79) **including its "Data NOT cached
      (privacy/data minimization)" list, which decision 1 changes**; `products_cache` (~128);
      the stale duplicate SQL block (~320–395)
- [ ] `docs/datamodel.md` — all four tables
- [ ] `docs/legal-requirements-and-how-we-meet-them.md` — **new §4 Jugendschutz** (renumber
      Gemeinnützigkeit to §5): JuSchG § 9 as the requirement; product `min_age` + member
      `date_of_birth` + terminal block + server flag as the mechanism. Plus a §3 row for the new
      processing purpose, cross-linked to the onboarding-form issue
- [ ] `docs/role-based-access.md` — the Getränkewart sets a legal age, still sees no member
- [ ] This plan and `plans/INDEX.md` refreshed; moved to Completed with a date

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

## ADR-0045 outline

House template: `# ADR-NNNN: Title`, **Status**/**Date**/**Deciders**, `---`, Context, Decision,
Consequences (✅/❌/Mitigations), Alternatives Considered, Related Decisions, References. Minimal
code; Mermaid for the checkout sequence.

**Context** — JuSchG § 9 as a hard external constraint; the club serves alcohol; nothing
enforces it; the offline terminal must decide without the server.

**Decision** — the four decisions above. Load-bearing paragraphs:

1. **Why the limit sits on the product, not the category.** A category is a display grouping the
   Getränkewart rearranges freely. A legal limit must not move when a tile is dragged.
2. **Why the raw birth date is synced.** Age is not a stable attribute — it changes on a date the
   server cannot predict a sync for. A precomputed flag or age-in-years is silently wrong the
   moment a member has a birthday between syncs. State plainly that this puts personal data on
   the kiosk, that `docs/erm-frontend.md`'s minimisation list is amended, and what compensates:
   the kiosk sits on club premises, the cache holds no contact or banking data, and erasure
   propagates through the same delta sync.
3. **Why the server records and flags rather than rejects.** This **applies** ADR-0020's
   two-layer rule rather than departing from it — cite the #162 amendment directly, then add the
   § 146 AO argument it did not need to make. Note explicitly that an earlier draft chose
   rejection and why that was wrong, so the next reader does not re-derive it.
4. **Why mandatory, and how that coexists with erasure.** The DDL/validation split, with the
   `first_name` precedent.

**Consequences** — ❌ personal data on kiosks; ❌ a violation channel someone must actually watch;
❌ a mandatory field that erasure must still be able to clear. Mitigations numbered.

**Alternatives Considered** — derived threshold dates per member (`{"16": "2026-03-04", …}`:
minimal and offline-correct, rejected as over-engineered for the actual privacy delta);
precomputed `age_years` (rejected: wrong between birthdays); category-level limits (rejected:
categories are presentation); **server-side rejection** (rejected: destroys the record of a real
sale, conflicts with § 146 AO, and was already removed once in #162); auto-storno on violation
(rejected: the club eats the cost, and a storno reverses an admin decision, not a legal one).

**Related Decisions** — 0002 (product schema and sync contract), 0004 (immutability), **0020
(the two-layer rule this follows)**, 0028 and 0029 (DOB as erasable operational data), 0033
(sync contract), 0044 (role split).

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

Manual proof for M6, against the real kiosk UI:

1. Seed a member born 17 years ago and a product with `min_age = 18`.
2. Scan in, tap the product → tile shows the restricted state.
3. Force it into the cart past the UI and press Buy → refusal modal, **no Retry button**.
4. Repeat with a member born **exactly 18 years ago today** — must be allowed.

Screenshots go in `docs/reviews/`, matching the existing
`docs/reviews/2026-08-10-terminal-ux/proof-*.png` convention.
