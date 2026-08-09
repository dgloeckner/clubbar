# Excluded from Collection — standing view under Members

**Issue**: [#188](https://github.com/dgloeckner/clubbar/issues/188) — admin frontend for the credit-balances
listing, extended to carry collection holds on the same surface.

**Status**: Implemented — M0–M6 and M8 verified green; M7 (mobile) written and verified on Chromium at phone width, **unverified on WebKit in this sandbox** (see M7)

**Follow-up already filed**: [#258](https://github.com/dgloeckner/clubbar/issues/258) — the third exclusion
reason (no SEPA mandate) needs a backend endpoint first, and ships after this.

---

## Why

`SettlementPreviewDto` partitions every member the next run will leave out into buckets that each need a
different remedy. Two of them have standing endpoints and no consumer:

| bucket | why excluded | remedy | standing endpoint | frontend |
|--------|--------------|--------|-------------------|----------|
| `credit` | the club owes them money | pay the member back | ✅ `GET /members/credit-balances` | ❌ none |
| `held` | their last collection bounced | investigate, then clear | ✅ `GET /members/collection-holds` | ❌ none |
| `ineligible` | no active SEPA mandate | chase the bank details | ❌ none | ❌ none — see #258 |

An exclusion only visible inside a settlement preview is an exclusion nobody resolves between runs. This
plan gives the first two a permanent home under Members, using the preview's own wording so the same fact
reads the same way in both places.

Wireframe agreed 2026-08-09: one tab, two sections, two stat tiles, one write (clear hold).

## Scope decisions already taken

- **A tab on Members, not a tenth sidebar entry.** The sidebar carries nine items; both lists answer a
  question about members.
- **A new page component, not tabs bolted into `MembersPage.tsx`.** That file is 1933 lines. The tab strip
  becomes a small shared component rendered by both pages; the new surface gets its own file.
- **No payout control on credit rows.** The `payout` transaction type has no service path (#158 §3). The
  remedy is stated in prose rather than offered as a control that would fail.
- **Clearing a hold is confirmed and names the hold**, following the #127 precedent set by the settlement
  undo dialog.
- **No `useListQuery`.** Both endpoints are unpaginated `{ items, total }` envelopes, not the standard list
  envelope, and neither sorts, filters or pages. `admin-frontend/patterns/data-fetching.md` applies instead:
  one `useLatestRequest` slot per stream.

---

## Milestone 0 — Close the API test gap

The assumption that these endpoints are covered does not hold. Unit coverage is good; **HTTP-level coverage
of `credit-balances` is zero** — no test has ever called it over the wire, so route wiring, session auth and
the JSON envelope are unverified. `collection-holds` and the clear endpoint are exercised only incidentally,
as steps inside `settlement-reversal.spec.ts`, never as endpoints in their own right.

| Endpoint | PHPUnit | Playwright API |
|----------|---------|----------------|
| `GET /members/credit-balances` | 2 tests (`SettlementsServiceTest`) | **none** |
| `GET /members/collection-holds` | 5 + 3 tests (`CollectionHoldServiceTest`, `AdminControllerCollectionHoldsTest`) | incidental only (`settlement-reversal.spec.ts:105`) |
| `POST /members/{id}/collection-hold/clear` | covered | happy path + 409 (`:144`, `:343`) |

- [x] **0.1** New `e2etests/tests/api/member-exclusions.spec.ts` covering both listings as endpoints:
  - `credit-balances`: envelope shape; a seeded credit member appears with the expected
    `balance_cents`; ordering is most-negative-first across two seeded members; `total_credit_cents` equals
    the sum; a soft-deleted member in credit is absent; 401 unauthenticated.
  - `collection-holds`: the fields the reversal spec never asserts — `collection_hold_reason`, `held_at`,
    `held_by_admin_name`, and `total_held_cents` as a sum; 401 unauthenticated.
  - **Success**: `npx playwright test tests/api/member-exclusions.spec.ts --workers=4` green.
- [x] **0.2** Lift `seedMember` out of `new-settlement.spec.ts` into `e2etests/utils/exclusions.ts`, adding a
  `seedHeldMember` alongside it (settle, then reverse with `bank_return`, which is the only way production
  makes a hold). `new-settlement.spec.ts` imports the lifted helper unchanged.
  - **Success**: `npx playwright test tests/admin/new-settlement.spec.ts --workers=4` still green — the
    existing spec is the regression check on the extraction.

## Milestone 1 — Route, tab strip, translations

- [x] **1.1** `MembersTabs` component (`src/components/members/MembersTabs.tsx`): two tabs, active state from
  the router, count badge on the excluded tab, hidden when the count is zero. Test IDs `members-tab-all`,
  `members-tab-excluded`, `members-tab-excluded-count`.
- [x] **1.2** Route `/members/excluded` in `App.tsx` behind `ProtectedRoute`; `MainLayout` keeps the Members
  nav item active on both paths.
- [x] **1.3** Locale keys under a new `excluded` namespace in `public/locales/{en,de}.json` — every visible
  string, per #129. Section titles reuse the preview's phrasing.
- [x] **1.4** Render `MembersTabs` on `MembersPage` without otherwise touching it.
  - **Success**: `npm run type-check && npm run lint` clean; clicking between tabs moves the route and the
    Members nav item stays lit.

## Milestone 2 — Data layer

- [x] **2.1** `useExcludedFromCollection` hook: two independent `useLatestRequest` slots (one per endpoint),
  `signal.aborted` re-checked before every setter and in `finally`, both streams reloaded by one
  `reload()` the clear-hold mutation calls. Distinguishes empty from failed, per #132.
- [x] **2.2** Vitest unit test for the hook, following `useExecutionDateInfo.test.ts`: both loaded, one
  failing, abort on unmount, reload after mutation.
  - **Success**: `npm run test:unit -- useExcludedFromCollection` green.

## Milestone 3 — Desktop layout

- [x] **3.1** `ExcludedFromCollectionPage.tsx` shell: tab strip, page title, loading indicator, error alert.
- [x] **3.2** Two stat tiles — credit total (orange, negative as it arrives) and held total (red) — using
  `formatPrice`, `tabular-nums`, and the `StatCard` colour variants.
- [x] **3.3** Credit section: table of member + credit balance, footer total, the "pay by hand" note, and an
  empty state that reads as a result rather than a blank.
- [x] **3.4** Hold section: member, held-back amount, reason verbatim, held-since (admin's calendar day, per
  the #95 rule), held-by, and the Clear hold action; footer total; empty state.
- [x] **3.5** Test IDs throughout, per the wireframe's inventory and `patterns/test-ids.md`.
  - **Success**: `npm run type-check && npm run lint && npm run build` clean; page renders both populated
    and empty against the dev stack.

## Milestone 4 — Clearing a hold

- [x] **4.1** `ConfirmDialog` wired to name the member, the amount going back into the next run, and the
  reason and provenance of the hold.
- [x] **4.2** `POST .../collection-hold/clear`, then `reload()`. A 409 (someone else cleared it) surfaces
  inline and still reloads, so the page stops disagreeing with the server.
  - **Success**: covered by 6.2 below.

## Milestone 5 — Mobile layout

- [x] **5.1** Below the `tablet` breakpoint (`useBreakpoint`), both sections become one card per member —
  name and amount on one line so the page stays skimmable, reason wrapping underneath, Clear hold
  full-width in the card.
- [x] **5.2** Tiles stack; the tab strip shortens its labels; no horizontal body scroll at 390px.
  - **Success**: verified by 7.1 below.

## Milestone 6 — E2E, desktop

`e2etests/tests/admin/excluded-from-collection.spec.ts`, project `admin-chromium`. Patterns 001, 003, 004,
005, 006, 008 — data seeded per test, rows found by member id, no shared state, `expect()` never try/catch.

- [x] **6.1** *The issue's named test*: a member in credit appears in the credit section here **and not**
  among the collectable members on New Settlement. Credit is seeded the way production makes it — a synced
  purchase, then a storno.
- [x] **6.2** Clearing a hold is a full round trip: dialog names the member → confirm → row gone, hold total
  dropped, and the member is selectable again on New Settlement.
- [x] **6.3** A held member appears in the hold section and not the credit section, with the reason text the
  reversal wrote.
- [x] **6.4** Totals equal the sum of the rows the test seeded (found by id, not by position).
- [x] **6.5** The tab badge counts both lists.
  - **Success**: `npx playwright test tests/admin/excluded-from-collection.spec.ts --workers=4` green, then
    green again under `--workers=1` to rule out an isolation problem masking as a pass.

## Milestone 7 — E2E, mobile

`e2etests/tests/admin-mobile/excluded-from-collection-mobile.spec.ts`, project `admin-mobile` (iPhone 14,
**WebKit** — needs `npx playwright install webkit` plus `install-deps`, per the cloud-session notes).

- [!] **7.1** Both sections render as cards at 390px; a seeded credit member and a seeded held member are
  each visible with their amount; the page does not scroll horizontally.
- [!] **7.2** Clear hold works from the card — the same round trip as 6.2, driven through the mobile layout.
  - **Status**: `[!]` — **cannot be run in this sandbox.** WebKit will not install: the egress policy
    answers `403` to `CONNECT playwright.download.prss.microsoft.com:443` (confirmed via
    `curl -sS "$HTTPS_PROXY/__agentproxy/status"`), and CLAUDE.md forbids working around an allowlist
    denial from code. Fixing it needs an admin to add that host in the environment settings.
  - **What was verified instead**: the same four specs were run against **Chromium at the iPhone 14
    viewport (390×844)** through a throwaway config, and all four pass — the breakpoint switch, the card
    layout, the absence of a table, the no-horizontal-scroll check and clear-hold-from-a-card are all
    exercised. What remains unverified is WebKit's own rendering of that layout. **CI installs the full
    browser set and runs the genuine `admin-mobile` project**, so the real check happens there.

## Milestone 8 — Page object, fixture, docs

- [x] **8.1** `e2etests/pages/ExcludedFromCollectionPage.ts` — private locators, public intent methods
  (Pattern 006), exported from `pages/index.ts`.
- [x] **8.2** `authenticatedExcludedPage` fixture in `fixtures/pageObjects.ts` (Pattern 007).
- [x] **8.3** Update `plans/INDEX.md`; note the new surface in `admin-frontend/patterns/components.md` if
  `MembersTabs` is reusable.
- [x] **8.4** Full suite green before the PR: `cd e2etests && npm test -- --workers=4`.

---

## Verification commands

```bash
# stack
scripts/dev-setup.sh --with-frontend

# frontend
cd admin-frontend && npm run type-check && npm run lint && npm run test:unit && npm run build

# api + desktop + mobile
cd e2etests
npx playwright test tests/api/member-exclusions.spec.ts --workers=4
npx playwright test tests/admin/excluded-from-collection.spec.ts --workers=4
npx playwright test --project=admin-mobile --grep "excluded" --workers=4
npm test -- --workers=4          # full suite before the PR
node scripts/report-failures.mjs # same failure report CI prints
```

Per the Test Verification Policy: nothing is marked `[x]` until the named command has been run and passed.
A `[!]` carries the error and the next step.

### Verified on 2026-08-09

| Check | Result |
|-------|--------|
| `tests/api/member-exclusions.spec.ts` | **10 passed** |
| `tests/admin/excluded-from-collection.spec.ts` | **8 passed** at `--workers=4`, and again at `--workers=1` |
| `tests/admin/new-settlement.spec.ts` (regression on the `seedMember` extraction) | **11 passed** |
| `admin-mobile` specs on Chromium @390px | **4 passed** — WebKit itself unavailable, see M7 |
| Full suite (`api-tests` + `admin-chromium`) | **814 specs, 0 failures** |
| `npm run type-check` / `npm run lint` | clean (9 pre-existing warnings, none in new files) |
| `npx vitest run` | **216 passed** across 22 files |

One flake surfaced on the first full-suite run and did not recur:
`tests/admin/categories.spec.ts:282` ("should delete category when confirm dialog is confirmed") failed
once under parallel load, then passed on its own and passed in the clean full-suite re-run. It touches no
code this plan changes.

## References

- Issue [#188](https://github.com/dgloeckner/clubbar/issues/188); follow-up [#258](https://github.com/dgloeckner/clubbar/issues/258)
- Rulings: #141 (exclude-and-flag, §4 minimum-viable payout), #148 §3–§4 (collection hold and its
  precedence), #158 §3 (`payout` out of scope)
- [ADR-0030](../adr/0030-settlement-selection-is-a-member-picker.md) — the preview surface this mirrors
- [ADR-0022](../adr/0022-test-strategy-and-automation.md) — test pyramid
- `admin-frontend/patterns/data-fetching.md`, `test-ids.md`, `components.md`
- `e2etests/patterns/README.md` — Patterns 001–008
- `api/admin.yaml` — `listCreditBalances`, `listCollectionHolds`, `clearCollectionHold`
