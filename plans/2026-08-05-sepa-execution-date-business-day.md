# SEPA Execution Date: Bank Business Day Rule

**Issue**: [#11 — SEPA export: execution date can fall on a weekend (invalid ReqdColltnDt)](https://github.com/dgloeckner/clubbar/issues/11)
**Status**: In Progress (analysis done, implementation not started)
**Created**: 2026-08-05

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
| A4 | Admin UI computes `execution_date` client-side as `today + 7` in two independent places — never asks the user, so a weekend date is submitted silently | `admin-frontend/src/pages/JournalPage.tsx:402-404`, `admin-frontend/src/components/modals/SettlementConfirmModal.tsx:51-55` |
| A5 | The modal (preview) and the submit path compute the date separately — the value shown and the value sent can differ; `JournalPage` also uses `toISOString()` on a local `Date`, which shifts the day for UTC-negative offsets | same as A4 |
| A6 | `GET /settlements/execution-date-info` referenced in the issue, ADR-0009 and UC-SEPA-07 **does not exist** — no route, no controller method | `backend/src/routes.php:125-135` |
| A7 | The `+7` rule is anchored to `settlement_date`, while ADR-0009 specifies `TODAY + 7`. Divergence pre-dates this issue; documented here, not changed by this plan | `AdminController.php:105` |
| A8 | E2E helpers build `execution_date` as `today + 7` dynamically. Once weekend dates are rejected, these tests fail on ~2 of 7 weekdays | `e2etests/utils/transactions.ts:184-192`, `e2etests/tests/api/settlements.spec.ts:690`, `e2etests/tests/admin/journal-and-settlements.spec.ts:298` |

**Reproduction**: `settlement_date = 2026-08-02` (Sunday), `execution_date = 2026-08-09` (Sunday)
passes validation today and lands in the XML as `<ReqdColltnDt>2026-08-09</ReqdColltnDt>`.

## Decision: reject at the API, roll forward in the UI

Two options were on the table (issue, suggested fix 1). Chosen combination:

- **API rejects** a weekend `execution_date` with 422. Silently rewriting a date the caller
  explicitly supplied would make the stored settlement differ from the request and is invisible
  in the audit log. Rejection is explicit and testable.
- **UI rolls forward.** The admin never picks the date — the frontend derives it. So the
  frontend must produce a valid date (next Mon–Fri on/after `today + 7`), otherwise the
  settle button breaks for ~2 of 7 weekdays.

Scope stays inside ADR-0009's "no holiday calendar" principle: weekday check only, stateless,
no new tables. TARGET2 holidays are explicitly out of scope (see Follow-up).

---

## Milestones

### M1 — Shared business-day helper (backend)

- [ ] **T1.1** Add `backend/src/Shared/Utils/BankingDate.php` with `isBusinessDay(string $date): bool`
      and `nextBusinessDay(string $date): string` (Sat/Sun → next Monday; returns input if already Mon–Fri).
      Sits alongside `DateFormatter`, `SepaSanitizer` — no DI, pure static helper.
- [ ] **T1.2** Unit test `backend/tests/Unit/Shared/Utils/BankingDateTest.php`: each weekday,
      Sat→Mon, Sun→Mon, idempotency of `nextBusinessDay`, invalid date string handling.
      **Verify**: `cd backend && ./vendor/bin/phpunit tests/Unit/Shared/Utils/BankingDateTest.php` — all green.

### M2 — API validation

- [ ] **T2.1** `AdminController::store()` — reject non-business-day `execution_date` with 422,
      message `execution_date must be a bank business day (Mon-Fri)`, alongside the existing +7 check.
- [ ] **T2.2** `AdminController::settleFilter()` — add the **missing** `+7` lead-time check (A2)
      *and* the business-day check, so both creation paths enforce the same rule. Extract the shared
      block into a private `validateExecutionDate(string $settlementDate, string $executionDate): ?array`.
      **Verify**: `curl` a Sunday `execution_date` against both endpoints → 422 with the new message;
      a Monday date → 201.

### M3 — Export guard (defense in depth)

- [ ] **T3.1** `SepaExportService::generateXml()` — throw a domain exception (mapped to 409/422 by the
      centralized handler, Pattern 007) if the stored `execution_date` is not a business day, so legacy
      rows written before this fix cannot silently produce an invalid file.
- [ ] **T3.2** Unit test in `backend/tests/Unit/Modules/Settlements/Services/SepaExportServiceTest.php`:
      settlement with a Sunday `execution_date` → export throws; Monday → `ReqdColltnDt` present and valid.
      **Verify**: `./vendor/bin/phpunit tests/Unit/Modules/Settlements` — all green.

### M4 — Admin frontend

- [ ] **T4.1** Add `admin-frontend/src/utils/bankingDate.ts` with a timezone-safe
      `toIsoDate(date)` (local components, not `toISOString()`) and
      `suggestedExecutionDate(from = new Date())` = roll `from + 7` forward to the next Mon–Fri.
- [ ] **T4.2** Use it in `JournalPage.handleConfirmSettlement` (both the settle-filter and the
      transaction_ids branch) and in `SettlementConfirmModal`, replacing both inline computations —
      fixes A4 and A5 (shown date == submitted date).
- [ ] **T4.3** Vitest unit test for `suggestedExecutionDate`: for each weekday of a fixed reference
      week the result is Mon–Fri and `>= from + 7`.
      **Verify**: `cd admin-frontend && npm run test` — all green.

### M5 — Tests (E2E / API)

- [ ] **T5.1** Fix the day-of-week flakiness introduced by M2 (A8): route
      `e2etests/utils/transactions.ts`, `settlements.spec.ts:690` and
      `journal-and-settlements.spec.ts:298` through one shared helper
      (`e2etests/utils/dates.ts` → `businessDayFromNow(7)`).
- [ ] **T5.2** New API tests in `e2etests/tests/api/settlements.spec.ts`: weekend `execution_date`
      → 422 with the business-day message, for **both** `POST /settlements` and
      `POST /settlements/settle-filter`; adjacent Monday → 201. Use fixed dates (a known
      Saturday/Sunday) per Pattern 003 rather than computed ones.
- [ ] **T5.3** E2E: settle from the Journal page and assert the created settlement's
      `execution_date` is a Mon–Fri, verified against the API (frontend → API → DB, per the
      end-to-end requirement in CLAUDE.md).
      **Verify**: `cd e2etests && npm test -- tests/api/settlements.spec.ts --workers=4`, then the
      full suite with `--workers=4`.

### M6 — Documentation

- [ ] **T6.1** **ADR-0009 amendment** — add the weekday rule to Decision + validation pseudocode +
      Consequences. ⚠️ Requires explicit maintainer approval before editing (project convention:
      never modify ADRs without confirmation).
- [ ] **T6.2** `use-cases/sepa/uc-sepa-07-settlement-finalize.md` — update the "Execution Date Rules"
      table with the Mon–Fri constraint.
- [ ] **T6.3** `api/admin.yaml` — document the new 422 case for `/admin/settlements` and
      `/admin/settlements/settle-filter`; re-run orval if any schema changes.

---

## Out of scope / follow-up

- **TARGET2 closing days** (Jan 1, Good Friday, Easter Monday, May 1, Dec 25, Dec 26). Needs a
  separate ADR-0009 amendment and maintainer approval — ADR-0009 deliberately excluded holiday
  logic (Alternative 1, rejected). Track as its own issue.
- **`GET /admin/settlements/execution-date-info`** (A6). The rule is trivial enough to duplicate in
  M4's frontend helper; a server endpoint would remove the drift risk but is not required to close
  this issue. Track separately if the rule ever grows (e.g. holidays).
- **`+7` anchor divergence** (A7): `settlement_date + 7` vs ADR-0009's `TODAY + 7`. Pre-existing;
  not changed here.

## Success criteria

1. Weekend `execution_date` → 422 on both creation endpoints (M2, covered by T5.2).
2. Settling from the admin UI never produces a weekend `execution_date` (M4, covered by T5.3).
3. Export refuses to emit a `ReqdColltnDt` that is not a business day (M3, covered by T3.2).
4. Full E2E suite passes with `--workers=4` on any day of the week.
