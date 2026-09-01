# Pattern 003: Data Transfer Objects (DTOs) for Type-Safe Responses

**Category**: Data Transfer & Response Handling
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Type-Safe Responses)

---

## Problem

Without DTOs, response construction is scattered and error-prone:

```php
// ❌ Problematic: Inconsistent response formats
public function show(Request $request, Response $response, array $args): Response
{
    $row = $this->repo->findById($args['id']);

    // Manual array construction - easy to forget fields
    $response->getBody()->write(json_encode([
        'id' => $row['id'],
        'first_name' => $row['first_name'],
        // 'email' => ... forgot this field
    ]));
    return $response;
}
```

Issues:
- No type checking for response data
- Inconsistent response formats across endpoints
- Difficult to refactor API response structure
- No validation that all required fields are included

---

## Solution

Use **immutable DTOs** (Data Transfer Objects) to:
- Encapsulate response data with type safety
- Ensure consistent response structure across endpoints
- Provide `fromRow()` factory for database row conversion
- Provide `toArray()` method for JSON serialization

---

## Implementation Pattern

### Basic DTO Structure

```php
// src/Modules/Members/DTOs/MemberDto.php
namespace App\Modules\Members\DTOs;

final readonly class MemberDto
{
    public function __construct(
        public string $id,
        public ?string $cardUid,
        public string $firstName,
        public string $lastName,
        public string $preferredLanguage,
        public bool $isActive,
        public bool $isSepaValid,
        public ?string $deletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            cardUid: $row['card_uid'] ?? null,
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            preferredLanguage: $row['preferred_language'],
            isActive: (bool) $row['is_active'],
            isSepaValid: !empty($row['iban']) && !empty($row['mandate_reference']),
            deletedAt: $row['deleted_at'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'card_uid' => $this->cardUid,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'preferred_language' => $this->preferredLanguage,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'deleted_at' => $this->deletedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
```

### DTO in Service Layer

```php
// src/Modules/Members/Services/MembersService.php
class MembersService
{
    public function getMember(string $memberId): MemberAdminDto
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        return MemberAdminDto::fromRow($member);
    }

    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->membersRepository->findModifiedSince($since);
        $members = array_map(fn($row) => MemberDto::fromRow($row), $rows);
        return new SyncResultDto(items: $members, cursor: $cursor);
    }
}
```

### DTO in Controller

```php
// src/Modules/Members/Controllers/AdminController.php
public function show(Request $request, Response $response, array $args): Response
{
    $member = $this->membersService->getMember($args['memberId']);
    return $this->json($response, $member->toArray());
}

public function store(Request $request, Response $response): Response
{
    // ...validation...
    $member = $this->membersService->createMember(...);
    return $this->json($response, $member->toArray(), 201);
}
```

---

## Collection DTOs

### Paginated Result DTO

```php
// src/Shared/DTOs/PaginatedResultDto.php
namespace App\Shared\DTOs;

final readonly class PaginatedResultDto
{
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {}

    public function hasMore(): bool
    {
        return ($this->offset + $this->limit) < $this->total;
    }

    public function toArray(): array
    {
        $items = array_map(function ($item) {
            return is_object($item) && method_exists($item, 'toArray')
                ? $item->toArray()
                : (is_array($item) ? $item : (array) $item);
        }, $this->items);

        return [
            'items' => $items,
            'total' => $this->total,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'has_more' => $this->hasMore(),
        ];
    }
}
```

### Sync Result DTO

```php
// src/Shared/DTOs/SyncResultDto.php
namespace App\Shared\DTOs;

final readonly class SyncResultDto
{
    public function __construct(
        public array $items,
        public int $cursor,
    ) {}

    public function toArray(string $itemsKey = 'items'): array
    {
        $mappedItems = array_map(
            fn($item) => is_object($item) && method_exists($item, 'toArray')
                ? $item->toArray() : $item,
            $this->items
        );
        return [
            $itemsKey => $mappedItems,
            'cursor' => $this->cursor,
            'count' => count($mappedItems),
        ];
    }
}
```

---

## Converting Database Rows to DTOs

### The `fromRow()` Pattern

All DTOs use a static `fromRow(array $row)` factory that converts a PDO fetch result to a typed DTO:

```php
// Repository returns raw associative array from PDO
$row = $stmt->fetch(PDO::FETCH_ASSOC);
// ['id' => '...', 'first_name' => 'John', 'is_active' => 1, ...]

// DTO factory handles type conversion
$dto = MemberDto::fromRow($row);
// MemberDto(id: '...', firstName: 'John', isActive: true, ...)
```

