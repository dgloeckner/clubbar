# Pattern 005: Repository Interface for Data Access

**Category**: Data Access & Persistence
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Independence), ADR-0004 (Immutable Storage)

---

## Problem

Without repositories, data access logic is scattered throughout the application:

```php
// ❌ Problematic: Direct model access from services
class SyncService
{
    public function syncMembers(DateTimeImmutable $since): SyncResultDto
    {
        // Direct Eloquent query in service
        $members = Member::where('updated_at', '>=', $since)
            ->limit(100)
            ->get();

        // Manual mapping
        $dtos = array_map(fn($m) => new MemberDto(...), $members);
        return new SyncResultDto($dtos, $cursor, $hasMore);
    }
}

// Duplicate logic elsewhere
class AdminService
{
    public function getActiveMembers()
    {
        // Same query pattern repeated
        $members = Member::where('is_active', true)
            ->get();
    }
}
```

Issues:
- Data access logic duplicated across services
- Hard to change query strategy (add caching, change ORM, etc.)
- Difficult to unit test (requires database mocking)
- Services tightly coupled to Eloquent
- No abstraction layer for persistence

---

## Solution

Use **Repository Interfaces** to:
- Abstract data access behind interfaces
- Decouple services from persistence implementation (Eloquent, raw SQL, etc.)
- Centralize query logic
- Enable easy mocking in tests
- Allow swapping implementations (e.g., cache layer, different DB)

---

## Implementation Pattern

### Repository Interface

```php
// app/Repositories/MemberRepository.php
<?php

namespace App\Repositories;

use App\DTOs\MemberDto;
use App\DTOs\SyncResultDto;
use App\Enums\SupportedLanguage;
use DateTimeImmutable;

interface MemberRepository
{
    /**
     * Get all members modified since a timestamp (sync operation)
     */
    public function getModifiedSince(DateTimeImmutable $since): SyncResultDto;

    /**
     * Update member's preferred language
     */
    public function updateLanguage(
        string $memberId,
        SupportedLanguage $language
    ): MemberDto;

    /**
     * Find member by ID (or null if not found)
     */
    public function findById(string $id): ?MemberDto;

    /**
     * Get active members
     */
    public function getActive(): array; // Array of MemberDto
}
```

### Eloquent Implementation

```php
// app/Repositories/Eloquent/EloquentMemberRepository.php
<?php

namespace App\Repositories\Eloquent;

use App\DTOs\MemberDto;
use App\DTOs\SyncResultDto;
use App\Enums\SupportedLanguage;
use App\Models\Member;
use App\Repositories\MemberRepository;
use DateTimeImmutable;

final readonly class EloquentMemberRepository implements MemberRepository
{
    public function __construct(
        private Member $model,
    ) {}

    public function getModifiedSince(DateTimeImmutable $since): SyncResultDto
    {
        // Encapsulate query logic here
        const BATCH_SIZE = 100;

        $query = $this->model
            ->where('updated_at', '>=', $since->format('Y-m-d H:i:s'))
            ->orderBy('updated_at', 'asc')
            ->orderBy('id', 'asc');

        // Fetch one extra to determine hasMore
        $members = $query->limit(BATCH_SIZE + 1)->get();
        $hasMore = count($members) > BATCH_SIZE;

        if ($hasMore) {
            $members = $members->slice(0, BATCH_SIZE);
        }

        // Generate cursor for next request
        $lastMember = $members->last();
        $cursor = $lastMember ? $lastMember->updated_at->format('Y-m-d\TH:i:s\Z') : 'end';

        $dtos = $members->map(fn($m) => $this->modelToDto($m))->toArray();

        return new SyncResultDto(
            items: $dtos,
            cursor: $cursor,
            hasMore: $hasMore,
        );
    }

    public function updateLanguage(
        string $memberId,
        SupportedLanguage $language
    ): MemberDto {
        $member = $this->model->findOrFail($memberId);

        // Update database
        $member->update([
            'preferred_language' => $language->value,
        ]);

        // Refresh and return DTO
        $member->refresh();
        return $this->modelToDto($member);
    }

    public function findById(string $id): ?MemberDto
    {
        $member = $this->model->find($id);
        return $member ? $this->modelToDto($member) : null;
    }

    public function getActive(): array
    {
        return $this->model
            ->where('is_active', true)
            ->get()
            ->map(fn($m) => $this->modelToDto($m))
            ->toArray();
    }

    private function modelToDto(Member $member): MemberDto
    {
        return new MemberDto(
            id: $member->id,
            cardUid: $member->card_uid,
            firstName: $member->first_name,
            lastName: $member->last_name,
            preferredLanguage: $member->preferred_language,
            isActive: $member->is_active,
            isSepaValid: $member->is_sepa_valid,
            deletedAt: $member->deleted_at ? new DateTimeImmutable($member->deleted_at) : null,
            createdAt: new DateTimeImmutable($member->created_at),
            updatedAt: new DateTimeImmutable($member->updated_at),
        );
    }
}
```

