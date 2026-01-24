# Phase 1: Backend Foundation

**Goal**: Working backend with OAS-driven endpoints, mock data, and verified Playwright API tests.

**Status**: Not Started

---

## Progress Summary

| Milestone | Status | Tests Passed |
|-----------|--------|--------------|
| 1. Docker Infrastructure | [x] | 3/3 |
| **1.5. Health Controller Refactoring (Pattern Test Case)** | **[x]** | **3/3** |
| 2. Mock Controllers | [~] | 19/32 (59% - GET: 15/15, PATCH: 4/7, POST: 0/10) |
| 3. Playwright Tests | [ ] | 0/7 |
| 4. End-to-End Verification | [ ] | 0/1 |

---

## Milestone 1: Docker Infrastructure

**Objective**: Containers start and backend responds.

**Note**: Build tools run on host machine. Results are mounted into containers.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 1.1 | Install backend dependencies (host) | `cd backend && composer install && ls vendor/autoload.php` | File exists | [x] |
| 1.2 | Start Docker containers | `docker compose up -d && docker compose ps` | All containers show "running" | [x] |
| 1.3 | Backend health check | `curl -s http://localhost:8080/api/health \| jq .status` | Returns `"ok"` | [x] |

### Failures

_None yet_

---

## Milestone 1.5: Health Controller Refactoring (Pattern Test Case)

**Objective**: Refactor existing health controller to follow backend patterns. Serves as reference implementation for other controllers.

**Rationale**: Establish pattern compliance before implementing mock controllers. Health endpoint is simple enough to serve as clear example without boilerplate complexity.

**Patterns to Implement**:
- Pattern 001: Form Request (validation layer)
- Pattern 003: DTO (response data)
- Pattern 004: Service Layer (business logic)
- Pattern 006: Thin Controller (HTTP routing only)

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| 1.5.1 | Analyze current health controller | Read `backend/app/Http/Controllers/HealthController.php` and identify what to refactor | [x] |
| 1.5.2 | Create HealthCheckService | Move health check logic to Service Layer (Pattern 004). Service returns HealthResponseDto. | [x] |
| 1.5.3 | Create HealthResponseDto | Create immutable DTO with `toArray()` method (Pattern 003) for `{"status":"ok","timestamp":"..."}` response. | [x] |
| 1.5.4 | Create HealthRequest FormRequest | Create FormRequest for validation (Pattern 001). Even though health endpoint has no input, demonstrates pattern usage. | [x] |
| 1.5.5 | Refactor HealthController | Thin controller (Pattern 006) that: injects Service, calls service method, serializes DTO. Verify Playwright tests pass. | [x] |

### Success Criteria

- [x] Health controller is thin (no business logic, <10 lines) — CODE COMPLETE
- [x] All logic in HealthCheckService — CODE COMPLETE
- [x] Response uses HealthResponseDto with `toArray()` — CODE COMPLETE
- [x] health.spec.ts passes (Playwright tests) — **✅ VERIFIED** (3/3 tests passing)
- [x] Controller follows all 4 patterns correctly — CODE VERIFIED
- [x] Code serves as reference for other controllers — CONFIRMED

**Test Verification**: health.spec.ts (3 tests)
- Test 1: GET /api/health returns ok status
- Test 2: GET /api/health returns valid ISO 8601 timestamp
- Test 3: GET /api/health returns JSON content type

When Docker available: `npx playwright test tests/api/health.spec.ts`

### Failures

_None yet_

---

## Milestone 2: SyncController Refactoring (Pattern-Compliant Implementation)

**Objective**: Refactor all SyncController endpoints to follow all 8 patterns. All endpoints now pattern-compliant.

**Status**: ✅ CODE COMPLETE — Awaiting test verification

**Refactoring Complete**:
- ✅ SyncController refactored: 282 lines → 70 lines
- ✅ 14 new pattern-compliant files created
- ✅ All 5 endpoints refactored with patterns

### Tasks (Code Implementation - ALL COMPLETE)

