# UC-T14: Update Local Balance on Transaction Sync

## Actor
System (Terminal Sync Process)

## Preconditions
- Terminal has unsynchronized transactions in local queue
- Network connectivity is available
- Backend is responsive

## Trigger
Periodic sync cycle (every 30-60 seconds) OR manual sync requested by operator

## Main Flow

1. Terminal initiates sync cycle
2. System retrieves unsynced transactions from SQLite
3. System POST batch to `/sync/transactions` with transaction list
4. Backend processes:
   - Validates transactions
   - Inserts into database (idempotent via UUID)
   - Calculates updated balances for affected members
   - Returns response with accepted transaction IDs and member balances
5. Terminal receives response with:
   - `accepted_transaction_ids`: List of confirmed transaction UUIDs
   - `member_balances`: Map of member_id → balance_cents
6. **Terminal atomically updates** (in single SQLite transaction):
   - Set `synced = true` for accepted transactions
   - Update `members_balance` table with new balances
   - Record sync timestamp
7. Transaction commit succeeds → sync cycle complete
8. Next sync cycle begins

## Postconditions
- All unsynced transactions marked as synced
- Member balances updated to authoritative backend values
- Sync timestamp persisted
- Member sees updated balance on next screen refresh

## Variants

### V1: Sync Partially Succeeds
1. Terminal uploads 50 transactions
2. Backend accepts 45 but rejects 5 (validation errors)
3. Terminal response lists only 45 accepted IDs
4. Terminal marks only those 45 as synced
5. Remaining 5 stay in queue for next sync attempt
6. Balance updated for transactions that succeeded

### V2: Network Fails Mid-Sync
1. Terminal sends POST request
2. Network drops before response received
3. Terminal receives connection timeout
4. Entire request is retried in next sync cycle
5. **Atomicity**: If response not received, nothing is committed (no partial updates)
6. Idempotent design ensures retry is safe

### V3: Backend Returns Error
1. Terminal sends sync request
2. Backend returns 5xx Service Unavailable
3. Terminal doesn't update state
4. Transaction remains unsync'd, will retry next cycle
5. Member sees stale balance until next successful sync

### V4: No Transactions to Sync
1. Sync cycle triggered
2. No unsynced transactions in queue
3. System still fetches member/product deltas (per ADR-0012)
4. No balance update needed (nothing changed)
5. Sync timestamp still persisted

### V5: New Member in Transactions
1. Terminal syncs purchases for new member (just added)
2. Backend accepts transaction, includes member in response
3. Terminal inserts/updates that member's balance row
4. Member now displays correct balance on future screens

### V6: Balance Divergence Detected
1. Terminal stores local balance of €50.00 for a member
2. Sync response shows backend balance is €52.00
3. **Decision**: Trust backend (authoritative source)
4. Update to €52.00
5. Log warning in terminal logs (may indicate issue)
6. Next sync will reflect correct state

## Database Atomicity

**Critical**: Balance update must be atomic with transaction sync marking:

```sql
BEGIN TRANSACTION;
  UPDATE transactions SET synced = true WHERE id IN (...);
  UPDATE members_balance SET balance_cents = ? WHERE member_id = ?;
  UPDATE members_balance SET balance_cents = ? WHERE member_id = ?;
  INSERT INTO sync_log (completed_at, transaction_count, ...) VALUES (...);
COMMIT;
```

**If ANY step fails → ROLLBACK entire transaction**
- No partial updates to balance
- No transactions marked as synced without balance update
- Retry in next sync cycle

## API Contract

### Request (POST /sync/transactions)

```json
{
  "transactions": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "member_id": "550e8400-e29b-41d4-a716-446655440100",
      "product_id": "550e8400-e29b-41d4-a716-446655440200",
      "amount_cents": 350,
      "created_at": "2025-01-25T14:32:00Z"
    }
  ]
}
```

### Response (200 OK)

```json
{
  "accepted_transaction_ids": [
    "550e8400-e29b-41d4-a716-446655440000"
  ],
  "member_balances": {
    "550e8400-e29b-41d4-a716-446655440100": 4500
  }
}
```

**Key requirement**: Response MUST include `member_balances` object for implementation of this UC.

## Balance Update Behavior

| Situation | Action |
|-----------|--------|
| Member balance in response | Update SQLite members_balance table |
| Member balance missing | Keep existing local value (log warning) |
| New member (not in local table) | Insert new row with balance from response |
| Negative balance (credit) | Store as negative int (e.g., -2000 = €-20.00 credit) |
| Zero balance | Update to 0 (not an error) |

## Sync Success Indicators

- [ ] Transactions marked as synced
- [ ] Member balances table updated
- [ ] Sync timestamp persisted
- [ ] No database errors
- [ ] Transaction commit successful

## Error Recovery

| Error Type | Recovery Strategy |
|-----------|------------------|
| Network timeout | Entire request retried next cycle (no changes made) |
| Backend 5xx error | Entire request retried next cycle (no changes made) |
| Validation error (4xx) | Failed transactions logged; investigate in next cycle |
| Database write failure | Entire transaction rolled back; retry next sync |
| Response missing balances | Keep previous balances; log warning |

## Testing Requirements

### Happy Path
- [ ] Sync 10 transactions successfully
- [ ] All marked as synced
- [ ] Balance updated correctly
- [ ] Timestamp persisted

### Partial Success
- [ ] Sync 10 transactions, 8 accepted
- [ ] Only accepted ones marked as synced
- [ ] Balance reflects only accepted count
- [ ] Remaining 2 in queue for next sync

### Network Failure
- [ ] Request timeout mid-sync
- [ ] No transactions marked as synced
- [ ] Balance unchanged
- [ ] Next sync retries same batch

### No Transactions
- [ ] Sync cycle with empty queue
- [ ] Members/products still fetched (per ADR-0012)
- [ ] Timestamp updated
- [ ] No balance changes

### Balance Divergence
- [ ] Local balance €50, response €52
- [ ] Terminal updates to €52 (authoritative)
- [ ] Warning logged
- [ ] Future displays show €52

### Atomicity
- [ ] Simulate database commit failure mid-transaction
- [ ] Verify no partial updates
- [ ] Transactions remain unsynced
- [ ] Balance unchanged
- [ ] Retry succeeds on next cycle

### Large Batch
- [ ] Sync 100 transactions (may be batched in code)
- [ ] All process correctly
- [ ] Performance acceptable (< 2s)
- [ ] All balances updated

## Related Use Cases
- UC-T01: Book Product to Tab (creates transaction that gets synced)
- UC-T02: View Tab Balance (displays balance updated by this sync)
- UC-T12: Error Scenarios (covers sync failures)

## Related ADRs
- ADR-0023: Terminal Balance State Management
- ADR-0004: Immutable Transaction Storage
- ADR-0012: Eventual Consistency and Frontend Caching

## Implementation Notes

**API Change**: Extend `POST /sync/transactions` response to include `member_balances` field.

**SQLite Schema**: Create `members_balance` table with fields:
- `member_id` (PK)
- `balance_cents` (INT)
- `last_updated_at` (DATETIME)

**Transaction Wrapping**: Use SQLite transactions (BEGIN/COMMIT/ROLLBACK) for atomicity.

**Logging**: Log each sync with:
- Transaction count synced
- Member IDs and balance changes
- Timestamp
- Success/failure status

**Testing**: Use test fixtures to simulate partial failures, timeouts, and missing balance data.