### Other Repository Interfaces

```php
// app/Repositories/CategoryRepository.php
<?php

namespace App\Repositories;

use App\DTOs\SyncResultDto;
use DateTimeImmutable;

interface CategoryRepository
{
    public function getModifiedSince(DateTimeImmutable $since): SyncResultDto;
}

// app/Repositories/ProductRepository.php
<?php

namespace App\Repositories;

use App\DTOs\SyncResultDto;
use DateTimeImmutable;

interface ProductRepository
{
    public function getModifiedSince(DateTimeImmutable $since): SyncResultDto;
}

// app/Repositories/TransactionRepository.php
<?php

namespace App\Repositories;

use App\DTOs\TransactionBatchResultDto;

interface TransactionRepository
{
    public function insertBatch(array $transactions): TransactionBatchResultDto;
}
```

---

## Service Using Repository

Services depend on interfaces, not implementations:

```php
// app/Services/SyncService.php
<?php

namespace App\Services;

use App\Repositories\MemberRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\DTOs\SyncResultDto;
use DateTimeImmutable;

final readonly class SyncService
{
    public function __construct(
        private MemberRepository $members,         // Interface, not concrete class
        private CategoryRepository $categories,
        private ProductRepository $products,
    ) {}

    public function syncMembers(DateTimeImmutable $since): SyncResultDto
    {
        // Call repository; don't know/care about implementation
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
}
```

---

## Dependency Injection / Service Provider

Wire interfaces to implementations in service provider:

```php
// app/Providers/RepositoryServiceProvider.php
<?php

namespace App\Providers;

use App\Repositories\CategoryRepository;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use App\Repositories\Eloquent\EloquentMemberRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Repositories\Eloquent\EloquentTransactionRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ProductRepository;
use App\Repositories\TransactionRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        MemberRepository::class => EloquentMemberRepository::class,
        CategoryRepository::class => EloquentCategoryRepository::class,
        ProductRepository::class => EloquentProductRepository::class,
        TransactionRepository::class => EloquentTransactionRepository::class,
    ];
}
```

### Usage in Controllers

```php
// Automatic injection via Laravel container
public function __construct(
    private SyncService $syncService,  // Depends on MemberRepository
) {}

// Container resolves the dependency chain:
// Controller → SyncService → MemberRepository → EloquentMemberRepository
```

---

## Testing with Mocked Repository

Repositories enable easy unit testing:

