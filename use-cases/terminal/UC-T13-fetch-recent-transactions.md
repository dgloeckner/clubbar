# UC-T13: Fetch and Display Recent Transaction History On-Demand

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: Backend endpoint exists and is called. Transaction list UI on terminal is minimal — balance display confirmed sufficient by stakeholder.

## Actor
Member / Terminal Operator

## Preconditions
- Member has valid RFID card and is identified
- Terminal is running (member on product view or balance screen)
- User explicitly requests transaction history

## Trigger
Member taps "View Transactions", "Transaction History", or balance detail area showing "View more"

## Main Flow

1. Member taps transaction history button
2. Terminal checks network connectivity
3. System initiates fetch: `GET /api/transactions/{member_id}?limit=50&sort=created_at:desc`
4. Backend processes request and returns transaction list
5. Terminal displays formatted transaction list:
   - Date grouped (Today, Yesterday, Last week, etc.)
   - Time and product name per transaction
   - Amount (red for charges, green for stornos)
   - Sorted by date (newest first)
6. Member scrolls through transactions
7. Member taps back or waits for timeout
8. Returns to product view

## Postconditions
- Transaction history displayed to member
- No state changes
- No transactions created

## Variants

### V1: Network Unavailable
1. Member taps "View Transactions"
2. Terminal detects no network connectivity
3. System displays: "Transaction history requires an internet connection"
4. Member acknowledges (no fallback to cached data)
5. Returns to product view; can continue shopping

### V2: No Recent Transactions
1. Member has no transactions in last 90 days
2. Backend returns empty transaction list
3. Terminal displays: "No recent transactions"
4. Show balance as €0.00 or current unsettled amount

### V3: Backend Error (5xx)
1. Backend temporarily unavailable
2. Terminal shows: "Unable to load transactions - try again later"
3. Optionally displays cached data (if available) with "outdated" warning

### V4: Member Not Found
1. Invalid member_id (should not occur in normal flow)
2. Terminal shows: "Member not recognized"
3. Prompt to scan card again

### V5: Large Transaction History (100+ transactions)
1. Terminal requests first 50 transactions
2. User scrolls to bottom
3. Show "Load more" button or auto-load next batch
4. Fetch additional transactions with offset parameter

## API Requirements

**Endpoint**: `GET /api/terminal/transactions/{member_id}`

**Query Parameters**:
- `limit` (int, default 50): Maximum transactions
- `offset` (int, default 0): For pagination
- `since` (ISO datetime, optional): Transactions after date

**Response Fields Per Transaction**:
- `id` (UUID): Transaction identifier
- `amount_cents` (int): Amount (positive=charge, negative=credit)
- `type` (enum): 'purchase', 'storno' or 'payout'
- `product_name` (string): What was purchased or reason
- `created_at` (ISO 8601): When transaction occurred
- `notes` (string): Reason — required for a storno

**Error Responses**:
- 404: Member not found
- 503: Service unavailable
- 400: Invalid parameters

## Data Display Format

| Field | Display Format | Example |
|-------|---|---|
| Date | Day group (Today, Yesterday, Mon Jan 15) | Today |
| Time | HH:MM format | 14:32 |
| Product | Product name in member's language | "Pils" |
| Quantity | "2x Product" if qty > 1 | "2x Sprite" |
| Amount | €X.XX with color coding | €3.50 (red) or €-3.50 (green) |
| Type Storno | "Storno: [reason]" | "Storno: Wrong amount" |

## Transaction Type Display

| Type | Display | Example |
|------|---------|---------|
| Purchase | Product name | "Pils" |
| Storno | "Storno: [reason]" | "Storno: Duplicate charge" |

## Offline Requirement

Transaction history is **online-only**. No offline fallback or cache.

| Scenario | Behavior |
|----------|----------|
| Network available | Fetch fresh from backend |
| Network unavailable | Show "Transaction history requires an internet connection" |
| Network slow (> 3s timeout) | Show "Connection timed out - please try again" |
| Backend error (5xx) | Show "Unable to load transactions - try again later" |

## Navigation Flow

```
Product View
    │
    │ tap balance or history
    ▼
Balance Detail with History
    │
    ├─ tap "View More" or history link
    │  ▼
    ├─ Fetch Recent Transactions
    │  ├─ (Network unavailable) ──► Show offline message
    │  ├─ (Success) ──────────────► Display transaction list
    │  └─ (Error) ───────────────► Show error + cached data
    │
    │ tap back
    ▼
Product View
```

## Test Derivation

### Happy Path
- [ ] Member scans, taps "View Transactions"
- [ ] Network available, transactions fetched
- [ ] Display shows correct transaction count
- [ ] Transactions sorted newest first
- [ ] Amounts displayed correctly (positive=red, negative=green)
- [ ] Date grouping correct (Today, Yesterday, etc.)

### Offline Scenario
- [ ] Offline terminal, tap "View Transactions"
- [ ] Show "Transaction history requires an internet connection"
- [ ] No cached data displayed (online-only feature)
- [ ] User can return to product view and continue shopping

### No Transactions
- [ ] New member (no purchase history)
- [ ] Tap "View Transactions"
- [ ] Display "No recent transactions"

### Backend Error
- [ ] Backend returns 503 Service Unavailable
- [ ] Terminal shows error message
- [ ] If cached available, show cached + warning
- [ ] User can return and continue shopping

### Large History
- [ ] Member with 150+ transactions
- [ ] First 50 fetched and displayed
- [ ] "Load more" button shows
- [ ] Tap loads next 50
- [ ] Pagination works correctly

### Timeout Handling
- [ ] Network very slow (2-3s latency)
- [ ] Terminal timeout set to 2-3s
- [ ] Graceful fallback (cached or "unavailable")
- [ ] Checkout not blocked by slow network

### Invalid Member
- [ ] Somehow invalid member_id provided (edge case)
- [ ] Backend returns 404
- [ ] Terminal shows "Member not recognized"

## Related Use Cases
- UC-T02: View Tab Balance (parent: shows balance detail where history is accessed)
- UC-T01: Book Product to Tab (context: purchase creates transactions shown in history)

## Related ADRs
- ADR-0024: Transaction History Retrieval in Terminal
- ADR-0012: Eventual Consistency and Frontend Caching
- ADR-0004: Immutable Transaction Storage
