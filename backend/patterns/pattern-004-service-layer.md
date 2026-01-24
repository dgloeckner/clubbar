# Pattern 004: Service Layer for Business Logic

**Category**: Application Layer & Separation of Concerns
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Clean Separation)

---

## Problem

Without a service layer, business logic is scattered across controllers:

```php
// ❌ Problematic: Mixed concerns
public function updateLanguage(UpdateLanguageRequest $request, string $memberId): JsonResponse
{
    // HTTP parsing
    $language = SupportedLanguage::from($request->input('preferred_language'));

    // Business logic (should be here??)
    $member = Member::findOrFail($memberId);
    $member->update(['preferred_language' => $language->value]);

    // Audit logging (mixed in)
    Log::info('Member language updated', ['member_id' => $memberId]);

    // Response serialization
    return response()->json(['preferred_language' => $member->preferred_language]);
}

// Repeated in another controller
public function batchUpdateLanguages(array $updates): JsonResponse
{
    foreach ($updates as $memberId => $language) {
        // Same business logic duplicated!
        $member = Member::findOrFail($memberId);
        $member->update(['preferred_language' => $language->value]);
        Log::info('Member language updated', ['member_id' => $memberId]);
    }
    // ...
}
```

Issues:
- Business logic scattered across multiple controllers
- Difficult to test logic (requires HTTP context)
- Duplicated logic across endpoints
- Hard to reuse logic from other services
- Controller becomes fat and hard to understand

---

## Solution

Use **Service Layer** to:
- Encapsulate business logic in dedicated classes
- Accept domain objects (not HTTP requests)
- Return DTOs (not HTTP responses)
- Hide data access complexity
- Enable reuse across multiple controllers/consumers

---

## Implementation Pattern

### Service Interface

```php
// app/Services/SyncService.php
<?php

namespace App\Services;

use App\DTOs\MemberDto;
use App\DTOs\SyncResultDto;
use App\Enums\SupportedLanguage;
use DateTimeImmutable;

interface SyncService
{
    public function syncMembers(DateTimeImmutable $since): SyncResultDto;

    public function syncCategories(DateTimeImmutable $since): SyncResultDto;

    public function syncProducts(DateTimeImmutable $since): SyncResultDto;

    public function updateMemberLanguage(
        string $memberId,
        SupportedLanguage $language
    ): MemberDto;
}
```

### Service Implementation

```php
// app/Services/DefaultSyncService.php
<?php

namespace App\Services;

use App\DTOs\MemberDto;
use App\DTOs\SyncResultDto;
use App\Enums\SupportedLanguage;
use App\Repositories\MemberRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use DateTimeImmutable;

final readonly class DefaultSyncService implements SyncService
{
    public function __construct(
        private MemberRepository $members,
        private CategoryRepository $categories,
        private ProductRepository $products,
    ) {}

    public function syncMembers(DateTimeImmutable $since): SyncResultDto
    {
        // Delegate to repository; service orchestrates
        return $this->members->getModifiedSince($since);
    }

    public function syncCategories(DateTimeImmutable $since): SyncResultDto
    {
        return $this->categories->getModifiedSince($since);
    }

    public function syncProducts(DateTimeImmutable $since): SyncResultDto
    {
        return $this->products->getModifiedSince($since);
    }

    public function updateMemberLanguage(
        string $memberId,
        SupportedLanguage $language
    ): MemberDto {
        // Business logic: update and return DTO
        return $this->members->updateLanguage($memberId, $language);
    }
}
```

### Complex Service Logic

```php
// app/Services/TransactionService.php
<?php

namespace App\Services;

use App\DTOs\TransactionBatchResultDto;
use App\Repositories\TransactionRepository;
use App\Repositories\MemberRepository;

final readonly class TransactionService
{
    public function __construct(
        private TransactionRepository $transactions,
        private MemberRepository $members,
    ) {}

    public function processBatch(array $transactions): TransactionBatchResultDto
    {
        $accepted = [];
        $errors = [];

        foreach ($transactions as $idx => $txn) {
            try {
                // Business rule: validate member exists
                $member = $this->members->findById($txn['member_id']);
                if (!$member) {
                    $errors[] = [
                        'index' => $idx,
                        'error' => 'member_not_found',
                    ];
                    continue;
                }

                // Business rule: validate amount positive
                if ($txn['amount_cents'] <= 0) {
                    $errors[] = [
                        'index' => $idx,
                        'error' => 'invalid_amount',
                    ];
                    continue;
                }

                // Attempt insert
                $this->transactions->insert($txn);
                $accepted[] = $txn['id'];

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $idx,
                    'error' => 'insert_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return new TransactionBatchResultDto(
            acceptedIds: $accepted,
            rejectedCount: count($errors),
            errors: $errors,
        );
    }
}
```

---

## Service Dependencies

Services should depend on **Repositories** and **other Services**, never on Eloquent Models or Controllers:

