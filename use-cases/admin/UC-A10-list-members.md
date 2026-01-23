# UC-A10: List Members

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Members section

## Main Flow
1. Admin clicks "Members" in navigation
2. System displays member list
3. List shows for each member:
   - Name
   - Tab balance
   - RFID status (assigned/unassigned)
   - SEPA status (valid/invalid)
4. Admin can browse, filter, or search

## List Columns

| Column | Content |
|--------|---------|
| Name | First name + last name |
| Balance | Current tab balance (€) |
| RFID | Icon: card assigned or not |
| SEPA | Icon: valid (has IBAN + mandate) or invalid (missing either) |
| Actions | Edit, View details |

## SEPA Status

SEPA status is derived from data (not stored separately):

| Status | Condition | Icon |
|--------|-----------|------|
| Valid | IBAN present AND mandate_reference present | Green checkmark |
| Invalid | IBAN missing OR mandate_reference missing | Red warning |

See [ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md) for details.

## Filters

| Filter | Options |
|--------|---------|
| Status | All, Active only, Inactive |
| Balance | All, With balance, Zero balance |
| SEPA | All, Valid, Invalid |
| Search | Text search on name |

## Sorting
- Name (A-Z, Z-A)
- Balance (high-low, low-high)
- Default: Name A-Z

## Pagination
- 25 members per page
- Page navigation at bottom

## Postconditions
- Member list displayed
- Filters and search applied

## Test Derivation
- Display all members: verify list shows correct count
- Filter by active: only active members shown
- Filter by balance: only members with balance > 0
- Filter by SEPA invalid: only members missing IBAN or mandate_reference
- Filter by SEPA valid: only members with both IBAN and mandate_reference
- Search by name: partial match works
- Combine filters: active + with balance + SEPA invalid
- Pagination: navigate pages, count correct
- Sort by balance: descending order correct
- Empty state: no members → "No members found"
- SEPA icon: member with IBAN + mandate → green checkmark
- SEPA icon: member without IBAN → red warning
