# Pattern 002: Enum for Type-Safe Domain Values

**Category**: Type Safety & Domain Modeling
**PHP Feature**: PHP 8.1+ Backed Enums
**Related ADR**: ADR-0018 (Modular Architecture - Type-Safe Responses)

---

## Problem

Without enums, domain values (e.g., supported languages, transaction types) are represented as strings or magic constants:

```php
// ❌ Problematic: No type safety
$language = 'de';  // Is this valid? What are valid values?
$type = 'PURCHASE'; // String comparison error-prone

if ($language === 'De') {  // Bug: typo, case sensitivity
    // ...
}
```

Issues:
- No compile-time validation
- Typos go undetected
- IDE autocomplete not available
- Invalid values slip through

---

## Solution

Use **PHP Backed Enums** to:
- Enumerate valid domain values
- Provide type-safe, IDE-aware constants
- Enable pattern matching on values
- Make invalid states impossible

---

## Implementation Pattern

### Basic Enum (Backed Enum)

```php
// src/Modules/Members/Enums/SupportedLanguage.php
namespace App\Modules\Members\Enums;

enum SupportedLanguage: string
{
    case German = 'de';
    case English = 'en';
    case French = 'fr';
}
```

### Usage in Code

```php
// Type-safe usage
$language = SupportedLanguage::German;

// From external input (validated)
$language = SupportedLanguage::from($body['preferred_language']); // 'de' → SupportedLanguage::German

// Safe comparison
if ($language === SupportedLanguage::German) {
    // ...
}

// Get backing value for DB/API
$value = $language->value; // 'de'
```

### Enum in Validation

Combine with the `Validator` (Pattern 001) `in:` rule:

```php
// In controller: validate input is a valid enum value
if (!$this->validator->validate($body, [
    'preferred_language' => ['required', 'string', 'in:de,en,fr'],
])) {
    return $this->json($response, [...], 422);
}

// Convert validated string to type-safe enum
$language = SupportedLanguage::from($body['preferred_language']);
```

### Enum with Methods

```php
// src/Shared/Enums/AuditAction.php
namespace App\Shared\Enums;

enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case ANONYMIZE = 'anonymize';

    public function label(): string
    {
        return match($this) {
            self::CREATE => 'Created',
            self::UPDATE => 'Updated',
            self::DELETE => 'Deleted',
            self::ANONYMIZE => 'Anonymized',
        };
    }
}
```

### Enum in Service Layer

```php
// src/Modules/Members/Services/MembersService.php
use App\Modules\Members\Enums\SupportedLanguage;

class MembersService
{
    public function updateLanguage(
        string $memberId,
        SupportedLanguage $language  // Type-safe parameter
    ): MemberDto {
        $member = $this->membersRepository->updateById($memberId, [
            'preferred_language' => $language->value,
        ]);
        return MemberDto::fromRow($member);
    }
}
```

---

## Common Enum Patterns

### Trying Enum (Invalid Values)

```php
// Safe fallback for invalid input
$language = SupportedLanguage::tryFrom($userInput) ?? SupportedLanguage::German;
```

### Enum in Database Query (PDO)

```php
// Store backing value in DB
$stmt = $pdo->prepare('UPDATE members SET preferred_language = ? WHERE id = ?');
$stmt->execute([$language->value, $memberId]); // 'de'

// Read from DB and hydrate
$row = $stmt->fetch();
$language = SupportedLanguage::from($row['preferred_language']);
```

### Enum in JSON Response (via DTO)

```php
// In DTO toArray() method
public function toArray(): array
{
    return [
        'preferred_language' => $this->preferredLanguage, // Already string from DB
    ];
}
```

---

## Benefits

- **Type safety**: IDE autocomplete, compile-time checks
- **Invalid states impossible**: Can't accidentally use invalid value
- **Self-documenting**: Valid values visible in code
- **Testable**: Trivial to iterate valid enum cases
- **Refactor-safe**: Rename enum case; IDE finds all usages
- **Performance**: Enum instances are singletons; no memory overhead

---

## When to Use

- Domain values with fixed set (languages, statuses, types)
- Database enum columns
- API request/response fields with restricted values
- Switch/match statements on domain values

---

## When NOT to Use

- Open-ended values (free text, variable categories)
- Frequently changing value sets (use database tables instead)
- Single-use constants (simple `const` suffices)

---

## Consistency with Modularity (ADR-0018)

Enums are organized by scope:
- **Module-specific**: `src/Modules/Members/Enums/SupportedLanguage.php`
- **Shared**: `src/Shared/Enums/AuditAction.php`, `src/Shared/Enums/EntityType.php`
- Reused across modules where applicable
- Import in controllers, services, repositories, DTOs

---

## Related Patterns

- **Pattern 001**: Input Validation (validate enum values with `in:` rule)
- **Pattern 003**: Data Transfer Objects (enum fields in DTOs)
- **Pattern 004**: Service Layer (type-safe method parameters)

---

## References

- [PHP 8.1 Enums Documentation](https://www.php.net/manual/en/language.enums.backed.php)
