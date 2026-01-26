# Module 9: Dashboard - Implementation Plan

## Overview

**Module**: Dashboard (Admin Overview & Metrics)

**Objective**: Implement a read-only dashboard endpoint that aggregates key metrics and provides system overview for administrators.

**Current Status**:
- ✅ All 8 preceding modules complete (members, products, transactions, settlements, terminals, admin-users, audit-log, sepa-config)
- ❌ Dashboard module not yet started

**Scope**:
- Single GET endpoint: GET /api/admin/dashboard
- Aggregates metrics from existing data
- Read-only operation (no mutations)
- ~15 E2E tests covering all metrics and scenarios
- ~5-7 days implementation

---

## Architecture & Data Model

### Dashboard Data Structure

The dashboard endpoint returns a single aggregated response with multiple metric categories:

```json
{
  "metrics": {
    "active_members": 42,
    "inactive_members": 3,
    "outstanding_balance_cents": 12500,
    "todays_revenue_cents": 45000,
    "terminal_count": 5,
    "active_terminals": 4,
    "settled_members": 35,
    "sepa_issue_count": 2
  },
  "recent_transactions": [
    {
      "id": "txn-uuid",
      "member_id": "member-uuid",
      "member_name": "John Doe",
      "type": "purchase",
      "amount_cents": 1500,
      "product_name": "Beer",
      "timestamp": "2026-01-26T18:30:00Z"
    }
  ],
  "terminal_status": [
    {
      "id": "terminal-uuid",
      "name": "Bar Counter Terminal 1",
      "is_active": true,
      "last_sync_at": "2026-01-26T18:25:00Z",
      "status": "online"
    }
  ],
  "system_status": {
    "last_settlement_date": "2026-01-20T00:00:00Z",
    "pending_settlement_count": 5,
    "total_members": 45,
    "total_transactions": 1234,
    "database_health": "ok"
  },
  "alerts": {
    "sepa_issues": {
      "count": 2,
      "severity": "warning",
      "message": "2 members have SEPA data issues"
    }
  }
}
```

### Metric Calculations

| Metric | Source | Logic | Update Frequency |
|--------|--------|-------|-------------------|
| active_members | members table | Count where is_active=true | Real-time |
| inactive_members | members table | Count where is_active=false | Real-time |
| outstanding_balance_cents | members table | Sum balance field for active members | Real-time |
| todays_revenue_cents | transactions table | Sum amount for transactions created today | Real-time |
| terminal_count | terminals table | Count all terminals | Real-time |
| active_terminals | terminals table | Count where is_active=true | Real-time |
| settled_members | settlements table | Count distinct members in settled transactions | Real-time |
| sepa_issue_count | members table | Count where iban is null OR mandate_reference is null | Real-time |
| recent_transactions | transactions table | Last 10 transactions ordered by created_at DESC | Real-time |
| last_settlement_date | settlements table | Max created_at from settlements | Real-time |
| pending_settlement_count | settlements table | Count where status='pending' | Real-time |

---

## Implementation Phases

### Phase 1: Service Layer (2-3 hours)

**Tasks**:

1. Create DashboardService
   - Extends BaseService (or standalone, since read-only)
   - Dependencies: None (only uses repositories)
   - Methods:
     - `getDashboardMetrics(): DashboardDto` - Main aggregation method
     - Private helper methods for each metric category

