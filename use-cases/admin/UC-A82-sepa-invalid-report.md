# UC-A82: Members Needing SEPA Data

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin clicks "SEPA Issues" in navigation or dashboard alert

## Overview

This report shows members who:
1. Have missing or invalid SEPA data (cannot use terminal)
2. Have an open balance (owe money that cannot be collected)

**When members appear here:**
- Legacy members created before SEPA requirement was enforced
- Members whose IBAN was cleared by admin (e.g., to revoke SEPA access)

These members need admin attention to either:
- Add SEPA data so they can continue using the terminal
- Resolve their balance through other means

## Main Flow
1. Admin navigates to SEPA Issues report
2. System displays list of members with SEPA issues
3. Admin can filter and sort the list
4. Admin clicks member row to edit
5. Admin adds IBAN and/or mandate reference
6. System validates and saves
7. Member is removed from report

## Report Columns

| Column | Description |
|--------|-------------|
| Member Name | First name, last name |
| Current Balance | Outstanding amount (€) |
| Missing Data | "IBAN", "Mandate", or "Both" |
| Last Transaction | Date of most recent transaction |
| Created At | Member account creation date |

## SEPA Issue Detection

```
is_sepa_valid = FALSE when:
  - iban IS NULL, OR
  - mandate_reference IS NULL
```

## Filter Options

| Filter | Options |
|--------|---------|
| Missing Data | All, IBAN only, Mandate only, Both |
| Balance | All, Has balance (> 0), No balance (= 0) |
| Sort | Balance (desc), Last transaction, Name |

## Priority View

Default sort: Balance descending (highest debt first)

Members with high balance and missing SEPA should be addressed urgently.

## Dashboard Integration

Dashboard shows alert if any members have SEPA issues:

| Condition | Display |
|-----------|---------|
| No issues | No alert |
| 1-5 members | Yellow badge: "X members need SEPA data" |
| 6+ members | Red badge: "X members need SEPA data" |

Clicking badge navigates to this report.

## Quick Actions

| Action | Description |
|--------|-------------|
| Edit Member | Opens member edit form (to add SEPA data) |
| Manual Settlement | Settle selected members without SEPA ([UC-A35](./UC-A35-manual-settlement.md)) |
| Export CSV | Download report for offline processing |

**Resolution Options:**
1. **Add SEPA data** → Member can use terminal and be included in future SEPA settlements
2. **Manual settlement** → Clear balance via cash/transfer/write-off (member still cannot use terminal)

## Postconditions
- Report displayed with current data
- Admin can navigate to member edit or manual settlement

## Variants

### V1: No Issues Found
1. Admin opens report
2. System shows "All members have valid SEPA data"
3. Empty state with checkmark icon

### V2: Member Balance = 0
1. Member has no SEPA data but balance = 0
2. Still shown in report (cannot use terminal)
3. Lower priority than members with balance

### V3: Export to CSV
1. Admin clicks "Export CSV"
2. System generates CSV with report columns
3. Admin receives download
4. Useful for bulk outreach to members

## Error Cases

### E1: No Permission
- Unauthenticated users cannot access this report
- Display "Authentication required"

## Test Derivation
- Happy path: create member without IBAN, verify appears in report
- Missing mandate only: member with IBAN but no mandate appears
- Both missing: member without IBAN and mandate appears
- SEPA valid: member with both does not appear
- Balance filter: filter by has balance, verify correct results
- Sort by balance: verify highest balance first
- Edit flow: click member, add IBAN, save, verify removed from report
- Dashboard badge: create SEPA-invalid member, verify badge appears
- Export CSV: download and verify content matches report

## Related

- [UC-A11: Create Member](./UC-A11-create-member.md) - SEPA data required during creation
- [UC-A12: Edit Member](./UC-A12-edit-member.md) - SEPA data can be updated
- [UC-A35: Manual Settlement](./UC-A35-manual-settlement.md) - Settle without SEPA
- [UC-A30: Create Settlement (SEPA)](./UC-A30-create-settlement.md) - SEPA settlements exclude invalid members
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
