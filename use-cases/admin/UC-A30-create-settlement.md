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

> ### ⚠️ Reshaped 2026-08-07 — selection picks **members**, not transactions
>
> [Exclude-and-flag](https://github.com/dgloeckner/ruderbar/issues/141) §1–§2 changed what a settlement contains. A settlement sweeps **every unsettled transaction of each included member**, ignoring the date window and any hand-picked subset. The window and the selection choose *which members take part*; each included member then settles their **whole position**.
>
> Why: testing eligibility on a windowed slice while settling only that slice lets an old credit strand outside the run. Overcharged €20 in January, drinks €5 in February — settling February alone debits €5 the member does not owe and leaves the €20 invisible.
>
> Consequences: `period_start`/`period_end` become **descriptive**, not a bound on contents; a run may reach back indefinitely; and the transaction picker below is effectively a **member picker**. See [#128](https://github.com/dgloeckner/ruderbar/issues/128) — its cross-page selection bug is in the same screen, and the screen's right shape is not yet designed.
>
> Also: a member in **net credit** is excluded entirely, and a member at **exactly zero** is settled (closing the rows out) but generates no line in the file.

Creates a SEPA Direct Debit settlement. Selection determines which **members** take part; each included member settles their entire unsettled position.

Members without an **active mandate** are excluded. Under SEPA-only ([ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md)) such a member cannot use the bar at all, so this set should be **empty in steady state** — anyone in it is inside the terminal's offline sync window or on a post-return collection hold. Treat it as an alarm, not a routine worklist ([UC-A82](./UC-A82-sepa-invalid-report.md)).

## Main Flow

1. Admin clicks "New Settlement"
2. System displays transaction selection view:
   - Default: All open transactions of SEPA-valid members (pre-selected)
   - Grouped by member
   - SEPA-invalid members shown separately (not selectable)
3. Admin reviews and adjusts selection (optional)
4. Admin clicks "Continue"
5. System displays settlement summary:
   - Selected transaction count
   - Total amount
   - Member count
6. Admin selects execution date
7. System suggests earliest valid date (TODAY + 7 days)
8. Admin confirms settlement
9. System creates settlement record
10. System marks selected transactions as settled
11. System displays confirmation with download links (SEPA XML, CSV)

## Transaction Selection View

### SEPA-Valid Members Section

| Column | Description |
|--------|-------------|
| ☑ | Checkbox (transaction-level) |
| Member | Member name (grouped header) |
| Date | Transaction date |
| Product | Product name or "Manual entry" |
| Amount | Transaction amount |
| Member Total | Sum of selected transactions for member |

**Selection Controls:**
- "Select All" → selects all transactions
- "Select None" → deselects all
- Member row checkbox → toggles all transactions for that member
- Individual transaction checkbox → toggles single transaction

**Default Selection:** All transactions selected

### SEPA-Invalid Members Section (Read-only)

| Column | Description |
|--------|-------------|
| Member | Member name |
| Balance | Total outstanding balance |
| Issue | "Missing IBAN", "Missing Mandate", or "Both" |
| Action | Link to "Manual Settlement" |

These members cannot be included in SEPA settlement.

## Filters

| Filter | Options |
|--------|---------|
| Date range | From date, To date |
| Member | Search by name |
| Amount | Min, Max |

## SEPA Eligibility

Transaction included if member meets ALL conditions:

| Condition | Check |
|-----------|-------|
| Active mandate | The member has an active mandate record, which carries the reference, the IBAN and the signature date ([ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md), amended) |
| IBAN valid | Passes checksum validation |
| Account active | `is_active = TRUE` |
| Not anonymized | `deleted_at IS NULL` |

## Execution Date Rules
- Minimum: TODAY + 7 calendar days
- Suggested: Earliest valid date
- Weekends/holidays: System warns but allows

## Postconditions
- Settlement record created (type: `sepa`)
- Selected transactions marked as settled
- Member balances updated (for selected transactions only)
- Unselected transactions remain open
- SEPA XML available for download
- CSV available for download
- Audit log entry

## Error Cases

### E1: No SEPA Configuration
- Display "Configure SEPA settings first"
- Link to settings (UC-A60)

### E2: No Transactions Selected
- Display "Select at least one transaction"

### E3: No Eligible Members
- All members with open transactions have SEPA invalid
- Display "No transactions eligible for SEPA settlement"
- Suggest: "Use Manual Settlement for members without SEPA data"

### E4: Execution Date Too Soon
- Display "Execution date must be at least 7 days from today"

## Test Derivation

**Selection:**
- Default selection: all transactions pre-selected
- Deselect transaction: excluded from settlement
- Deselect member: all member's transactions excluded
- Select none then pick: only picked transactions included
- Filter by date: only matching transactions shown

**Settlement:**
- Partial selection: only selected transactions settled
- Remaining open: unselected transactions still have balance
- Multiple members: each member's selected transactions settled

**SEPA-Invalid:**
- Cannot select: SEPA-invalid members' transactions not selectable
- Shown separately: listed with issue description
- Link to manual: can navigate to manual settlement

**Validation:**
- No selection: error message
- Execution date: reject < 7 days

## Related

- [UC-A35: Manual Settlement](./UC-A35-manual-settlement.md) - Settle transactions without SEPA
- [UC-A31: Download SEPA XML](./UC-A31-download-sepa-xml.md)
- [UC-A32: Download CSV](./UC-A32-download-csv.md)
- [UC-A82: SEPA Issues Report](./UC-A82-sepa-invalid-report.md) - Members needing SEPA data
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
