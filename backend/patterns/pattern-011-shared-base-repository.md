# Pattern 011: Shared Base Repository

**Status**: Active

**Purpose**: Extract common data access patterns into base repository class to minimize duplication across modules while maintaining abstraction from Eloquent ORM.

---

## Context

When implementing modules (Pattern 009) with repositories (Pattern 005), each module's repository duplicates:
- Standard CRUD queries: findById, findAll, create, update, delete
- Pagination and filtering patterns
- Query builder setup and cleanup
- Error handling for database operations
- Timestamp conversions for delta sync

Without shared abstractions, each module's repository reimplements the same patterns:

```php
// ❌ Duplication in MembersRepository
class MembersRepository
{
    public function findById(string $id) { /* query */ }
    public function findAll() { /* query */ }
    public function create(array $data) { /* query */ }
    public function update(string $id, array $data) { /* query */ }
}

// ❌ Duplication in ProductsRepository (identical structure)
class ProductsRepository
{
    public function findById(string $id) { /* same pattern */ }
    public function findAll() { /* same pattern */ }
    public function create(array $data) { /* same pattern */ }
    public function update(string $id, array $data) { /* same pattern */ }
}
```

This violates DRY principle and creates maintenance burden.

**Solution**: Create `BaseRepository` with standard CRUD methods. Module-specific repositories extend base and add domain-specific queries.

---

## Pattern Definition

### Base Repository Interface

```php
// app/Shared/Repositories/RepositoryInterface.php
namespace App\Shared\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for data access repositories.
 *
 * All module repositories must implement this interface.
 * Enables dependency injection and future abstraction (e.g., swap to different ORM).
 */
interface RepositoryInterface
{
    /**
     * Build query builder for this repository's model.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query();

    /**
     * Find entity by primary key (ID).
     *
     * @param string $id UUID
     * @return Model|null
     */
    public function findById(string $id): ?Model;

    /**
     * Find multiple entities by IDs.
     *
     * @param array $ids
     * @return Collection
     */
    public function findByIds(array $ids): Collection;

    /**
     * Find all entities (no limit).
     *
     * @return Collection
     */
    public function findAll(): Collection;

    /**
     * Create new entity.
     *
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model;

    /**
     * Update entity by ID.
     *
     * @param string $id
     * @param array $attributes
     * @return Model|null
     */
    public function updateById(string $id, array $attributes): ?Model;

    /**
     * Delete entity by ID.
     *
     * @param string $id
     * @return bool True if entity existed and was deleted
     */
    public function deleteById(string $id): bool;

    /**
     * Delete multiple entities by IDs.
     *
     * @param array $ids
     * @return int Number deleted
     */
    public function deleteByIds(array $ids): int;

    /**
     * Count total entities matching criteria.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if entity exists by ID.
     *
     * @param string $id
     * @return bool
     */
    public function exists(string $id): bool;
}
```

### Base Repository Implementation

