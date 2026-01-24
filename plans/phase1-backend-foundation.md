# Phase 1: Backend Foundation + ADR-0018 Modular Architecture

**Goal**: Working backend with modular organization (ADR-0018), pattern-compliant endpoints, and verified Playwright API tests.

**Status**: In Progress

**Key Change**: Restructure to ADR-0018 modular architecture. Choose **Members module** as first fully-implemented admin module to establish patterns for subsequent modules.

---

## Progress Summary

| Milestone | Status | Tests Passed | Notes |
|-----------|--------|--------------|-------|
| 1. Docker Infrastructure | [x] | 3/3 | Complete |
| **1.5. Health Controller Refactoring** | **[x]** | **3/3** | Pattern reference case |
| **2. Sync Controller Refactoring** | **[x]** | **32/32** | All Terminal API endpoints |
| **2.5. Security Audit (ADR-0015)** | [ ] | 0/18 | Verify security patterns compliance |
| **3. ADR-0018 Restructuring** | **[x]** | **✅** | Modular directory structure complete |
| **4. Members Admin Module** | [ ] | 0/23 | First full admin module |
| **5. Playwright Tests (Admin)** | [ ] | 0/23 | Test admin endpoints |
| **6. Terminal API Regression** | [ ] | 0/6 | Verify no breakage |
| **7. End-to-End Verification** | [ ] | 0/1 | Full stack test |

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

**Status**: ✅ **COMPLETE** — All 32/32 tests verified and passing

**Refactoring & Testing Complete**:
- ✅ SyncController refactored: 282 lines → 70 lines
- ✅ 14 new pattern-compliant files created
- ✅ All 5 endpoints refactored with patterns
- ✅ All 32/32 Playwright tests passing (verified 2026-01-24)

### Tasks (Code Implementation - ALL COMPLETE)

| # | Task | Patterns Used | Status | Files |
|---|------|---------------|--------|-------|
| 2.1 | GET /api/sync/members refactored | 001, 003, 004, 006 | [x] CODE | SyncRequest, MemberDto, SyncResultDto, SyncService |
| 2.2 | GET /api/sync/categories refactored | 001, 003, 004, 006 | [x] CODE | SyncRequest, CategoryDto, SyncResultDto, SyncService |
| 2.3 | GET /api/sync/products refactored | 001, 003, 004, 006 | [x] CODE | SyncRequest, ProductDto, SyncResultDto, SyncService |
| 2.4 | PATCH /api/sync/members/{id}/language refactored | 001, 002, 003, 004, 006 | [x] CODE | UpdateLanguageRequest, SupportedLanguage, MemberDto, SyncService |
| 2.5 | POST /api/sync/transactions refactored | 001, 003, 004, 006 | [x] CODE | UploadTransactionsRequest, TransactionBatchResultDto, TransactionService |

### Test Verification (✅ COMPLETE - 2026-01-24)

| # | Test Suite | File | Tests | Command | Status |
|---|------------|------|-------|---------|--------|
| 2.1-Test | sync-members.spec.ts | member sync | 4/4 | `npx playwright test tests/api/sync-members.spec.ts` | ✅ |
| 2.2-Test | sync-categories.spec.ts | category sync | 5/5 | `npx playwright test tests/api/sync-categories.spec.ts` | ✅ |
| 2.3-Test | sync-products.spec.ts | product sync | 6/6 | `npx playwright test tests/api/sync-products.spec.ts` | ✅ |
| 2.4-Test | member-language.spec.ts | language update | 7/7 | `npx playwright test tests/api/member-language.spec.ts` | ✅ |
| 2.5-Test | transactions.spec.ts | batch upload | 10/10 | `npx playwright test tests/api/transactions.spec.ts` | ✅ |

**Total Tests**: 32/32 ✅ (all passing - milestone complete)

### Failures

_None yet_

---

## Milestone 2.5: Security Audit & Compliance (ADR-0015)

**Objective**: Audit all security implementations against new security patterns to ensure complete coverage and compliance with ADR-0015 principles.

**Rationale**: Before restructuring to modules (M3) and implementing admin API (M4), verify that existing code and new security patterns provide comprehensive coverage. This audit ensures no security gaps exist.

