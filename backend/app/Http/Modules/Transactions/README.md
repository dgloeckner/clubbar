# Transactions Module

## Overview

The Transactions module handles all transaction-related operations for both Terminal (sync) and Admin APIs. It implements immutable transaction storage per ADR-0004 and follows modular architecture principles defined in ADR-0018.

**Status**: Refactoring Phase 1 (Controllers)

**Key Patterns**:
- Pattern 001: Form Requests for validation
- Pattern 003: DTOs for responses
- Pattern 004: Service Layer for business logic
- Pattern 005: Repository Pattern for data access
- Pattern 006: Thin Controllers (no business logic)
- Pattern 016: Audit logging for all mutations
- Pattern 009: Module structure (this pattern)

**Key ADRs**:
- ADR-0004: Immutable transaction storage (append-only)
- ADR-0018: Modular admin interface architecture
- ADR-0023: Terminal balance state management
- ADR-0024: Transaction history retrieval (online-only)

---

## Terminal API Endpoints

### POST /api/sync/transactions
**Batch transaction upload from offline terminals**

Accepts batch of up to 100 transactions from offline terminals. Implements idempotent processing: duplicate UUIDs from retries are silently skipped via `insertOrIgnore()`.

**Request**:
```json
{
  "transactions": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "member_id": "550e8400-e29b-41d4-a716-446655440001",
      "product_id": "550e8400-e29b-41d4-a716-446655440002",
      "amount_cents": 1500,
      "created_at": "2026-01-25T14:30:00Z"
    }
  ]
}
```

**Response** (201 Created):
```json
{
  "accepted_ids": ["550e8400-e29b-41d4-a716-446655440000"],
  "rejected": {
    "count": 0,
    "errors": []
  },
  "member_balances": {
    "550e8400-e29b-41d4-a716-446655440001": 5000
  }
}
```

**Status Codes**:
- `201` - Transactions processed
- `400` - Invalid request structure
- `422` - Validation error (invalid fields)
- `401` - Unauthorized (missing/invalid token)

**Implements**:
- Pattern 001: UploadTransactionsRequest validation
- Pattern 003: TransactionBatchResultDto response
- Pattern 004: TransactionsService business logic
- ADR-0023: Terminal balance state management

---

### GET /api/terminal/transactions/{memberId}
**Retrieve transaction history for a member**

Returns recent transaction history for a member with optional pagination and filtering.

**Query Parameters**:
- `limit` (optional, int): Max transactions to return (default 50, max 100)
- `offset` (optional, int): Pagination offset (default 0)
- `since` (optional, string): ISO 8601 timestamp; return only transactions after this time

**Response** (200 OK):
```json
[
  {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "member_id": "550e8400-e29b-41d4-a716-446655440001",
    "product_id": "550e8400-e29b-41d4-a716-446655440002",
    "amount_cents": 1500,
    "transaction_type": "purchase",
    "notes": null,
    "related_transaction_id": null,
    "created_by_terminal_id": "550e8400-e29b-41d4-a716-446655440003",
    "created_by_admin_id": null,
    "created_at": "2026-01-25T14:30:00Z"
  }
]
```

**Status Codes**:
- `200` - Transaction list retrieved
- `404` - Member not found
- `401` - Unauthorized (missing/invalid token)
- `500` - Server error

**Implements**:
- Pattern 001: Query parameter validation
- Pattern 003: TransactionDto response objects
- Pattern 004: TransactionsService business logic
- ADR-0024: Transaction history retrieval (online-only, no cache)

---

## Admin API Endpoints

### POST /api/admin/members/{memberId}/transactions/correct
**Record manual correction transaction**

Creates a manual booking (correction transaction) for accounting adjustments. The transaction is immutable per ADR-0004; corrections are recorded as separate transactions linked via `related_transaction_id`.

**Request**:
```json
{
  "amount_cents": 1500,
  "reason": "Manual adjustment for overpayment"
}
```

