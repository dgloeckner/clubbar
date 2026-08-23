# Per-Member Credit Limits

**Epic**: [#555](https://github.com/dgloeckner/clubbar/issues/555)
**Design**: [ADR-0046](../adr/0046-configurable-credit-limits.md)
**Status**: **M1–M9 implemented and verified.** Open as a stacked queue of PRs, each based on the
slice before it and retargeting automatically as the lower ones merge.
**Branch**: one PR per slice, each based on the branch before it and the lowest one on a
long-lived `feature/credit-limits`. Epic #555 states no branching convention of its own, so this
follows the one the Jugendschutz epic ([#582](https://github.com/dgloeckner/clubbar/issues/582))
established: a queue of stacked PRs, one per milestone.

| Milestone | Issue | PR | Branch |
|---|---|---|---|
| M1 — the design of record | [#556](https://github.com/dgloeckner/clubbar/issues/556) | [#659](https://github.com/dgloeckner/clubbar/pull/659) (merged) | `claude/inspiring-mayer-zgnexv` |
| M2 — the club's ceiling becomes configuration | [#557](https://github.com/dgloeckner/clubbar/issues/557) | [#665](https://github.com/dgloeckner/clubbar/pull/665) | `claude/inspiring-mayer-zgnexv` |
| M3 — a member may carry a ceiling of their own | [#558](https://github.com/dgloeckner/clubbar/issues/558) | [#667](https://github.com/dgloeckner/clubbar/pull/667) | `claude/inspiring-mayer-zgnexv-558` |
| M4 — every backend answer resolves it | [#559](https://github.com/dgloeckner/clubbar/issues/559) | [#669](https://github.com/dgloeckner/clubbar/pull/669) | `claude/inspiring-mayer-zgnexv-559` |
| M5 — the terminal contract | [#560](https://github.com/dgloeckner/clubbar/issues/560) | [#671](https://github.com/dgloeckner/clubbar/pull/671) | `claude/inspiring-mayer-zgnexv-560` |
| M6 — the terminal enforces it, offline | [#561](https://github.com/dgloeckner/clubbar/issues/561) | [#672](https://github.com/dgloeckner/clubbar/pull/672) | `claude/inspiring-mayer-zgnexv-561` |
| M7 — Settings splits by role, and gains a Limits tab | [#562](https://github.com/dgloeckner/clubbar/issues/562) | [#673](https://github.com/dgloeckner/clubbar/pull/673) | `claude/inspiring-mayer-zgnexv-562` |
| M8 — the member form and the dashboard | [#563](https://github.com/dgloeckner/clubbar/issues/563) | [#675](https://github.com/dgloeckner/clubbar/pull/675) | `claude/inspiring-mayer-zgnexv-563` |
| M9 — E2E coverage, use cases and data-model docs | [#564](https://github.com/dgloeckner/clubbar/issues/564) | this plan | `claude/inspiring-mayer-zgnexv-564` |

---

## What this buys

The ceiling a member's Deckel may reach was **already enforced** ([#24](https://github.com/dgloeckner/clubbar/issues/24)) —
the terminal blocks a checkout past it and warns from the band below. What it was not, was
*configurable*: the number was a constant duplicated in two languages, in
`terminal-frontend/lib/config/app_config.dart` and in a backend class. A club wanting a different
ceiling needed a rebuilt terminal binary and a backend deploy, **released together**, or the
dashboard's near-limit list and the Deckelauszug would start naming a figure nobody was holding.

**After this plan**: the club sets its ceiling and warning band in the admin panel, and a member
who needs a different one carries their own. The terminal learns both on its ordinary sync cycle
and keeps refusing offline.

**Not contained here**: any automatic ceiling — no scaling by tenure, arrears history or
settlement behaviour. A ceiling is a number a human sets and another human can read.

---

## Decisions taken

| # | Decision | Consequence |
|---|---|---|
| 1 | `NULL` on `members.credit_limit_cents` = *follow the club default*; `0` = *unlimited for this member* | Raising the club's ceiling lifts everyone not deliberately excepted, which is the whole point of a default. A `NOT NULL DEFAULT` column would have frozen each member at whatever the default was on the day they were created |
| 2 | A negative ceiling is **refused by the API**, not stored | `limitCents <= 0` already reads as "not enforced" at the terminal, so a negative value would pass that test by accident and mean the opposite of what whoever typed it intended |
| 3 | The warning band is **club-wide only** | One knob per member keeps the resolution rule to a single line on each side of the wire, which is what makes duplicating it in Dart safe |
| 4 | The band boundary is **integer division** on both sides (`intdiv`, `DIV`) | Dart, PHP and MariaDB agree on the cent at which a warning starts, so the dashboard and the kiosk cannot disagree by one |
| 5 | The club default rides its **own** `GET /api/sync/config`, not `/sync/members` and not `/health` | `/sync/members` is a delta on `updated_at` and a club setting touches no member row, so a synced terminal would never see the change. `/health` carries only what a terminal needs *before* it can authenticate, and a spending ceiling is not that |
| 6 | Signed `INT`, not `INT UNSIGNED` | An out-of-range write is a rejected value rather than a silently clamped one. ADR-0046 was amended to say so (commit `bb510ea`) with the user's explicit confirmation, the ADR rules requiring it |
| 7 | The terminal **prevents**, the backend **records** | Unchanged from [ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md): a sale a stale terminal let through is stored, never rejected |

### Why the club setting is TREASURY and not admin-only

A ceiling is a money decision, and [ADR-0044](../adr/0044-tiered-admin-roles.md)'s Kassenwart is
the office that makes money decisions: they run settlements, place collection holds and read the
Deckel. Both verbs of `/api/admin/credit-limit-config` are therefore `TREASURY` in
`RouteRoleMap`, and the Getränkewart — who sets a drink's price and its legal age — takes 403 from
both. The panel mirrors that grant rather than restating it: `SETTINGS_TAB_ROLES` is
default-deny, so a new tab is invisible until somebody classifies it.

---

## Milestones

Ordered by dependency. Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed
(test verified) · `[!]` failed.

### M1 — Design of record ✅ ([#556](https://github.com/dgloeckner/clubbar/issues/556), merged in [#659](https://github.com/dgloeckner/clubbar/pull/659))

No production code.

- [x] `adr/0046-configurable-credit-limits.md` — seven rules, the five-row NULL/`0` resolution
      table, a sequence diagram of the two transports, alternatives and non-goals
- [x] `adr/README.md` — the 0046 row
- [x] Amended after the fact: the cents columns are signed `INT`. The ADR said `INT UNSIGNED`
      and issue #557 pinned `int(11)`; the conflict was raised rather than resolved silently,
      and the user chose to amend the ADR

### M2 — The club's ceiling becomes configuration ✅ ([#557](https://github.com/dgloeckner/clubbar/issues/557), [#665](https://github.com/dgloeckner/clubbar/pull/665))

- [x] `backend/db/migrations/052_credit_limit_config.sql` + rollback — the singleton table and
      `members.credit_limit_cents`. **Numbered 052, not 049**: Jugendschutz had taken 049–051
      between the epic being written and this slice being built
- [x] The seeded row is exactly the constants it replaces (10000 / 80), which is what makes the
      migration deployable and verifiable before anything reads it
- [x] `Modules/CreditLimits/Domain/` — `CreditLimit`, `CreditLimitPolicy`, `CreditLimitStatus`.
      `forMember()` is the one-line resolution rule: `$overrideCents ?? $this->defaultLimitCents`
- [x] DTO, repository, service and controller per `backend/patterns/` 003–008; the service is
      **fail-soft on a `PDOException`**, falling back to the shipped defaults rather than taking
      the dashboard down with the settings table
- [x] `RouteRoleMap` — both verbs `TREASURY`; `EntityType::CREDIT_LIMIT_CONFIG` for the audit row
- [x] Deleted `Modules/Dashboard/Domain/CreditLimit.php`, the constant this replaces
- [x] Tests: schema test over the real migration, policy/enum unit tests, service fail-soft test

### M3 — A member may carry a ceiling of their own ✅ ([#558](https://github.com/dgloeckner/clubbar/issues/558), [#667](https://github.com/dgloeckner/clubbar/pull/667))

- [x] `Members/Controllers/AdminController.php` — `['nullable','integer','gte:0','lte:'.MAX]` on
      both paths, and `credit_limit_cents` in `BLANK_MEANS_NULL` so an emptied field means
      *follow the club* rather than a 422
- [x] `MembersService` `$updatable` allow-list — the line that silently dropped the field until
      an API test caught it, exactly as it did for `date_of_birth` in the Jugendschutz plan
- [x] `MembersRepository` INSERT, `updateById()`, and `findMailRecipients()`
- [x] `MemberAdminDto`, `api/admin.yaml`
- [x] Tests: `member-credit-limit.spec.ts` covers all three request shapes (absent, explicit
      null, a value) on create and update, both bounds, and the audit entry

### M4 — Every backend answer resolves it ✅ ([#559](https://github.com/dgloeckner/clubbar/issues/559), [#669](https://github.com/dgloeckner/clubbar/pull/669))

- [x] `DashboardRepository::NEAR_LIMIT_ROWS` — `COALESCE(m.credit_limit_cents, :default_limit)`
      per row, `HAVING limit_cents > 0 AND balance_cents >= limit_cents * :warn_percent DIV 100`
- [x] **The ordering changed deliberately**: the list now ranks by *share of ceiling*, not raw
      amount, because with per-member ceilings the largest tab is no longer the closest to its
      limit. That needed a derived table — MariaDB cannot use an aggregate alias inside an
      `ORDER BY` expression, and `ATTR_EMULATE_PREPARES=false` forbids reusing a named
      placeholder, so the expression could not simply be repeated
- [x] `DashboardService` no longer short-circuits on a zero club default: a club with no ceiling
      may still have members who carry one
- [x] `DeckelStatementService` resolves per member, so the statement's limit sentence names the
      member's own number
- [x] Tests: repository and service unit tests, plus the dashboard E2E

### M5 — The terminal contract ✅ ([#560](https://github.com/dgloeckner/clubbar/issues/560), [#671](https://github.com/dgloeckner/clubbar/pull/671))

- [x] `Modules/CreditLimits/Controllers/SyncController.php` — `GET /api/sync/config` returning
      `default_limit_cents`, `warn_threshold_percent` and the **precomputed** `warn_at_cents`,
      inside the bearer-authenticated `/api/sync` group
- [x] `MemberDto` gains `credit_limit_cents` — the override rides the delta the terminal already
      pulls
- [x] `api/terminal.yaml` — the new path, `SyncConfigResponse`, and the `Member` field
- [x] Tests: `sync-config.spec.ts`, including that `/health` carries **no** limit keys.
      A per-file random `10.60.x.y` `REMOTE_ADDR` plus a `terminal_auth_attempts` cleanup keeps
      the two deliberate-401 cases from poisoning the shared rate-limit bucket for the rest of
      the run — they passed alone and 429'd in a full suite until then

### M6 — The terminal enforces it, offline ✅ ([#561](https://github.com/dgloeckner/clubbar/issues/561), [#672](https://github.com/dgloeckner/clubbar/pull/672))

- [x] `lib/models/credit_limit.dart` — `CreditLimitPolicy` with `forMember` and `evaluate`, the
      Dart twin of the PHP domain object, integer division included
- [x] `lib/config/app_config.dart` — the constants demoted from *the* limit to a **seed** used
      only until the first successful `/sync/config`
- [x] `ConfigService.creditLimitPolicy` + `setCreditLimitPolicy`, written into `config.json`
      merge-safely so an unrelated setting is not clobbered
- [x] `SyncService._syncCreditLimitPolicy()` — **non-fatal**: a failed config fetch leaves the
      last stored policy standing rather than reverting to a compiled-in number
- [x] `members_cache` gains the column, `schemaVersion` 11 → 12 with `_addColumnIfNotExists`
- [x] `CartService` now requires `ConfigService`, which is what makes the check read the live
      policy; both screens and `main.dart` thread it through
- [x] Tests: unit and widget suites green. **The integration tests could not be run here** — the
      bundle would not launch in this sandbox even with the GTK dependencies installed. Disclosed
      in the PR rather than papered over; CI's `build-terminal` job passed

### M7 — Settings splits by role, and gains a Limits tab ✅ ([#562](https://github.com/dgloeckner/clubbar/issues/562), [#673](https://github.com/dgloeckner/clubbar/pull/673))

- [x] `src/utils/adminRoles.ts` — `/settings` becomes `TREASURY`, and a new `SETTINGS_TAB_ROLES`
      does inside the page what `SECTION_ROLES` does for navigation: default-deny, one entry per
      tab, with `maySeeSettingsTab` / `settingsTabsFor` / `firstSettingsTab`
- [x] `SettingsPage.tsx` — role-filtered tabs, `activeTab: SettingsTab | null` with a landing
      effect, so an office that may see one tab lands on it rather than on a refusal
- [x] `components/settings/CreditLimitsTab.tsx`, de/en strings under `settings.limits.*`
- [x] Tests: `role-navigation.spec.ts` extended with two cases — a Kassenwart sees the Limits tab
      and no admin-only tabs; a Getränkewart is refused `/settings` entirely
- [!] → [x] A scripted JSX wrap put the Limits button **inside** the Mail button's
      `{!isMobile && (...)}`, so the admin saw eight tabs and the Kassenwart saw none. The unit
      tests could not see it — both lists hold the same strings — and only the browser run
      caught it. Fixed in the same slice

### M8 — The member form and the dashboard ✅ ([#563](https://github.com/dgloeckner/clubbar/issues/563), [#675](https://github.com/dgloeckner/clubbar/pull/675))

- [x] `src/utils/creditLimit.ts` — `creditLimitToInput` (`null` → `''`, `0` → `'0.00'`),
      `creditLimitFromInput` (`''` → `null`, `'0'` → `0`, `'12,34'` → `1234`, invalid → `NaN`)
      and `isValidCreditLimitInput`, so the empty-versus-zero distinction is decided in **one**
      pure function rather than at three call sites
- [x] `MembersPage.tsx` — the field, its prefill, both payloads, and the club default fetched via
      `useLatestRequest` so the placeholder names the number the member would actually inherit
- [x] `DashboardPage.tsx` — `dashboard.balanceOfLimit` per row and the envelope relabelled
      `creditLimitClubDefault`, because it is no longer *the* limit
- [x] Tests: 14 unit tests over the conversion functions, plus the E2E below

### M9 — E2E coverage, use cases and data-model docs ✅ ([#564](https://github.com/dgloeckner/clubbar/issues/564), this plan)

- [x] `e2etests/tests/api/credit-limit-config.spec.ts` — 10 tests, serial and restoring the
      singleton, including Kassenwart read **and** write and Getränkewart 403 on both verbs
- [x] `e2etests/tests/api/sync-config.spec.ts` — 6 tests: the bearer read, `warn_at_cents` = 2333
      for 3333 at 70 % (integer division, asserted over the wire), a zero-ceiling club, 401
      without and with a bad token, and `/health` carrying no limit keys
- [x] `e2etests/tests/api/member-credit-limit.spec.ts` — 6 tests: three request shapes, bounds on
      both paths, and the audit entry present on a change and absent on a no-op. The audit
      assertion pages by the endpoint's `entity_id` filter rather than walking the newest 100
      rows, which never reached the member
- [x] `e2etests/tests/admin/member-credit-limit-form.spec.ts` — the override typed into the
      *member form*: saved as cents, read back as typed, cleared to `NULL` (never `0`), a typed
      `0` stored and returned as `0.00`, a negative one refused before the save, and the helper
      line distinguishing the two look-alike states. Every claim about what was stored is checked
      against the API, because a field can hold `""` and still have sent `0`
- [x] `e2etests/tests/admin/dashboard-credit-limit.spec.ts` — the hardcoded `LIMIT_CENTS` /
      `WARN_AT_CENTS` gone, and each fixture given a **ceiling of its own** so the file depends on
      no shared mutable setting at all
- [!] → [x] The first full run failed two of those dashboard tests, and the cause was this
      slice's own doing: the acceptance flow edits the club default while the dashboard file was
      measuring its members against it, so under four workers the fixtures fell out of the
      warning band. Reading the setting instead of hardcoding it was not enough — a singleton
      another spec writes is shared mutable state whichever way you read it. Fixed by giving
      every fixture its own ceiling (Pattern 001 applied to settings, not just to rows), and by
      keeping every club-default write in the one serial file that owns it
- [x] `e2etests/tests/admin/credit-limits-flow.spec.ts` — the acceptance flow in eight steps, plus
      the Limits tab's own behaviour (it reads back what is stored, and refuses a ceiling it
      cannot send). Both live in one serial file because every test that writes the club setting
      has to be serial with respect to every other, and inside one file is the only place
      Playwright can promise that. The steps: set
      500,00 € / 80 % in the panel → two members with identical 45.000-cent tabs both at 90 % of
      the club ceiling → override one down to 40.000 → 113 %, exceeded, the bystander unchanged →
      both visible in the UI → raise the club ceiling to 500.000 and both leave the list → set it
      to `0` and they stay off → `/sync/config` reports 50000/40000 and `/sync/members` carries
      `0` for one member and `null` for the other
- [x] Page objects extended per pattern 006 — `SettingsPage` gains the Limits tab locators,
      `setCreditLimits` and the saved/rejected expectations; `MembersPage` gains the field's
      setter, getter, placeholder, helper text and rejection expectation. `role-navigation.spec.ts`
      now reads its tab list through the page object rather than a hand-rolled locator
- [x] `use-cases/admin/UC-A65-configure-credit-limits.md` — new, with the roles rationale, the
      `0`-versus-empty table and error cases E1–E4
- [x] `use-cases/terminal/UC-T11` and `UC-T12` E2 — both now name **where the ceiling comes
      from** rather than saying "from config"
- [x] `use-cases/README.md` — the UC-A65 row and the domain totals
- [x] `docs/erm-master.md` — `members.credit_limit_cents` and the `credit_limit_config` table, in
      the diagram, the table definitions, the FK list and the ADR index
- [x] `docs/erm-frontend.md` — `members_cache.credit_limit_cents`, the `/sync/config` step in the
      sync sequence, and why the club default is a `config.json` value rather than a cache table
- [x] `research/credit-limit-precedents.md` — a dated note recording what this epic settled
- [x] `CONTEXT.md` — the **Limit** term
- [x] This plan and its `plans/INDEX.md` row

---

## Known gaps

- **Reported, not fixed**: `GET /api/admin/scheduler` returns 403 for every role below `admin`,
  and `SchedulerBanner` sits in `MainLayout` — so a Kassenwart triggers a failed request on
  every page they open. Pre-existing and outside this epic; it became visible only because M7
  put a lesser role on a page it had never been able to reach.
- The terminal's **integration** tests remain unrun in this sandbox (M6). Unit, widget and CI
  build are green.