```php
// app/Shared/Repositories/BaseRepository.php
namespace App\Shared\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base repository implementing standard CRUD operations.
 *
 * Modules extend this and add domain-specific queries.
 *
 * Implements Pattern 005: Repository Interface for Data Access
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * The Eloquent model class for this repository.
     * Must be set by subclass.
     *
     * @var class-string
     */
    protected string $modelClass;

    /**
     * Create new repository for given model.
     *
     * @param Model $model Instance used for query builder
     */
    public function __construct(
        protected readonly Model $model,
    ) {}

    /**
     * Get query builder for this repository.
     *
     * Subclasses can override to add default scopes, eager loading, etc.
     *
     * @return Builder
     */
    public function query(): Builder
    {
        return $this->model->query();
    }

    /**
     * Find entity by primary key.
     *
     * @param string $id UUID
     * @return Model|null
     */
    public function findById(string $id): ?Model
    {
        return $this->query()
            ->where($this->getKeyName(), $id)
            ->first();
    }

    /**
     * Find multiple entities by IDs.
     *
     * @param array $ids
     * @return Collection
     */
    public function findByIds(array $ids): Collection
    {
        return $this->query()
            ->whereIn($this->getKeyName(), $ids)
            ->get();
    }

    /**
     * Find all entities (no pagination).
     *
     * ⚠️ WARNING: Use carefully for large tables. Prefer pagination.
     *
     * @return Collection
     */
    public function findAll(): Collection
    {
        return $this->query()->get();
    }

    /**
     * Create new entity.
     *
     * @param array $attributes Validated data (from FormRequest)
     * @return Model
     */
    public function create(array $attributes): Model
    {
        return $this->model->create($attributes);
    }

    /**
     * Create multiple entities in batch.
     *
     * @param array $items Each item is validated attributes array
     * @return Collection Created models
     */
    public function createMany(array $items): Collection
    {
        $created = collect();

        foreach ($items as $attributes) {
            $created->push($this->create($attributes));
        }

        return $created;
    }

    /**
     * Update entity by ID.
     *
     * @param string $id UUID
     * @param array $attributes Validated data
     * @return Model|null Null if entity not found
     */
    public function updateById(string $id, array $attributes): ?Model
    {
        $entity = $this->findById($id);

        if (!$entity) {
            return null;
        }

        $entity->update($attributes);
        return $entity->fresh();  // Reload from database to get computed fields
    }

    /**
     * Update multiple entities.
     *
     * @param array $items Key-value pairs: ['id' => attributes]
     * @return Collection Updated models
     */
    public function updateMany(array $items): Collection
    {
        $updated = collect();

        foreach ($items as $id => $attributes) {
            $updated->push($this->updateById($id, $attributes));
        }

        return $updated->filter();  // Remove nulls
    }

    /**
     * Delete entity by ID.
     *
     * @param string $id UUID
     * @return bool True if entity existed and was deleted
     */
    public function deleteById(string $id): bool
    {
        $entity = $this->findById($id);

        if (!$entity) {
            return false;
        }

        $entity->delete();
        return true;
    }

    /**
     * Delete multiple entities by IDs.
     *
     * @param array $ids
     * @return int Number of entities deleted
     */
    public function deleteByIds(array $ids): int
    {
        return $this->query()
            ->whereIn($this->getKeyName(), $ids)
            ->delete();
    }

    /**
     * Count total entities.
     *
     * Subclasses can override to add default filtering.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Check if entity exists by ID.
     *
     * @param string $id UUID
     * @return bool
     */
    public function exists(string $id): bool
    {
        return $this->query()
            ->where($this->getKeyName(), $id)
            ->exists();
    }

    /**
     * Get the primary key name for this model.
     * Override if using non-standard key name.
     *
     * @return string
     */
    protected function getKeyName(): string
    {
        return $this->model->getKeyName();
    }

    /**
     * Eager load relationships on query.
     * Override in subclass to add common relationships.
     *
     * Example in MembersRepository:
     *   protected function with(): array {
     *       return ['transactions', 'bookings'];
     *   }
     *
     * @return array
     */
    protected function with(): array
    {
        return [];
    }
}
```

### Module-Specific Repository (Members Example)

