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
// src/Shared/Services/BaseService.php
namespace App\Shared\Services;

use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\NotFoundException;

/**
 * Abstract base service for standard CRUD operations.
 *
 * Modules extend this for entity-specific services (MembersService, ProductsService, etc.).
 * Provides common patterns:
 * - Pagination with filtering (delegated to repository)
 * - Error handling (NotFoundException)
 * - DTO transformation
 *
 * Implements Pattern 004: Service Layer
 *
 * Note: Repositories use PDO with raw SQL (prepared statements).
 * Entities are plain associative arrays, not ORM model objects.
 */
abstract class BaseService
{
    /**
     * Constructor receives repository (injected by ServiceFactory).
     * Repository implements Pattern 011: Base Repository.
     */
    public function __construct(
        protected readonly object $repository,
    ) {}

    /**
     * List entities with pagination and filtering.
     *
     * Delegates to repository's listPaginated() method which builds
     * the SQL query with WHERE clauses, ORDER BY, LIMIT/OFFSET.
     *
     * @param int $limit Records per page (default 50)
     * @param int $offset Pagination offset (default 0)
     * @param array $filters Optional key-value pairs: ['is_active' => true, 'category_id' => '...']
     * @param string $sortKey Column to sort by (default 'created_at')
     * @param string $sortOrder Sort direction: 'asc' or 'desc'
     * @return PaginatedResultDto
     */
    public function listWithPagination(
        int $limit = 50,
        int $offset = 0,
        array $filters = [],
        string $sortKey = 'created_at',
        string $sortOrder = 'desc',
    ): PaginatedResultDto {
        // Repository handles SQL building, filtering, pagination via PDO
        $result = $this->repository->listPaginated($limit, $offset, $filters, $sortKey, $sortOrder);

        // Transform rows (associative arrays) to DTOs
        $items = array_map(fn(array $row) => $this->transform($row)->toArray(), $result['items']);

        return new PaginatedResultDto(
            items: $items,
            total: $result['total'],
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
        $row = $this->repository->findById($id);

        if (!$row) {
            throw NotFoundException::forResource($this->getEntityName(), $id);
        }

        return $this->transform($row);
    }

    /**
     * Create new entity.
     *
     * @param array $validated Validated input (from Validator)
     * @return object DTO
     */
    public function create(array $validated): object
    {
        $row = $this->repository->create($validated);
        return $this->transform($row);
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
        $row = $this->repository->updateById($id, $validated);

        if (!$row) {
            throw NotFoundException::forResource($this->getEntityName(), $id);
        }

        return $this->transform($row);
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
            throw NotFoundException::forResource($this->getEntityName(), $id);
        }
    }

    /**
     * Common timestamp conversion: Unix milliseconds → MySQL DATETIME string.
     * Used for delta sync queries.
     *
     * Note: Sync timestamps arrive as milliseconds from the terminal.
     */
    protected function timestampToDateTime(int $unixMilliseconds): string
    {
        $seconds = (int) ($unixMilliseconds / 1000);
        return date('Y-m-d H:i:s', $seconds);
    }

    /**
     * Hook for subclasses to transform a single database row to DTO.
     * Must be implemented in module-specific service.
     *
     * @param array $row Associative array from PDO fetch
     * @return object DTO instance
     */
    abstract protected function transform(array $row): object;

    /**
     * Hook for subclasses to return the entity name for error messages.
     * Override in module-specific service.
     *
     * @return string e.g., 'Member', 'Product'
     */
    abstract protected function getEntityName(): string;
}
```

### Module-Specific Service (Members Example)

```php
// src/Modules/Members/Services/MembersService.php
namespace App\Modules\Members\Services;

use App\Modules\Members\DTOs\MemberDto;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;

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
 * - syncSince()         — terminal delta sync
 * - updateLanguage()    — terminal language preference
 * - exportMember()      — GDPR data export
 * - anonymizeMember()   — GDPR Art. 17 anonymization
 */
