# Backend Core Modules Implementation Plan

**Goal**: Complete all core backend modules required for a production-ready POS system before building terminal UI.

**Status**: Planning & Prioritization

**Timeline**: Sequential implementation of 5 core modules + completion of 3 cross-cutting concerns

---

## Overview

After completing Phase 1 (Members), Phase 3 (Products), and Phase 2.A Backend (Balance tracking), the following core backend modules remain to complete the system:

| Module | Purpose | Priority | Estimated Scope |
|--------|---------|----------|------------------|
| **Settlement System** | Payment processing, SEPA generation, member accounting | **P0** | Large (Models, APIs, Services, Tests) |
| **Advanced Transactions** | Corrections, reversals, manual bookings, exports | **P0** | Medium (extend existing) |
| **Admin/User Management** | Admin CRUD, roles, password management, organization settings | **P0** | Medium (Models, APIs, Tests) |
| **Reporting & Analytics** | Dashboard, reports, audit logs, member ranking | **P1** | Large (Services, APIs, Tests) |
| **Card Management** | Unassigned cards, card blocking, card lifecycle | **P1** | Small (extend Members) |
| **GDPR/Data Protection** | Right to access, erasure, rectification, audit trails | **P1** | Medium (extend existing) |

---

## Dependencies & Prerequisites

### Must Be Complete First

✅ **Phase 1: Backend Foundation**
- Members module with RFID cards
- Authentication (session-based admin, bearer-token terminal)
- GDPR endpoints (export, anonymize)
- Audit logging infrastructure

✅ **Phase 3: Backend Products Module**
- Products and categories
- Product management APIs
- Terminal sync endpoints

✅ **Phase 2.A Backend: Balance Tracking**
- Member balance state management
- Transaction history API
- Balance updates on sync

### External Dependencies

- Laravel framework (Phase 1) ✅
- Database migrations framework ✅
- Bearer token authentication (Phase 1) ✅
- Audit logging service (Phase 1) ✅
- PHPUnit/Playwright test framework ✅

---

## Module 1: Settlement System (P0 — Priority)

### Objective

Implement complete settlement workflow for accounting and payments. Enables:
- Admin to create settlements (accounting periods)
- Calculate member balances and amounts due
- Generate SEPA XML for bank transfers
- Track payment status and history

### Scope

**Milestones**:

| # | Milestone | Scope | Status |
|---|-----------|-------|--------|
| 1.A | Architecture & ADRs | Settlement workflow, SEPA strategy | [ ] |
| 1.B | Database Schema | settlements, settlement_members, sepa_mandates tables | [ ] |
| 1.C | Models & Repositories | Settlement, SettlementMember, SepaMandatemodels | [ ] |
| 1.D | Service Layer | SettlementService (create, calculate, finalize) | [ ] |
| 1.E | SEPA Generation | SEPA XML export with validation | [ ] |
| 1.F | Admin APIs | POST/GET endpoints for settlement CRUD | [ ] |
| 1.G | CSV Export | CSV download for reconciliation | [ ] |
| 1.H | Member IBAN Management | Admin API to manage member bank accounts | [ ] |
| 1.I | API Tests | Playwright tests for all settlement endpoints | [ ] |

### Related Use Cases

- UC-A30: Create Settlement
- UC-A31: Download SEPA XML
- UC-A32: Download CSV
- UC-A33: Settlement History
- UC-A34: Settlement Details
- UC-A35: Manual Settlement
- UC-SEPA-01 through UC-SEPA-09: SEPA configuration and workflows

### Success Criteria

- [ ] Settlement creation calculates correct balances per member
- [ ] SEPA XML generation valid (validates against XSD)
- [ ] CSV export includes all required fields
- [ ] All settlement workflows tested (create, preview, finalize, export)
- [ ] 30+ API tests passing

---

## Module 2: Advanced Transactions (P0 — Priority)

### Objective

Extend transaction system to support corrections, reversals, and advanced operations.

### Scope

**Milestones**:

| # | Milestone | Scope | Status |
|---|-----------|-------|--------|
| 2.A | Architecture | Reversal/correction strategy, API design | [ ] |
| 2.B | Reversal API | POST endpoint to create reversal transactions | [ ] |
| 2.C | Manual Booking | Admin ability to book product to member tab | [ ] |
| 2.D | Transaction Export | CSV export with all transaction details | [ ] |
| 2.E | API Tests | Tests for reversals, manual bookings, exports | [ ] |

### Related Use Cases

- UC-A21: Manual Booking
- UC-A22: Export Transactions

### Success Criteria

- [ ] Reversals create correct negative transactions
- [ ] Manual bookings support all fields (product, amount, reason)
- [ ] Exports include all necessary details
- [ ] 15+ API tests passing

---

## Module 3: Admin/User Management (P0 — Priority)

### Objective

Complete admin user lifecycle: creation, password management, role management.

### Scope

**Milestones**:

| # | Milestone | Scope | Status |
|---|-----------|-------|--------|
| 3.A | Architecture | Admin roles/permissions design | [ ] |
| 3.B | Database Schema | admin_roles, admin_permissions tables | [ ] |
| 3.C | Admin Model | Update User model with role support | [ ] |
| 3.D | Password Management | Reset endpoint, secure reset flow | [ ] |
| 3.E | Admin CRUD | Create, list, update, deactivate admins | [ ] |
| 3.F | Organization Settings | Edit org details (name, address, SEPA config) | [ ] |
| 3.G | API Tests | Tests for all admin operations | [ ] |

### Related Use Cases