**Response** (201 Created):
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "member_id": "550e8400-e29b-41d4-a716-446655440001",
  "product_id": null,
  "amount_cents": 1500,
  "transaction_type": "correction",
  "notes": "Manual adjustment for overpayment",
  "related_transaction_id": null,
  "created_by_terminal_id": null,
  "created_by_admin_id": "550e8400-e29b-41d4-a716-446655440002",
  "created_at": "2026-01-25T14:30:00Z"
}
```

**Status Codes**:
- `201` - Transaction created
- `404` - Member not found
- `422` - Validation error (invalid fields)
- `401` - Unauthorized (not authenticated)
- `403` - Forbidden (insufficient role)

**Audit Logging**:
- Action: `CREATE`
- Entity Type: `TRANSACTION`
- Values logged: member_id, amount_cents, reason

**Implements**:
- Pattern 001: CreateCorrectionRequest validation
- Pattern 003: TransactionDto response
- Pattern 004: TransactionsService business logic
- Pattern 016: Audit logging for mutation
- UC-A21: Manual Booking

---

### GET /api/admin/transactions/export
**Export transactions as CSV file**

Generates and downloads a CSV file of transactions within a date range with optional filtering.

**Query Parameters**:
- `from_date` (required, string): Start date (YYYY-MM-DD format)
- `to_date` (required, string): End date (YYYY-MM-DD format, >= from_date)
- `member_id` (optional, string): Filter by member UUID (must exist)
- `product_id` (optional, string): Filter by product UUID (must exist)
- `type` (optional, string): Filter by transaction type (`purchase`, `correction`, or `all`)

**Response** (200 OK):
- Content-Type: `text/csv; charset=utf-8`
- Content-Disposition: `attachment; filename="transactions-YYYYMMDD-YYYYMMDD.csv"`
- Body: CSV file with semicolon delimiter

**CSV Format**:
```
member_id;product_id;amount_cents;transaction_type;notes;created_by_terminal_id;created_by_admin_id;created_at
550e8400-e29b-41d4-a716-446655440001;550e8400-e29b-41d4-a716-446655440002;1500;purchase;;550e8400-e29b-41d4-a716-446655440003;;2026-01-25T14:30:00Z
```

**Status Codes**:
- `200` - CSV file generated and downloaded
- `400` - Invalid query parameters
- `422` - Validation error (invalid date format, date range, etc.)
- `401` - Unauthorized (not authenticated)
- `403` - Forbidden (insufficient role)
- `500` - Server error (file generation failed)

**Audit Logging**:
- Action: `EXPORT`
- Entity Type: `TRANSACTION`
- Values logged: from_date, to_date, member_id, product_id, type

**Implements**:
- Pattern 001: ExportTransactionsRequest validation
- Pattern 004: TransactionsService business logic
- Pattern 016: Audit logging for action
- UC-A22: Export Transactions

---

## Code Organization

### Controllers (`Controllers/`)

#### SyncController.php
- `processBatch(UploadTransactionsRequest): JsonResponse` — POST /api/sync/transactions
- `transactionHistory(Request, string): JsonResponse` — GET /api/terminal/transactions/{memberId}

Implements Pattern 006 (Thin Controllers):
- <50 lines per method
- No business logic
- Delegates all logic to TransactionsService
- Handles HTTP request/response only
- Validates UUIDs and query parameters

#### AdminController.php
- `recordCorrection(string, CreateCorrectionRequest): JsonResponse` — POST /api/admin/members/{memberId}/transactions/correct
- `exportTransactions(ExportTransactionsRequest): Response` — GET /api/admin/transactions/export

Implements Pattern 006 (Thin Controllers):
- <50 lines per method
- No business logic
- Delegates all logic to TransactionsService
- Logs audit entries via AuditService (Pattern 016)
- Proper error handling with correct HTTP status codes

---

### Services (`Services/`) — Phase 2

**TransactionsService.php** (to be moved from `app/Services/`)
- `processBatch(array): TransactionBatchResultDto` — Batch processing with idempotency
- `recordCorrection(string, int, string, ?string): array` — Create correction transaction
- `exportTransactions(string, string, ?string, ?string, ?string): array` — Generate CSV
- `getRecentTransactions(string, int, int, ?string): array` — Fetch transaction history

Implements Pattern 004 (Service Layer):
- All business logic isolated from HTTP
- Delegates data access to TransactionsRepository
- Returns DTOs or structured arrays
- Throws exceptions for error handling (Pattern 007)

**TransactionsRepository.php** (to be created)
- `batchInsert(array): array` — Insert batch of transactions (idempotent)
- `findByMemberId(string, int, int, ?int): array` — Query transactions by member
- `insertTransaction(array): array` — Create single transaction
- Query builder methods for filtering

Implements Pattern 005 (Repository Interface):
- All database queries abstracted
- No Eloquent Model (immutability per ADR-0004)
- Extends BaseRepository (Pattern 011)
- No update/delete methods (append-only)

---

### Requests (`Requests/`) — Phase 3

**UploadTransactionsRequest.php** (to be moved)
- Validates transaction batch (1-100 items)
- Validates each transaction: id (UUID), member_id (UUID), product_id (UUID), amount_cents (int >= 1), created_at (ISO 8601)

**CreateCorrectionRequest.php** (to be moved)
- Validates: amount_cents (required, non-zero integer)
- Validates: reason (required, string, max 255)

**ExportTransactionsRequest.php** (to be moved)
- Validates: from_date (required, YYYY-MM-DD)
- Validates: to_date (required, YYYY-MM-DD, >= from_date)
- Validates: member_id (optional, UUID, must exist)
- Validates: product_id (optional, UUID, must exist)
- Validates: type (optional, purchase|correction|all)

All implement Pattern 001 (Form Requests):
- Declarative validation rules
- Typed accessor methods (e.g., `amountCents()`, `fromDate()`)
- Custom error messages
- Consistent with other modules

---

### DTOs (`DTOs/`) — Phase 3

**TransactionBatchResultDto.php** (to be moved from `app/DTOs/`)
- Properties: acceptedIds (array), rejectedCount (int), errors (array), memberBalances (array)
- Method: `toArray(): array` — Serialize to API response

**TransactionDto.php** (to be created)
- Properties: id, memberId, productId, amountCents, transactionType, notes, relatedTransactionId, createdByTerminalId, createdByAdminId, createdAt
- Methods: `from(array)`, `toArray()` — Serialize single transaction

**TransactionHistoryDto.php** (to be created)
- Properties: transactions (array), totalCount (int), limit (int), offset (int)
- Method: `toArray(): array` — Serialize paginated response

All implement Pattern 003 (Data Transfer Objects):
- Type-safe response objects
- Immutable (readonly properties)
- Serialize to API-compatible format
- Used in service return values

---

### Routes (`routes/`)

**terminal.php**
- Registers Terminal API endpoints
- Assumes AuthenticateTerminalToken middleware applied globally

**admin.php**
- Registers Admin API endpoints
- Assumes session auth and admin role middleware applied globally

Both implement Pattern 009 (Module Structure):
- Grouped by functional domain (Transactions)
- Registered in global route aggregation (`routes/modules/transactions.php`)
- Clear endpoint documentation

---

## Integration with Global Routes

Routes are aggregated in the global entry point:

```php
// routes/modules/transactions.php
return array_merge(
    require app_path('Http/Modules/Transactions/routes/terminal.php'),
    require app_path('Http/Modules/Transactions/routes/admin.php'),
);

