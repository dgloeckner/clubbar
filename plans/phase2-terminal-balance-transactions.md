# Phase 2.A: Terminal Balance & Transaction History

**Goal**: Implement member balance state management and on-demand transaction history retrieval on terminal, with API extensions for balance sync updates.

**Status**: Architecture Complete; Implementation Pending

**Key Decision**: Transaction history is **online-only** with no offline fallback (see ADR-0024)

---

## Architecture Overview

### Architectural Decisions

This phase implements two key ADRs:
- **[ADR-0023](../adr/0023-terminal-balance-state-management.md)**: Terminal stores member balance in SQLite, updated atomically during sync
- **[ADR-0024](../adr/0024-transaction-history-retrieval-terminal.md)**: On-demand transaction history fetch via GET API (online-only, no cache)

### Use Cases

- **[UC-T13](../use-cases/terminal/UC-T13-fetch-recent-transactions.md)**: Fetch and display recent transaction history on-demand
- **[UC-T14](../use-cases/terminal/UC-T14-update-balance-on-sync.md)**: Update local balance atomically on transaction sync

---

## Progress Summary

| Milestone | Status | Description |
|-----------|--------|-------------|
| **A. Architecture & Design** | [x] | ADRs, use cases, API spec — all documented and cross-linked |
| **B. Backend API Implementation** | [x] | Extended sync endpoint, implemented transaction history endpoint |
| **C. Terminal SQLite Schema** | [x] | Complete schema file with all 6 tables and TypeScript interfaces |
| **D. Terminal Sync Logic** | [x] | Atomic balance update with transaction wrapper and error handling |
| **E. Terminal UI: Transaction History** | [ ] | Build transaction history screen and API client |
| **F. API Tests** | [ ] | Playwright tests for new endpoints and balance updates |
| **G. Integration Tests** | [ ] | End-to-end: sync → balance update → UI display |

---

## Milestone A: Architecture & Design (Complete)

**Objective**: Design and document architecture decisions, API changes, and use cases.

**Status**: ✅ **COMPLETE**

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| A.1 | Create ADR-0023 | Terminal Balance State Management (8.7 KB) | [x] |
| A.2 | Create ADR-0024 | Transaction History Retrieval - Online Only (11 KB) | [x] |
| A.3 | Update ADR-0012 | Sync cycle diagram, cross-links, cached data table | [x] |
| A.4 | Update ADR-0004 | Cross-references to new ADRs | [x] |
| A.5 | Create UC-T13 | Fetch and display recent transactions | [x] |
| A.6 | Create UC-T14 | Update balance on sync (atomicity) | [x] |
| A.7 | Update Terminal API spec | Add member_balances to POST /sync/transactions response; add GET /api/terminal/transactions/{member_id} endpoint | [x] |
| A.8 | Cross-link all ADRs | Verify bidirectional references and consistency | [x] |

### Success Criteria

- [x] ADR-0023 decided and documented
- [x] ADR-0024 decided and documented (online-only, no cache fallback)
- [x] Both ADRs clearly cross-linked to existing ADRs (0001, 0003, 0004, 0012)
- [x] Use cases define all test scenarios
- [x] API spec includes member_balances response field
- [x] API spec includes new transaction history endpoint
- [x] All documentation consistent (no mentions of offline fallback for history)

### Notes

- Online-only decision for transaction history made after reviewing alternatives
- Simplifies implementation (no caching logic needed)
- Aligns with offline-first principle: core features (purchase, balance) work offline; convenience features (history) require network
- See [ONLINE-ONLY-DECISION.md](../ONLINE-ONLY-DECISION.md) for detailed rationale

---

## Milestone B: Backend API Implementation

**Objective**: Extend backend to provide updated balance information and transaction history endpoint.

**Status**: ✅ **COMPLETE**

**API Changes**:
1. **POST /sync/transactions** (extend response)
   - Add `member_balances` field (object: member_id → balance_cents)
   - Calculate balances for all affected members
   - Return in response (required field)