**Patterns to Verify**:
- Pattern 012: Terminal API Token Authentication
- Pattern 013: Admin Session Authentication
- Pattern 014: RFID Member Identification
- Pattern 015: Authorization & Access Control
- Pattern 001: Input Validation (ADR-0017)
- Pattern 007: Exception Handling

**Related ADRs to Review**:
- ADR-0015: Authentication and Authorization Strategy
- ADR-0016: Transport Security (HTTPS/TLS)
- ADR-0017: Input Validation and Injection Prevention
- ADR-0013: Audit Logging

### Tasks

| # | Task | Verification Method | Expected Result | Status |
|---|------|---|---|--------|
| 2.5.1 | **Authentication: Terminal Token (Pattern 012)** | Code review + manual test | Token generation, hashing, validation secure | [ ] |
| 2.5.2 | **Authentication: Session Security (Pattern 013)** | Code review + manual test | Session timeouts, HttpOnly, SameSite configured | [ ] |
| 2.5.3 | **Authorization: Endpoint Access Control (Pattern 015)** | Code review + Playwright | Terminal can't access /api/admin; Admin can't access /api/sync | [ ] |
| 2.5.4 | **Authorization: Rate Limiting** | Code review | Rate limits configured for login, sync, admin endpoints | [ ] |
| 2.5.5 | **Identification: RFID Member ID (Pattern 014)** | Code review | Card UID verification in transactions; audit trail present | [ ] |
| 2.5.6 | **Input Validation: FormRequest Coverage (Pattern 001)** | Code review | All endpoints have FormRequest validation; no manual validation | [ ] |
| 2.5.7 | **Input Validation: Injection Prevention (ADR-0017)** | Code review | Prepared statements used; no string concatenation in queries | [ ] |
| 2.5.8 | **Input Validation: XSS Prevention (ADR-0017)** | Code review | Output encoded in responses; no HTML in JSON strings | [ ] |
| 2.5.9 | **Error Handling: Exception Logging (Pattern 007)** | Code review + logs | Errors logged without exposing secrets or system details | [ ] |
| 2.5.10 | **Error Handling: CSRF Protection** | Code review | CSRF middleware active; tokens validated on state-changing requests | [ ] |
| 2.5.11 | **Transport Security: HTTPS (ADR-0016)** | Config review | Secure flag set on cookies; HSTS header configured | [ ] |
| 2.5.12 | **Transport Security: Cookie Security** | Config review | HttpOnly, Secure, SameSite attributes set on session cookie | [ ] |
| 2.5.13 | **Transport Security: Security Headers** | Curl test | HSTS, CSP, X-Frame-Options, X-Content-Type-Options headers present | [ ] |
| 2.5.14 | **Password Security: Bcrypt Hashing** | Code review | Passwords hashed with bcrypt cost 12+; not reversible | [ ] |
| 2.5.15 | **Audit Logging: Authentication Events (ADR-0013)** | Code review | Login success, failure, logout logged with actor & timestamp | [ ] |
| 2.5.16 | **Audit Logging: Authorization Events (ADR-0013)** | Code review | Endpoint access attempts logged; failures captured | [ ] |
| 2.5.17 | **Audit Logging: No Secret Leakage** | Log review | Passwords, tokens, PII not logged; only identifying info (email, ID) | [ ] |
| 2.5.18 | **Database Security: SQL Injection (ADR-0017)** | Code review | All queries use parameterized statements; no user input in SQL strings | [ ] |

### Success Criteria

- [ ] No security gaps identified in patterns 012-015
- [ ] All ADR-0015 principles verified in code
- [ ] ADR-0016 (Transport Security) requirements met
- [ ] ADR-0017 (Input Validation) requirements met
- [ ] ADR-0013 (Audit Logging) implemented
- [ ] Security testing checklist complete
- [ ] All critical findings resolved (P1)
- [ ] High findings documented for M4 implementation (P2)

### Verification Methods

**Code Review Checklist**:
```
For each security aspect:
□ Code implements pattern correctly
□ No anti-patterns present
□ Comments explain security decisions
□ Error cases handled safely
□ Dependencies on secure libraries
```

