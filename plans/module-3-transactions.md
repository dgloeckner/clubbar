# Module 3: Transactions — Finalization Plan

**Goal**: Complete transaction module with manual corrections and CSV export functionality.

**Status**: ⏸️ PARTIAL — Enhance from Phase 2.A foundation

**Timeline**: 2-3 days

---

## Current State

### ✅ Already Implemented (Phase 2.A)

**TransactionService** (`backend/app/Services/TransactionService.php`):
- `processBatch()` — Process terminal transaction uploads
- `getRecentTransactions()` — Fetch transaction history for display

**Database Schema** (`backend/database/migrations/...create_transactions_table.php`):
- All fields present: transaction_type, notes, related_transaction_id, created_by_admin_id
- Indexes on (member_id, created_at) for efficient queries

**API Tests** (Phase 2.A):
- 25 tests passing for transaction upload and history

### ❌ Missing Implementation

1. **Manual Correction Endpoint** — POST /api/admin/members/{id}/transactions/correct
2. **Export Endpoint** — GET /api/admin/transactions/export
3. **Form Request Validation** — CreateCorrectionRequest, ExportTransactionsRequest
4. **Service Methods** — recordCorrection(), exportTransactions()
5. **API Tests** — Tests for corrections and exports (15+ tests)

---

## Milestone 1: Manual Correction Booking

**Objective**: Enable admin to manually record corrections/adjustments to member balance.

**Related Use Case**: UC-A21 Manual Booking

### Required Implementation

| # | Task | Details | Status |
|---|------|---------|--------|
| 1.A | CreateCorrectionRequest | Form request with validation | [ ] |
| 1.B | recordCorrection() method | Service layer logic | [ ] |
| 1.C | Admin API endpoint | POST /api/admin/members/{id}/transactions/correct | [ ] |
| 1.D | Audit logging | Log correction with reason | [ ] |
| 1.E | API tests | 8+ tests for corrections | [ ] |

### CreateCorrectionRequest Validation

**Fields**:
- `amount_cents` (integer, required)
  - Validation: must be non-zero
  - Message: "Amount cannot be zero"
- `reason` (string, required)
  - Validation: max 255 characters
  - Message: "Reason is required and cannot exceed 255 characters"

### recordCorrection() Implementation

```typescript
// Pseudocode
recordCorrection(memberId, amountCents, reason, adminId)
  1. Validate member exists
  2. Create transaction record:
     - type: "correction"
     - amount_cents: amountCents
     - notes: reason
     - related_transaction_id: null (standalone correction)
     - created_by_admin_id: adminId
  3. Update member balance (sum all unsettled transactions)
  4. Log audit entry (AuditAction::CREATE, notes: reason)
  5. Return created transaction
```

### API Endpoint

**POST /api/admin/members/{memberId}/transactions/correct**

Request:
```json
{
  "amount_cents": -350,
  "reason": "Refund for duplicate charge"
}
```

Response (201 Created):
```json
{
  "id": "uuid",
  "member_id": "uuid",
  "amount_cents": -350,
  "transaction_type": "correction",
  "notes": "Refund for duplicate charge",
  "created_by_admin_id": "uuid",
  "created_at": "2026-01-25T15:30:00Z"
}
```

### Test Cases (8 tests)

1. Positive amount: creates charge (balance increases)
2. Negative amount: creates credit (balance decreases)
3. Zero amount: validation error 422
4. Missing reason: validation error 422
5. Member not found: 404 error
6. Reason exceeds 255 chars: 422 validation
7. Transaction created: appears in history
8. Balance updated: verify sum includes correction

---

## Milestone 2: Transaction Export

**Objective**: Enable admin to export transactions as CSV for reconciliation.

**Related Use Case**: UC-A22 Export Transactions

### Required Implementation

| # | Task | Details | Status |
|---|------|---------|--------|
| 2.A | ExportTransactionsRequest | Query params validation | [ ] |
| 2.B | exportTransactions() method | Service layer logic | [ ] |
| 2.C | Admin API endpoint | GET /api/admin/transactions/export | [ ] |
| 2.D | CSV generation | Format per spec | [ ] |
| 2.E | Audit logging | Log export action | [ ] |
| 2.F | API tests | 7+ tests for exports | [ ] |

### ExportTransactionsRequest Validation

**Query Parameters**:
- `from_date` (date, required)
  - Format: YYYY-MM-DD
  - Validation: valid date
- `to_date` (date, required)
  - Format: YYYY-MM-DD
  - Validation: valid date, >= from_date
- `member_id` (UUID, optional)
  - Validation: if provided, member must exist
- `product_id` (UUID, optional)
  - Validation: if provided, product must exist
- `type` (string, optional)
  - Values: purchase, correction, all
  - Default: all

### exportTransactions() Implementation

```typescript
// Pseudocode
exportTransactions(fromDate, toDate, filters = {})
  1. Build query:
     - WHERE created_at >= fromDate AND created_at <= toDate
     - Add filters (member_id, product_id, type)
  2. Fetch transactions with joins to:
     - members (for name)
     - products (for name)
  3. Format each row:
     - date: ISO timestamp
     - member_name: Full name
     - product: Product name (or empty for corrections)
     - type: purchase/correction
     - amount: Signed decimal (EUR)
  4. Generate CSV content
  5. Log audit entry (AuditAction::EXPORT)
  6. Return CSV stream with filename
```