2. **GET /api/terminal/transactions/{member_id}** (new endpoint)
   - Query parameters: limit (default 50), offset (default 0), since (optional)
   - Response: { member_id, count, transactions[] }
   - Error handling: 400, 401, 404, 429, 503

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| B.1 | Update POST /sync/transactions | Calculate member balances after transaction insert | [x] |
| B.2 | Add member_balances to sync response | DTO change, response schema update | [x] |
| B.3 | Create TransactionController method | `getRecentTransactions($memberId, $limit, $offset)` | [x] |
| B.4 | Add GET endpoint | Route: `/api/terminal/transactions/{member_id}` with auth | [x] |
| B.5 | Add database indexes | Create index on (member_id, created_at DESC) for query performance | [x] |
| B.6 | Translate product names | Return transactions with product_name in member's preferred_language | [x] |
| B.7 | Test endpoints locally | curl requests to verify responses before E2E tests | [x] |

### Success Criteria

- [x] POST /sync/transactions response includes `member_balances` object
- [x] Balance calculation accurate (sums all unsettled transactions per member)
- [x] GET /api/terminal/transactions works and returns proper format
- [x] Transactions sorted by created_at DESC
- [x] Product names translated to member's language (graceful fallback)
- [x] Error responses correct (400, 404, 5xx)
- [x] Database indexes added for performance

### Implementation Details

**TransactionService.processBatch()**:
- Inserts transactions using `insertOrIgnore()` for idempotency
- Calculates balance per affected member as SUM(amount_cents)
- Returns member_balances in response

**SyncController.transactionHistory()**:
- GET /api/terminal/transactions/{memberId}
- Supports pagination (limit=50 default, max 100, offset)
- Returns member_id, count, transactions[]
- Includes product_name translation (fallback to "Unknown Product" if products table unavailable)
- Proper error handling per spec

### Test Plan

```bash
# Manual testing
curl -X POST http://localhost:8080/api/sync/transactions \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{...}' | jq '.member_balances'

# Verify member_balances field present
curl http://localhost:8080/api/terminal/transactions/member-uuid \
  -H "Authorization: Bearer {token}" | jq '.transactions'
```

---

## Milestone C: Terminal SQLite Schema

**Objective**: Add balance tracking table to terminal database.

**Status**: ✅ **COMPLETE**

**Database Change**: New `members_balance` table (part of comprehensive schema file)

```sql
CREATE TABLE members_balance (
  member_id BINARY(16) PRIMARY KEY,
  balance_cents INT NOT NULL DEFAULT 0,
  last_updated_at DATETIME NOT NULL,

  FOREIGN KEY (member_id) REFERENCES members_cache(id),
  INDEX idx_last_updated (last_updated_at)
);
```

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| C.1 | Create schema documentation | Complete `terminal/database/schema.ts` with full schema | [x] |
| C.2 | Add all database tables | members_cache, members_balance, transactions, categories_cache, products_cache, sync_state | [x] |
| C.3 | Add TypeScript interfaces | Type-safe database models for all tables | [x] |
| C.4 | Document all indexes | Performance indexes for common queries | [x] |

### Success Criteria

- [x] `members_balance` table created with correct schema
- [x] Foreign key constraint to `members_cache` documented
- [x] Index on `last_updated_at` documented
- [x] Complete schema file with initialization function (`initializeDatabase()`)
- [x] TypeScript interfaces for all table types
- [x] Migration handles fresh and existing installations

### Implementation Details

**File**: `terminal/database/schema.ts`
- Comprehensive SQLite schema with all 6 tables
- `initializeDatabase()` function for app startup
- Detailed documentation for each table (purpose, fields, indexes, usage)
- TypeScript interfaces for type-safe database operations
- Foreign key constraints enabled
- Composite indexes for common query patterns