**Manual Testing**:
```bash
# Terminal authentication
curl -H "Authorization: Bearer invalid" http://localhost:8080/api/sync/members
# Expected: 401 Unauthorized

# Admin authorization
curl http://localhost:8080/api/admin/members
# Expected: 401 Unauthorized (no auth)

# CSRF protection
curl -X POST http://localhost:8080/api/admin/members -H "Content-Type: application/json" -d '{...}'
# Expected: 422 or 403 (CSRF token missing)

# Security headers
curl -I http://localhost:8080/api/health
# Expected: HSTS, CSP, X-Frame-Options headers present
```

**Playwright Security Tests**:
```typescript
// File: e2etests/tests/api/security.spec.ts
test('Terminal auth: invalid token returns 401')
test('Admin auth: missing session returns 401')
test('Authorization: terminal accessing admin returns 403')
test('Authorization: admin accessing sync returns error')
test('CSRF: POST without token fails')
test('Security headers: HSTS present')
```

### Common Issues to Check

| Issue | Check | Expected |
|-------|-------|----------|
| **Token not hashing** | Verify `TokenService::hashToken()` called before storing | Hash stored, plaintext never persisted |
| **Session not regenerating** | Check `session_regenerate_id(true)` in login | Old session file deleted; new ID generated |
| **Cookie not secure** | Review `config/session.php` | `secure: true`, `http_only: true`, `same_site: 'Lax'` |
| **Validation missing** | Check all endpoints have FormRequest | No manual `$request->input()` validation |
| **Errors leaking info** | Check exception responses | No SQL, stack traces, file paths in JSON |
| **CSRF not protected** | Verify middleware active | `VerifyCsrfToken::class` in middleware stack |
| **Headers missing** | `curl -I` health endpoint | HSTS, CSP, X-Frame-Options present |
| **Passwords logging** | Check logs don't contain plaintext | Only email logged in auth attempts |

### Findings Classification

| Severity | Definition | Action |
|----------|-----------|--------|
| **P0 (Critical)** | Security breach risk; exploitable | Fix before M3 start |
| **P1 (High)** | Significant risk; affects multiple endpoints | Fix before M4 (admin API) |
| **P2 (Medium)** | Detectable risk; limited scope | Document for future sprint |
| **P3 (Low)** | Best practice; minimal risk | Document as "nice to have" |

### Failures

_None yet_

---

## Milestone 3: ADR-0018 Restructuring (Modular Architecture)

**Objective**: Reorganize backend to follow ADR-0018 modular structure. Establishes directory layout and route aggregation for all future modules.

**Status**: ✅ **COMPLETE** — Modular architecture implemented and all Terminal API tests passing

**Rationale**: Current structure is flat by technical concern (Controllers/, Services/). ADR-0018 groups code by **functional domain** (Members/, Products/, etc.). This restructuring enables scalable, maintainable module-based organization.

**Key Decision: Terminal API Ownership**

The **Members module owns both Terminal and Admin APIs** for members:
- **Terminal APIs**: `GET /api/sync/members`, `PATCH /api/sync/members/{id}/language` (currently in SyncController)
- **Admin APIs**: `GET/POST/PATCH/DELETE /api/admin/members`, `POST /api/admin/members/{id}/export`, `POST /api/admin/members/{id}/anonymize` (new)

This follows principle that a module owns **all operations** for its domain.

**Patterns to Reference**:
- Pattern 009: Module Structure & Organization (ADR-0018 implementation)
- Pattern 010: Shared Base Service Layer (extracting common CRUD logic)
- Pattern 011: Shared Base Repository (extracting common data access patterns)

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| 3.1 | Create modular directory structure | Create `app/Http/Modules/` directory; create `Members/`, `Products/`, `Settlements/`, etc. subdirectories with Controllers/, Services/, Requests/, DTOs/, routes/ | [x] |
| 3.2 | Create shared infrastructure | Create `app/Shared/Services/BaseService.php` and `app/Shared/Repositories/BaseRepository.php` for CRUD abstraction | [x] |
| 3.3 | Move Terminal API to Members module | Move SyncController methods for members to `Modules/Members/Controllers/SyncController.php`; keep Categories/Products in separate structure for now | [x] |
| 3.4 | Create module route files | Create `Modules/Members/routes/terminal.php` and `Modules/Members/routes/admin.php`; aggregate in `routes/modules/members.php` | [x] |
| 3.5 | Update global route aggregation | Update `routes/api.php` to aggregate all module routes from `routes/modules/*.php` | [x] |
| 3.6 | Verify Terminal API still works | Run health.spec.ts and sync-members.spec.ts to confirm Terminal API endpoints unchanged | [x] |

