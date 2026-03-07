# UC-T02: View Tab Balance and Transaction History

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: Balance is displayed in the terminal header bar. The scrollable 90-day transaction history is not implemented — balance display alone confirmed sufficient by stakeholder.

## Actor
Member

## Preconditions
- Member has valid RFID card
- Member exists in local cache

## Trigger
Member scans RFID card

## Main Flow
1. Member scans RFID card
2. System displays greeting with member name
3. System displays current tab balance
4. System shows product view (cart is empty)
5. Member taps "My Balance" or balance display area
6. System shows balance detail screen with transaction history
7. Member reviews transactions
8. Member taps back or waits for timeout

## Postconditions
- No changes to data
- No transaction created
- Shopping cart remains empty

## Balance Display (Product View)

| Element | Description |
|---------|-------------|
| Current Balance | Sum of unsettled transactions |
| Preview Balance | Current + cart total (same when cart empty) |
| Balance tap target | Navigates to detail view |

## Balance Detail Screen

| Element | Description |
|---------|-------------|
| Current Balance | Large display of total owed |
| Last Settlement | Date and amount of last settlement |
| Transaction List | Scrollable list of recent transactions |
| Back Button | Return to product view |

## Transaction List

### Display Period
- Last 90 days of transactions
- Stored in local cache
- Sorted by date (newest first)

### Transaction Item Display

| Field | Content |
|-------|---------|
| Date | Transaction date (e.g., "Mon, 15 Jan") |
| Time | Transaction time (e.g., "14:32") |
| Product | Product name (in member's language) |
| Quantity | If > 1, show "2x Product Name" |
| Amount | Positive (charge) or negative (correction) |

### Transaction Types

| Type | Display | Amount Color |
|------|---------|--------------|
| Purchase | Product name | Red/negative |
| Correction | "Correction: [Product]" or reason | Green/positive |

### Grouping
- Transactions grouped by date
- Date headers separate groups (e.g., "Today", "Yesterday", "Mon, 15 Jan")

## Variants

### V1: No Transactions
- Member has no transactions in last 90 days
- Display "No recent transactions" message
- Balance shows €0.00 (or current unsettled amount)

### V2: Many Transactions
- List is scrollable
- Lazy loading for performance (load 20 at a time)
- "Load more" or infinite scroll

### V3: View From Cart
- Member can also access balance detail from cart view
- Same transaction list displayed

## Data Source
- Transactions from local SQLite cache
- 90-day window calculated from current date
- May not include very recent backend transactions if sync pending
- Sync indicator shows if data may be stale

## Navigation

```
Product View
    │
    │ tap balance
    ▼
Balance Detail ◄──────┐
    │                 │
    │ tap back        │ scroll/view
    ▼                 │
Product View     Transaction List
```

## Test Derivation
- View balance: scan, tap balance, verify detail screen shown
- Transaction list: verify transactions from last 90 days displayed
- Correct amounts: verify purchase shows as charge, correction shows as credit
- Date grouping: verify transactions grouped by date
- Sort order: verify newest transactions first
- Empty history: new member, verify "No recent transactions" message
- Scroll: member with many transactions, verify scroll works
- Back navigation: tap back, verify return to product view
- Timeout: view transactions, wait for timeout, verify return to idle
- Corrections: verify corrections shown with product name or reason
- Offline data: verify cached transactions shown when offline