### CSV Format

**Columns**:
- date (ISO 8601 timestamp)
- member_name (full name)
- product (product name, or empty)
- type (purchase/correction)
- amount (decimal, e.g., 3.50)

**Example**:
```csv
date;member_name;product;type;amount
2026-01-25 14:23:00;Max Mustermann;Beer 0.5L;purchase;3.50
2026-01-25 14:25:00;Max Mustermann;;correction;-3.50
2026-01-25 15:10:00;Anna Müller;Wine Glass;purchase;4.20
```

**Filename**: `transactions-YYYY-MM-DD-to-YYYY-MM-DD.csv`

### API Endpoint

**GET /api/admin/transactions/export**

Query string:
```
?from_date=2026-01-01&to_date=2026-01-31&member_id=uuid&type=all
```

Response (200 OK):
- Content-Type: text/csv
- Content-Disposition: attachment; filename="transactions-2026-01-01-to-2026-01-31.csv"
- Body: CSV content

### Test Cases (7 tests)

1. Export all: all transactions in range included
2. Filter by member: only that member's transactions
3. Filter by product: only that product
4. Filter by type: only purchases or corrections
5. Date range: respects from_date and to_date
6. Empty result: returns CSV with headers only
7. File format: valid CSV, correct encoding, headers present

---

## Implementation Order

### Phase 1: Setup (Day 1)

1. Create CreateCorrectionRequest class
2. Add recordCorrection() to TransactionService
3. Create ExportTransactionsRequest class
4. Add exportTransactions() to TransactionService

### Phase 2: Endpoints (Day 1)

5. Create admin controller methods:
   - `correctTransaction()` — POST /api/admin/members/{id}/transactions/correct
   - `exportTransactions()` — GET /api/admin/transactions/export
6. Add routes for both endpoints
7. Integrate service methods into controllers

### Phase 3: Testing & Refinement (Days 2-3)

8. Write 8 tests for manual corrections
9. Write 7 tests for exports
10. Fix any issues found in testing
11. Verify all patterns followed

---

## Files to Create/Modify

### New Files

```
backend/app/Http/Modules/Members/Requests/CreateCorrectionRequest.php
backend/app/Http/Modules/Members/Requests/ExportTransactionsRequest.php
```

### Modify Existing

```
backend/app/Services/TransactionService.php
  + recordCorrection()
  + exportTransactions()

backend/app/Http/Modules/Members/Controllers/AdminController.php
  + correctTransaction() method
  + exportTransactions() method

backend/app/Http/Modules/Members/routes/admin.php
  + POST /api/admin/members/{id}/transactions/correct
  + GET /api/admin/transactions/export

e2etests/tests/api/members.spec.ts
  + 15+ new test cases
```

---

## Pattern Compliance

### Pattern 001: Form Requests
- ✅ CreateCorrectionRequest with validation rules and messages
- ✅ ExportTransactionsRequest with query param validation

### Pattern 003: DTOs
- ✅ Return transaction DTO from correction endpoint
- ✅ CSV stream for export (special case)

### Pattern 004: Service Layer
- ✅ recordCorrection() in TransactionService
- ✅ exportTransactions() in TransactionService

### Pattern 006: Thin Controllers
- ✅ Controllers delegate to service methods
- ✅ Only HTTP routing, no business logic

### Pattern 016: Audit Logging
- ✅ Log CREATE when correction is recorded
- ✅ Log EXPORT when CSV is generated

---

## Success Criteria

- [ ] CreateCorrectionRequest created with validation
- [ ] recordCorrection() method implemented in service
- [ ] POST /api/admin/members/{id}/transactions/correct endpoint works
- [ ] ExportTransactionsRequest created with query param validation
- [ ] exportTransactions() method implemented in service
- [ ] GET /api/admin/transactions/export endpoint returns CSV
- [ ] CSV format matches spec (date, member_name, product, type, amount)
- [ ] All 15+ API tests passing
- [ ] Corrections appear in transaction history
- [ ] Balance updated after correction
- [ ] Audit log records corrections and exports
- [ ] All patterns followed (001, 003, 004, 006, 016)

---

## Testing Commands

```bash
# Run all transaction tests
cd e2etests
npm test -- --grep "Transactions|Corrections|Export" --workers=1

# After implementation:
npm test -- --grep "POST /api/admin/members/.*/transactions/correct"
npm test -- --grep "GET /api/admin/transactions/export"
```

---

## Dependencies

- ✅ Phase 1: Backend Foundation (auth, audit logging)
- ✅ Phase 3: Products Module (product names for exports)
- ✅ Phase 2.A: TransactionService (processBatch, getRecentTransactions)

---

## References

- UC-A21: Manual Booking — Correction transaction requirements
- UC-A22: Export Transactions — CSV export requirements
- ADR-0004: Immutable Transaction Storage — No updates/deletes
- Pattern 001-016: Backend code patterns