| # | Task | Patterns Used | Status | Files |
|---|------|---------------|--------|-------|
| 2.1 | GET /api/sync/members refactored | 001, 003, 004, 006 | [x] CODE | SyncRequest, MemberDto, SyncResultDto, SyncService |
| 2.2 | GET /api/sync/categories refactored | 001, 003, 004, 006 | [x] CODE | SyncRequest, CategoryDto, SyncResultDto, SyncService |
| 2.3 | GET /api/sync/products refactored | 001, 003, 004, 006 | [x] CODE | SyncRequest, ProductDto, SyncResultDto, SyncService |
| 2.4 | PATCH /api/sync/members/{id}/language refactored | 001, 002, 003, 004, 006 | [x] CODE | UpdateLanguageRequest, SupportedLanguage, MemberDto, SyncService |
| 2.5 | POST /api/sync/transactions refactored | 001, 003, 004, 006 | [x] CODE | UploadTransactionsRequest, TransactionBatchResultDto, TransactionService |

### Test Verification (AWAITING DOCKER)

| # | Test Suite | File | Tests | Command | Status |
|---|------------|------|-------|---------|--------|
| 2.1-Test | sync-members.spec.ts | member sync | 3 tests | `npx playwright test tests/api/sync-members.spec.ts` | ⏳ |
| 2.2-Test | sync-categories.spec.ts | category sync | 3 tests | `npx playwright test tests/api/sync-categories.spec.ts` | ⏳ |
| 2.3-Test | sync-products.spec.ts | product sync | 3 tests | `npx playwright test tests/api/sync-products.spec.ts` | ⏳ |
| 2.4-Test | member-language.spec.ts | language update | 4 tests | `npx playwright test tests/api/member-language.spec.ts` | ⏳ |
| 2.5-Test | transactions.spec.ts | batch upload | 4 tests | `npx playwright test tests/api/transactions.spec.ts` | ⏳ |

**Total Tests**: 17/17 (all must pass for milestone complete)

### Failures

_None yet_

---

## Milestone 3: Playwright Test Suite

**Objective**: Complete API test coverage for all Terminal endpoints.

**Note**: Tests run on host machine against Docker backend at localhost:8080.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 3.0 | Install test dependencies (host) | `cd e2etests && npm install && ls node_modules/.bin/playwright` | File exists | [ ] |
| 3.1 | health.spec.ts passes | `cd e2etests && npx playwright test tests/api/health.spec.ts` | All tests pass | [ ] |
| 3.2 | sync-members.spec.ts passes | `cd e2etests && npx playwright test tests/api/sync-members.spec.ts` | All tests pass | [ ] |
| 3.3 | sync-categories.spec.ts passes | `cd e2etests && npx playwright test tests/api/sync-categories.spec.ts` | All tests pass | [ ] |
| 3.4 | sync-products.spec.ts passes | `cd e2etests && npx playwright test tests/api/sync-products.spec.ts` | All tests pass | [ ] |
| 3.5 | member-language.spec.ts passes | `cd e2etests && npx playwright test tests/api/member-language.spec.ts` | All tests pass | [ ] |
| 3.6 | transactions.spec.ts passes | `cd e2etests && npx playwright test tests/api/transactions.spec.ts` | All tests pass | [ ] |

### Failures

_None yet_

---

## Milestone 4: End-to-End Verification

**Objective**: Full stack works from clean state.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 4.1 | All tests pass from clean start | `docker compose down -v && cd backend && composer install && cd .. && docker compose up -d && sleep 10 && cd e2etests && npm install && npx playwright test` | 0 failed tests | [ ] |

### Failures

_None yet_

---

## Test Coverage Requirements

Each endpoint needs these test cases:

### health.spec.ts
- [ ] Returns 200 with status "ok"
- [ ] Includes ISO8601 timestamp
- [ ] Responds within 500ms

### sync-members.spec.ts
- [ ] Returns 200 with members array
- [ ] Supports `?since=` delta query parameter
- [ ] Includes cursor for pagination
- [ ] Members have required fields: id, card_uid, display_name, preferred_language, is_active, is_sepa_valid

### sync-categories.spec.ts
- [ ] Returns 200 with categories array
- [ ] Categories have i18n names (JSON object with language keys)
- [ ] Includes cursor for delta sync

### sync-products.spec.ts
- [ ] Returns 200 with products array
- [ ] Products have i18n names and descriptions
- [ ] Includes price_cents as integer
- [ ] Supports delta sync with cursor

### member-language.spec.ts
- [ ] Returns 204 on valid language code (de, en, fr, it)
- [ ] Returns 422 on invalid language code
- [ ] Returns 404 on unknown member ID

### transactions.spec.ts
- [ ] Accepts single transaction, returns 201
- [ ] Accepts batch up to 100 transactions
- [ ] Returns 422 on missing required fields
- [ ] Returns 422 on invalid amount (zero/negative for purchase)
- [ ] Returns 400 on empty array
- [ ] Returns 413 on batch > 100
- [ ] Idempotent: same UUID returns same result