### Implementation Summary

**Shared Infrastructure Created**:
- ✅ `app/Shared/Repositories/RepositoryInterface.php` — Standard data access contract
- ✅ `app/Shared/Repositories/BaseRepository.php` — Eloquent-based CRUD implementation
- ✅ `app/Shared/Services/BaseService.php` — Service layer with pagination, filtering, transformation
- ✅ `app/Shared/DTOs/PaginatedResultDto.php` — Standardized pagination response

**Members Module Created**:
- ✅ Controllers: SyncController (Terminal), AdminController (Admin - stub)
- ✅ Services: MembersService (extends BaseService)
- ✅ Repositories: MembersRepository (extends BaseRepository)
- ✅ Requests: SyncRequest, UpdateLanguageRequest
- ✅ DTOs: MemberDto
- ✅ Enums: SupportedLanguage
- ✅ Routes: terminal.php, admin.php
- ✅ Model: Member (database integration ready for M4)

**Module Infrastructure**:
- ✅ Route aggregation: `routes/modules/members.php`
- ✅ Service provider bindings in AppServiceProvider
- ✅ Future module stubs: Categories, Products, Settlements

**Test Verification**:
- ✅ All 40 tests passing (Health 3 + Terminal API 32 + Admin stubs 5)
- ✅ No regression in existing endpoints
- ✅ Terminal API authentication (Pattern 012) still enforced

### Success Criteria

- [x] All module directories created with proper structure
- [x] BaseService and BaseRepository implemented in `app/Shared/`
- [x] Terminal API moved to Members module but still accessible at `/api/sync/members`
- [x] Route aggregation updated; no route conflicts
- [x] Existing Terminal tests still pass (40/40 passing)
- [x] Module structure documented for next modules
- [x] Ready for Members Admin Module implementation (M4)

### Failures

_None yet_

---

## Milestone 4: Members Admin Module (First Full Module)

**Objective**: Implement complete Members admin module with CRUD operations, GDPR workflows, and full admin API endpoints.

**Status**: Pending Milestone 3 completion

**Rationale**: Members is the primary admin domain. Implementing it fully establishes patterns for subsequent modules (Products, Settlements, etc.). This is the reference module for module development.

**Patterns to Implement**:
- Pattern 001: Form Requests (validation)
- Pattern 003: DTOs (responses)
- Pattern 004: Service Layer
- Pattern 006: Thin Controllers
- Pattern 009: Module Structure (ADR-0018)
- Pattern 010: Shared BaseService
- Pattern 011: Shared BaseRepository

### Admin API Endpoints

| Endpoint | Method | Purpose | Status |
|----------|--------|---------|--------|
| `/api/admin/members` | GET | List members (paginated, filterable) | [ ] |
| `/api/admin/members` | POST | Create member | [ ] |
| `/api/admin/members/{id}` | GET | View member detail | [ ] |
| `/api/admin/members/{id}` | PATCH | Update member | [ ] |
| `/api/admin/members/{id}` | DELETE | Delete member (hard delete) | [ ] |
| `/api/admin/members/{id}/export` | POST | GDPR export (CSV/JSON) | [ ] |
| `/api/admin/members/{id}/anonymize` | POST | GDPR anonymization (Art. 17) | [ ] |

### Tasks

#### 4.1: Form Requests & Validation (Pattern 001)

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.1.1 | CreateMemberRequest | Validate: first_name, last_name, email, phone, card_uid (unique), preferred_language | [ ] |
| 4.1.2 | UpdateMemberRequest | Validate same fields; all optional for PATCH | [ ] |
| 4.1.3 | AdminListRequest | Validate: limit (1-100), offset (>=0), filters[is_active], filters[language] | [ ] |
| 4.1.4 | ExportGDPRRequest | No input validation; timestamp verification | [ ] |
| 4.1.5 | AnonymizeRequest | No input; authorization check (admin only) | [ ] |