```php
// app/Http/Modules/Members/Repositories/MembersRepository.php
namespace App\Http\Modules\Members\Repositories;

use App\Http\Modules\Members\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Transaction;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Members repository: extends BaseRepository with Members-specific queries.
 *
 * Inherits standard CRUD from BaseRepository:
 * - findById()
 * - findAll()
 * - create()
 * - updateById()
 * - deleteById()
 *
 * Implements Members-specific data access:
 * - findModifiedSince()
 * - findActiveSince()
 * - getTransactionHistory()
 * - getBookingHistory()
 * - anonymize()
 */
final class MembersRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Member());
    }

    /**
     * Terminal: Find members modified since timestamp (delta sync).
     *
     * @param int $sinceTimestamp Unix timestamp
     * @return Collection
     */
    public function findModifiedSince(int $sinceTimestamp): Collection
    {
        $since = \DateTime::createFromFormat('U', (string)$sinceTimestamp);

        return $this->query()
            ->where('updated_at', '>=', $since)
            ->orderBy('updated_at', 'asc')
            ->get();
    }

    /**
     * Admin: Find members active since date.
     *
     * @param int $sinceTimestamp Unix timestamp
     * @return Collection
     */
    public function findActiveSince(int $sinceTimestamp): Collection
    {
        $since = \DateTime::createFromFormat('U', (string)$sinceTimestamp);

        return $this->query()
            ->where('is_active', true)
            ->where('created_at', '>=', $since)
            ->get();
    }

    /**
     * Admin: Get transaction history for member.
     *
     * @param string $memberId
     * @return Collection
     */
    public function getTransactionHistory(string $memberId): Collection
    {
        return Transaction::query()
            ->where('member_id', $memberId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Admin: Get booking history for member.
     *
     * @param string $memberId
     * @return Collection
     */
    public function getBookingHistory(string $memberId): Collection
    {
        // Assuming booking table exists
        return \DB::table('bookings')
            ->where('member_id', $memberId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Admin: Anonymize member data (GDPR Art. 17).
     *
     * @param string $memberId
     * @return void
     */
    public function anonymize(string $memberId): void
    {
        $this->updateById($memberId, [
            'first_name' => 'Deleted',
            'last_name' => 'User',
            'email' => null,
            'phone' => null,
            'iban' => null,
            'mandate_reference' => null,
            'is_active' => false,
        ]);
    }

    /**
     * Override to add eager loading of related data.
     * Automatically loads transactions with each query.
     */
    protected function with(): array
    {
        return [];  // Add 'transactions' if needed for all queries
    }
}
```

### Another Repository Example (Products)

```php
// app/Http/Modules/Products/Repositories/ProductsRepository.php
namespace App\Http\Modules\Products\Repositories;

use App\Models\Product;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Products repository: extends BaseRepository.
 *
 * Products-specific queries:
 * - findByCategory()
 * - findActive()
 */
final class ProductsRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Product());
    }

    /**
     * Find all active products in category.
     *
     * @param string $categoryId
     * @return Collection
     */
    public function findByCategory(string $categoryId): Collection
    {
        return $this->query()
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('display_name', 'asc')
            ->get();
    }

    /**
     * Terminal: Find active products modified since timestamp.
     *
     * @param int $sinceTimestamp
     * @return Collection
     */
    public function findActiveModifiedSince(int $sinceTimestamp): Collection
    {
        $since = \DateTime::createFromFormat('U', (string)$sinceTimestamp);

        return $this->query()
            ->where('is_active', true)
            ->where('updated_at', '>=', $since)
            ->get();
    }
}
```

---

## Usage in Services

Services use repositories for data access, then transform to DTOs:

```php
// app/Http/Modules/Members/Services/MembersService.php
final class MembersService extends BaseService  // Extends Pattern 010: BaseService
{
    public function __construct(
        private readonly MembersRepository $repository,
    ) {
        parent::__construct($repository);
    }

    /**
     * Terminal: Sync members modified since timestamp.
     */
    public function syncSince(int $sinceTimestamp): SyncResultDto
    {
        // Use repository-specific method
        $members = $this->repository->findModifiedSince($sinceTimestamp);

        // Transform to DTOs
        $dtos = $members->map(fn($m) => MemberDto::from($m))->toArray();

        return new SyncResultDto('members', $dtos);
    }

    /**
     * Admin: Export member data (GDPR).
     */
    public function exportGDPR(string $memberId): GDPRExportDto
    {
        // Use multiple repository methods
        $member = $this->repository->findById($memberId);
        $transactions = $this->repository->getTransactionHistory($memberId);
        $bookings = $this->repository->getBookingHistory($memberId);

        return new GDPRExportDto(
            member: MemberDto::from($member),
            transactions: $transactions,
            bookings: $bookings,
        );
    }

    // Hook implementations for BaseService
    protected function transform(Model $entity): MemberDto
    {
        return MemberDto::from($entity);
    }
}
```