- UC-A60: Edit Organization
- UC-A61: Manage Admins
- UC-A62: Create Admin
- UC-A63: Reset Admin Password

### Success Criteria

- [ ] Admin creation with secure password setup
- [ ] Password reset works with token validation
- [ ] Org settings editable by authorized admins
- [ ] 20+ API tests passing

---

## Module 4: Reporting & Analytics (P1 — Medium Priority)

### Objective

Provide admin dashboard and reports for business insights.

### Scope

**Milestones**:

| # | Milestone | Scope | Status |
|---|-----------|-------|--------|
| 4.A | Architecture | Dashboard metrics, query optimization | [ ] |
| 4.B | Dashboard API | GET /api/admin/dashboard with key metrics | [ ] |
| 4.C | Member Ranking | Top spenders, inactive members | [ ] |
| 4.D | Terminal Activity | Transaction volume, peak times | [ ] |
| 4.E | Reports Generation | Customizable report queries | [ ] |
| 4.F | Audit Log API | Query audit trail with filters | [ ] |
| 4.G | API Tests | Tests for all report endpoints | [ ] |

### Related Use Cases

- UC-A50: Reports
- UC-A51: Member Ranking
- UC-A52: Terminal Activity
- UC-A80: Dashboard
- UC-A81: Audit Log

### Success Criteria

- [ ] Dashboard returns metrics in <500ms
- [ ] Member ranking queries efficient (indexed)
- [ ] Audit log queryable by date, user, action
- [ ] 20+ API tests passing

---

## Module 5: Card Management & Blocking (P1 — Lower Priority)

### Objective

Track unassigned RFID cards and enable card blocking for lost/stolen cards.

### Scope

**Milestones**:

| # | Milestone | Scope | Status |
|---|-----------|-------|--------|
| 5.A | Architecture | Card blocking strategy | [ ] |
| 5.B | Database Schema | Extend cards table with blocked_at, reason | [ ] |
| 5.C | Card Blocking API | POST endpoint to block card | [ ] |
| 5.D | Unassigned Cards List | GET endpoint to list unassigned cards | [ ] |
| 5.E | Terminal Integration | Terminal blocks banned cards on sync | [ ] |
| 5.F | API Tests | Tests for blocking, unassigned card list | [ ] |

### Related Use Cases

- UC-A70: Unassigned Cards
- UC-A71: Block Card

### Success Criteria

- [ ] Blocked cards rejected by terminal
- [ ] Unassigned cards tracked and queryable
- [ ] 10+ API tests passing

---

## Cross-Cutting Concerns (Apply to All Modules)

### 1. Pattern Compliance

All modules must follow backend patterns:
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
- Unit tests (PHPUnit) for services and repositories
- API tests (Playwright) for all endpoints
- Validation tests (required/optional fields)
- Error scenarios (4xx, 5xx responses)
- Authorization tests (authenticated/unauthenticated)

**Target**: 80%+ code coverage per module

### 3. Audit Logging

Every data mutation must log via AuditService:
- CREATE operations
- UPDATE operations with old/new values
- DELETE operations (or deactivations)
- Special actions (settlements, exports, payments)

### 4. Documentation

Each module needs:
- ADR for architectural decisions
- Updated ERMs for new tables
- Updated OpenAPI specs for new endpoints
- Use case fulfillment checklist

---

## Implementation Order

**Recommended sequence** (dependency-aware):

1. **Module 3: Admin/User Management**
   - Required for: Settings, authorization, admin dashboard
   - No dependencies on other new modules
   - Unblocks: Modules 4, 5

2. **Module 2: Advanced Transactions**
   - Extends existing transaction system
   - Required for: Module 1 (settlement calculations)
   - Unblocks: Module 1

3. **Module 1: Settlement System**
   - Largest module; most business-critical
   - Depends on: Module 2, Module 3
   - Final core functionality

4. **Module 5: Card Management**
   - Independent from others
   - Can run in parallel with Module 1
   - Lower priority

5. **Module 4: Reporting & Analytics**
   - Can start once Modules 1, 3 complete
   - Provides read-only queries, no conflicts
   - Builds on existing audit log

---

## Success Criteria (Overall)

All modules complete when:

- [ ] All 5 modules implemented and tested
- [ ] 100+ API tests passing across all modules
- [ ] All patterns followed consistently
- [ ] ADRs written for architectural decisions
- [ ] Database migrations for all schema changes
- [ ] OpenAPI specs updated for all endpoints
- [ ] No console errors or warnings in tests
- [ ] Code review checklist passed
- [ ] Ready for Phase 2.A Terminal UI resumption

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Settlement calculations incorrect | High | Extensive calculation tests; peer review |
| SEPA XML validation failures | High | XSD validation; test with real bank |
| Performance issues with large datasets | Medium | Query optimization; database indexes; caching |
| Incomplete pattern compliance | Medium | Automated linter; peer review checklist |
| Missing test coverage | Medium | Coverage reports; enforce 80% minimum |

---

## References

- [ADR-0018: Modular Architecture](../adr/0018-modular-architecture.md)
- [ADR-0022: Test Strategy and Automation](../adr/0022-test-strategy-and-automation.md)
- [Backend Patterns](../backend/patterns/) — Pattern 001-016
- [Use Cases: Admin](../use-cases/admin/) — UC-A20 through UC-A82
- [Use Cases: SEPA](../use-cases/sepa/) — UC-SEPA-01 through UC-SEPA-09
- [E2E Testing Patterns](../e2etests/patterns/) — Pattern 001-004
