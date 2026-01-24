# Test Verification Framework

**Status**: Ready for execution (awaiting Docker)
**Purpose**: Define test-to-milestone mapping for Phase 1 Backend Foundation
**Approach**: Green tests = Milestone completion verified

---

## Test Execution Strategy

Each milestone is verified by running specific Playwright tests. Tests passing = milestone verified. This is the source of truth for completion.

---

## Test-to-Milestone Mapping

### Milestone 1.5: Health Controller Refactoring
**Verified by**: `health.spec.ts` (3 tests)

| Test | File | Verifies | Pattern |
|------|------|----------|---------|
| GET /api/health returns ok status | health.spec.ts:12 | Response structure, HTTP 200 | 006 (Thin controller) |
| GET /api/health returns valid ISO 8601 timestamp | health.spec.ts:23 | DTO.toArray() formatting | 003 (DTO) |
| GET /api/health returns JSON content type | health.spec.ts:37 | HTTP headers | 006 (Controller) |

**Success Criteria**: 3/3 tests passing

**What it proves**:
- ✓ HealthRequest validates input (Pattern 001)
- ✓ HealthCheckService.check() executes (Pattern 004)
- ✓ HealthResponseDto.toArray() formats correctly (Pattern 003)
- ✓ HealthController routes correctly (Pattern 006)
- ✓ AppServiceProvider injects HealthCheckService (Pattern 008)

---

### Milestone 2: SyncController Refactoring
**Verified by**: 5 test suites (20 tests total)

#### 2.1: Members Sync Endpoint
**File**: `sync-members.spec.ts`
**Tests**: 3 tests

| Test | Verifies |
|------|----------|
| GET /api/sync/members returns member delta response | SyncRequest validates, Response structure, SyncResultDto |
| GET /api/sync/members returns valid member objects | MemberDto fields and formatting |
| GET /api/sync/members supports delta sync with cursor | Pagination, cursor handling |

**Patterns Verified**: 001 (SyncRequest), 003 (SyncResultDto, MemberDto), 004 (SyncService), 006 (SyncController)

#### 2.2: Categories Sync Endpoint
**File**: `sync-categories.spec.ts`
**Tests**: 3 tests

| Test | Verifies |
|------|----------|
| GET /api/sync/categories returns category delta response | SyncRequest validates, Response structure |
| GET /api/sync/categories returns valid category objects | CategoryDto with i18n names |
| GET /api/sync/categories supports delta sync with cursor | Pagination via SyncResultDto |

**Patterns Verified**: 001, 003, 004, 006

#### 2.3: Products Sync Endpoint
**File**: `sync-products.spec.ts`
**Tests**: 3 tests

| Test | Verifies |
|------|----------|
| GET /api/sync/products returns product delta response | SyncRequest validates, Response structure |
| GET /api/sync/products returns valid product objects | ProductDto with i18n names/descriptions, price_cents |
| GET /api/sync/products supports delta sync with cursor | Pagination via SyncResultDto |

**Patterns Verified**: 001, 003, 004, 006

#### 2.4: Member Language Update Endpoint
**File**: `member-language.spec.ts`
**Tests**: 4 tests

| Test | Verifies |
|------|----------|
| PATCH /api/sync/members/{memberId}/language updates language successfully | UpdateLanguageRequest validates, SupportedLanguage enum enforced, MemberDto response |
| PATCH /api/sync/members/{memberId}/language accepts different languages | Enum supports de/en/fr |
| PATCH /api/sync/members/{memberId}/language rejects invalid language code | FormRequest validation rejects invalid enum value |
| PATCH /api/sync/members/{memberId}/language returns 404 for unknown member | Service handles missing member |

**Patterns Verified**: 001 (UpdateLanguageRequest), 002 (SupportedLanguage enum), 003 (MemberDto), 004 (SyncService.updateMemberLanguage), 006 (SyncController.updateLanguage)

#### 2.5: Transaction Upload Endpoint
**File**: `transactions.spec.ts`
**Tests**: 4 tests

| Test | Verifies |
|------|----------|
| POST /api/sync/transactions accepts single transaction | UploadTransactionsRequest validates, TransactionService processes, TransactionBatchResultDto response |
| POST /api/sync/transactions accepts batch up to 100 transactions | Batch validation (min:1, max:100) |
| POST /api/sync/transactions rejects invalid batch | FormRequest rejects negative amounts, missing fields |
| POST /api/sync/transactions returns idempotent results | Transaction IDs tracked in acceptedIds |

**Patterns Verified**: 001 (UploadTransactionsRequest), 003 (TransactionBatchResultDto), 004 (TransactionService), 006 (SyncController.transactions)

**Milestone 2 Success Criteria**: 17/17 tests passing

---

## Test Execution Commands

### Prerequisites
```bash
# Start Docker containers
docker compose up -d

# Wait for backend to be ready (health check)
sleep 5

# Verify backend is responding
curl http://localhost:8080/api/health
```

### Install Test Dependencies
```bash
cd e2etests
npm install
```

### Run All Tests
```bash
npx playwright test
```

