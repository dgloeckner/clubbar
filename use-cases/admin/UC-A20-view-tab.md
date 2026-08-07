# UC-A20: View Tab

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: No dedicated per-member tab view. Member balance shown on Members page; transaction history accessible via Journal page with member filter. Confirmed acceptable by stakeholder.

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists

## Trigger
Admin opens member detail or clicks balance

## Main Flow
1. Admin navigates to member detail
2. System displays tab information:
   - Current balance
   - Recent transactions (last 30 days)
   - Settlement history
3. Admin can view full transaction history
4. Admin can filter by date range

## Tab View Elements

| Element | Content |
|---------|---------|
| Current balance | Sum of unsettled transactions |
| Last transaction | Date and amount |
| Transaction list | Scrollable list with details |
| Settlement list | Past settlements with amounts |

## Transaction Display

| Column | Content |
|--------|---------|
| Date | Timestamp |
| Type | Purchase, Storno, Payout |
| Description | Product name or adjustment reason |
| Amount | Positive (charge) or negative (credit) |
| Running total | Balance after transaction |

## Filters
- Date range (from/to)
- Transaction type
- Include settled transactions

## Postconditions
- Tab information displayed
- No data modified

## Test Derivation
- View balance: correct sum shown
- Transaction list: recent first
- Filter by date: only matching shown
- Settlement history: all past settlements listed
- Empty history: "No transactions" message
- Running total: calculated correctly