Key `fromRow()` responsibilities:
- Map snake_case DB columns → camelCase DTO properties
- Type coercion: `(bool) $row['is_active']` for integer columns
- Computed fields: `isSepaValid` derived from `iban` + `mandate_reference`
- Null handling: `$row['card_uid'] ?? null`

### Mapping Multiple Rows

```php
// In service
$rows = $this->repository->findAll();
$dtos = array_map(fn($row) => MemberDto::fromRow($row), $rows);
```

---

## Timestamps: every instant leaves labelled UTC

**`toArray()` puts every instant through `DateFormatter::toUtcIso()`. No
exceptions, and no judgement about whether "this one is only shown as a date".**

```php
use App\Shared\Utils\DateFormatter;

public function toArray(): array
{
    return [
        // "2026-09-01 19:33:12" → "2026-09-01T19:33:12Z"
        'queued_at' => DateFormatter::toUtcIso($this->queuedAt),
        'sent_at' => DateFormatter::toUtcIso($this->sentAt),
        // A DATE column is a calendar day, not an instant: it carries no zone
        // and must not grow one, or the deadline moves a day.
        'settlement_date' => $this->settlementDate,
    ];
}
```

Why it is a rule rather than a habit: the columns hold UTC end to end
(`Shared\Time\Utc` pins PHP, `ConnectionFactory` pins the session), and the
frontend converts to the reader's local time. A bare `2026-09-01 19:33:12` is
**valid input to `new Date()`** and is specified to mean *local* time — so a
forgotten label does not fail, it silently subtracts the reader's own offset.
An invitation queued at 21:33 CEST was listed as 19:33 for exactly this reason,
long after #365 fixed the same class of bug elsewhere. Replacing the space with
a `T` is not enough either: `2026-09-01T19:33:12` is ISO 8601 *without a zone*,
which every parser also reads as local.

The rule applies to anything a client can read, not only to `*Dto` classes: a
service or controller that assembles a response array by hand (the dashboard's
`system_status`, an auth controller's profile payload) owes the same label.

Three things follow from it:

1. **`format: date-time` in `api/admin.yaml` is a promise about the string**,
   and a bare MariaDB datetime does not keep it. If the field is an instant, the
   spec says `date-time` and the response ends in `Z`.
2. **A date-only column stays bare.** `settlement_date`, `mandate_signed_at` and
   `date_of_birth` denote a calendar day in every zone; the frontend's
   `parseApiDate()` and the backend's `ClubTimeZone::moment()` both branch on
   exactly that shape, so the shape *is* the contract.
3. **Assert it in the DTO's unit test.** One `assertSame('…T…Z', …)` per instant
   is what stops the next reader from having to notice a two-hour offset on a
   screen to find out.

---

## Benefits

- **Type safety**: IDE knows response structure; type hints available
- **Consistency**: All endpoints return same response format
- **Immutability**: `readonly` properties prevent accidental mutation
- **Single responsibility**: DTO only handles data transformation
- **Testability**: Easy to construct test DTOs
- **Refactor-safe**: Change response format in one place

---

## When to Use

- All API responses (list, detail, created resource)
- Collection responses (with pagination/cursor)
- Batch operation results
- Nested response structures

---

## When NOT to Use

- Simple scalar responses (e.g., `{ "message": "deleted" }`)
- Pass-through responses (raw file downloads)

---

## Consistency with Modularity (ADR-0018)

DTOs are organized by scope:
- **Module-specific**: `src/Modules/Members/DTOs/MemberDto.php`
- **Shared**: `src/Shared/DTOs/PaginatedResultDto.php`, `src/Shared/DTOs/SyncResultDto.php`
- Named after entity (e.g., `MemberDto`, `ProductDto`)
- Each module responsible for own DTOs

---

## Related Patterns

- **Pattern 001**: Input Validation (validation of input)
- **Pattern 002**: Enum (type-safe fields in DTOs)
- **Pattern 004**: Service Layer (DTOs returned from services)
- **Pattern 005**: Repository (repositories return raw rows, services convert to DTOs)

---

## References

- [PHP readonly properties](https://www.php.net/manual/en/language.oop5.properties.php#language.oop5.properties.readonly-properties)
- [Data Transfer Object Pattern](https://en.wikipedia.org/wiki/Data_transfer_object)
