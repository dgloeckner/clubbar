# UC-A30: Create Settlement (SEPA)

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Organization SEPA configuration complete (UC-A60)
- At least one unsettled transaction for a member with valid SEPA data

## Trigger
Admin opens Settlements → New Settlement

## Overview

> ### Reshaped 2026-08-07 — selection picks **members**, not transactions
>
> [Exclude-and-flag](https://github.com/dgloeckner/ruderbar/issues/141) §1–§2 changed what a settlement contains. A settlement sweeps **every unsettled transaction of each included member**, ignoring the date window and any hand-picked subset. The selection chooses *which members take part*; each included member then settles their **whole position**.
>
> Why: testing eligibility on a windowed slice while settling only that slice lets an old credit strand outside the run. Overcharged €20 in January, drinks €5 in February — settling February alone debits €5 the member does not owe and leaves the €20 invisible.
>
> Consequences: `period_start`/`period_end` are **descriptive**, not a bound on contents, and a run may reach back indefinitely.
>
> Also: a member in **net credit** is excluded entirely, and a member at **exactly zero** is settled (closing the rows out) but generates no line in the file.
>
> **The screen's shape was settled in [ADR-0030](../../adr/0030-settlement-selection-is-a-member-picker.md)** (2026-08-08): the admin selects members on a dedicated New Settlement screen, and the Journal no longer carries settlement selection at all. This use case describes that screen. The interim transaction-picker in the Journal — and its cross-page selection bug, [#128](https://github.com/dgloeckner/ruderbar/issues/128) — is gone.

Creates a SEPA Direct Debit settlement. Selection determines which **members** take part; each included member settles their entire unsettled position.

Members without an **active mandate** are excluded. Under SEPA-only ([ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md)) such a member cannot use the bar at all, so this set should be **empty in steady state** — anyone in it is inside the terminal's offline sync window or on a post-return collection hold. Treat it as an alarm, not a routine worklist ([UC-A82](./UC-A82-sepa-invalid-report.md)).

## Main Flow

1. Admin opens Settlements → "New Settlement"
2. System displays the **member selection view**, in three sections (below)
3. Eligible members are **selected by default**; admin deselects any who should not take part
4. System restates the run whenever the selection changes — members, transactions swept, total amount — asking the server rather than summing the visible rows
5. System shows the execution date it will submit, the earliest valid one (UC-SEPA-07)
6. Admin confirms
7. System creates the settlement record naming the selected members
8. System marks **every unsettled transaction of each selected member** as settled
9. System displays confirmation with download links (SEPA XML, CSV)

## Member Selection View

The unit of selection is the **member** ([ADR-0030](../../adr/0030-settlement-selection-is-a-member-picker.md)). Individual transactions are shown as detail beneath a member's row and are never separately selectable — a per-transaction choice has had no meaning since the reshape, because the run sweeps the member's whole position either way.

### Eligible Members Section

Members with an active mandate whose total unsettled position is `>= 0`.

| Column | Description |
|--------|-------------|
| ☑ | Checkbox (**member**-level) |
| Member | Member name |
| Transactions | How many unsettled transactions this run would settle for them |
| Balance | The member's **whole** unsettled position — not a window's slice |
| ▸ | Expands to that member's unsettled transactions (read-only: date, product, amount) |

**Selection Controls:**
- "Select All" / "Select None" → toggles every eligible member
- Member checkbox → includes or excludes that member entirely

**Default Selection:** All eligible members selected.

**Run summary** (always visible, always from the server): selected member count · transactions the run will settle · total amount · execution date.

### No Active Mandate Section (Read-only)

| Column | Description |
|--------|-------------|
| Member | Member name |
| Balance | Total outstanding balance |
| Issue | "Missing IBAN", "Missing Mandate", or "Both" |
| Action | Link to "Manual Settlement" (UC-A35) |

These members cannot be collected by direct debit. Under SEPA-only this section should be **empty in steady state** — treat a non-empty one as an alarm ([UC-A82](./UC-A82-sepa-invalid-report.md)).

### In Credit Section (Read-only)

Members whose total unsettled position is **negative** — the club owes them money.

| Column | Description |
|--------|-------------|
| Member | Member name |
| Balance | Negative position |
| Action | Link to "Manual Settlement" (UC-A35) to record the payout |

Excluded from collection entirely (ruling #141, § 812 BGB). Kept separate from the section above because the remedy is the opposite one: pay them back rather than chase their bank details.

## Filters

| Filter | Options |
|--------|---------|
| Member | Search by name |

No date filter. The run ignores date bounds by construction, so offering one would misdescribe what is about to happen.

## SEPA Eligibility

A **member** takes part if they meet ALL conditions:

| Condition | Check |
|-----------|-------|
| Active mandate | The member has an active mandate record, which carries the reference, the IBAN and the signature date ([ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md), amended) |
| IBAN valid | Passes checksum validation |
| Not anonymized | `deleted_at IS NULL` |
| Position not negative | Total unsettled balance `>= 0`; a negative one is excluded into the credit section (ruling #141) |

`is_active` is deliberately **not** a condition (ruling #173). Deactivation is temporary — a lost card, a seasonal break — and must not strand debt the member genuinely owes.

## Execution Date Rules
- Minimum: TODAY + 7 calendar days
- Suggested: Earliest valid date
- Weekends/holidays: System warns but allows

## Postconditions
- Settlement record created (type: `sepa`)
- **Every** unsettled transaction of each selected member marked as settled — including transactions older than any period shown
- Members not selected keep their whole position open
- `period_start`/`period_end` recorded descriptively; they do not bound what was settled
- A selected member at exactly zero is settled (their rows close out) but produces no line in the SEPA file
- SEPA XML available for download
- CSV available for download
- Audit log entry

## Error Cases

### E1: No SEPA Configuration
- Display "Configure SEPA settings first"
- Link to settings (UC-A60)

### E2: No Members Selected
- Display "Select at least one member"

### E3: No Eligible Members
- All members with open transactions have SEPA invalid
- Display "No transactions eligible for SEPA settlement"
- Suggest: "Use Manual Settlement for members without SEPA data"

### E4: Execution Date Too Soon
- Display "Execution date must be at least 7 days from today"

## Test Derivation

**Selection:**
- Default selection: every eligible member pre-selected
- Deselect member: that member's whole position stays open
- Select none then pick one: only that member is settled
- Search by name: narrows the member list without changing the selection
- Individual transactions are detail only — there is no per-transaction checkbox to assert

**Settlement (the sweep):**
- A member with transactions older than any period shown has **all** of them settled
- Selecting a member settles rows the admin never saw on screen
- Multiple members: each selected member settled in full, others untouched
- A member at exactly zero settles and produces no line in the SEPA file

**Excluded members:**
- No mandate: listed in its own section, not selectable, with the issue named
- In credit: listed in its **own** section, distinct from no-mandate, not selectable
- Neither can be dragged into a run — creation refuses them even if posted directly
- Both link to manual settlement

**The run summary:**
- States the swept transaction count, not the number of members ticked
- Changes when the selection changes
- Matches what the settlement actually contains once created

**Validation:**
- No selection: error message
- Execution date: reject < 7 days

## Related

- [UC-A35: Manual Settlement](./UC-A35-manual-settlement.md) - Settle transactions without SEPA
- [UC-A31: Download SEPA XML](./UC-A31-download-sepa-xml.md)
- [UC-A32: Download CSV](./UC-A32-download-csv.md)
- [UC-A82: SEPA Issues Report](./UC-A82-sepa-invalid-report.md) - Members needing SEPA data
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