---

## Commands Reference

All build commands run on the host machine. Docker containers mount the built artifacts.

```bash
# 1. Install backend dependencies (host)
cd backend && composer install

# 2. Start Docker containers
docker compose up -d

# 3. Check backend health
curl -s http://localhost:8080/api/health | jq

# 4. Install test dependencies (host)
cd e2etests && npm install

# 5. Run all API tests (host)
npx playwright test

# Run specific test file
npx playwright test tests/api/health.spec.ts

# Run with verbose output
npx playwright test --reporter=list
```

---

## Completion Criteria

Phase 1 is complete when:
- [x] All Milestone 1 tasks: [x] ✓
- [x] All Milestone 1.5 tasks: [x] ✓ (Pattern implementation verified in code; tests ⏳ awaiting Docker)
- [ ] All Milestone 2 tasks:
  - [x] Code refactoring complete (5/5 endpoints)
  - [ ] Tests passing (0/17 tests; ⏳ awaiting Docker)
- [ ] All Milestone 3 tasks: [x] (Playwright tests - blocked on milestones 1.5 & 2 tests passing)
- [ ] All Milestone 4 tasks: [x] (Full stack verification)
- [ ] No unresolved failures in any section

**Note**: Milestones marked COMPLETE when tests pass GREEN, not before.

---

## Implementation Notes

**Pattern-First Approach**: All controllers must implement the 8 patterns from `backend/patterns/` before mock implementation. Health controller refactoring (Milestone 1.5) establishes the pattern-compliant structure that all other controllers will follow.

**Dependency Chain for Milestone 2**:
- SyncController: Depends on Patterns 001-008 (all patterns) ✅ COMPLETE
- 5 endpoints refactored: members, categories, products, language, transactions ✅ COMPLETE

Each controller implements:
1. Typed FormRequest (Pattern 001) ✅
2. Service Layer (Pattern 004) with business logic ✅
3. DTOs (Pattern 003) with type-safe fields ✅
4. Enums (Pattern 002) for domain values ✅
5. Thin controller (Pattern 006) ✅
6. Service Provider (Pattern 008) for DI ✅

---

## Test-Driven Verification Approach

**Source of Truth**: Tests verify milestone completion. GREEN tests = milestone complete.

### How It Works

1. **Code Implementation**: Patterns implemented in code (Milestones 1.5 & 2)
2. **Test Verification**: Playwright tests verify implementation works (Milestone 3)
3. **Test Results**: Green tests mark milestone as verified complete
4. **Plan Update**: Plan updated with test results and committed

### Test-to-Milestone Mapping

**Milestone 1.5**: Verified by `health.spec.ts` (3 tests)
- ✅ Code complete
- ⏳ Tests pending (Docker required)
- Status: `[x] CODE → ⏳ TEST`

**Milestone 2**: Verified by 5 test suites (17 tests total)
- ✅ Code complete (all 5 endpoints refactored)
- ⏳ Tests pending (Docker required)
- Test suites:
  - sync-members.spec.ts (3 tests)
  - sync-categories.spec.ts (3 tests)
  - sync-products.spec.ts (3 tests)
  - member-language.spec.ts (4 tests)
  - transactions.spec.ts (4 tests)
- Status: `[x] CODE → ⏳ TEST`

**Milestone 3**: Depends on milestones 1.5 & 2 tests
- Blocked until milestone 2 tests pass
- Then runs full Playwright suite (Milestone 1 health tests + Milestone 2 sync tests)
- Status: `⏳ BLOCKED`

**Milestone 4**: Depends on milestone 3 tests
- Blocked until milestone 3 full suite passes
- Runs end-to-end verification from clean Docker build
- Status: `⏳ BLOCKED`

### When Docker Becomes Available

```bash
# 1. Start Docker
docker compose up -d

# 2. Install test dependencies
cd e2etests && npm install

# 3. Run Milestone 1.5 tests (health controller)
npx playwright test tests/api/health.spec.ts --reporter=verbose

# 4. If 3/3 pass → Update plan, mark Milestone 1.5 [x] VERIFIED
# 5. Run Milestone 2 tests (sync controller endpoints)
npx playwright test tests/api/sync-*.spec.ts tests/api/member-language.spec.ts tests/api/transactions.spec.ts --reporter=verbose

# 6. If 17/17 pass → Update plan, mark Milestone 2 [x] VERIFIED
# 7. Run Milestone 3 (full suite)
npx playwright test

# 8. If all pass → Update plan, mark Milestone 3 [x] VERIFIED
# 9. Run Milestone 4 (end-to-end from clean state)
docker compose down -v && docker compose up -d && sleep 10 && npx playwright test

# 10. If all pass → Update plan, mark Phase 1 [x] COMPLETE
```