---

## Service Provider Bindings

Register repositories in AppServiceProvider:

```php
// app/Providers/AppServiceProvider.php
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Members Module
        $this->app->singleton(MembersRepository::class);
        $this->app->singleton(MembersService::class, function ($app) {
            return new MembersService(
                $app->make(MembersRepository::class),
            );
        });

        // Products Module
        $this->app->singleton(ProductsRepository::class);
        $this->app->singleton(ProductsService::class, function ($app) {
            return new ProductsService(
                $app->make(ProductsRepository::class),
            );
        });
    }
}
```

---

## Key Design Decisions

### 1. **Eloquent as Implementation Detail**

Base repository hides Eloquent from services. Services call `repository->findById()`, not `Model::find()`.

**Benefit**: Can swap ORM without changing service code.

```php
// ✅ Good: Service depends on repository interface
class MembersService {
    public function __construct(private MembersRepository $repo) {}
}

// ❌ Bad: Service depends on Eloquent
class MembersService {
    public function syncMembers() {
        return Member::where(...)  // Tightly coupled
    }
}
```

### 2. **Query Builder Access**

Base repository exposes `query()` method for complex queries:

```php
// Simple queries use specific methods
$members = $repository->findById($id);

// Complex queries use query() builder
$active = $repository->query()
    ->where('is_active', true)
    ->whereDate('created_at', '>=', $since)
    ->orderBy('last_name')
    ->get();
```

### 3. **Null Handling for "Not Found"**

Repositories return `null` if entity not found. Services throw exceptions:

```php
// Repository: Returns null
$member = $repository->findById($memberId);  // Null if not found

// Service: Throws exception
if (!$entity) {
    throw new NotFoundException("Member not found: {$memberId}");
}
```

This separates concerns: repository handles data access, service handles business rules.

---

## Common Patterns Extracted

| Pattern | BaseRepository Method | Purpose |
|---------|----------------------|---------|
| CRUD Operations | create, findById, updateById, deleteById | Standard persistence |
| Bulk Operations | createMany, updateMany, deleteByIds | Batch processing |
| Query Building | query() | Complex queries |
| Existence Check | exists() | Conditional logic |
| Count | count() | Pagination calculations |

---

## When to Add Domain-Specific Methods

Add repository methods when:

1. **Query is used in multiple services** → Extract to repository
2. **Query requires business logic** → Own it in repository
3. **Query is complex** → Hide complexity in repository

```php
// ✅ Repository method (used by multiple services)
public function findModifiedSince(int $since): Collection {
    return $this->query()
        ->where('updated_at', '>=', $this->since($since))
        ->orderBy('updated_at')
        ->get();
}

// ❌ Not in repository (simple, one-off query)
// Just use query() builder directly in service
```

---

## Consequences

### Positive

- **Reduced duplication**: CRUD operations written once
- **Consistent error handling**: All repos behave the same
- **Testability**: Can mock repositories in service tests
- **Flexibility**: Change query strategy without affecting services
- **Maintainability**: Bug fix in pagination logic fixes all modules

### Negative

- **Abstraction overhead**: Learning repository interface adds complexity
- **Over-generalization risk**: Trying to fit all queries into base class
- **Extra layer**: More files/code than direct Eloquent queries

### Mitigations

1. **Keep BaseRepository focused** on CRUD only
2. **Allow query() access** for complex queries
3. **Document module-specific methods** clearly
4. **Provide working examples** in each module

---

## See Also

- **Pattern 005**: Repository Interface Pattern (contract definition)
- **Pattern 010**: Shared Base Service Layer (service patterns)
- **Pattern 009**: Module Structure & Organization (module context)
- **Pattern 004**: Service Layer (business logic)
- **ADR-0004**: Immutable Transaction Storage (repository patterns for immutable data)