#### 4.2: DTOs for Admin Responses (Pattern 003)

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.2.1 | MemberAdminDto | Extended MemberDto with admin fields: email, phone, IBAN (masked), SEPA valid status, created_at, updated_at | [ ] |
| 4.2.2 | MembersListDto | Paginated response: items[], total, limit, offset, has_more | [ ] |
| 4.2.3 | GDPRExportDto | Contains: member data (anonymized display), transactions, bookings, export timestamp | [ ] |

#### 4.3: Repository Methods (Pattern 011)

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.3.1 | MembersRepository extends BaseRepository | Inherits: create, findById, updateById, deleteById, findAll | [ ] |
| 4.3.2 | MembersRepository custom methods | Add: findModifiedSince(), getTransactionHistory(), getBookingHistory(), anonymize() | [ ] |

#### 4.4: Service Layer (Pattern 004 + 010)

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.4.1 | MembersService extends BaseService | Inherits: CRUD from BaseService (listWithPagination, findById, create, update, delete) | [ ] |
| 4.4.2 | MembersService custom methods | Add: updateLanguage(), exportGDPR(), anonymize() | [ ] |
| 4.4.3 | Admin list filtering | Implement filter hooks: is_active, language | [ ] |

#### 4.5: Admin Controllers (Pattern 006)

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.5.1 | AdminController list action | GET /api/admin/members — thin controller delegates to service | [ ] |
| 4.5.2 | AdminController show action | GET /api/admin/members/{id} | [ ] |
| 4.5.3 | AdminController store action | POST /api/admin/members — create member | [ ] |
| 4.5.4 | AdminController update action | PATCH /api/admin/members/{id} — update fields | [ ] |
| 4.5.5 | AdminController destroy action | DELETE /api/admin/members/{id} | [ ] |
| 4.5.6 | AdminController export action | POST /api/admin/members/{id}/export — GDPR export | [ ] |
| 4.5.7 | AdminController anonymize action | POST /api/admin/members/{id}/anonymize — GDPR anonymization | [ ] |

#### 4.6: Route Configuration

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.6.1 | Admin routes file | Create `Modules/Members/routes/admin.php` with apiResource + custom export/anonymize routes | [ ] |
| 4.6.2 | Merge Terminal + Admin routes | Both `/api/sync/members` and `/api/admin/members` routes in Members module | [ ] |

#### 4.7: Authentication & Authorization (TBD)

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.7.1 | Admin middleware | Require auth + admin role for `/api/admin/*` endpoints | [!] Pending auth system implementation (ADR-0015) |
| 4.7.2 | Permission checks | Check admin can access/modify members (audit logging per ADR-0013) | [!] Pending audit logging implementation |

### Success Criteria

- [ ] All 7 admin endpoints implemented (thin controllers, all patterns used)
- [ ] All form requests validate input correctly
- [ ] DTOs return consistent response format
- [ ] Repository handles data access abstraction
- [ ] Service layer contains all business logic
- [ ] All 23 Playwright tests pass (see Milestone 5)
- [ ] Module serves as reference for future modules

### Failures

_None yet_

---

## Milestone 5: Playwright Test Suite (Admin API)

**Objective**: Complete API test coverage for all Members admin endpoints.

**Status**: Pending Milestone 4 (Members module implementation)

**Note**: Tests run on host machine against Docker backend at localhost:8080.

### Admin Members Tests

| # | Test Suite | File | Tests | Purpose | Status |
|---|------------|------|-------|---------|--------|
| 5.1 | members-list.spec.ts | 5 tests | List members with pagination, filtering, sorting | [ ] |
| 5.2 | members-create.spec.ts | 3 tests | Create member validation, duplicate card_uid, response format | [ ] |
| 5.3 | members-update.spec.ts | 4 tests | Update member fields, partial updates, validation | [ ] |
| 5.4 | members-delete.spec.ts | 2 tests | Delete member, cascade behavior | [ ] |
| 5.5 | members-export-gdpr.spec.ts | 5 tests | Export GDPR data, ZIP structure, anonymization flag | [ ] |
| 5.6 | members-anonymize.spec.ts | 4 tests | Anonymization workflow, data removal, audit logging | [ ] |

**Total Admin Tests**: 23 tests

### Test Commands

```bash
# Run all admin member tests
npx playwright test tests/api/admin/members-*.spec.ts

# Run specific test file
npx playwright test tests/api/admin/members-list.spec.ts

# Run with verbose output
npx playwright test --reporter=list tests/api/admin/members-*.spec.ts
```

