# Pattern 010: Shared Base Service Layer

**Status**: Active

**Purpose**: Extract common CRUD patterns and reusable business logic into base service classes to minimize duplication across modules while maintaining clear separation of concerns.

---

## Context

When implementing modules (Pattern 009), each module requires CRUD operations:
- List entities with pagination and filtering
- Create entity
- Read entity by ID
- Update entity
- Delete entity

Without shared abstractions, each module duplicates:
- Same pagination logic
- Same error handling (not found, validation failures)
- Same repository interaction patterns
- Same DTO transformation

This leads to:
- Code duplication across modules
- Inconsistent error handling
- Higher maintenance burden
- Missed opportunities for shared optimization

**Solution**: Create abstract base service classes that encapsulate common CRUD patterns. Module-specific services extend base classes and implement only unique business logic.

---

## Pattern Definition

### Base Service for CRUD Operations

```php
// app/Shared/Services/BaseService.php
namespace App\Shared\Services;

use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base service for standard CRUD operations.
 *
 * Modules extend this for entity-specific services (MembersService, ProductsService, etc.).
 * Provides common patterns:
 * - Pagination with filtering
 * - Error handling (NotFoundException)
 * - DTO transformation
 *
 * Implements Pattern 004: Service Layer
 */
abstract class BaseService
{
    /**
     * Constructor receives repository (injected by service provider).
     * Repository implements Pattern 011: Repository Interface.
     */
    public function __construct(
        protected readonly BaseRepository $repository,
    ) {}

    /**
     * List entities with pagination and filtering.
     *
     * Common use cases:
     * - Terminal: Sync entities modified since timestamp
     * - Admin: List paginated with filters
     *
     * @param int $limit Records per page (default 50)
     * @param int $offset Pagination offset (default 0)
     * @param array $filters Optional key-value pairs: ['is_active' => true, 'category' => 'bar']
     * @param ?int $since Optional Unix timestamp for delta sync (modified_at >= since)
     * @return PaginatedResultDto
     */
    public function listWithPagination(
        int $limit = 50,
        int $offset = 0,
        array $filters = [],
        ?int $since = null,
    ): PaginatedResultDto {
        // Build query
        $query = $this->repository->query();

        // Apply timestamp filter for delta sync
        if ($since !== null) {
            $query = $query->where('updated_at', '>=', $this->timestampToDateTime($since));
        }

        // Apply custom filters (delegated to module-specific service)
        $query = $this->applyFilters($query, $filters);

        // Count total before pagination
        $total = $query->count();

        // Apply pagination
        $entities = $query
            ->limit($limit)
            ->offset($offset)
            ->get();

        // Transform to DTOs (delegated to module-specific service)
        $dtos = $this->transformCollection($entities);

        return new PaginatedResultDto(
            items: $dtos,
            total: $total,
            limit: $limit,
            offset: $offset,
        );
    }

    /**
     * Retrieve single entity by ID.
     * Throws NotFoundException if not found.
     *
     * @param string $id Entity UUID
     * @return object DTO
     * @throws NotFoundException
     */
    public function findById(string $id): object
    {
        $entity = $this->repository->findById($id);

        if (!$entity) {
            throw new NotFoundException("Entity not found: {$id}");
        }

        return $this->transform($entity);
    }

    /**
     * Create new entity.
     *
     * @param array $validated Validated input (from FormRequest)
     * @return object DTO
     */
    public function create(array $validated): object
    {
        $entity = $this->repository->create($validated);
        return $this->transform($entity);
    }

    /**
     * Update existing entity.
     *
     * @param string $id Entity UUID
     * @param array $validated Validated input
     * @return object DTO
     * @throws NotFoundException
     */
    public function update(string $id, array $validated): object
    {
        $entity = $this->repository->updateById($id, $validated);

        if (!$entity) {
            throw new NotFoundException("Entity not found: {$id}");
        }

        return $this->transform($entity);
    }

    /**
     * Delete entity by ID.
     *
     * @param string $id Entity UUID
     * @throws NotFoundException
     */
    public function delete(string $id): void
    {
        $deleted = $this->repository->deleteById($id);

        if (!$deleted) {
            throw new NotFoundException("Entity not found: {$id}");
        }
    }

    /**
     * Common timestamp conversion: Unix seconds → Carbon DateTime
     * Used for delta sync queries.
     */
    protected function timestampToDateTime(int $unixTimestamp): \DateTime
    {
        return \DateTime::createFromFormat('U', (string)$unixTimestamp);
    }

    /**
     * Hook for subclasses to apply domain-specific filters.
     * Override in module-specific service.
     *
     * Example implementation in MembersService:
     *   public function applyFilters($query, array $filters) {
     *       if (isset($filters['is_active'])) {
     *           $query = $query->where('is_active', $filters['is_active']);
     *       }
     *       return $query;
     *   }
     */
    protected function applyFilters($query, array $filters)
    {
        // Base implementation: no filters
        // Subclasses override to implement domain-specific filtering
        return $query;
    }

    /**
     * Hook for subclasses to transform single entity to DTO.
     * Must be implemented in module-specific service.
     *
     * Example in MembersService:
     *   protected function transform($entity) {
     *       return MemberDto::from($entity);
     *   }
     */
    abstract protected function transform(Model $entity): object;

    /**
     * Hook for subclasses to transform collection to DTOs.
     * Default: map transform() over each entity.
     * Override in subclass if custom transformation needed.
     */
    protected function transformCollection(Collection $entities): array
    {
        return $entities->map(fn($entity) => $this->transform($entity))->toArray();
    }
}
```

