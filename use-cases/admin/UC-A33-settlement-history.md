# UC-A33: Settlement History

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settlements section

## Main Flow
1. Admin clicks "Settlements" in navigation
2. System displays list of all settlements
3. List shows for each settlement:
   - Settlement date
   - Execution date
   - Member count
   - Total amount
   - Status

## List Columns

| Column | Content |
|--------|---------|
| Created | Settlement creation date |
| Execution | SEPA execution date |
| Members | Count of members included |
| Amount | Total settlement amount |
| Exported | Yes/No or timestamp |
| Cancelled | Yes/No (cancelled settlements shown differently) |
| Actions | View details, Download, Cancel |

## Sorting
- Default: Most recent first (by created date)

## Postconditions
- Settlement list displayed

## Test Derivation
- List all settlements: all shown (including cancelled)
- Sorting: most recent first
- Amounts: calculated correctly
- Exported indicator: shows if/when exported
- Cancelled display: cancelled settlements visually distinct
- Empty state: "No settlements yet"
