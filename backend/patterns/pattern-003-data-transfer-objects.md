# Pattern 003: Data Transfer Objects (DTOs) for Type-Safe Responses

**Category**: Data Transfer & Response Handling
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Type-Safe Responses)

---

## Problem

Without DTOs, response construction is scattered and error-prone:

```php
// ❌ Problematic: Inconsistent response formats
public function member(string $id): JsonResponse
{
    $member = $this->db->find($id);

    // Manual array construction - easy to forget fields
    return response()->json([
        'id' => $member->id,
        'first_name' => $member->firstName,
        // 'email' => ... forgot this field
        'created_at' => $member->createdAt->format('Y-m-d\TH:i:s\Z'),
    ]);
}

// Another endpoint constructs differently
public function members(): JsonResponse
{
    $members = $this->db->all();

    return response()->json(array_map(function ($m) {
        return [
            'id' => $m->id,
            'name' => $m->firstName . ' ' . $m->lastName, // Different structure!
            // created_at format might differ
        ];
    }, $members));
}
```

Issues:
- No type checking for response data
- Inconsistent response formats across endpoints
- Difficult to refactor API response structure
- No validation that all required fields are included
- Response construction duplicated across multiple endpoints

---

## Solution

Use **immutable DTOs** (Data Transfer Objects) to:
- Encapsulate response data with type safety
- Ensure consistent response structure across endpoints
- Provide single `toArray()` method for JSON serialization
- Make invalid data impossible (constructor validation)
- Document API response schema in code

---

## Implementation Pattern

### Basic DTO Structure

```php
// app/DTOs/MemberDto.php
<?php

namespace App\DTOs;

use DateTimeImmutable;

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
        public ?DateTimeImmutable $deletedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

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
            'deleted_at' => $this->deletedAt?->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
```

### DTO in Service Layer

```php
// app/Services/SyncService.php
use App\DTOs\MemberDto;

final readonly class SyncService
{
    public function updateMemberLanguage(
        string $memberId,
        SupportedLanguage $language
    ): MemberDto {  // Type-safe return
        $member = $this->members->find($memberId);
        $member->update(['preferred_language' => $language->value]);

        // Construct DTO from model
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

### DTO in Controller

```php
// app/Http/Controllers/SyncController.php
public function updateLanguage(
    UpdateLanguageRequest $request,
    string $memberId
): JsonResponse {
    $member = $this->syncService->updateMemberLanguage(
        $memberId,
        $request->preferredLanguage()
    );

    // Use DTO's toArray() for consistent response
    return response()->json([
        'member' => $member->toArray(),
        'updated_at' => $member->updatedAt->format('Y-m-d\TH:i:s\Z'),
    ]);
}
```

---

## Collection DTOs

### Sync Result DTO (Paginated/Cursor-based)

```php
// app/DTOs/SyncResultDto.php
<?php

namespace App\DTOs;

final readonly class SyncResultDto
{
    public function __construct(
        public array $items,      // Array of DTOs (MemberDto, ProductDto, etc.)
        public string $cursor,    // Cursor for pagination
        public bool $hasMore,     // Whether more results available
    ) {}

    public function toResponse(string $itemsKey): array
    {
        return [
            $itemsKey => array_map(fn($item) => $item->toArray(), $this->items),
            'cursor' => $this->cursor,
            'count' => count($this->items),
            'has_more' => $this->hasMore,
        ];
    }
}
```

### Usage in Controller

```php
public function members(SyncRequest $request): JsonResponse
{
    $result = $this->syncService->syncMembers($request->since());

    // Response structure guaranteed by DTO
    return response()->json($result->toResponse('members'));
}
```

---

## Batch Operation DTO

```php
// app/DTOs/TransactionBatchResultDto.php
<?php

namespace App\DTOs;

final readonly class TransactionBatchResultDto
{
    public function __construct(
        public array $acceptedIds,    // UUIDs of accepted transactions
        public int $rejectedCount,    // Number of rejected transactions
        public array $errors,         // Error details per rejected transaction
    ) {}

    public function toArray(): array
    {
        return [
            'accepted_ids' => $this->acceptedIds,
            'rejected' => [
                'count' => $this->rejectedCount,
                'errors' => $this->errors,
            ],
        ];
    }
}
```

---

## Converting Models to DTOs

### From Eloquent Model

```php
// Method 1: Explicit constructor in repository
return new MemberDto(
    id: $model->id,
    cardUid: $model->card_uid,
    firstName: $model->first_name,
    // ... all fields
);

// Method 2: Helper method in model
// app/Models/Member.php
public function toDto(): MemberDto
{
    return new MemberDto(
        id: $this->id,
        cardUid: $this->card_uid,
        // ... mapping
    );
}

// Usage
return $model->toDto();
```

### Mapping Multiple Models

```php
// In Service or Repository
$members = $this->members->getAllActive();

$dtos = array_map(
    fn(Member $member) => new MemberDto(
        id: $member->id,
        // ... fields
    ),
    $members
);

return new SyncResultDto(
    items: $dtos,
    cursor: $nextCursor,
    hasMore: $hasMoreResults,
);
```

---

## Benefits

✅ **Type safety**: IDE knows response structure; type hints available
✅ **Consistency**: All endpoints return same response format
✅ **Immutability**: `readonly` properties prevent accidental mutation
✅ **Single responsibility**: DTO only handles data transformation
✅ **Testability**: Easy to construct test DTOs
✅ **Documentation**: Response structure clear in code (no guessing)
✅ **Refactor-safe**: Change response format in one place
✅ **Date formatting**: Consistent ISO-8601 formatting

---

## When to Use

- All API responses (list, detail, created resource)
- Collection responses (with pagination/cursor)
- Batch operation results
- Nested response structures
- Error details that need consistent format

---

## When NOT to Use

- Simple scalar responses (e.g., `{ "success": true }`)
- Pass-through responses (raw file downloads)
- Third-party API responses (use external models)

---

## Consistency with Modularity (ADR-0018)

DTOs are **module-specific**:
- Located in `app/DTOs/` directory (or `Modules/MemberModule/DTOs/`)
- Named after entity (e.g., `MemberDto`, `ProductDto`)
- Each module responsible for own DTOs
- Shared DTOs in common location (e.g., `SyncResultDto`, `TransactionBatchResultDto`)

---

## Related to Immutability (ADR-0004)

DTOs reinforce immutability:
- `readonly` class prevents modification after construction
- Complete audit trail: DTO captures all fields at point of serialization
- Linked to transaction corrections via DTOs (e.g., `TransactionDto` includes `relatedTransactionId`)

---

## Related Patterns

- **Pattern 001**: Form Requests (validation of input)
- **Pattern 002**: Enum (type-safe fields in DTOs)
- **Pattern 004**: Service Layer (DTOs returned from services)
- **Pattern 005**: Repository Interfaces (DTOs returned from repositories)

---

## References

- [PHP readonly properties](https://www.php.net/manual/en/language.oop5.properties.php#language.oop5.properties.readonly-properties)
- [Data Transfer Object Pattern](https://en.wikipedia.org/wiki/Data_transfer_object)