### Run Specific Test Suite
```bash
# Health endpoint (Milestone 1.5)
npx playwright test tests/api/health.spec.ts

# Sync members (Milestone 2.1)
npx playwright test tests/api/sync-members.spec.ts

# Member language (Milestone 2.4)
npx playwright test tests/api/member-language.spec.ts

# Transactions (Milestone 2.5)
npx playwright test tests/api/transactions.spec.ts
```

### Run with Verbose Output
```bash
npx playwright test --reporter=verbose
```

### Generate HTML Report
```bash
npx playwright test && npx playwright show-report
```

---

## Test Verification Checklist

### Milestone 1.5: Health Controller
- [ ] `health.spec.ts` passes (3/3 tests)
- [ ] Tests verify HealthRequest validation
- [ ] Tests verify HealthCheckService execution
- [ ] Tests verify HealthResponseDto formatting
- [ ] Tests verify HealthController routing

**Status**: ⏳ Awaiting Docker

### Milestone 2: SyncController
- [ ] `sync-members.spec.ts` passes (3/3 tests)
- [ ] `sync-categories.spec.ts` passes (3/3 tests)
- [ ] `sync-products.spec.ts` passes (3/3 tests)
- [ ] `member-language.spec.ts` passes (4/4 tests)
- [ ] `transactions.spec.ts` passes (4/4 tests)
- [ ] Total: 17/17 tests passing

**Status**: ⏳ Awaiting Docker

### Milestone 3: Playwright Test Suite
- [ ] All 20 tests in health + sync suites passing
- [ ] All response formats match OpenAPI spec
- [ ] All pattern implementations verified
- [ ] Error handling tested (validation errors, not found)

**Status**: ⏳ Depends on milestones 1.5 & 2

### Milestone 4: End-to-End Verification
- [ ] Clean Docker build works
- [ ] All tests pass from clean state
- [ ] No unresolved failures
- [ ] Full stack integration verified

**Status**: ⏳ Depends on milestone 3

---

## Pattern Verification via Tests

Each test verifies one or more patterns:

| Pattern | Verified By |
|---------|------------|
| **001: Form Requests** | All endpoints validate input correctly |
| **002: Enums** | member-language.spec.ts verifies de/en/fr enforcement |
| **003: DTOs** | All tests verify response structure and formatting |
| **004: Services** | All tests verify business logic execution |
| **005: Repositories** | N/A (mock data in services) |
| **006: Thin Controllers** | All tests verify controller delegates to services |
| **007: Exception Handler** | Tests verify error responses (400, 404, 422) |
| **008: Service Provider** | Tests verify dependency injection works (services execute) |

---

## Test Results Template

When tests are executed, fill in results here:

### Milestone 1.5: Health Controller Tests

```
✅ or ❌ health.spec.ts
  - Test 1: GET /api/health returns ok status
    Status: [PASS/FAIL]
    Response: {...}
  - Test 2: GET /api/health returns valid ISO 8601 timestamp
    Status: [PASS/FAIL]
  - Test 3: GET /api/health returns JSON content type
    Status: [PASS/FAIL]

  Summary: X/3 tests passing
```

### Milestone 2: SyncController Tests

```
✅ or ❌ sync-members.spec.ts (3 tests)
✅ or ❌ sync-categories.spec.ts (3 tests)
✅ or ❌ sync-products.spec.ts (3 tests)
✅ or ❌ member-language.spec.ts (4 tests)
✅ or ❌ transactions.spec.ts (4 tests)

Total: X/17 tests passing
```

---

## Dependency Chain

```
Milestone 1.5 Health Tests (3 tests)
    ↓ (must pass)
Milestone 2 SyncController Tests (17 tests)
    ↓ (must pass)
Milestone 3 Playwright Full Suite (20 tests)
    ↓ (must pass)
Milestone 4 End-to-End Verification (full stack)
```

All milestones depend on previous tests passing.

---

## Next Steps When Docker Available

1. **Start Docker**
   ```bash
   docker compose up -d
   ```

2. **Verify Backend Health**
   ```bash
   curl http://localhost:8080/api/health
   ```

3. **Install Test Dependencies**
   ```bash
   cd e2etests && npm install
   ```

4. **Run Health Tests** (Milestone 1.5 verification)
   ```bash
   npx playwright test tests/api/health.spec.ts --reporter=verbose
   ```

5. **If health tests pass**: Update plan, commit

6. **Run Sync Tests** (Milestone 2 verification)
   ```bash
   npx playwright test tests/api/sync-*.spec.ts tests/api/member-language.spec.ts tests/api/transactions.spec.ts --reporter=verbose
   ```

7. **If sync tests pass**: Update plan, commit

8. **Run Full Suite** (Milestone 3 verification)
   ```bash
   npx playwright test
   ```

---

## Integration with Plan

Tests drive the plan:
- Tests PASS → Milestone marked [x] COMPLETE
- Tests FAIL → Milestone marked [!] FAILED with error details
- No Docker → Milestone marked ⏳ BLOCKED (awaiting environment)

This ensures plan status always reflects actual verified state.