// routes/api.php
require base_path('routes/modules/transactions.php'); // ← Included here
```

Middleware is applied in global route groups:
```php
Route::prefix('api')
    ->middleware([AuthenticateTerminalToken::class])  // Terminal routes
    ->group(function () {
        // terminal.php routes
    });

Route::prefix('api')
    ->middleware(['auth:session', RequireAdminRole::class])  // Admin routes
    ->group(function () {
        // admin.php routes
    });
```

---

## Dependencies & Bindings

Service provider bindings (to be configured in Phase 4.C):

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(
    TransactionsRepository::class,
    function ($app) {
        return new TransactionsRepository();
    }
);

$this->app->bind(
    TransactionsService::class,
    function ($app) {
        return new TransactionsService(
            $app->make(TransactionsRepository::class)
        );
    }
);
```

---

## Immutability (ADR-0004)

Transactions are append-only:
- No UPDATE operations exist
- No DELETE operations exist
- Corrections are recorded as separate transactions
- `related_transaction_id` field links corrections to original transactions
- TransactionsRepository has NO `update()` or `delete()` methods

---

## Testing Strategy (Phase 5)

### Existing Tests (to be moved)
- Terminal batch upload tests (SyncController)
- Transaction history tests (SyncController)
- Correction booking tests (AdminController)
- CSV export tests (AdminController)

