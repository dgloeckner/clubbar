# Pattern 011: Shared Base Repository

**Status**: Active

**Purpose**: Extract common data access patterns into base repository class to minimize duplication across modules while encapsulating PDO-based SQL queries.

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
// src/Shared/Repositories/RepositoryInterface.php
namespace App\Shared\Repositories;

/**
 * Contract for data access repositories.
 *
 * All module repositories must implement this interface.
 * Uses PDO with raw SQL (prepared statements) for data access.
 * Entities are plain associative arrays (from PDO::FETCH_ASSOC), not ORM models.
 */
interface RepositoryInterface
{
    /**
     * Find entity by primary key (ID).
     *
     * @param string $id UUID
     * @return array|null Associative array or null if not found
     */
    public function findById(string $id): ?array;

    /**
     * Find all entities (no pagination).
     *
     * @return array[] Array of associative arrays
     */
    public function findAll(): array;

    /**
     * Create new entity.
     *
     * @param array $data Validated data (from Validator)
     * @return array The created row (re-fetched from database)
     */
    public function create(array $data): array;

    /**
     * Update entity by ID.
     *
     * @param string $id UUID
     * @param array $data Validated data
     * @return array|null Updated row or null if not found
     */
    public function updateById(string $id, array $data): ?array;

    /**
     * Delete entity by ID.
     *
     * @param string $id UUID
     * @return bool True if entity existed and was deleted
     */
    public function deleteById(string $id): bool;

    /**
     * Count total entities.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if entity exists by ID.
     *
     * @param string $id UUID
     * @return bool
     */
    public function exists(string $id): bool;

    /**
     * List entities with pagination, filtering, and sorting.
     *
     * @param int $limit Records per page
     * @param int $offset Pagination offset
     * @param array $filters Key-value filter criteria
     * @param string $sortKey Column to sort by
     * @param string $sortOrder 'asc' or 'desc'
     * @return array{items: array[], total: int}
     */
    public function listPaginated(
        int $limit,
        int $offset,
        array $filters = [],
        string $sortKey = 'created_at',
        string $sortOrder = 'desc',
    ): array;
}
```

### Base Repository Implementation

```php
// src/Shared/Repositories/BaseRepository.php
namespace App\Shared\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

/**
 * Abstract base repository implementing standard CRUD operations via PDO.
 *
 * Modules extend this and add domain-specific queries.
 * All queries use prepared statements to prevent SQL injection.
 *
 * Implements Pattern 005: Repository Interface for Data Access
 */
abstract class BaseRepository implements RepositoryInterface
{
    public function __construct(
        protected PDO $db,
        protected Logger $logger,
    ) {}

    /**
     * The database table name. Must be set by subclass.
     */
    abstract protected function getTableName(): string;

    /**
     * Columns allowed for update operations.
     * Must be set by subclass to prevent mass-assignment.
     *
     * @return string[]
     */
    abstract protected function getAllowedUpdateColumns(): array;

