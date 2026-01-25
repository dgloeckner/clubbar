# Backend Core Modules Implementation Plan

**Goal**: Implement all 9 core backend modules for a complete production-ready POS system.

**Status**: Inventory & Gap Analysis

**Timeline**: Sequential implementation of remaining core modules

---

## Core Modules Inventory

| # | Module | Description | Status |
|---|--------|-------------|--------|
| 1 | **members** | Member management (CRUD, GDPR, balance view) | ✅ **COMPLETE** (Phase 1) |
| 2 | **products** | Product catalog (CRUD, toggle, category filter) | ✅ **COMPLETE** (Phase 3) |
| 3 | **transactions** | Transaction journal (list, filter, corrections) | ⏸️ **PARTIAL** (list/filter done, corrections needed) |
| 4 | **settlements** | Periodic billing (create, preview, export CSV/SEPA, revoke) | ❌ **NOT STARTED** |
| 5 | **terminals** | Terminal devices (register, token generation, status) | ❌ **NOT STARTED** |
| 6 | **admin-users** | Admin accounts (CRUD, password reset, activation) | ⏸️ **PARTIAL** (login done, full CRUD needed) |
| 7 | **audit-log** | Activity history (list, filter, detail, read-only) | ✅ **COMPLETE** (Phase 1) |
| 8 | **sepa-config** | SEPA settings (setup wizard, configuration) | ❌ **NOT STARTED** |
| 9 | **dashboard** | Overview (statistics, quick actions, sync status) | ❌ **NOT STARTED** |

### Remaining Work Summary

**Must Complete**:
- Module 4: **settlements** (Large)
- Module 5: **terminals** (Medium)
- Module 6: **admin-users** (Medium - enhancement)
- Module 8: **sepa-config** (Medium)
- Module 9: **dashboard** (Large)

**Should Complete**:
- Module 3: **transactions** (Small - enhancements for corrections)

---

## Dependencies & Prerequisites

### Already Complete

✅ **Module 1: members** (Phase 1)
- Member CRUD, RFID cards
- GDPR export/anonymization
- Balance view

✅ **Module 2: products** (Phase 3)
- Product CRUD, categories
- Activation toggle
- Category filtering

✅ **Module 7: audit-log** (Phase 1)
- Audit logging infrastructure
- Activity history

✅ **Phase 2.A Backend**
- Balance tracking
- Transaction history API

### External Infrastructure

- Laravel framework ✅
- Database migrations ✅
- Bearer token authentication ✅
- Session authentication ✅
- PHPUnit/Playwright testing ✅

---

## Module 3: Transactions (Partial — Enhancement)

**Status**: ⏸️ Phase 2.A Backend implemented list/filter; needs corrections

### Key Operations

- List transactions (with filters)
- Filter by member, date range, type
- Record corrections/reversals
- Export transactions

### Remaining Work

| # | Task | Details | Status |
|---|------|---------|--------|
| 3.A | Correction Booking | POST endpoint to record manual corrections | [ ] |
| 3.B | Reversal Records | Track reversed/corrected transactions | [ ] |
| 3.C | Export Endpoint | CSV export of transactions | [ ] |
| 3.D | API Tests | Tests for corrections and exports | [ ] |

### Related Use Cases

- UC-A21: Manual Booking
- UC-A22: Export Transactions

---

## Module 4: Settlements (New — Large)

**Status**: ❌ NOT STARTED

### Key Operations

- Create settlement (accounting period)
- Preview settlement (before finalizing)
- Export CSV (reconciliation)
- Export SEPA XML (bank transfers)
- Revoke settlement

### Required Implementation

| # | Task | Details | Status |
|---|------|---------|--------|
| 4.A | Architecture | Settlement workflow design | [ ] |
| 4.B | Database Schema | settlements, settlement_members tables | [ ] |
| 4.C | Models & Repos | Settlement model and repository | [ ] |
| 4.D | Service Layer | SettlementService (create, calculate, export) | [ ] |
| 4.E | CSV Export | Settlement export as CSV | [ ] |
| 4.F | SEPA XML Export | SEPA XML generation with validation | [ ] |
| 4.G | Admin APIs | POST/GET/DELETE endpoints | [ ] |
| 4.H | API Tests | 25+ tests for settlement operations | [ ] |

### Related Use Cases

