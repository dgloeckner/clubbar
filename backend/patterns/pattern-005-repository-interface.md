# Pattern 005: Repository for Data Access

**Category**: Data Access & Persistence
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Independence), ADR-0004 (Immutable Storage)

---

## Problem

Without repositories, data access logic is scattered throughout the application:

```php
// ❌ Problematic: Direct PDO queries in service
class MembersService
{
    public function syncMembers(int $since): SyncResultDto
    {
        $stmt = $this->pdo->prepare('SELECT * FROM members WHERE updated_at > ?');
        $stmt->execute([date('Y-m-d H:i:s', $since)]);
        $rows = $stmt->fetchAll();
        // Manual mapping...
    }
}
```

Issues:
- Data access logic duplicated across services
- Hard to change query strategy
- Difficult to unit test (requires database)
- Services tightly coupled to SQL details
- No abstraction layer for persistence

---

## Solution

Use **Repository classes** to:
- Centralize data access logic per entity
- Encapsulate PDO queries and prepared statements
- Provide clean API for services (find, create, update)
- Return raw associative arrays (services convert to DTOs)
- Enable consistent logging and error handling

---

## Implementation Pattern

### Repository Class

```php
// src/Modules/Members/Repositories/MembersRepository.php
namespace App\Modules\Members\Repositories;

use PDO;
use App\Shared\Logging\Logger;

class MembersRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', (int) ($sinceTimestamp / 1000));

        $stmt = $this->db->prepare(
            'SELECT * FROM members
             WHERE updated_at > ? OR (deleted_at > ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, card_uid, first_name, last_name, email, phone,
             preferred_language, is_active, iban, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['card_uid'] ?? null,
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['preferred_language'] ?? 'de',
            $data['is_active'] ?? true ? 1 : 0,
            $data['iban'] ?? null,
            $now,
            $now,
        ]);

        $this->logger->info('Member created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['card_uid', 'first_name', 'last_name', 'email', 'phone',
                     'preferred_language', 'is_active', 'iban', 'deleted_at'];
        [$set, $values] = $this->buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s'); // updated_at
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE members SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        return $this->findById($id);
    }

    public function listPaginated(int $limit, int $offset, array $filters, string $sortKey, string $sortOrder, ?string $search): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = ?';
            $params[] = $filters['is_active'] ? 1 : 0;
        }

        if ($search) {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = implode(' AND ', $where);
        $sortMap = ['name' => 'last_name', 'created_at' => 'created_at', 'email' => 'email'];
        $sortCol = $sortMap[$sortKey] ?? 'created_at';
        $sortDir = strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC';

        // Count total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM members WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $stmt = $this->db->prepare(
            "SELECT * FROM members WHERE {$whereClause} ORDER BY {$sortCol} {$sortDir} LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }

    private function buildUpdate(array $data, array $allowed): array
    {
        $set = [];
        $values = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $set[] = "{$key} = ?";
                $values[] = $value;
            }
        }
        return [implode(', ', $set), $values];
    }
}
```

---

## Service Using Repository

Services call repository methods and convert results to DTOs:

```php
// src/Modules/Members/Services/MembersService.php
class MembersService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private AuditService $auditService,
    ) {}

    public function getMember(string $memberId): MemberAdminDto
    {
        $row = $this->membersRepository->findById($memberId);
        if (!$row) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        return MemberAdminDto::fromRow($row);
    }

    public function listMembers(int $limit, int $offset, ...): PaginatedResultDto
    {
        $result = $this->membersRepository->listPaginated($limit, $offset, ...);
        $items = array_map(fn($row) => MemberAdminDto::fromRow($row)->toArray(), $result['items']);
        return new PaginatedResultDto(items: $items, total: $result['total'], ...);
    }
}
```

---

## Repository Conventions

### Return Types

| Method | Returns | Notes |
|--------|---------|-------|
| `findById(string $id)` | `?array` | Raw PDO row or null |
| `findAll()` | `array` | Array of raw PDO rows |
| `create(array $data)` | `array` | Created row (re-fetched) |
| `updateById(string $id, array $data)` | `?array` | Updated row (re-fetched) |
| `listPaginated(...)` | `['items' => array, 'total' => int]` | Paginated result |

### Constructor Dependencies

All repositories receive `PDO` and `Logger` via constructor:

```php
public function __construct(
    private PDO $db,
    private Logger $logger,
) {}
```

### Prepared Statements

All queries use prepared statements to prevent SQL injection:

```php
// ✅ Always use prepared statements
$stmt = $this->db->prepare('SELECT * FROM members WHERE id = ?');
$stmt->execute([$id]);

// ❌ Never concatenate user input
$this->db->query("SELECT * FROM members WHERE id = '{$id}'");
```

---

## Benefits

- **Abstraction**: Services don't know SQL details
- **Testability**: Can mock repository in service tests
- **Centralization**: Query logic in one place per entity
- **Reusability**: Multiple services can use same repository
- **Security**: Prepared statements prevent injection
- **Consistency**: All data access returns same types

---

## When to Use

- All data access from services
- Complex queries (pagination, filtering, sorting)
- Operations used by multiple services

---

## When NOT to Use

- Health checks or trivial queries can be done inline

---

## Consistency with Modularity (ADR-0018)

Repositories are **module-scoped**:
- Located in `src/Modules/{Module}/Repositories/`
- Each module owns repositories for its entities
- Shared helper trait in `src/Shared/Repository/SafeQuery.php`

---

## Related to Immutability (ADR-0004)

Repositories enforce immutability:
- Transaction repository only allows INSERT (never UPDATE/DELETE)
- Settlement items are immutable links
- Query logic respects append-only constraint

---

## Related Patterns

- **Pattern 003**: Data Transfer Objects (services convert rows to DTOs)
- **Pattern 004**: Service Layer (services use repositories)
- **Pattern 007**: Exception Handling (repositories may trigger exceptions)

---

## References

- [Repository Pattern - Microsoft Learn](https://learn.microsoft.com/en-us/dotnet/architecture/microservices/microservice-ddd-cqrs-patterns/infrastructure-persistence-layer-design)
- [Dependency Inversion Principle](https://en.wikipedia.org/wiki/Dependency_inversion_principle)