    /**
     * Find entity by primary key.
     *
     * @param string $id UUID
     * @return array|null Associative array or null
     */
    public function findById(string $id): ?array
    {
        $table = $this->getTableName();
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find all entities (no pagination).
     *
     * WARNING: Use carefully for large tables. Prefer listPaginated().
     *
     * @return array[]
     */
    public function findAll(): array
    {
        $table = $this->getTableName();
        return $this->db->query("SELECT * FROM {$table} ORDER BY created_at DESC")->fetchAll();
    }

    /**
     * Create new entity.
     * Subclasses typically override this to handle specific column mappings,
     * JSON fields, UUID generation, etc.
     *
     * @param array $data Validated data (from Validator)
     * @return array The created row (re-fetched)
     */
    abstract public function create(array $data): array;

    /**
     * Update entity by ID using SafeQuery to build SET clause.
     *
     * @param string $id UUID
     * @param array $data Validated data
     * @return array|null Updated row or null if not found
     */
    public function updateById(string $id, array $data): ?array
    {
        $allowed = $this->getAllowedUpdateColumns();
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');  // updated_at
        $values[] = $id;

        $table = $this->getTableName();
        $stmt = $this->db->prepare("UPDATE {$table} SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info("{$table} updated", ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Delete entity by ID.
     *
     * @param string $id UUID
     * @return bool True if entity existed and was deleted
     */
    public function deleteById(string $id): bool
    {
        $table = $this->getTableName();
        $stmt = $this->db->prepare("DELETE FROM {$table} WHERE id = ?");
        $result = $stmt->execute([$id]);
        $this->logger->info("{$table} deleted", ['id' => $id]);
        return $result && $stmt->rowCount() > 0;
    }

    /**
     * Count total entities.
     *
     * @return int
     */
    public function count(): int
    {
        $table = $this->getTableName();
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * Check if entity exists by ID.
     *
     * @param string $id UUID
     * @return bool
     */
    public function exists(string $id): bool
    {
        $table = $this->getTableName();
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return (bool) $stmt->fetch();
    }

    /**
     * List entities with pagination, filtering, and sorting.
     * Subclasses override to implement domain-specific filters and sort maps.
     *
     * @return array{items: array[], total: int}
     */
    abstract public function listPaginated(
        int $limit,
        int $offset,
        array $filters = [],
        string $sortKey = 'created_at',
        string $sortOrder = 'desc',
    ): array;

    /**
     * Generate a UUID v4.
     */
    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

### Module-Specific Repository (Members Example)

```php
// src/Modules/Members/Repositories/MembersRepository.php
namespace App\Modules\Members\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Repositories\BaseRepository;
use App\Shared\Sync\SyncCursor;

/**
 * Members repository: extends BaseRepository with Members-specific queries.
 *
 * Inherits standard CRUD from BaseRepository:
 * - findById()
 * - findAll()
 * - updateById()
 * - deleteById()
 * - count(), exists()
 *
 * Implements Members-specific data access:
 * - findModifiedSince()     — terminal delta sync
 * - findByIdIncludingDeleted() — admin view of soft-deleted
 * - anonymize()             — GDPR Art. 17
 * - listPaginated()         — admin list with filters
 */
final class MembersRepository extends BaseRepository
{
    public function __construct(PDO $db, Logger $logger)
    {
        parent::__construct($db, $logger);
    }

    protected function getTableName(): string
    {
        return 'members';
    }

    protected function getAllowedUpdateColumns(): array
    {
        return ['card_uid', 'first_name', 'last_name', 'email', 'phone',
                'preferred_language', 'is_active', 'iban', 'account_holder_name',
                'mandate_reference', 'mandate_signed_at', 'deleted_at', 'deleted_by_admin_id'];
    }

    /**
     * Override findById to exclude soft-deleted members.
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Admin: Find member including soft-deleted (for anonymized member views).
     */
    public function findByIdIncludingDeleted(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Terminal: Find members modified since timestamp (delta sync).
     * Includes tombstones (deleted_at) so terminals can remove deleted items.
     *
     * @param int $sinceTimestamp Unix timestamp in milliseconds
     * @return array[]
     */
    public function findModifiedSince(int $sinceTimestamp): array
    {
        $sinceDate = SyncCursor::lowerBound($sinceTimestamp);

        // Inclusive bound: the columns have second precision, so a strict >
        // loses every row written later in the cursor's own second (#84)
        $stmt = $this->db->prepare(
            'SELECT * FROM members
             WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    /**
     * Create new member with UUID generation and auto-generated mandate reference.
     */
    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $iban = $data['iban'] ?? null;
        $mandateReference = array_key_exists('mandate_reference', $data)
            ? ($data['mandate_reference'] ?: null)
            : ($iban !== null ? str_replace('-', '', $id) : null);

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, card_uid, first_name, last_name, email, phone,
             preferred_language, is_active, iban, account_holder_name,
             mandate_reference, mandate_signed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $data['card_uid'] ?? null, $data['first_name'], $data['last_name'],
            $data['email'], $data['phone'] ?? null, $data['preferred_language'] ?? 'de',
            $data['is_active'] ?? true ? 1 : 0, $iban, $data['account_holder_name'] ?? null,
            $mandateReference, $data['mandate_signed_at'] ?? null, $now, $now,
        ]);

        $this->logger->info('Member created', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Admin: Anonymize member data (GDPR Art. 17).
     * Removes personal data but retains record for accounting.
     */
    public function anonymize(string $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE members SET first_name = ?, last_name = ?, email = ?,
             phone = NULL, iban = NULL, account_holder_name = NULL,
             mandate_reference = NULL, card_uid = NULL, is_active = 0,
             deleted_at = ?, updated_at = ? WHERE id = ?'
        );
        return $stmt->execute(['DELETED', 'DELETED', 'deleted@example.com', $now, $now, $id]);
    }

    /**
     * Admin: List members with pagination, filtering, sorting, and search.
     * Builds SQL WHERE clause dynamically based on filters.
     */
    public function listPaginated(
        int $limit, int $offset, array $filters = [],
        string $sortKey = 'created_at', string $sortOrder = 'desc',
        ?string $search = null,
    ): array {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = ?';
            $params[] = $filters['is_active'] ? 1 : 0;
        }
        if (isset($filters['language'])) {
            $where[] = 'preferred_language = ?';
            $params[] = $filters['language'];
        }
        if ($search) {
            $escaped = SafeQuery::escapeLike($search);
            $where[] = "(CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?)";
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%"]);
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $columnMap = ['first_name' => 'first_name', 'last_name' => 'last_name', 'created_at' => 'created_at'];
        $col = SafeQuery::column($sortKey, array_keys($columnMap));
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM members {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT * FROM members {$whereClause} ORDER BY {$columnMap[$col]} {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
```

### Another Repository Example (Products)

```php
// src/Modules/Products/Repositories/ProductsRepository.php
namespace App\Modules\Products\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Repositories\BaseRepository;
use App\Shared\Sync\SyncCursor;

/**
 * Products repository: extends BaseRepository.
 *
 * Products-specific queries:
 * - findByCategory()
 * - findActive()
 * - findModifiedSince()
 */
final class ProductsRepository extends BaseRepository
{
    public function __construct(PDO $db, Logger $logger)
    {
        parent::__construct($db, $logger);
    }

    protected function getTableName(): string
    {
        return 'products';
    }

    protected function getAllowedUpdateColumns(): array
    {
        return ['category_id', 'names', 'descriptions', 'price_cents',
                'is_active', 'icon_name', 'requires_dispenser',
                'deleted_at', 'deleted_by_admin_id'];
    }

    /**
     * Find all active products in category.
     */
    public function findByCategory(string $categoryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM products WHERE category_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    }

    /**
     * Terminal: Find products modified since timestamp (delta sync).
     * Includes tombstones for terminal cache invalidation.
     *
     * @param int $sinceTimestamp Unix timestamp in milliseconds
     */
    public function findModifiedSince(int $sinceTimestamp): array
    {
        $sinceDate = SyncCursor::lowerBound($sinceTimestamp);

        // Inclusive bound: the columns have second precision, so a strict >
        // loses every row written later in the cursor's own second (#84)
        $stmt = $this->db->prepare(
            'SELECT * FROM products
             WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    /**
     * Create new product with UUID generation and JSON encoding for multilingual fields.
     */
    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');
        $names = is_array($data['names']) ? json_encode($data['names']) : $data['names'];
        $descriptions = is_array($data['descriptions'] ?? [])
            ? json_encode($data['descriptions'] ?? [])
            : ($data['descriptions'] ?? '{}');

        $stmt = $this->db->prepare(
            'INSERT INTO products (id, category_id, names, descriptions, price_cents,
             is_active, icon_name, requires_dispenser, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $data['category_id'], $names, $descriptions,
            (int) $data['price_cents'], ($data['is_active'] ?? true) ? 1 : 0,
            $data['icon_name'] ?? null, ($data['requires_dispenser'] ?? false) ? 1 : 0,
            $now, $now,
        ]);

        $this->logger->info('Product created', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Admin: List products with pagination, filtering, sorting, and search.
     * Joins categories table for category name sorting.
     */
    public function listPaginated(
        int $limit, int $offset, array $filters = [],
        string $sortBy = 'created_at', string $sortOrder = 'desc',
    ): array {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') { $where[] = 'p.is_active = 1'; }
            elseif ($filters['status'] === 'inactive') { $where[] = 'p.is_active = 0'; }
        }
        if (isset($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (isset($filters['search'])) {
            $where[] = "JSON_SEARCH(p.names, 'one', ?) IS NOT NULL";
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sortMap = [
            'name' => "JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.de'))",
            'price' => 'p.price_cents',
            'created_at' => 'p.created_at',
        ];
        $sortCol = $sortMap[$sortBy] ?? 'p.created_at';
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT p.*, c.names as category_names
             FROM products p LEFT JOIN categories c ON p.category_id = c.id
             {$whereClause} ORDER BY {$sortCol} {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
```

---

## Usage in Services

Services use repositories for data access, then transform rows (associative arrays) to DTOs:

```php
// src/Modules/Members/Services/MembersService.php
final class MembersService extends BaseService  // Extends Pattern 010: BaseService
{
    public function __construct(
        private readonly MembersRepository $membersRepository,
        private readonly TransactionsRepository $transactionsRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($membersRepository);
    }

    /**
     * Terminal: Sync members modified since timestamp.
     */
    public function syncSince(int $since): SyncResultDto
    {
        // Use repository-specific method (returns array of associative arrays)
        $rows = $this->membersRepository->findModifiedSince($since);

        // Transform rows to DTOs
        $members = array_map(fn($row) => MemberDto::fromRow($row), $rows);

        $cursor = !empty($rows)
            ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
            : $since;

        return new SyncResultDto(items: $members, cursor: $cursor);
    }

    /**
     * Admin: Export member data (GDPR).
     */
    public function exportMember(string $memberId): array
    {
        // Use multiple repository methods
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

    // Hook implementations for BaseService
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

---

## ServiceFactory Bindings

Register repositories in ServiceFactory (Pattern 008: Service Provider Bindings).
Repositories receive PDO and Logger via constructor injection:

```php
// src/ServiceFactory.php
class ServiceFactory implements ContainerInterface
{
    private array $instances = [];

    public function __construct(
        private PDO $pdo,
        private AppConfig $config,
        private Logger $logger,
    ) {}

    // Repositories: PDO + Logger injected
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

    // Services: Repositories + AuditService injected
    public function getMembersService(): MembersService
    {
        return $this->resolve(MembersService::class, fn() =>
            new MembersService(
                $this->getMembersRepository(),
                $this->getTransactionsRepository(),
                $this->getAuditService(),
            ));
    }

    // Lazy singleton resolution
    private function resolve(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }
}
```

---

## Key Design Decisions

### 1. **PDO as Implementation Detail**

Base repository encapsulates PDO queries. Services call `repository->findById()`, never write SQL directly.

**Benefit**: SQL is centralized in repositories, can be optimized without changing service code.

```php
// ✅ Good: Service depends on repository
class MembersService {
    public function __construct(private MembersRepository $repo) {}
    public function getMember(string $id): MemberAdminDto {
        $row = $this->repo->findById($id);  // Repository handles SQL
        return MemberAdminDto::fromRow($row);
    }
}

// ❌ Bad: Service uses PDO directly
class MembersService {
    public function getMember(string $id) {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ?');  // SQL leaking into service
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
```

### 2. **Domain-Specific Query Methods**

Instead of exposing a generic query builder, repositories provide domain-specific methods with raw SQL:

```php
// Simple queries use standard methods
$member = $repository->findById($id);

// Complex queries are encapsulated in named methods
$active = $repository->findModifiedSince($sinceTimestamp);
$paginated = $repository->listPaginated($limit, $offset, $filters, $sortKey, $sortOrder);
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
| CRUD Operations | create, findById, updateById, deleteById | Standard persistence via PDO |
| Pagination | listPaginated() | Filtered, sorted, paginated queries |
| Existence Check | exists() | Conditional logic |
| Count | count() | Pagination calculations |
| UUID Generation | generateUuid() | Client-generated UUIDs for idempotent APIs |

---

## When to Add Domain-Specific Methods

Add repository methods when:

1. **Query is used in multiple services** → Extract to repository
2. **Query requires business logic** → Own it in repository
3. **Query is complex** → Hide complexity in repository

```php
// ✅ Repository method (used by multiple services, encapsulates SQL)
public function findModifiedSince(int $sinceTimestamp): array {
    $sinceDate = SyncCursor::lowerBound($sinceTimestamp);
    $stmt = $this->db->prepare(
        'SELECT * FROM members WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([$sinceDate]);
    return $stmt->fetchAll();
}

// ❌ Not in repository (one-off query that belongs in service)
// Don't create a repository method for every unique query
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
- **Extra layer**: More files/code than direct PDO queries in services

### Mitigations

1. **Keep BaseRepository focused** on CRUD only
2. **Add domain-specific methods** in module repositories for complex queries
3. **Document module-specific methods** clearly
4. **Provide working examples** in each module

---

## See Also

- **Pattern 005**: Repository Interface Pattern (contract definition)
- **Pattern 010**: Shared Base Service Layer (service patterns)
- **Pattern 009**: Module Structure & Organization (module context)
- **Pattern 004**: Service Layer (business logic)
- **ADR-0004**: Immutable Transaction Storage (repository patterns for immutable data)