### New Tests (to be added)
- Module route aggregation integration tests
- Authorization tests (token auth, session auth)
- Error scenario tests (404, 422, etc.)
- Audit logging verification tests

### Test Locations
- Unit tests: `tests/Unit/Modules/Transactions/`
- Feature/API tests: `e2etests/tests/api/transactions.spec.ts`

---

## Implementation Progress

| Phase | Task | Status |
|-------|------|--------|
| **1** | Module structure | ✅ DONE (1.A) |
| **1** | SyncController | ✅ DONE (1.B) |
| **1** | AdminController | ✅ DONE (1.C) |
| **1** | Route files | ✅ DONE (1.A) |
| **1** | README.md | ✅ DONE (1.A) |
| **2** | TransactionsService | [ ] TBD (move from app/Services/) |
| **2** | TransactionsRepository | [ ] TBD (create new) |
| **2** | Service bindings | [ ] TBD (update AppServiceProvider) |
| **3** | Move request validators | [ ] TBD (from Members module) |
| **3** | Create/move DTOs | [ ] TBD (from app/DTOs/) |
| **4** | Global route aggregation | [ ] TBD (create routes/modules/transactions.php) |
| **4** | Remove old endpoints | [ ] TBD (cleanup SyncController, Members AdminController) |
| **5** | Update/add tests | [ ] TBD (verify all tests passing) |
| **6** | Remove old code | [ ] TBD (delete orphaned files) |
| **6** | Final documentation | [ ] TBD (verify complete) |

---

## Related Documentation

- [ADR-0004: Immutable Transaction Storage](../../adr/0004-immutable-transaction-storage.md)
- [ADR-0018: Modular Admin Interface Architecture](../../adr/0018-modular-admin-interface-architecture.md)
- [ADR-0023: Terminal Balance State Management](../../adr/0023-terminal-balance-state-management.md)
- [ADR-0024: Transaction History Retrieval](../../adr/0024-transaction-history-retrieval.md)
- [Pattern 001: Form Requests Validation](../../patterns/pattern-001-form-requests-validation.md)
- [Pattern 003: Data Transfer Objects](../../patterns/pattern-003-data-transfer-objects.md)
- [Pattern 004: Service Layer](../../patterns/pattern-004-service-layer.md)
- [Pattern 005: Repository Interface](../../patterns/pattern-005-repository-interface.md)
- [Pattern 006: Thin Controllers](../../patterns/pattern-006-thin-controllers.md)
- [Pattern 009: Module Structure](../../patterns/pattern-009-module-structure-adr-0018.md)
- [Pattern 016: Audit Logging](../../patterns/pattern-016-audit-logging.md)
- [Use Case UC-A21: Manual Booking](../../use-cases/admin/UC-A21-manual-booking.md)
- [Use Case UC-A22: Export Transactions](../../use-cases/admin/UC-A22-export-transactions.md)

---

## Next Steps

1. **Phase 2**: Move/create TransactionsService and TransactionsRepository
2. **Phase 3**: Move request validators and DTOs
3. **Phase 4**: Update global route aggregation and cleanup old code
4. **Phase 5**: Update/verify all tests passing
5. **Phase 6**: Remove old code and final documentation