- UC-A30: Create Settlement
- UC-A31: Download SEPA XML
- UC-A32: Download CSV
- UC-A33: Settlement History
- UC-A34: Settlement Details
- UC-A35: Manual Settlement

---

## Module 5: Terminals (New — Medium)

**Status**: ❌ NOT STARTED

### Key Operations

- Register new terminal device
- Generate API token for terminal
- Monitor terminal status/activity
- Update terminal sync cursor

### Required Implementation

| # | Task | Details | Status |
|---|------|---------|--------|
| 5.A | Architecture | Terminal device model, token lifecycle | [ ] |
| 5.B | Database Schema | terminals table with device info | [ ] |
| 5.C | Models & Repos | Terminal model and repository | [ ] |
| 5.D | Service Layer | TerminalService (register, token generation) | [ ] |
| 5.E | Admin API | POST register, GET list, GET status | [ ] |
| 5.F | Token Management | Secure token generation and validation | [ ] |
| 5.G | API Tests | 15+ tests for terminal operations | [ ] |

### Related Use Cases

- Terminal registration and management
- Device token lifecycle

---

## Module 6: Admin-Users (Partial — Enhancement)

**Status**: ⏸️ Phase 1 implemented login; needs full CRUD

### Key Operations

- Create admin user
- List admin users
- Update admin user (name, email, role)
- Reset admin password
- Activate/deactivate admin

### Remaining Work

| # | Task | Details | Status |
|---|------|---------|--------|
| 6.A | Admin CRUD | POST create, GET list, PATCH update, DELETE deactivate | [ ] |
| 6.B | Password Reset | Secure reset link, token validation | [ ] |
| 6.C | Role Management | Admin roles/permissions design | [ ] |
| 6.D | Database Schema | Extend users table with role/status fields | [ ] |
| 6.E | API Tests | 20+ tests for admin operations | [ ] |

### Related Use Cases

- UC-A61: Manage Admins
- UC-A62: Create Admin
- UC-A63: Reset Admin Password

---

## Module 8: SEPA-Config (New — Medium)

**Status**: ❌ NOT STARTED

### Key Operations

- Setup SEPA configuration (creditor ID, org name, etc.)
- Edit SEPA configuration
- Manage member IBANs
- Validate SEPA mandates

### Required Implementation

| # | Task | Details | Status |
|---|------|---------|--------|
| 8.A | Architecture | SEPA settings model and validation | [ ] |
| 8.B | Database Schema | sepa_config, member_ibans tables | [ ] |
| 8.C | Models & Repos | SepaConfig, MemberIban models | [ ] |
| 8.D | Service Layer | SepaConfigService (setup, validate) | [ ] |
| 8.E | Admin API | Setup wizard, configuration edit | [ ] |
| 8.F | Member IBAN API | Admin can set/update member IBANs | [ ] |
| 8.G | API Tests | 15+ tests for SEPA configuration | [ ] |

### Related Use Cases

- UC-SEPA-01: SEPA Config Setup
- UC-SEPA-02: Config Update
- UC-SEPA-03: Member IBAN
- UC-SEPA-04: Mandate Reference

---

## Module 9: Dashboard (New — Large)

**Status**: ❌ NOT STARTED

### Key Operations

- Display overview statistics (members, transactions, balance)
- Show quick actions (create settlement, view recent activity)
- Display sync status across terminals
- Show recent transactions/activity

### Required Implementation

| # | Task | Details | Status |
|---|------|---------|--------|
| 9.A | Architecture | Dashboard metrics and data models | [ ] |
| 9.B | Service Layer | DashboardService (aggregate metrics, optimize queries) | [ ] |
| 9.C | API Endpoint | GET /api/admin/dashboard with all metrics | [ ] |
| 9.D | Member Ranking | Top spenders, active/inactive members | [ ] |
| 9.E | Terminal Activity | Terminal sync status, recent activity | [ ] |
| 9.F | Audit Summary | Recent admin actions | [ ] |
| 9.G | API Tests | 20+ tests for dashboard endpoints | [ ] |

### Related Use Cases

- UC-A80: Dashboard
- UC-A51: Member Ranking
- UC-A52: Terminal Activity
- UC-A50: Reports (general)

---

## Cross-Cutting Concerns (Apply to All Modules)

### 1. Pattern Compliance