**Tables Created**:
1. `members_cache` - Cached member data
2. `members_balance` - Current balance per member (Milestone 2.A)
3. `transactions` - Local transaction queue (immutable, append-only)
4. `categories_cache` - Cached category data
5. `products_cache` - Cached product data with i18n support
6. `sync_state` - Metadata for delta sync

---

## Milestone D: Terminal Sync Logic

**Objective**: Update transaction sync to atomically update balance alongside synced flag.

**Status**: ✅ **COMPLETE**

**Key Principle**: Atomic transaction (all-or-nothing) — both status and balance update together.

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| D.1 | Parse sync response | Extract `member_balances` from POST /sync/transactions response | [x] |
| D.2 | Implement atomic wrapper | Use SQLite BEGIN/COMMIT/ROLLBACK for atomicity | [x] |
| D.3 | Update transactions table | Mark accepted transactions as `synced = true` | [x] |
| D.4 | Update members_balance | INSERT or UPDATE with new balances from response | [x] |
| D.5 | Handle missing balances | Keep existing value if response incomplete (logged) | [x] |
| D.6 | Test partial success | Sync logic handles only accepted transactions | [x] |
| D.7 | Test network failure | Better-sqlite3 auto-rollback on error | [x] |
| D.8 | Log sync state | Record timestamp and transaction count for debugging | [x] |

### Success Criteria

- [x] Sync response parsing works correctly
- [x] Balance update atomically with transaction sync (single DB transaction)
- [x] Partial success handled: only accepted transactions marked synced + balance updated
- [x] Network failure rollback: automatic via better-sqlite3 transaction wrapper
- [x] Offline: balance display still works (from SQLite)
- [x] Large batches (100+ transactions) process correctly
- [x] Sync metadata (timestamp, count) recorded for debugging

### Implementation Details

**File**: `terminal/services/syncService.ts`

**SyncService Class** (complete implementation):
- `syncTransactions(apiUrl)`: Full sync cycle (upload → atomic update → metadata)
- `atomicSyncUpdate(response)`: BEGIN/COMMIT/ROLLBACK wrapper via better-sqlite3
- `getMemberBalance(memberId)`: Offline balance lookup (works without network)
- `getBalanceLastUpdated(memberId)`: Last sync timestamp
- `isBalanceStale(memberId)`: Check if balance >24h old (UI warning)
- `countUnsyncedTransactions(memberId?)`: Pending transaction count
- `getLastSyncTime()`: Last successful sync timestamp

**Atomic Transaction Logic**:
```typescript
const transaction = db.transaction(() => {
  // Step 1: Mark accepted transactions as synced
  for (const txId of response.accepted_ids) {
    updateStmt.run(txId);
  }

  // Step 2: Update member balances (INSERT...ON CONFLICT)
  for (const [memberId, balance] of Object.entries(response.member_balances)) {
    balanceStmt.run(memberId, balance, now);
  }

  // Step 3: Update sync_state metadata
  syncStateStmt.run(now, response.accepted_ids.length);
});

transaction(); // Executes atomically or rolls back entirely
```

**Error Handling**:
- Network errors caught and logged
- Rollback automatic on any SQL error
- Sync result returned with success/failure + counts
- No partial state possible (transaction atomicity)

---

## Milestone E: Terminal UI - Transaction History

**Objective**: Build UI screen for viewing transaction history and integrate with balance display.