### Module-Specific Service (Members Example)

```php
// app/Http/Modules/Members/Services/MembersService.php
namespace App\Http\Modules\Members\Services;

use App\Http\Modules\Members\DTOs\MemberDto;
use App\Http\Modules\Members\Repositories\MembersRepository;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Model;

/**
 * Members service: extends BaseService with Members-specific logic.
 *
 * Inherits from BaseService:
 * - listWithPagination()
 * - findById()
 * - create()
 * - update()
 * - delete()
 *
 * Implements Members-specific operations:
 * - updateLanguage()
 * - exportGDPR()
 * - anonymize()
 */
final class MembersService extends BaseService
{
    public function __construct(
        private readonly MembersRepository $membersRepository,
    ) {
        parent::__construct($membersRepository);
    }

    /**
     * Terminal: Update member's language preference.
     *
     * Members-specific logic: Validate language enum, log audit event.
     */
    public function updateLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        $member = $this->repository->updateById($memberId, [
            'preferred_language' => $language->value,
        ]);

        if (!$member) {
            throw new NotFoundException("Member not found: {$memberId}");
        }

        return MemberDto::from($member);
    }

    /**
     * Admin: Export member data for GDPR compliance.
     *
     * Gathers:
     * - Member profile
     * - Transaction history
     * - Booking records
     *
     * Returns ZIP file with JSON exports.
     */
    public function exportGDPR(string $memberId): GDPRExportDto
    {
        $member = $this->repository->findById($memberId);

        if (!$member) {
            throw new NotFoundException("Member not found: {$memberId}");
        }

        $transactions = $this->repository->getTransactionHistory($memberId);
        $bookings = $this->repository->getBookingHistory($memberId);

        return new GDPRExportDto(
            member: MemberDto::from($member),
            transactions: $transactions,
            bookings: $bookings,
            exportedAt: now(),
        );
    }

    /**
     * Admin: Anonymize member data (GDPR Art. 17).
     *
     * Removes personal data:
     * - First/last name → "Deleted User"
     * - Email/phone → NULL
     * - IBAN → NULL
     * - Mandate ref → NULL
     *
     * Retains for accounting:
     * - Transaction history (anonymized reference)
     * - Balance history
     */
    public function anonymize(string $memberId): void
    {
        $member = $this->repository->findById($memberId);

        if (!$member) {
            throw new NotFoundException("Member not found: {$memberId}");
        }

        $this->repository->anonymize($memberId);

        // Log audit event: Someone anonymized a member
        // (See Pattern 013: Audit Logging)
    }

    /**
     * Apply Members-specific filters to query.
     * Override of BaseService hook.
     *
     * Supports: is_active, has_sepa, language
     */
    protected function applyFilters($query, array $filters)
    {
        if (isset($filters['is_active'])) {
            $query = $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['has_sepa'])) {
            $query = $query->where('is_sepa_valid', $filters['has_sepa']);
        }

        if (isset($filters['language'])) {
            $query = $query->where('preferred_language', $filters['language']);
        }

        return $query;
    }

    /**
     * Transform Member model to MemberDto.
     * Override of BaseService hook.
     */
    protected function transform(Model $entity): MemberDto
    {
        return MemberDto::from($entity);
    }
}
```

### Another Module-Specific Service (Products Example)

```php
// app/Http/Modules/Products/Services/ProductsService.php
namespace App\Http\Modules\Products\Services;

use App\Http\Modules\Products\DTOs\ProductDto;
use App\Http\Modules\Products\Repositories\ProductsRepository;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Model;

/**
 * Products service: extends BaseService.
 *
 * Products-specific operations:
 * - toggleActive()
 * - updatePricing()
 */
final class ProductsService extends BaseService
{
    public function __construct(
        private readonly ProductsRepository $productsRepository,
    ) {
        parent::__construct($productsRepository);
    }

    /**
     * Admin: Toggle product active status.
     */
    public function toggleActive(string $productId, bool $isActive): ProductDto
    {
        $product = $this->repository->updateById($productId, [
            'is_active' => $isActive,
        ]);

        if (!$product) {
            throw new NotFoundException("Product not found: {$productId}");
        }

        return ProductDto::from($product);
    }

    /**
     * Products-specific filters: category, is_active
     */
    protected function applyFilters($query, array $filters)
    {
        if (isset($filters['category_id'])) {
            $query = $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['is_active'])) {
            $query = $query->where('is_active', $filters['is_active']);
        }

        return $query;
    }

    protected function transform(Model $entity): ProductDto
    {
        return ProductDto::from($entity);
    }
}
```