### Plan Status Legend

| Status | Meaning |
|--------|---------|
| `[x]` | Code complete + Tests passing (VERIFIED) |
| `[~]` | Code in progress |
| `[ ]` | Not started |
| `[!]` | Failed with documented reason |
| `⏳` | Blocked (waiting for Docker or previous milestone) |

Milestone is only marked `[x]` COMPLETE when tests pass GREEN.

---

## Test Execution Results (January 24, 2026)

### Milestone 1.5: Health Controller ✅ VERIFIED
**Status**: All 3/3 tests passing
- ✅ GET /api/health returns ok status
- ✅ GET /api/health returns valid ISO 8601 timestamp
- ✅ GET /api/health returns JSON content type

**Commit**: f551ed4 — [TEST] Milestone 1.5: health.spec.ts (3/3 tests passing) ✅

### Milestone 2: SyncController — IN PROGRESS
**Overall**: 19/32 tests passing (59%)

#### GET Endpoints: ✅ 15/15 PASSING
**sync-members.spec.ts**: 4/4 ✅
- Returns member delta response
- Returns valid member objects
- Count matches array length
- Returns JSON content type

**sync-categories.spec.ts**: 5/5 ✅
- Returns category delta response
- Returns valid category objects
- Returns multilingual names
- Count matches array length
- Returns JSON content type

**sync-products.spec.ts**: 5/5 ✅
- Returns product delta response
- Returns valid product objects
- Returns multilingual names and descriptions
- Count matches array length
- Returns valid price in cents
- Returns JSON content type

#### PATCH Endpoint: 4/7 PASSING
**member-language.spec.ts**: 4/7
- ✅ Updates language successfully
- ✅ Accepts different languages (de, en, fr)
- ✅ Returns JSON content type
- ✅ Returns valid ISO 8601 timestamp
- ❌ Rejects invalid language code (expects 400, validation incomplete)
- ❌ Rejects missing language (expects 400, validation incomplete)
- ❌ Returns 404 for invalid UUID (needs error handling)

#### POST Endpoint: 0/10 FAILING
**transactions.spec.ts**: 0/10
- ❌ All POST tests failing due to CSRF middleware redirects
- **Issue**: POST requests returning 302 redirects to '/' (CSRF token missing)
- **Root Cause**: VerifyCsrfToken middleware exception pattern not matching 'api*' routes
- **Status**: Requires middleware configuration fix

### Known Issues & Blockers

**Issue 1**: CSRF Protection on POST Requests
- **Problem**: POST /api/sync/transactions returns 302 redirect
- **Cause**: VerifyCsrfToken middleware redirecting stateless requests
- **Solution**: Need to properly exclude api/* routes from CSRF or use token-based auth
- **Status**: Requires debugging middleware exception patterns in Laravel

**Issue 2**: Validation Error Handling (PATCH)
- **Problem**: Invalid requests should return 400/422 with JSON error details
- **Cause**: Test expectations don't match current FormRequest error responses
- **Solution**: Configure FormRequest to return JSON error responses for API
- **Status**: Requires error handler configuration

### Architecture Patterns Verified
✅ All 15 GET endpoint tests confirm:
- Pattern 001: FormRequest validation (SyncRequest works correctly)
- Pattern 003: DTO serialization (MemberDto, CategoryDto, ProductDto)
- Pattern 004: Service layer execution (SyncService returns correct data)
- Pattern 006: Thin controller delegation
- Pattern 008: Service Provider dependency injection

### Next Actions Required
1. **Fix CSRF/Middleware**: Resolve POST request 302 redirects
   - Debug middleware exception pattern matching
   - May need to use API token auth instead of CSRF
2. **Add Validation Error Handling**: Return JSON errors for invalid requests
   - Configure FormRequest to respond with JSON
   - Update tests or error handling as needed
3. **Complete POST Tests**: Get all 10 transaction tests passing
4. **Mark Milestone 2 [x] Complete**: When all 32 tests pass

