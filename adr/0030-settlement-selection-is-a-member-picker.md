# ADR-0030: Settlement Selection Is a Member Picker

**Status**: Accepted
**Date**: 2026-08-08

## Context

[Exclude-and-flag](https://github.com/dgloeckner/clubbar/issues/141) §1–§2, implemented in [#161](https://github.com/dgloeckner/clubbar/issues/161), changed what a settlement *contains*. A run no longer settles the transactions it was handed. It resolves those transactions to their members and then sweeps **every unsettled transaction of each included member**, ignoring the date window and any hand-picked subset.

That was a deliberate money decision: testing eligibility on a windowed slice while settling only that slice strands a credit outside the run. Overcharged €20 in January, drinks €5 in February — settling February alone debits €5 the member does not owe and leaves the €20 invisible.

What it did not change was the screen. The admin still ticks **transactions**, in a paginated transaction journal, under a date filter. Three consequences of the reshape are invisible there:

- Ticking one row of a member silently pulls in that member's other rows, including rows on other pages and rows outside the active filter.
- Unticking one row of a member who has another row ticked changes nothing at all.
- `period_start`/`period_end` are descriptive; the run reaches back indefinitely regardless of the period shown.

[#128](https://github.com/dgloeckner/clubbar/issues/128) was filed as a cross-page selection bug — the count included rows the payload dropped — and fixing that arithmetic made the numbers honest without making the model comprehensible. The admin still learns that selection is per-member by watching a number change at the confirmation step: tick one row, the modal says 47 transactions.

[UC-A30](../use-cases/admin/UC-A30-create-settlement.md) already recorded the gap — *"the transaction picker below is effectively a member picker … the screen's right shape is not yet designed"* — and its own Main Flow already describes something closer to the right shape (grouped by member, invalid members in a separate section) than the code ever implemented.

## Decision

**The unit of settlement selection is the member, and the screen says so.**

Three parts:

### 1. Selection names members, not transactions

The admin selects members. A member's transactions are shown as detail *underneath* their row — informational, never individually selectable, because a per-transaction choice has had no meaning since #161.

The API follows: `POST /admin/settlements/preview` and `POST /admin/settlements` both accept `member_ids`. `transaction_ids` is retained as an alias that resolves to the same members, since the terminal-era clients and existing tests post it, but it is no longer the interface the admin UI speaks.

### 2. Selection lives on its own screen

Settlement selection moves out of the Journal to **Settlements → New Settlement**, which is where [UC-A30](../use-cases/admin/UC-A30-create-settlement.md) always said it was triggered from. A transaction journal is the wrong home for a member picker: its unit of display is the transaction, its filters bound a period the run ignores, and its pagination fragments a selection that must be whole.

The Journal returns to what it is — the record of what happened, with storno as its row action.

### 3. Exclusions are shown while choosing, not at confirmation

The preview's three buckets become three sections, visible before anything is submitted.

| Section | Selectable | Why it is shown |
|---|---|---|
| Eligible (`balance >= 0`, active mandate) | Yes, all selected by default | The run |
| No active mandate | No | An alarm, not a worklist — under SEPA-only ([ADR-0020](./0020-sepa-mandate-requirement-terminal-access.md)) this set should be empty in steady state ([UC-A82](../use-cases/admin/UC-A82-sepa-invalid-report.md)) |
| In credit (`balance < 0`) | No | The club owes them money (§ 812 BGB); the remedy is a refund, and it must never be silent (ruling #141) |

Keeping the last two apart is load-bearing: their remedies are opposites — chase the bank details versus pay the member back — so one merged warning list hides the action the treasurer has to take.

```mermaid
sequenceDiagram
    actor Admin
    participant Screen as New Settlement
    participant API as Settlements API

    Admin->>Screen: open
    Screen->>API: POST /settlements/preview
    API-->>Screen: eligible · no-mandate · credit, each with whole position
    Screen-->>Admin: three sections; eligible pre-selected
    Admin->>Screen: deselect some members
    Screen->>API: POST /settlements/preview {member_ids}
    API-->>Screen: run totals for exactly those members
    Screen-->>Admin: members · transactions · amount · execution date
    Admin->>Screen: confirm
    Screen->>API: POST /settlements {member_ids}
    API-->>Screen: settlement created
```

Every figure the admin sees comes from the server, derived from the same members the create call will name. The client never computes a total: under the sweep it cannot, since what it holds is a selection and what the run contains is a position.

## Consequences

**Positive**

- The affordance matches the semantics. Selecting a member and settling that member's whole position are the same act, and the screen shows it as one.
- Cross-page selection loss (#128) becomes structurally impossible rather than fixed: there are far fewer members with open positions than transactions, and the list is not a paginated view of a larger truth.
- The credit bucket gets a home. Members owed money were previously discoverable only by triggering a 422, which is also the standing report ruling #141 §4 asked for.
- The date filter stops lying, because it is gone from the flow that ignores it.

**Negative**

- The Journal loses a workflow some admins may be used to. Mitigation: the Journal keeps storno, and links to the new screen.
- A member with one €2 purchase and a member with 300 rows look alike in a member list. Mitigation: each row carries the transaction count and balance, and expands to the detail.
- `transaction_ids` living on as an alias is two ways to say one thing. Mitigation: it is documented as the compatibility path and the admin UI does not use it.
- The member list is unpaginated. Acceptable at club scale (hundreds), and revisited if a deployment shows otherwise.

**Neutral**

- No schema change. The reshape this screen exposes was already implemented in #161; this ADR is about the surface, not the money.