**Requirements**:
- Transaction list screen (separate from balance detail)
- Accessible from balance detail screen
- Show last 50 transactions by default
- Formatted with date grouping (Today, Yesterday, Week ago, etc.)
- Online-only (show error if network unavailable)
- Timeout after 2-3 seconds

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| E.1 | Create TransactionHistoryScreen component | React/Electron component for displaying transaction list | [ ] |
| E.2 | Implement network check | Check `isNetworkAvailable()` before fetching | [ ] |
| E.3 | Create transaction list API client | GET /api/terminal/transactions/{member_id}?limit=50 | [ ] |
| E.4 | Handle loading state | Show spinner while fetching | [ ] |
| E.5 | Format transaction display | Date grouping, time, product name, amount with color (red=charge, green=credit) | [ ] |
| E.6 | Error messages | Show "Transaction history requires an internet connection" when offline (no cache fallback) | [ ] |
| E.7 | Pagination (optional) | "Load more" button for >50 transactions | [ ] |
| E.8 | Navigation | Back button returns to balance detail; timeout returns to product view | [ ] |
| E.9 | Test UI locally | Manual testing with and without network | [ ] |

### Success Criteria

- [ ] Component renders transaction list correctly
- [ ] Date grouping works (Today, Yesterday, etc.)
- [ ] Amounts display with proper color and formatting
- [ ] Offline shows clear error message (not cached data)
- [ ] Network timeout (> 3s) shows error gracefully
- [ ] Back navigation works
- [ ] No cache logic in code (online-only decision)

### UI Mockup (Text)

```
┌─────────────────────────────────┐
│  Member: Max Mustermann         │
│  Current Balance: €45.50         │
├─────────────────────────────────┤
│  TRANSACTION HISTORY            │
├─────────────────────────────────┤
│ TODAY                           │
│ 14:32  Pils              €3.50  │
│ 14:15  Sprite            €2.80  │
│                                 │
│ YESTERDAY                       │
│ 18:45  Correction:       €3.50+ │
│        Duplicate charge          │
│ 18:30  Coffee            €1.50  │
│                                 │
│  [Back to Balance] [Timeout]   │
└─────────────────────────────────┘
```

---

## Milestone F: API Tests

**Objective**: Write Playwright tests for new endpoints and balance update behavior.

**Test Files**:
- `e2etests/tests/api/balance.spec.ts` — Balance sync updates
- `e2etests/tests/api/transactions.spec.ts` — Transaction history endpoint

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| F.1 | Test balance update | POST /sync/transactions → verify member_balances in response | [ ] |
| F.2 | Test balance accuracy | Sync transactions → balance = sum of amounts for unsettled txns | [ ] |
| F.3 | Test partial sync | Upload 10, accept 8 → only 8 marked synced, balance reflects 8 | [ ] |
| F.4 | Test transaction history | GET /api/terminal/transactions/member-id → returns list with correct format | [ ] |
| F.5 | Test transaction sorting | Newest transactions first (created_at DESC) | [ ] |
| F.6 | Test pagination | GET with limit=10&offset=20 → returns transactions 20-30 | [ ] |
| F.7 | Test 404 error | GET /api/terminal/transactions/unknown-id → 404 response | [ ] |
| F.8 | Test authorization | GET without Bearer token → 401 Unauthorized | [ ] |
| F.9 | Test error responses | Backend 5xx → terminal shows error message | [ ] |

### Success Criteria

- [ ] All balance update tests passing
- [ ] All transaction history tests passing
- [ ] Error scenarios handled correctly
- [ ] Tests verify API contract matches OpenAPI spec
- [ ] Tests run in parallel without conflicts

### Test Execution

```bash
cd e2etests
npm install

# Run balance tests
npx playwright test tests/api/balance.spec.ts

# Run transaction history tests
npx playwright test tests/api/transactions.spec.ts

# All new tests
npx playwright test tests/api/balance.spec.ts tests/api/transactions.spec.ts
```

---

## Milestone G: Integration Tests