All modules must follow backend patterns (Patterns 001-016):
- **Pattern 001**: Form Requests for validation
- **Pattern 002**: Enums for type-safe values
- **Pattern 003**: DTOs for responses
- **Pattern 004**: Service layer for business logic
- **Pattern 005**: Repository pattern for data access
- **Pattern 006**: Thin controllers
- **Pattern 007**: Centralized exception handling
- **Pattern 008**: Service provider bindings
- **Pattern 016**: Audit logging for changes

### 2. Testing Strategy (ADR-0022)

Each module must include:
- Unit tests (PHPUnit) for services/repositories
- API tests (Playwright) for all endpoints
- Validation tests (required/optional fields)
- Error scenarios (4xx, 5xx responses)
- Authorization tests

**Target**: 80%+ code coverage per module

### 3. Audit Logging

Every data mutation must log via AuditService:
- CREATE operations
- UPDATE operations (with old/new values)
- DELETE operations (or deactivations)
- Special actions (settlements, exports, SEPA)

### 4. Documentation

Each module needs:
- ADR for key architectural decisions
- Updated ERM diagrams for new tables
- Updated OpenAPI specs for new endpoints
- Use case fulfillment checklist

---

## Implementation Order (Dependency-Aware)

**Phase 1 — Foundation**:
1. **Module 6: Admin-Users** (enhancement)
   - Needed for: Authorization in other modules
   - Dependencies: None (builds on Phase 1)
   - Unblocks: Modules 4, 8, 9

2. **Module 8: SEPA-Config**
   - Needed for: Module 4 (settlement configuration)
   - Dependencies: Module 6
   - Unblocks: Module 4

**Phase 2 — Core Business Logic**:
3. **Module 3: Transactions** (enhancement)
   - Needed for: Module 4 (settlement calculations)
   - Dependencies: None (extends Phase 2.A)
   - Unblocks: Module 4

4. **Module 4: Settlements** (large)
   - Core business functionality
   - Dependencies: Modules 3, 6, 8
   - Unblocks: All complete systems

**Phase 3 — Operations**:
5. **Module 5: Terminals** (medium)
   - Device management and token lifecycle
   - Dependencies: Module 6
   - Can run in parallel with other phases

6. **Module 9: Dashboard** (large)
   - Read-only aggregation queries
   - Dependencies: Modules 3, 4, 5, 6
   - Final piece

---

## Success Criteria (Overall)

**9-Core-Modules Complete When**:

- [x] Module 1: members — Complete ✅ (Phase 1)
- [x] Module 2: products — Complete ✅ (Phase 3)
- [x] Module 7: audit-log — Complete ✅ (Phase 1)
- [ ] Module 3: transactions — Enhancements (2-3 days)
- [ ] Module 4: settlements — Full implementation (10-15 days)
- [ ] Module 5: terminals — Full implementation (5-7 days)
- [ ] Module 6: admin-users — Full CRUD (5-7 days)
- [ ] Module 8: sepa-config — Full implementation (5-7 days)
- [ ] Module 9: dashboard — Full implementation (7-10 days)

**Overall Completion Criteria**:
- [ ] All 6 remaining modules implemented and tested
- [ ] 120+ new API tests passing across modules
- [ ] All patterns followed consistently
- [ ] Database migrations for all schema changes
- [ ] OpenAPI specs updated for all endpoints
- [ ] ADRs written for architectural decisions
- [ ] No console errors/warnings in tests
- [ ] Ready for Phase 2.A Terminal UI

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Settlement calculations incorrect | High | Extensive calculation tests; peer review |
| SEPA XML validation failures | High | XSD validation; test against bank specs |
| Token expiration/lifecycle issues | High | Clear token lifecycle; comprehensive tests |
| Dashboard performance issues | Medium | Query optimization; caching strategy |
| Pattern compliance gaps | Medium | Automated checks; code review |
| Test coverage gaps | Medium | Coverage reports; minimum 80% enforced |

---

## References

- [ADR-0018: Modular Architecture](../adr/0018-modular-architecture.md)
- [ADR-0022: Test Strategy and Automation](../adr/0022-test-strategy-and-automation.md)
- [Backend Patterns](../backend/patterns/) — Pattern 001-016
- [Use Cases: Admin](../use-cases/admin/) — UC-A20 through UC-A82
- [Use Cases: SEPA](../use-cases/sepa/) — UC-SEPA-01 through UC-SEPA-09
- [E2E Testing Patterns](../e2etests/patterns/) — Pattern 001-004