```php
// tests/Unit/Services/SyncServiceTest.php
<?php

use App\DTOs\MemberDto;
use App\DTOs\SyncResultDto;
use App\Repositories\MemberRepository;
use App\Services\SyncService;
use PHPUnit\Framework\TestCase;

class SyncServiceTest extends TestCase
{
    public function test_sync_members_returns_dto(): void
    {
        // Create mock repository
        $memberRepo = $this->createMock(MemberRepository::class);

        $memberDto = new MemberDto(
            id: 'member123',
            cardUid: null,
            firstName: 'John',
            lastName: 'Doe',
            preferredLanguage: 'de',
            isActive: true,
            isSepaValid: false,
            deletedAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        // Mock getModifiedSince to return test data
        $memberRepo->expects($this->once())
            ->method('getModifiedSince')
            ->willReturn(new SyncResultDto(
                items: [$memberDto],
                cursor: 'end',
                hasMore: false,
            ));

        $service = new SyncService($memberRepo);

        $result = $service->syncMembers(new DateTimeImmutable('1970-01-01T00:00:00Z'));

        $this->assertInstanceOf(SyncResultDto::class, $result);
        $this->assertCount(1, $result->items);
    }
}
```

---

## Alternative Implementation (Cache Layer)

Repositories enable swapping implementations:

```php
// app/Repositories/Cached/CachedMemberRepository.php
<?php

namespace App\Repositories\Cached;

use App\DTOs\MemberDto;
use App\DTOs\SyncResultDto;
use App\Repositories\MemberRepository;
use App\Repositories\Eloquent\EloquentMemberRepository;
use Illuminate\Cache\Repository as Cache;

final readonly class CachedMemberRepository implements MemberRepository
{
    public function __construct(
        private MemberRepository $inner,  // Wrap Eloquent repo
        private Cache $cache,
    ) {}

    public function getModifiedSince(DateTimeImmutable $since): SyncResultDto
    {
        // Cache key based on cursor
        $cacheKey = "sync:members:{$since->format('Y-m-d')}";

        return $this->cache->remember(
            $cacheKey,
            3600, // 1 hour
            fn() => $this->inner->getModifiedSince($since)
        );
    }

    // ... other methods delegate to inner repository
}
```

Then swap in service provider:

```php
// In RepositoryServiceProvider
$this->app->bind(
    MemberRepository::class,
    function ($app) {
        return new CachedMemberRepository(
            new EloquentMemberRepository($app->make(Member::class)),
            $app->make(Cache::class),
        );
    }
);
```

---

## Benefits

✅ **Abstraction**: Services don't know data access details
✅ **Testability**: Easy to mock repositories in tests
✅ **Flexibility**: Swap implementations (Eloquent, raw SQL, cache, API calls)
✅ **Centralization**: Query logic in one place
✅ **Reusability**: Multiple services can use same repository
✅ **Isolation**: Changes to queries don't affect services
✅ **Consistency**: All data access returns same DTO types

---

## When to Use

- All data access from services
- Complex queries (pagination, filtering, sorting)
- Logic that might change implementation (caching, external API)
- Operations used by multiple services

---

## When NOT to Use

- Simple one-off queries (can use Eloquent directly from controller)
- CRUD operations that don't need abstraction (rare in practice)

---

## Consistency with Modularity (ADR-0018)

Repositories are **module-scoped**:
- Located in `app/Repositories/` or within module
- Interfaces in main directory; implementations in `Eloquent/` subdirectory
- Each module owns repositories for its entities
- Shared repositories in common location

---

## Related to Immutability (ADR-0004)

Repositories enforce immutability:
- Transaction repository only allows INSERT (never UPDATE/DELETE)
- Settlement repository manages settlement_items (immutable link)
- Query logic respects append-only constraint

---

## Related Patterns

- **Pattern 003**: Data Transfer Objects (repositories return DTOs)
- **Pattern 004**: Service Layer (services use repositories)
- **Pattern 007**: Exception Handler (repositories throw domain exceptions)

---

## References

- [Repository Pattern - Microsoft Learn](https://learn.microsoft.com/en-us/dotnet/architecture/microservices/microservice-ddd-cqrs-patterns/infrastructure-persistence-layer-design)
- [The Repository Pattern - Eloquent](https://laravel.com/docs/eloquent#repositories)
- [Dependency Inversion Principle](https://en.wikipedia.org/wiki/Dependency_inversion_principle)