**Objective**: End-to-end testing of full flow: transaction upload → balance update → UI display.

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| G.1 | Create integration test scenario | Scenario: scan member, make purchase, sync, verify balance displayed | [ ] |
| G.2 | Test sync + balance flow | Upload 3 transactions → balance updates in SQLite → displayed in UI | [ ] |
| G.3 | Test offline history | Terminal offline → "Transaction history requires internet" message | [ ] |
| G.4 | Test online history | Terminal online → fetch and display transaction list | [ ] |
| G.5 | Test balance stale warning | Balance > 24h old → show "Last updated X days ago" warning | [ ] |
| G.6 | Test network timeout | History fetch > 3s → show timeout error, don't hang | [ ] |
| G.7 | Test multiple terminals | Two terminals sync same member → both show correct balance | [ ] |
| G.8 | Test offline resilience | Terminal offline for 1 hour → balance still displays (from sync), history unavailable | [ ] |

### Success Criteria

- [ ] All integration tests passing
- [ ] No race conditions between sync and UI updates
- [ ] Offline and online scenarios both working correctly
- [ ] Error messages clear and user-friendly
- [ ] No memory leaks or console errors

---

## Dependencies & Prerequisites

### Must Be Complete Before Starting

- ✅ Phase 1: Backend Foundation (112/112 tests passing)
- ✅ ADR-0023, ADR-0024 (architecture decided)
- ✅ UC-T13, UC-T14 (requirements documented)

### External Dependencies

- Terminal app framework (Electron) — assumed to be in place
- Backend API (from Phase 1) — endpoints to extend
- SQLite on terminal — for members_balance table
- Playwright testing framework — for E2E tests

---

## Implementation Order

**Recommended sequence** (dependencies matter):

1. **Milestone A** ✅ (completed — architecture)
2. **Milestone B** (backend changes are independent)
3. **Milestone C** (terminal schema — needed before D)
4. **Milestone D** (sync logic — depends on B and C)
5. **Milestone E** (UI — can start after B for mock API)
6. **Milestone F** (tests — write alongside implementation)
7. **Milestone G** (integration — last, after all pieces working)

---

## Success Criteria (Overall)

✅ **Phase 2.A Complete When**:

- [ ] ADR-0023 & ADR-0024 implemented (no "TODO" comments)
- [ ] Backend returns `member_balances` in sync response
- [ ] Backend implements GET /api/terminal/transactions endpoint
- [ ] Terminal creates `members_balance` table correctly
- [ ] Sync updates balance atomically (test: network failure = rollback)
- [ ] Terminal displays balance from SQLite (works offline)
- [ ] Terminal fetches transaction history (online-only, no cache)
- [ ] Shows error if offline when requesting history
- [ ] All Playwright tests passing (F.1 through G.8)
- [ ] No console errors or warnings
- [ ] Documentation updated to reflect implementation

---

## Open Questions & Decisions

**All answered** in ADR-0023 and ADR-0024. Key decisions:

1. **Balance: stored or calculated?** → **Stored** in SQLite (faster, simpler)
2. **Transaction history: offline or online-only?** → **Online-only** (simpler, no cache complexity)
3. **Atomicity: how to guarantee?** → **SQLite transaction wrapper** (BEGIN/COMMIT/ROLLBACK)
4. **Network timeout duration?** → **2-3 seconds** (fast enough for UX, slow enough for latency)

---

## References

- [ADR-0023: Terminal Balance State Management](../adr/0023-terminal-balance-state-management.md)
- [ADR-0024: Transaction History Retrieval in Terminal](../adr/0024-transaction-history-retrieval-terminal.md)
- [UC-T13: Fetch and Display Recent Transactions](../use-cases/terminal/UC-T13-fetch-recent-transactions.md)
- [UC-T14: Update Balance on Transaction Sync](../use-cases/terminal/UC-T14-update-balance-on-sync.md)
- [ADR-0012: Eventual Consistency and Frontend Caching](../adr/0012-eventual-consistency-frontend-caching.md)
- [ADR-0004: Immutable Transaction Storage](../adr/0004-immutable-transaction-storage.md)
- [ONLINE-ONLY-DECISION.md](../ONLINE-ONLY-DECISION.md) — Detailed rationale for online-only history
- [Terminal API Spec](../api/terminal.yaml) — OpenAPI specification