final class MembersService extends BaseService
{
    public function __construct(
        private readonly MembersRepository $membersRepository,
        private readonly TransactionsRepository $transactionsRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($membersRepository);
    }

    /**
     * Terminal: Sync members modified since timestamp (delta sync).
     */
    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->membersRepository->findModifiedSince($since);
        $members = array_map(fn($row) => MemberDto::fromRow($row), $rows);

        $cursor = !empty($rows)
            ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
            : $since;

        return new SyncResultDto(items: $members, cursor: $cursor);
    }

    /**
     * Terminal: Update member's language preference.
     */
    public function updateLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        $member = $this->membersRepository->updateById($memberId, [
            'preferred_language' => $language->value,
        ]);

        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        return MemberDto::fromRow($member);
    }

    /**
     * Admin: Export member data for GDPR compliance.
     *
     * Gathers member profile and transaction history.
     * Returns array suitable for JSON response.
     */
    public function exportMember(string $memberId): array
    {
        $row = $this->membersRepository->findByIdIncludingDeleted($memberId);
        if (!$row) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        $member = MemberAdminDto::fromRow($row);
        $transactions = $this->transactionsRepository->findByMemberId($memberId, limit: 1000);

        return [
            'member' => $member->toArray(),
            'transactions' => $transactions,
            'bookings' => [],
            'export_timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Admin: Anonymize member data (GDPR Art. 17).
     *
     * Removes personal data (name, email, IBAN, etc.)
     * Retains transaction history for accounting.
     */
    public function anonymizeMember(string $memberId, ?string $adminUserId = null): MemberAdminDto
    {
        $oldMember = $this->membersRepository->findById($memberId);
        if (!$oldMember) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $this->membersRepository->anonymize($memberId);
        $member = $this->membersRepository->findByIdIncludingDeleted($memberId);

        $this->auditService->log(
            action: AuditAction::ANONYMIZE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: ['first_name' => $oldMember['first_name'], 'last_name' => $oldMember['last_name']],
            newValues: ['first_name' => 'DELETED', 'last_name' => 'DELETED'],
            adminUserId: $adminUserId,
        );

        return MemberAdminDto::fromRow($member);
    }

    /**
     * Transform database row to MemberAdminDto.
     * Override of BaseService hook.
     *
     * @param array $row Associative array from PDO fetch
     */
    protected function transform(array $row): MemberAdminDto
    {
        return MemberAdminDto::fromRow($row);
    }

    protected function getEntityName(): string
    {
        return 'Member';
    }
}
```

### Another Module-Specific Service (Products Example)

```php
// src/Modules/Products/Services/ProductsService.php
namespace App\Modules\Products\Services;

use App\Modules\Products\DTOs\ProductDto;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;

/**
 * Products service: extends BaseService.
 *
 * Products-specific operations:
 * - syncSince()      — terminal delta sync
 * - toggleStatus()   — activate/deactivate product
 * - createProduct()  — with category validation
 */
final class ProductsService extends BaseService
{
    public function __construct(
        private readonly ProductsRepository $productsRepository,
        private readonly CategoriesRepository $categoriesRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($productsRepository);
    }

    /**
     * Admin: Toggle product active status.
     */
    public function toggleStatus(string $productId, bool $isActive, ?string $adminUserId = null): ProductDto
    {
        $row = $this->productsRepository->updateById($productId, ['is_active' => $isActive]);

        if (!$row) {
            throw NotFoundException::forResource('Product', $productId);
        }

        $this->auditService->log(
            action: $isActive ? AuditAction::ACTIVATE : AuditAction::DEACTIVATE,
            entityType: EntityType::PRODUCT,
            entityId: $productId,
            newValues: ['is_active' => $isActive],
            adminUserId: $adminUserId,
        );

        return ProductDto::fromRow($row);
    }

    /**
     * Transform database row to ProductDto.
     * Override of BaseService hook.
     *
     * @param array $row Associative array from PDO fetch
     */
    protected function transform(array $row): ProductDto
    {
        return ProductDto::fromRow($row);
    }

    protected function getEntityName(): string
    {
        return 'Product';
    }
}
```

---

## ServiceFactory Configuration

Register services in ServiceFactory (Pattern 008: Service Provider Bindings).
The ServiceFactory implements `Psr\Container\ContainerInterface` and uses lazy singleton resolution:

```php
// src/ServiceFactory.php
namespace App;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MembersService;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Products\Services\ProductsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;
use PDO;
use Psr\Container\ContainerInterface;

class ServiceFactory implements ContainerInterface
{
    private array $instances = [];

    public function __construct(
        private PDO $pdo,
        private AppConfig $config,
        private Logger $logger,
    ) {}

    // --- Repositories (PDO + Logger injected) ---

    public function getMembersRepository(): MembersRepository
    {
        return $this->resolve(MembersRepository::class, fn() =>
            new MembersRepository($this->pdo, $this->logger));
    }

    public function getProductsRepository(): ProductsRepository
    {
        return $this->resolve(ProductsRepository::class, fn() =>
            new ProductsRepository($this->pdo, $this->logger));
    }

    // --- Services (repositories + audit service injected) ---

    public function getMembersService(): MembersService
    {
        return $this->resolve(MembersService::class, fn() =>
            new MembersService(
                $this->getMembersRepository(),
                $this->getTransactionsRepository(),
                $this->getAuditService(),
            ));
    }

    public function getProductsService(): ProductsService
    {
        return $this->resolve(ProductsService::class, fn() =>
            new ProductsService(
                $this->getProductsRepository(),
                $this->getCategoriesRepository(),
                $this->getAuditService(),
            ));
    }

    // Lazy singleton resolution
    private function resolve(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }

    // ... ContainerInterface methods for Slim route resolution
}
```

---

## Usage Examples

### Terminal Sync Controller

```php
// src/Modules/Members/Controllers/SyncController.php
use App\Modules\Members\Services\MembersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SyncController
{
    public function __construct(private readonly MembersService $service) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        // Use Members-specific syncSince() for delta sync
        $result = $this->service->syncSince($since);

        $response->getBody()->write(json_encode($result->toArray('members')));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Admin CRUD Controller

```php
// src/Modules/Members/Controllers/AdminController.php
use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController
{
    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['per_page'] ?? $params['limit'] ?? 50);
        $page = (int) ($params['page'] ?? 1);
        $offset = isset($params['offset']) ? (int) $params['offset'] : ($page - 1) * $limit;
        $sortKey = $params['sort'] ?? 'created_at';
        $sortOrder = $params['order'] ?? 'desc';
        $search = $params['search'] ?? null;

        $filters = [];
        if (isset($params['filters']['is_active'])) {
            $filters['is_active'] = filter_var($params['filters']['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $result = $this->membersService->listMembers($limit, $offset, $filters, $sortKey, $sortOrder, $search);
        return $this->json($response, $result->toArray());
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $member = $this->membersService->getMember($args['memberId']);
        return $this->json($response, $member->toArray());
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'preferred_language' => ['required', 'string', 'in:de,en,fr'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $member = $this->membersService->createMember(/* ... validated params ... */);
        return $this->json($response, $member->toArray(), 201);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        $this->membersService->deleteMember($args['memberId'], $adminId);
        return $this->json($response, ['message' => 'Member deleted']);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $exportData = $this->membersService->exportMember($args['memberId']);  // Members-specific
        return $this->json($response, $exportData);
    }

    public function anonymize(Request $request, Response $response, array $args): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        $member = $this->membersService->anonymizeMember($args['memberId'], $adminId);  // Members-specific
        return $this->json($response, $member->toArray());
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

---

## Common Patterns Extracted to BaseService

| Pattern | Location | Purpose |
|---------|----------|---------|
| Pagination | `listWithPagination()` | Shared across all modules |
| DTO transformation | `transform()` hook | Consistent conversion: database row (array) to DTO |
| Entity name | `getEntityName()` hook | Standardized error messages per module |
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
4. **DTO transformation** → Same pattern: database row (array) to DTO

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
