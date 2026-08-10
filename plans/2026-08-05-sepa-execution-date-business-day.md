# SEPA Execution Date: Bank Business Day Rule

**Issue**: [#11 — SEPA export: execution date can fall on a weekend (invalid ReqdColltnDt)](https://github.com/dgloeckner/clubbar/issues/11)
**Status**: Implemented — backend, frontend and docs complete; E2E execution pending CI
**Created**: 2026-08-05
**Scope note (2026-08-05)**: extended to cover the six TARGET2 closing days on maintainer
request. They were originally deferred as a follow-up; see "Scope extension" below for what
that changes.

---

## Problem

`execution_date` is written verbatim into the SEPA XML as `ReqdColltnDt`. SEPA requires the
requested collection date to be a bank business day, but nothing in the stack enforces this.
Banks either silently shift the date or (stricter portal validators, e.g. Sparkasse
"Datei-Übergabe") reject the whole file.

## Analysis (current behaviour)

| # | Finding | Location |
|---|---------|----------|
| A1 | `POST /api/admin/settlements` validates only `execution_date >= settlement_date + 7 days` — no weekday check | `backend/src/Modules/Settlements/Controllers/AdminController.php:101-111` |
| A2 | **`POST /api/admin/settlements/settle-filter` has no lead-time validation at all** — any date passes, including yesterday | `AdminController.php:53-78` |
| A3 | Export writes the stored date straight into `requestedCollectionDate`; no guard, and the XSD does not reject weekends | `backend/src/Modules/Settlements/Services/SepaExportService.php:68` |
| A4 | Admin UI computes `execution_date` client-side as `today + 7` in two independent places — never asks the user, so an invalid date is submitted silently | `admin-frontend/src/pages/JournalPage.tsx:402-404`, `admin-frontend/src/components/modals/SettlementConfirmModal.tsx:51-55` |
| A5 | The modal (preview) and the submit path compute the date separately — the value shown and the value sent can differ; `JournalPage` also uses `toISOString()` on a local `Date`, which shifts the day for UTC-negative offsets | same as A4 |
| A6 | `GET /settlements/execution-date-info` referenced in the issue, ADR-0009 and UC-SEPA-07 **does not exist** — no route, no controller method | `backend/src/routes.php:125-135` |
| A7 | The `+7` rule is anchored to `settlement_date`, while ADR-0009 specifies `TODAY + 7`. Divergence pre-dates this issue; documented here, not changed by this plan | `AdminController.php:105` |
| A8 | E2E helpers build `execution_date` as `today + 7` dynamically. Once invalid dates are rejected, these tests fail on ~2 of 7 weekdays (more around holidays) | `e2etests/utils/transactions.ts:184-192`, `e2etests/tests/api/settlements.spec.ts:690`, `e2etests/tests/admin/journal-and-settlements.spec.ts:298` |

**Reproduction**: `settlement_date = 2026-08-02` (Sunday), `execution_date = 2026-08-09` (Sunday)
passes validation today and lands in the XML as `<ReqdColltnDt>2026-08-09</ReqdColltnDt>`.

### A9 — Root cause, found during implementation (worse than reported)

`SepaExportService` built the payment info with a **`requestedCollectionDate`** key. Digitick's
`CustomerDirectDebitFacade::addPaymentInfo()` reads **`dueDate`**, and silently ignores keys it
does not recognise, falling back to `createDueDateFromPaymentInformation($info, '+5 day')`.

So `ReqdColltnDt` was never the settlement's execution date at all — every export carried
**today + 5 days**, whenever the export happened to run. The admin-chosen date reached the
database but never the file.

This explains the occurrence in the issue that the `+7` arithmetic never accounted for: the
export on 2026-08-04 produced 2026-08-09 because 2026-08-04 + 5 = 2026-08-09, not because of the
lead-time rule. Fixed in M3 by using the `dueDate` key; pinned by an exact-date unit test and an
E2E assertion on `ReqdColltnDt`.

## Decision: reject at the API, roll forward in the UI

Two options were on the table (issue, suggested fix 1). Chosen combination:

- **API rejects** an invalid `execution_date` with 422. Silently rewriting a date the caller
  explicitly supplied would make the stored settlement differ from the request and is invisible
  in the audit log. Rejection is explicit and testable.
- **UI rolls forward.** The admin never picks the date — the frontend derives it. So the
  frontend must produce a valid date, otherwise the settle button breaks on a predictable
  fraction of days.

## Scope extension: TARGET2 closing days

A bank business day is now **Mon–Fri, excluding the six TARGET2 closing days**: 1 January,
Good Friday, Easter Monday, 1 May, 25 December, 26 December. This reverses part of ADR-0009,
which rejected business-day calculation (Alternative 1) partly to avoid the Easter algorithm.
What is adopted is only the *stateless* half of that alternative — no `bank_holidays` table, no
regional variants, no external holiday sync; the six dates are computed, not stored.

Two consequences that were not present in the weekend-only version:

1. **Easter must be computed.** Use the Anonymous Gregorian (Meeus/Jones/Butcher) algorithm in
   plain PHP — *not* `easter_date()`, which needs `ext-calendar`. That extension is not declared
   in `backend/composer.json` and cannot be assumed on shared hosting (IONOS deployment target).
2. **The rule is no longer trivial enough to duplicate in TypeScript.** The frontend-local helper
   that the weekend-only plan proposed would mean maintaining the Easter algorithm twice, in two
   languages, with silent drift when they disagree. So **`GET /admin/settlements/execution-date-info`
   (A6) moves into scope** as the single source of truth, and the frontend consumes `minimum_date`
   instead of computing anything. This is also the endpoint ADR-0009 and UC-SEPA-07 already describe.

**Worst case lead time**: Good Friday + weekend + Easter Monday is four consecutive closing days,
so `today + 7` can roll to `today + 11`. Acceptable, but must be stated in the ADR.

### Decision: no date library, backend or frontend

**Backend — native `DateTimeImmutable` + a hand-written Easter function.** The helper needs
exactly three primitives: day-of-week (`format('N')`), add-one-day (`modify('+1 day')`), and
Easter Sunday. The first two are native; only Easter is real code, ~10 lines of integer
arithmetic from a closed-form algorithm that has not changed since 1582 and is pinned by the
unit tests in T1.3.

A holiday library does not fit this problem. There is **no TARGET2 package on Packagist**
(searched 2026-08-05, zero results). The obvious candidate, `azuyalabs/yasumi`, is a *country*
calendar library — its German provider includes Tag der Deutschen Einheit, Christi Himmelfahrt
and Pfingstmontag, none of which are TARGET2 closing days. Using it would mean adding a runtime
dependency and *then* writing filter logic to extract a six-date subset, i.e. more code than
computing the six dates directly, plus a wrong-by-default failure mode if the filter drifts.
The usual argument for a holiday library — the data changes yearly and someone else maintains it
— does not apply: the TARGET2 set is four fixed dates plus two Easter offsets, unchanged since
the ECB fixed it in 2002. This also keeps CLAUDE.md's "no external dependencies beyond Composer
basics" intact for a backend that ships to shared hosting.

`ext-calendar`'s `easter_days()` is likewise rejected: it saves ~10 lines in exchange for an
extension requirement that is not declared in `composer.json` and not guaranteed on the IONOS
target. A hard dependency on the deployment environment is a worse trade than ten testable lines.

**Frontend — nothing at all.** After M4/M5 the frontend performs no date arithmetic; it receives
`minimum_date` as a string and displays it through the existing `Intl`-based `formatDate` in
`design-system`. `admin-frontend/package.json` currently has zero date libraries and stays that
way. Adding `date-fns`/`dayjs` here would only serve the local computation that M5 deletes.

---

## Milestones

### M1 — Banking calendar helper (backend)

- [x] **T1.1** Add `backend/src/Shared/Utils/BankingCalendar.php`, alongside `DateFormatter` /
      `SepaSanitizer`, as a stateless static helper:
      `isBusinessDay(string $date): bool`, `nextBusinessDay(string $date): string`
      (rolls forward across chained weekends and holidays; returns input if already valid),
      `target2Holidays(int $year): array` (six ISO dates), `easterSunday(int $year): string`.
- [x] **T1.2** Easter via Anonymous Gregorian algorithm in plain PHP — **no `ext-calendar`**
      (not in `composer.json`, not guaranteed on the IONOS target).
- [x] **T1.3** Unit test `backend/tests/Unit/Shared/Utils/BankingCalendarTest.php`:
      - Easter Sunday for 2026-04-05, 2027-03-28, 2028-04-16
      - each fixed holiday is not a business day; `2026-04-03` (Good Friday) and `2026-04-06`
        (Easter Monday) rejected
      - **chained rolls**: `2026-04-03` (Good Fri) → `2026-04-07` (Tue, skips weekend + Easter Mon);
        `2028-12-25` (Mon) → `2028-12-27` (Wed, two consecutive holidays);
        `2026-12-25` (Fri) → `2026-12-28` (Mon); `2027-01-01` (Fri) → `2027-01-04` (Mon)
      - a holiday falling on a weekend is not double-counted (`2027-05-01` is a Saturday)
      - ordinary weekday unchanged; `nextBusinessDay` is idempotent
      **Verify**: `cd backend && ./vendor/bin/phpunit tests/Unit/Shared/Utils/BankingCalendarTest.php` — all green.

### M2 — API validation

- [x] **T2.1** `AdminController::store()` — reject a non-business-day `execution_date` with 422,
      message `execution_date must be a bank business day (Mon-Fri, excluding TARGET2 closing days)`,
      alongside the existing +7 check.
- [x] **T2.2** `AdminController::settleFilter()` — add the **missing** `+7` lead-time check (A2)
      *and* the business-day check, so both creation paths enforce the same rule. Extract the shared
      block into a private `validateExecutionDate(string $settlementDate, string $executionDate): ?array`.
      **Verify**: `curl` a Sunday and `2026-12-25` against both endpoints → 422 with the new message;
      an ordinary Monday → 201.

### M3 — Export guard (defense in depth)

- [x] **T3.1** `SepaExportService::generateXml()` — throw a domain exception (mapped by the
      centralized handler, Pattern 007) if the stored `execution_date` is not a business day, so rows
      written before this fix cannot silently produce an invalid file.
- [x] **T3.2** Unit test in `backend/tests/Unit/Modules/Settlements/Services/SepaExportServiceTest.php`:
      settlement with a Sunday and one with `2026-12-25` → export throws; ordinary weekday →
      `ReqdColltnDt` present and valid.
      **Verify**: `./vendor/bin/phpunit tests/Unit/Modules/Settlements` — all green.

### M4 — `GET /admin/settlements/execution-date-info` (moved in scope)

- [x] **T4.1** Route + `AdminController::executionDateInfo()` returning the shape ADR-0009 already
      specifies, with `minimum_date` = `nextBusinessDay(TODAY + 7)`:
      `{"minimum_date": "...", "lead_time_days": 7, "rule": "..."}`.
      Thin controller → `SettlementsService` → `BankingCalendar` (Patterns 004/006).
- [x] **T4.2** Add the path to `api/admin.yaml` and re-run orval so the frontend gets a typed client.
      **Verify**: `curl -s .../api/admin/settlements/execution-date-info | jq .` returns a Mon–Fri
      non-holiday date ≥ today + 7.

### M5 — Admin frontend

- [x] **T5.1** Fetch `minimum_date` from M4's endpoint and use it as `execution_date` in
      `JournalPage.handleConfirmSettlement` (both the settle-filter and the transaction_ids branch)
      and for display in `SettlementConfirmModal` — replacing **both** inline `today + 7` computations,
      which fixes A4 and A5 (shown date == submitted date) and removes the `toISOString()` timezone bug.
      No date rule is reimplemented in TypeScript.
- [x] **T5.2** Handle the fetch failing: keep the confirm button disabled with an error rather than
      falling back to a locally computed date, which is what would reintroduce the drift.
- [x] **T5.3** Vitest test for the modal/page: given a mocked endpoint response, the displayed and
      submitted date is exactly `minimum_date`.
      **Verify**: `cd admin-frontend && npm run test` — all green.

### M6 — Tests (E2E / API)

- [x] **T6.1** Fix the day-of-week flakiness introduced by M2 (A8): route
      `e2etests/utils/transactions.ts`, `settlements.spec.ts:690` and
      `journal-and-settlements.spec.ts:298` through one shared helper in `e2etests/utils/dates.ts`
      that calls the M4 endpoint (not a reimplemented rule).
- [x] **T6.2** New API tests in `e2etests/tests/api/settlements.spec.ts`, against **both**
      `POST /settlements` and `POST /settlements/settle-filter`: a weekend date → 422; `2026-12-25`
      (Good Friday-style fixed holiday on a weekday) → 422; `2026-04-06` (Easter Monday) → 422;
      the adjacent ordinary weekday → 201. Fixed dates per Pattern 003, not computed ones.
- [x] **T6.3** API test for `GET /settlements/execution-date-info`: returns 200, `lead_time_days` 7,
      and a `minimum_date` that is a weekday and not in the holiday set.
- [x] **T6.4** E2E: settle from the Journal page and assert the created settlement's `execution_date`
      is a valid business day, verified against the API (frontend → API → DB, per CLAUDE.md).
      **Verify**: `cd e2etests && npm test -- tests/api/settlements.spec.ts --workers=4`, then the
      full suite with `--workers=4`.

### M7 — Documentation

- [x] **T7.1** **ADR-0009 amendment** — the substantive one. Decision section gains weekends +
      the six TARGET2 closing days; the validation pseudocode gains `nextBusinessDay`; Alternative 1
      ("Business Day Calculation with Holiday Calendar", rejected) must be rewritten honestly as
      *partially adopted*: computed holidays yes, `bank_holidays` table and regional variants still
      no. Consequences gain the four-consecutive-closing-days worst case (lead time up to +11 days)
      and the Easter-algorithm maintenance cost.
      ⚠️ Scope extension approved by the maintainer on 2026-08-05; confirm the final ADR wording
      before it is merged.
- [x] **T7.2** `use-cases/sepa/uc-sepa-07-settlement-finalize.md` — replace "No business day
      calculation required" in the Execution Date Rules table, which becomes wrong.
- [x] **T7.3** `api/admin.yaml` — document the new 422 case on both creation endpoints (the
      endpoint itself is added in T4.2).

---

## Out of scope / follow-up

- **Regional bank holidays** beyond TARGET2 (e.g. German state holidays). TARGET2 governs SEPA
  settlement; local closures do not move `ReqdColltnDt`.
- ~~**`+7` anchor divergence** (A7): `settlement_date + 7` vs ADR-0009's `TODAY + 7`. Pre-existing;
  not changed here.~~ **Closed by [#113](https://github.com/dgloeckner/clubbar/issues/113)** — see below.

## Follow-up: A7 closed ([#113](https://github.com/dgloeckner/clubbar/issues/113), 2026-08-10)

A7 was documented here and left alone. It was a security hole, not a cosmetic divergence: the
anchor was a **request field**, so a caller who backdated `settlement_date` could name an
execution date tomorrow and pass the check. The SEPA pre-notification period the rule exists to
guarantee was optional for anyone posting to the API directly.

| # | Change | Where |
|---|--------|-------|
| F1 | The lead time is anchored on the server's today | `Modules/Settlements/Domain/SettlementLeadTime.php` (new) |
| F2 | `settlement_date` removed from both creation requests; the server records its own | `AdminController::store`, `::settleFilter`, `SettlementsService::createSettlement` |
| F3 | Suggestion and validation read the same anchor, so `minimum_date` is accepted by construction | `SettlementsService::getExecutionDateInfo` |
| F4 | ADR-0009's pseudo-code corrected — it said `settlementDate + 7` while its own decision said `TODAY + 7`, which is how the weaker rule got written | `adr/0009-settlement-lead-times-bank-working-days.md` |
| F5 | Admin UI stops sending `settlement_date`; OpenAPI drops it from the request schemas | `NewSettlementPage.tsx`, `api/admin.yaml` |

Verified: PHPUnit 1378 tests green (including a new `SettlementLeadTimeTest`), api-tests 555 green,
admin-chromium 278 green, admin-mobile 49 green.

Note this also retires the second, non-malicious symptom of the same root cause that
[#194](https://github.com/dgloeckner/clubbar/issues/194) patched on the client side: a browser
east of UTC was a calendar day ahead of the server every evening, so a locally-dated
`settlement_date` paired with the server's `minimum_date` failed the check on a pair the server
had just proposed.

## Implementation notes (deviations from the plan as written)

- **T5.3 changed shape.** The plan called for a Vitest test of the modal/page wiring. The admin
  frontend has no DOM test infrastructure — no jsdom, no testing-library, and zero existing
  Vitest tests — so that would have meant new devDependencies plus a `technologies.md` update,
  beyond this issue. Instead: Vitest covers the pure `toIsoDate` util (4 tests), and the
  component wiring is asserted by the E2E test in T6.4, which is where this project verifies UI
  behaviour anyway. Worth a separate decision whether the frontend should have unit tests at all.
- **Validation landed as a declarative `business_day` rule** in the shared `Validator` rather
  than only as controller code, so both endpoints get it from the rule list (Pattern 001). The
  cross-field lead-time check stayed a shared private controller method as planned.
- **E2E tests were not executed locally.** This environment has no Docker daemon and no MariaDB,
  so the stack cannot run. PHPUnit (118 tests), Vitest (4), tsc and lint were all verified
  locally; E2E execution is left to CI.

## Success criteria

1. Weekend **and** TARGET2 closing day `execution_date` → 422 on both creation endpoints (M2, covered by T6.2).
2. Settling from the admin UI never produces a non-business-day `execution_date`, and no date rule
   is duplicated in TypeScript (M4+M5, covered by T6.4).
3. Export refuses to emit a `ReqdColltnDt` that is not a business day (M3, covered by T3.2).
4. Full E2E suite passes with `--workers=4` on any day of the year, including holiday periods.