```php
// ✅ Correct dependencies
final readonly class SettlementService
{
    public function __construct(
        private TransactionRepository $transactions,    // Repository
        private SettlementRepository $settlements,      // Repository
        private AuditLogger $logger,                    // Cross-cutting concern
    ) {}
}

// ❌ Avoid
final class SettlementService
{
    public function __construct(
        private Transaction $model,        // Don't depend on Eloquent directly
        private SettlementController $ctrl, // Don't depend on controllers
    ) {}
}
```

---

## Controller Using Service

Controllers become thin **HTTP routers**:

```php
// app/Http/Controllers/SyncController.php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLanguageRequest;
use App\Http\Requests\SyncRequest;
use App\Http\Requests\UploadTransactionsRequest;
use App\Services\SyncService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

final class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly TransactionService $transactionService,
    ) {}

    public function members(SyncRequest $request): JsonResponse
    {
        // Validate (FormRequest) → Call Service → Serialize (DTO) → Return
        $result = $this->syncService->syncMembers($request->since());

        return response()->json($result->toResponse('members'));
    }

    public function updateLanguage(
        UpdateLanguageRequest $request,
        string $memberId
    ): JsonResponse {
        // Single responsibility: HTTP parsing → Service call → HTTP response
        $member = $this->syncService->updateMemberLanguage(
            $memberId,
            $request->preferredLanguage()
        );

        return response()->json([
            'member' => $member->toArray(),
            'updated_at' => $member->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function transactions(UploadTransactionsRequest $request): JsonResponse
    {
        $result = $this->transactionService->processBatch(
            $request->validated('transactions')
        );

        return response()->json($result->toArray());
    }
}
```

---

## Testing Services

Services are easy to unit test (no HTTP context):

```php
// tests/Unit/Services/TransactionServiceTest.php
<?php

use App\DTOs\TransactionBatchResultDto;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;

class TransactionServiceTest extends TestCase
{
    private TransactionService $service;

    protected function setUp(): void
    {
        // Mock repositories
        $transactionRepo = $this->createMock(TransactionRepository::class);
        $memberRepo = $this->createMock(MemberRepository::class);

        $this->service = new TransactionService($transactionRepo, $memberRepo);
    }

    public function test_process_batch_accepts_valid_transactions(): void
    {
        $txns = [
            [
                'id' => 'uuid1',
                'member_id' => 'member123',
                'product_id' => 'product456',
                'amount_cents' => 350,
                'created_at' => '2026-01-24T12:00:00Z',
            ],
        ];

        $result = $this->service->processBatch($txns);

        $this->assertInstanceOf(TransactionBatchResultDto::class, $result);
        $this->assertContains('uuid1', $result->acceptedIds);
    }

    public function test_process_batch_rejects_invalid_amounts(): void
    {
        $txns = [
            [
                'amount_cents' => -100, // Negative: invalid for purchase
            ],
        ];

        $result = $this->service->processBatch($txns);

        $this->assertGreaterThan(0, $result->rejectedCount);
    }
}
```

---

## Service Composition

Services can use other services:

```php
// app/Services/SettlementService.php
final readonly class SettlementService
{
    public function __construct(
        private SettlementRepository $settlements,
        private TransactionService $transactions,    // Service dependency
        private SyncService $sync,                   // Service dependency
        private AuditLogger $logger,
    ) {}

    public function createSettlement(
        DateTimeImmutable $executionDate,
        ?string $notes = null
    ): SettlementDto {
        // Call other services to compose logic
        $unsettled = $this->sync->getUnsettledTransactions();

        $settlement = $this->settlements->create([
            'execution_date' => $executionDate,
            'notes' => $notes,
        ]);

        // Log via cross-cutting concern
        $this->logger->logSettlementCreated($settlement);

        return $settlement->toDto();
    }
}
```

---

## Benefits

✅ **Separation of concerns**: Business logic isolated from HTTP
✅ **Reusability**: Logic reused across multiple endpoints/consumers
✅ **Testability**: Easy unit tests without HTTP context
✅ **Maintainability**: Changes to logic in one place
✅ **Composability**: Services can use other services
✅ **Clear API**: Service interface documents available operations
✅ **Dependency injection**: Easy to mock/test dependencies

---

## When to Use

- All business logic beyond simple CRUD
- Logic that could be reused across multiple endpoints
- Complex validation or transformation
- Multi-step operations (saga-like workflows)
- Orchestration across multiple repositories

---

## When NOT to Use

- Simple pass-through operations (rare; most needs composition)
- One-off HTTP-specific logic (belongs in controller/middleware)
- Trivial data retrieval (can call repository directly from controller)

---

## Consistency with Modularity (ADR-0018)

Services are **module-owned**:
- Located in `app/Services/` or within module (e.g., `Modules/TransactionModule/Services/`)
- Named per domain (e.g., `SyncService`, `TransactionService`)
- Each module has own service layer
- Shared services in common location

---

## Related Patterns

- **Pattern 003**: Data Transfer Objects (services return DTOs)
- **Pattern 005**: Repository Interfaces (services depend on repositories)
- **Pattern 006**: Thin Controllers (controllers delegate to services)

---

## References

- [Service Layer Pattern - Martin Fowler](https://martinfowler.com/eaaCatalog/serviceLayer.html)
- [SOLID: Single Responsibility Principle](https://en.wikipedia.org/wiki/SOLID)