---

## Service Provider Configuration

Register services in AppServiceProvider (Pattern 008: Service Provider Bindings):

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Http\Modules\Members\Repositories\MembersRepository;
use App\Http\Modules\Members\Services\MembersService;
use App\Http\Modules\Products\Repositories\ProductsRepository;
use App\Http\Modules\Products\Services\ProductsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Members Module
        $this->app->bind(MembersRepository::class, function ($app) {
            return new MembersRepository(new Member());
        });

        $this->app->singleton(MembersService::class, function ($app) {
            return new MembersService(
                $app->make(MembersRepository::class),
            );
        });

        // Products Module (same pattern)
        $this->app->bind(ProductsRepository::class, function ($app) {
            return new ProductsRepository(new Product());
        });

        $this->app->singleton(ProductsService::class, function ($app) {
            return new ProductsService(
                $app->make(ProductsRepository::class),
            );
        });

        // ... repeat for other modules
    }
}
```

---

## Usage Examples

### Terminal Sync Controller

```php
// app/Http/Modules/Members/Controllers/SyncController.php
final class SyncController extends Controller
{
    public function __construct(private readonly MembersService $service) {}

    public function index(SyncRequest $request): JsonResponse
    {
        // Use inherited BaseService::listWithPagination()
        $result = $this->service->listWithPagination(
            limit: 500,
            offset: 0,
            since: $request->since(),  // Delta sync
        );

        return response()->json($result->toResponse('members'));
    }
}
```

### Admin CRUD Controller

```php
// app/Http/Modules/Members/Controllers/AdminController.php
final class AdminController extends Controller
{
    public function __construct(private readonly MembersService $service) {}

    public function index(AdminListRequest $request): JsonResponse
    {
        // Use inherited BaseService::listWithPagination()
        $result = $this->service->listWithPagination(
            limit: $request->limit(),
            offset: $request->offset(),
            filters: $request->filters(),  // Custom filters applied via hook
        );

        return response()->json($result->toArray());
    }

    public function show(string $id): JsonResponse
    {
        $member = $this->service->findById($id);  // Inherited from BaseService
        return response()->json($member->toArray());
    }

    public function store(CreateMemberRequest $request): JsonResponse
    {
        $member = $this->service->create($request->validated());  // Inherited
        return response()->json($member->toArray(), 201);
    }

    public function update(UpdateMemberRequest $request, string $id): JsonResponse
    {
        $member = $this->service->update($id, $request->validated());  // Inherited
        return response()->json($member->toArray());
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);  // Inherited
        return response()->noContent();
    }

    public function export(string $id): JsonResponse
    {
        $export = $this->service->exportGDPR($id);  // Members-specific
        return response()->download($export->path, $export->filename);
    }

    public function anonymize(string $id): JsonResponse
    {
        $this->service->anonymize($id);  // Members-specific
        return response()->json(['status' => 'anonymized']);
    }
}
```

---

## Common Patterns Extracted to BaseService

| Pattern | Location | Purpose |
|---------|----------|---------|
| Pagination | `listWithPagination()` | Shared across all modules |
| Filtering | `applyFilters()` hook | Common query building, module-specific filters |
| DTO transformation | `transform()` / `transformCollection()` hooks | Consistent conversion to response objects |
| Error handling | `NotFoundException` | Standardized "not found" response |
| Timestamp conversion | `timestampToDateTime()` | Delta sync queries |

---

## Avoiding Over-Abstraction

**When NOT to extract to BaseService:**

1. **Logic appears in only one module** → Keep it in module-specific service
2. **Logic is entity-specific** → Implement directly in module service (e.g., `anonymize()` is Members-specific)
3. **Complex domain logic** → Belongs in module service, not base class

**Good extraction targets:**

1. **Pagination with filtering** → Common across all CRUD modules
2. **Standard CRUD operations** → Almost identical across modules
3. **Error handling** → Same "not found" logic everywhere
4. **DTO transformation** → Same pattern: model → DTO

---

## Consequences

### Positive

- **Reduced duplication**: CRUD boilerplate written once
- **Consistent behavior**: All modules paginate/filter the same way
- **Faster module implementation**: New module just overrides hooks
- **Bug fixes scale**: Fix pagination bug once, fixes across all modules
- **Testability**: BaseService behavior tested once, applies to all

### Negative

- **Abstraction overhead**: Understanding flow requires reading base class
- **Template Method pattern complexity**: Too many hooks can confuse flow
- **Over-engineering risk**: Temptation to extract too much too early

### Mitigations

1. **Document hooks clearly** in base class
2. **Keep BaseService focused** on CRUD patterns only
3. **Use abstract methods** for required hooks (e.g., `transform()`)
4. **Provide good examples** in module services

---

## See Also

- **Pattern 004**: Service Layer (business logic organization)
- **Pattern 011**: Repository Interface Pattern (data access)
- **Pattern 009**: Module Structure & Organization (module context)
- **Pattern 008**: Service Provider Bindings (dependency injection)
