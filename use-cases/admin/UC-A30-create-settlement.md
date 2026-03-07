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

Creates a SEPA Direct Debit settlement for selected transactions. By default, all open transactions of SEPA-valid members are selected, but admin can customize the selection.

Members without valid SEPA data are excluded and must be handled via [UC-A35: Manual Settlement](./UC-A35-manual-settlement.md).

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
| IBAN present | `iban IS NOT NULL` |
| IBAN valid | Passes checksum validation |
| Mandate reference present | `mandate_reference IS NOT NULL` |
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
