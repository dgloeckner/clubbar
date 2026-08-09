# Settlement Selection Becomes a Member Picker

**Issue**: [#128](https://github.com/dgloeckner/ruderbar/issues/128) (the arithmetic half, shipped in [#228](https://github.com/dgloeckner/clubbar/pull/228)) — this plan is the design half
**Decision**: [ADR-0030](../adr/0030-settlement-selection-is-a-member-picker.md)
**Use case**: [UC-A30](../use-cases/admin/UC-A30-create-settlement.md)

## Why

[#161](https://github.com/dgloeckner/ruderbar/issues/161) made a settlement sweep each included member's **whole** unsettled position. The screen kept asking the admin to tick **transactions**, in a paginated journal, under a date filter the run ignores.

Fixing #128's cross-page bug made the numbers honest — the confirmation now states what the run contains — but the admin still learns that selection is per-member by watching a number jump at the last step: tick one row, the modal says 47 transactions. Three things stay invisible while choosing:

- ticking one row of a member pulls in that member's other rows, on other pages, outside the filter;
- unticking one row of a member who has another ticked changes nothing;
- the period shown does not bound the run.

UC-A30 recorded this as unresolved ("the screen's right shape is not yet designed"). ADR-0030 resolves it: **the unit of selection is the member, and it lives on its own screen.**

## Success criteria

The plan is done when an admin can create a settlement without ever seeing a transaction checkbox, every figure on the screen comes from the server, excluded members are visible before submitting rather than as a 422, and the Journal is back to being a record of what happened.

---

## Milestone 1: The API speaks members

The screen holds members, so the endpoints must too.

- [x] **1.1** `SettlementsService::previewSettlement()` accepts `memberIds` and names participants directly
  *Test*: `previewSettlement(memberIds: [...])` consults neither the window nor `findUnsettledByIds` — and wins when both id lists are sent
- [x] **1.2** `createSettlement()` accepts `member_ids`, with `transaction_ids` retained as the alias that resolves to the same members (ADR-0030 §1)
  *Test*: the member path settles the same rows as the transaction path, dedupes its ids, and still refuses a credit member (#161 §6)
- [x] **1.3** Creation rejects a request naming neither; both routes reject a non-array. The member path also rejects members with nothing left to settle — it never touches an unsettled row on the way in, so an unknown id would otherwise create an empty settlement
  *Test*: 422 with a `messages` map naming the offending field; `BusinessRuleException` for the empty sweep
  *Note*: preview naming neither is **not** an error — that is the window path
- [x] **1.4** OAS updated for both endpoints; orval client regenerated
  *Test*: `php-openapi validate api/admin.yaml` passes
  *Found*: `SettlementCreateRequest` was stale — it documented `member_ids` as "optional filter" and omitted `transaction_ids` and `settlement_date` entirely. Corrected.
- [x] **1.5** Preview carries what a member row needs: transaction count per member, alongside the existing balance
  *Test*: a member with 4 unsettled rows reports 4; the run total counts eligible members only, while every bucket's rows carry their own count

**Status**: complete. Unit suite 682 tests, 6 pre-existing `bcmod` errors (missing bcmath extension locally), no new failures.

## Milestone 2: The New Settlement screen

Route `/settlements/new`, reached from Settlements → "New Settlement".

- [x] **2.1** Three sections render from one preview call: eligible (selectable), no active mandate (read-only), in credit (read-only)
  *Test*: a member in each bucket appears in exactly one section, and only the eligible one has a checkbox
- [x] **2.2** Every eligible member is selected by default (UC-A30)
  *Test*: on load, the run summary equals the full eligible bucket
- [x] **2.3** A member row expands to that member's unsettled transactions, read-only
  *Test*: expanding shows every row the sweep would settle, none of them selectable
- [x] **2.4** The run summary sums the **server's per-member figures** over the selected subset
  *Test*: deselecting a member drops exactly that member's `transaction_count` and `balance_cents` from the summary

  > **Revised from "re-derive from the server on every selection change".** That step was written before M1.5 put a `transaction_count` on every member row. Now that each member carries their whole position and their own count, summing the selected subset is *identical by construction* to what a preview call would answer — the server computes the run total the same way, from the same per-member numbers. A round-trip per checkbox would add latency and a stale-response class for zero accuracy.
  >
  > The rule ADR-0030 actually cares about is unbroken: the client still never computes a **position** from transactions it holds. It sums positions the server gave it. Concurrency is handled where it can be — `createSettlement()` re-partitions at commit and refuses with the buckets, so a member another admin swept mid-session fails the create rather than passing a preview.

- [x] **2.5** Select all / select none over the eligible section
  *Test*: none → the confirm is blocked with "Select at least one member"
- [x] **2.6** Search narrows the member list without altering the selection
  *Test*: searching then clearing leaves the run summary unchanged
  *Note*: filtered client-side. The preview already returns every member with an open position, and that set is bounded by club size — so there is no second query to race, and the stale-response class this screen might have had never arises.
- [x] **2.7** Execution date shown is the server's `minimum_date`, and is what gets submitted
  *Test*: the submitted `execution_date` equals the displayed one (the #11 drift)
- [x] **2.8** Confirm creates the settlement and navigates to it
  *Test*: E2E — create a run, then assert every selected member has nothing left open and unselected members are untouched

**Status**: complete. `/settlements/new`, reached from the Settlements page and from the Journal. One preview read serves the whole screen.

## Milestone 3: The Journal gives up settlement

- [x] **3.1** Remove settlement mode, row checkboxes, select-all, conclude and settle-all from `JournalPage`
  *Test*: no `journal-select-*` or `journal-settlement-*` test id resolves
- [x] **3.2** The Journal links to New Settlement instead
  *Test*: the link navigates to `/settlements/new`
- [x] **3.3** Delete the now-dead selection code: `utils/transactionSelection.ts` and its tests, the Journal page-object selection methods, `journal-cross-page-settlement.spec.ts`
  *Test*: vitest and `tsc` clean with no orphan imports
- [x] **3.4** Rewrite the E2E specs that drove the old flow — `journal-and-settlements.spec.ts` (settlement lifecycle, settle-all + undo) and `walkthrough/admin-walkthrough.spec.ts` — against the new screen
  *Test*: full Playwright suite green at 4 workers
- [x] **3.5** `transaction_ids`-based preview and its spec are kept as the documented compatibility path, not deleted
  *Test*: `settlement-preview-by-ids.spec.ts` still passes

**Status**: complete. Also deleted with the flow: `SettlementConfirmModal` (its only consumer was the Journal — the new screen's always-visible summary sits next to the create button, so a modal restating it added nothing), `utils/transactionSelection.ts` and its 18 vitest cases, and the `journal.settlementConfirm.*` / settle-button i18n keys.

## Milestone 4: Documentation closes

- [x] **4.1** A page object for the new screen, following patterns 006/007
- [x] **4.2** `admin-frontend/patterns/` gains nothing new unless the screen needs it — check before writing
  *Checked*: nothing new. The screen makes one read on mount and filters in memory, so the data-fetching pattern applies unchanged and has no new case to document.
- [x] **4.3** Mark #128 and this plan complete in `plans/INDEX.md`

---

## Risks

| Risk | Mitigation |
|---|---|
| Removing settle-all breaks a flow the treasurer relies on | The new screen *is* settle-all: every eligible member is selected by default, so opening it and confirming is the same act |
| The member list is unpaginated | Bounded by club size (hundreds), and it lists only members with an open position. Revisit if a deployment shows otherwise (ADR-0030) |
| A preview call per selection change is chatty | Debounce, and cancel superseded requests per `admin-frontend/patterns/data-fetching.md` — this screen is exactly the case that pattern exists for |
| The E2E rewrite is the largest part and touches money-critical tests | Milestone 3 lands with Milestone 2, never before it; the old specs stay green until the new screen replaces what they cover |

## Found on rebase onto main

Main moved under this branch and brought work that intersects it:

- **A fourth exclusion bucket.** Ruling #148 §4 added `held_members` — a member whose last collection bounced, skipped until somebody clears the hold. The DTO calls it "the one that must never be silent", which is exactly this screen's job, so the New Settlement screen gained a fourth read-only section showing the hold reason, and an E2E case that bounces a collection and asserts the member appears there and is not selectable.
- **Settlement paging was not deterministic.** `listPaginated` ordered by `a.display_name` with LIMIT/OFFSET, and that name repeats for every settlement one admin created. Row order within a tie is undefined, so paging could hand the same row to two pages and never hand over another — the Settlements list dropping rows as the treasurer pages. Fixed with a unique tiebreaker (`s.id`), found because `settlements-sort.spec.ts` failed against a 238-row dev database while passing in CI's small one. That spec now pages until it finds its own ids (Pattern 003) instead of assuming page 1 holds them.

## Follow-ups

- **Batch the participant lookup.** `previewSettlement()` still calls `MembersRepository::findById()` once per participant. That was tolerable while the preview was always called with a date window; since ADR-0030 the screen previews unfiltered, so the loop now runs over every member with an open position. Wants a `findByIds()` batch — deliberately not folded into this change, because it means rewriting ~20 existing tests that mock the per-member call and that churn does not belong in a change being debugged.

## Out of scope

- The standing "credit balances outstanding" report (ruling #141 §4) — this screen surfaces credit members during a run; the report is its own work.
- Manual settlement (UC-A35), which the excluded sections link to but do not change.
- Paginating or virtualizing the member list.