2. Query Optimization
   - Use efficient COUNT queries (avoid N+1)
   - Use SUM aggregations (database-side)
   - Filter by date ranges where applicable
   - Consider future caching strategy (mention but don't implement)

3. Error Handling
   - Handle database connection failures gracefully
   - Return partial data if certain queries fail
   - Log query errors to audit trail

**Success Criteria**:
- [x] DashboardService methods return correct data
- [x] All queries optimized (no N+1 issues)
- [x] Error handling for missing/corrupt data
- [x] Response structure matches spec

**Files**:
- `backend/app/Http/Modules/Dashboard/Services/DashboardService.php`

---

### Phase 2: Data Transfer Objects (1-2 hours)

**Tasks**:

1. Create DTOs
   - **DashboardDto**: Main response wrapper
   - **MetricsDto**: Metrics card data
   - **RecentTransactionDto**: Transaction entry for recent list
   - **TerminalStatusDto**: Terminal connectivity status
   - **SystemStatusDto**: System-wide status indicators
   - **AlertsDto**: Alert badges and messages

2. Serialization
   - All DTOs implement toArray() method
   - Proper datetime formatting (ISO 8601)
   - Currency formatting (amounts in cents, display as €)

**Success Criteria**:
- [x] All DTOs readonly with proper fields
- [x] toArray() methods implement proper formatting
- [x] No sensitive data exposed
- [x] Consistent structure matching spec

**Files**:
- `backend/app/Http/Modules/Dashboard/DTOs/DashboardDto.php`
- `backend/app/Http/Modules/Dashboard/DTOs/MetricsDto.php`
- `backend/app/Http/Modules/Dashboard/DTOs/RecentTransactionDto.php`
- `backend/app/Http/Modules/Dashboard/DTOs/TerminalStatusDto.php`
- `backend/app/Http/Modules/Dashboard/DTOs/SystemStatusDto.php`
- `backend/app/Http/Modules/Dashboard/DTOs/AlertsDto.php`

---

### Phase 3: Controller & Routes (1-2 hours)

**Tasks**:

1. Create DashboardController
   - Single method: `show()` or `index()`
   - GET /api/admin/dashboard
   - No request validation (no parameters)
   - Returns DashboardDto as JSON

2. Register Routes
   - Location: `app/Http/Modules/Dashboard/routes/admin.php`
   - Middleware: EncryptCookies, StartSession, AuthenticateAdminSession
   - Route aggregator: `routes/modules/dashboard.php`

3. Update Route Registrations
   - Add require statement to `routes/api.php`

**Success Criteria**:
- [x] Endpoint accessible at /api/admin/dashboard
- [x] Requires admin authentication
- [x] Returns correct response format
- [x] Proper error handling (500 if query fails)

**Files**:
- `backend/app/Http/Modules/Dashboard/Controllers/AdminController.php`
- `backend/app/Http/Modules/Dashboard/routes/admin.php`
- `backend/routes/modules/dashboard.php`
- Updated `backend/routes/api.php`

---

### Phase 4: E2E Testing (3-4 hours)

**Create**: `e2etests/tests/api/dashboard.spec.ts`

**Test Coverage** (15 tests):

**A. Metrics Accuracy (5 tests)**:
- Metrics structure: all fields present (active_members, balance, revenue, terminals, etc.)
- Active members count: matches database count of active members
- Outstanding balance: matches sum of member balances
- Today's revenue: correctly filters transactions by date
- Terminal status: counts active vs total terminals

**B. Recent Transactions (2 tests)**:
- Recent transactions: returns last 10 transactions
- Transaction fields: all required fields present (member, product, amount, timestamp)

**C. Terminal Status (2 tests)**:
- Terminal list: shows all terminals with status
- Last sync: reflects actual last_sync_at from database

**D. Alerts (3 tests)**:
- SEPA issues count: correct number of members with missing SEPA data
- SEPA alert severity: "warning" for 1-5, "error" for 6+
- No alert: no SEPA issues when all members have complete data

**E. Authentication & Performance (2 tests)**:
- Requires authentication: 401 without session
- Response time: completes within 500ms (not enforced, just monitored)

**Test Patterns**:
- Follow E2E Testing Patterns (001-004)
- Use `authenticatedRequest` fixture
- Database-agnostic assertions (check counts, not specific records)
- Parallel execution safe

**Success Criteria**:
- [x] 15/15 tests passing with 1 worker
- [x] 15/15 tests passing with 4 workers
- [x] No flaky tests
- [x] Both success and edge cases covered

**Files**:
- `e2etests/tests/api/dashboard.spec.ts`

---

### Phase 5: Integration & Documentation (2-3 hours)

**Tasks**:

1. Update OpenAPI Spec
   - Add GET /api/admin/dashboard endpoint
   - Document all response fields and data types
   - Include example response with sample data

2. Update ERM Documentation
   - No new tables needed (read-only aggregation)
   - Add diagram showing data sources (members, transactions, terminals, settlements tables)

3. Create Use Case Documentation
   - Verify UC-A80: Dashboard use case is satisfied
   - Document which use case requirements are met by endpoint

4. Update Planning Documents
   - Mark Module 9 as complete in `plans/INDEX.md`
   - Update backend-core-modules.md status (9/9 complete!)
   - Update progress summary

5. Add Service Provider Binding
   - Register DashboardService in AppServiceProvider

**Success Criteria**:
- [x] OpenAPI spec includes dashboard endpoint
- [x] ERM documentation shows data flow
- [x] Use cases documented
- [x] Planning documents updated
- [x] Service binding registered

**Files**:
- `api/admin.yaml`
- `docs/erm-master.md`
- `use-cases/admin/UC-A80-dashboard.md` (verify completion)
- `plans/INDEX.md` (update module status)
- `plans/backend-core-modules.md` (update completion status)
- `backend/app/Providers/AppServiceProvider.php` (add binding)

---

## Success Metrics

- [x] GET /api/admin/dashboard endpoint functional
- [x] All metrics calculated correctly
- [x] 15/15 E2E tests passing with 1 worker
- [x] 15/15 E2E tests passing with 4 workers
- [x] No flaky tests
- [x] Response time < 500ms (typical)
- [x] Proper error handling for database failures
- [x] All 8 core modules now complete (9/9 total)
- [x] Audit logging not required (read-only endpoint)
- [x] OpenAPI spec updated

---

## Critical Design Notes

### Read-Only Nature
- Dashboard is purely read-only (GET endpoint only)
- No mutations, no audit logging needed
- Can be cached safely (with cache invalidation strategy)
- No form requests or validation needed

### Query Performance
- Use database aggregations (COUNT, SUM) instead of application-level calculation
- Filter at database level (WHERE clauses) before aggregation
- Consider query optimization for scale:
  - Millions of transactions: May need indexed columns
  - Recent transactions: Use date range filter, pagination if needed
  - Historical metrics: Could cache 5-minute snapshots

### Data Accuracy
- All metrics are "point-in-time" snapshots (not real-time streams)
- Eventual consistency acceptable (admin refreshes page for latest data)
- Database connection failure gracefully degrades (returns 500 with partial data if possible)

### Error Handling
- If one metric fails, others still return (partial response)
- Log all query errors for investigation
- Return 500 if majority of metrics fail

---

## Testing Strategy

### Manual Testing
```bash
# After implementation
docker compose exec backend supervisorctl restart php-fpm:php-fpm
sleep 2

# Run tests with 1 worker (serial)
cd e2etests && npm test -- tests/api/dashboard.spec.ts --workers=1

# Run tests with 4 workers (parallel)
npm test -- tests/api/dashboard.spec.ts --workers=4

# Check response structure manually
curl -b /tmp/cookies.txt http://localhost:8080/api/admin/dashboard | jq .
```

### Test Data Setup
- Use existing seeded data (admin, members, products, transactions)
- Create test transactions with specific dates/amounts
- Create members with and without SEPA data
- Verify metrics against known values

---

## Risks & Mitigation

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Query timeout on large datasets | Medium | Indexed database columns; connection timeout handling |
| Incorrect metric calculations | High | Comprehensive test coverage; query verification |
| Performance degrades with scale | Medium | Query optimization; future caching strategy |
| Missing error handling | Medium | Proper exception handling; graceful degradation |

---

## Dependencies

**External**: None (uses existing modules)

**Internal**:
- Members module (for member counts, balance sums)
- Transactions module (for transaction history)
- Terminals module (for terminal status)
- Settlements module (for settlement metadata)
- Admin-Users module (authentication only)

**Blocking**:
- All 8 preceding modules must be complete (✅ they are)

---

## Next Steps After Completion

1. Run full E2E test suite to ensure no regressions: `npm test -- --workers=4`
2. Verify all 9 core modules complete
3. Update project status: **Backend Core Complete ✅**
4. Begin Phase 2.A: Terminal Balance & Transaction History UI (or Phase 2.B: Terminal Core Features)

---

## Completion Checklist

**Phase 1: Service Layer**
- [ ] DashboardService created with all metrics methods
- [ ] Queries optimized (no N+1)
- [ ] Error handling for failed queries

**Phase 2: DTOs**
- [ ] All DTOs created (Dashboard, Metrics, RecentTransaction, etc.)
- [ ] toArray() methods implemented
- [ ] Proper datetime/currency formatting

**Phase 3: Controller & Routes**
- [ ] AdminController with show() method
- [ ] Routes registered with proper middleware
- [ ] Service provider binding added

**Phase 4: E2E Testing**
- [ ] 15 tests written
- [ ] All tests passing with 1 worker
- [ ] All tests passing with 4 workers
- [ ] No flaky tests

**Phase 5: Integration & Documentation**
- [ ] OpenAPI spec updated
- [ ] ERM documentation updated
- [ ] Use cases verified
- [ ] Planning documents updated

**Final Verification**
- [ ] Full test suite runs: `npm test -- --workers=4`
- [ ] All 9 core modules marked complete
- [ ] Backend ready for frontend development

---

## Implementation Checklist

- [ ] Phase 1: Service Layer (2-3 hours)
- [ ] Phase 2: DTOs (1-2 hours)
- [ ] Phase 3: Controller & Routes (1-2 hours)
- [ ] Phase 4: E2E Testing (3-4 hours)
- [ ] Phase 5: Integration & Documentation (2-3 hours)

**Total Estimated Time**: 9-14 hours (1-2 days)

---

**Status**: Ready for implementation — This is the final core module!