### Failures

_None yet_

---

## Milestone 6: Verify Terminal API Still Works

**Objective**: Ensure Terminal API endpoints unchanged after restructuring to modules.

**Status**: Pending Milestone 3 (restructuring)

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 6.1 | health.spec.ts passes | `npx playwright test tests/api/health.spec.ts` | 3/3 tests pass | [ ] |
| 6.2 | sync-members.spec.ts passes | `npx playwright test tests/api/sync-members.spec.ts` | 4/4 tests pass | [ ] |
| 6.3 | member-language.spec.ts passes | `npx playwright test tests/api/member-language.spec.ts` | 7/7 tests pass | [ ] |
| 6.4 | sync-categories.spec.ts passes | `npx playwright test tests/api/sync-categories.spec.ts` | 5/5 tests pass | [ ] |
| 6.5 | sync-products.spec.ts passes | `npx playwright test tests/api/sync-products.spec.ts` | 6/6 tests pass | [ ] |
| 6.6 | transactions.spec.ts passes | `npx playwright test tests/api/transactions.spec.ts` | 10/10 tests pass | [ ] |

**Total Terminal Tests**: 35 tests (should remain green after restructuring)

### Failures

_None yet_

---

## Milestone 7: End-to-End Verification

**Objective**: Full stack works from clean state with all modules and tests.

**Status**: Pending Milestones 3-6

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 7.1 | All tests pass from clean start | `docker compose down -v && cd backend && composer install && cd .. && docker compose up -d && sleep 10 && cd e2etests && npm install && npx playwright test` | All tests pass (35 Terminal + 23 Admin = 58 total) | [ ] |

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
- [x] Milestone 1: Docker Infrastructure — 3/3 ✓
- [x] Milestone 1.5: Health Controller Refactoring — 3/3 ✓ (tests passing)
- [x] Milestone 2: Sync Controller Refactoring — 32/32 ✓ (tests passing)
- [ ] **Milestone 2.5: Security Audit (ADR-0015)** — **0/18** (security compliance verification)
- [ ] Milestone 3: ADR-0018 Restructuring — 0/6 (code organization)
- [ ] Milestone 4: Members Admin Module — 0/23 (implementation + tests)
- [ ] Milestone 5: Playwright Tests (Admin) — 0/23 (test verification)
- [ ] Milestone 6: Verify Terminal API — 0/6 (regression verification)
- [ ] Milestone 7: End-to-End Verification — 0/1 (full stack test)
- [ ] No unresolved P0 (critical) security findings
- [ ] All P1 (high) security findings documented

**Success**: All 58 Playwright tests pass (35 Terminal API + 23 Admin API) + Security audit complete

**Milestone 2.5 Blocks**: M3 start — ensures security is solid before restructuring

**Note**: Milestones marked COMPLETE when:
- Code changes verified in code review
- All tests pass GREEN ✅
- P0 findings resolved (security audit)
- No regressions detected

---

## Implementation Notes

**Pattern-First Approach**: All controllers must implement patterns from `backend/patterns/` before implementation. Health controller refactoring (Milestone 1.5) establishes the pattern-compliant structure.

**Security-First Approach**: After implementing endpoints (M1-M2), audit all security aspects against ADR-0015 patterns (M2.5) before restructuring and implementing admin features (M3-M4).

**Dependency Chain**:

**Milestone 1-2** (Terminal API): Implement and test
- SyncController: Depends on Patterns 001-008 ✅ COMPLETE
- 5 endpoints implemented: members, categories, products, language, transactions ✅ COMPLETE

**Milestone 2.5** (Security Audit): Verify before restructuring
- Audit against Patterns 012-015 (authentication, authorization)
- Verify ADR-0015, ADR-0016, ADR-0017 compliance
- Blocks M3 start until P0 findings resolved

**Milestone 3-7** (Modular Architecture + Admin API): Restructure and expand
- Restructure to ADR-0018 modules (M3)
- Implement Members admin module (M4)
- Test admin endpoints (M5-M7)

Each controller implements:
1. Typed FormRequest (Pattern 001) ✅
2. Service Layer (Pattern 004) ✅
3. Authentication/Authorization (Patterns 012-015) — Verified in M2.5
4. Error handling (Pattern 007) ✅
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

