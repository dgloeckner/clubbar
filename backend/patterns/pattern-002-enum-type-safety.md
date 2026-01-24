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
- Repetitive string/constant definitions

---

## Solution

Use **PHP Backed Enums** to:
- Enumerate valid domain values
- Provide type-safe, IDE-aware constants
- Enable pattern matching on values
- Make invalid states impossible
- Document valid values in code

---

## Implementation Pattern

### Basic Enum (Backed Enum)

```php
// app/Enums/SupportedLanguage.php
<?php

namespace App\Enums;

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
$language: SupportedLanguage = SupportedLanguage::German;

// From external input (validated)
$language = SupportedLanguage::from($request->input('language')); // 'de' → SupportedLanguage::German

// Safe comparison
if ($language === SupportedLanguage::German) {
    // ...
}

// Get backing value for DB/API
$value = $language->value; // 'de'
```

### Enum in Form Requests

```php
// app/Http/Requests/UpdateLanguageRequest.php
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'preferred_language' => [
            'required',
            'string',
            Rule::enum(SupportedLanguage::class), // Validates against enum values
        ],
    ];
}

public function preferredLanguage(): SupportedLanguage
{
    return SupportedLanguage::from($this->validated('preferred_language'));
}
```

### Enum with Methods

```php
// app/Enums/TransactionType.php
<?php

namespace App\Enums;

enum TransactionType: string
{
    case Purchase = 'purchase';
    case Correction = 'correction';

    // Helper methods
    public function isPositiveAmount(): bool
    {
        return $this === self::Purchase;
    }

    public function label(): string
    {
        return match($this) {
            self::Purchase => 'Purchase',
            self::Correction => 'Correction/Refund',
        };
    }
}
```

### Enum in Service Layer

```php
// app/Services/SyncService.php
use App\Enums\SupportedLanguage;

final readonly class SyncService
{
    public function updateMemberLanguage(
        string $memberId,
        SupportedLanguage $language  // Type-safe parameter
    ): MemberDto {
        return $this->members->updateLanguage($memberId, $language);
    }
}
```

### Enum in Repository Interface

```php
// app/Repositories/MemberRepository.php
interface MemberRepository
{
    public function updateLanguage(
        string $memberId,
        SupportedLanguage $language
    ): MemberDto;
}
```

---

## Migration from Strings

### Before (String-based)

```php
// Database stores 'de', 'en', 'fr'
public function setLanguage(string $language): void
{
    // Manual validation needed
    if (!in_array($language, ['de', 'en', 'fr'])) {
        throw new InvalidArgumentException('Invalid language');
    }
    $this->language = $language;
}
```

### After (Enum-based)

```php
// Database stores enum backing value 'de', 'en', 'fr'
// But code uses type-safe SupportedLanguage enum
public function setLanguage(SupportedLanguage $language): void
{
    $this->language = $language->value; // Auto-validated
}

// Reading from DB
$language = SupportedLanguage::from($dbValue); // Throws if invalid
```

---

## Common Enum Patterns

### Trying Enum (Invalid Values)

```php
// Safe fallback for invalid input
$language = SupportedLanguage::tryFrom($userInput) ?? SupportedLanguage::German;
```

### Enum in Database Query

```php
// Store backing value in DB
$language->value; // 'de'

// Query for enum value
$members = Member::where('preferred_language', SupportedLanguage::German->value)->get();

// Or type-cast in model
class Member extends Model
{
    protected $casts = [
        'preferred_language' => SupportedLanguage::class,
    ];
}
// Now Eloquent auto-casts: $member->preferred_language is SupportedLanguage instance
```

### Enum in JSON Response

```php
// In DTO toArray() method
public function toArray(): array
{
    return [
        'preferred_language' => $this->preferredLanguage->value, // 'de'
        'transaction_type' => $this->transactionType->value,     // 'purchase'
    ];
}
```

---

## Benefits

✅ **Type safety**: IDE autocomplete, compile-time checks
✅ **Invalid states impossible**: Can't accidentally use invalid value
✅ **Self-documenting**: Valid values visible in code
✅ **Testable**: Trivial to iterate valid enum cases
✅ **Refactor-safe**: Rename enum case; IDE finds all usages
✅ **Performance**: Enum instances are singletons; no memory overhead

---

## When to Use

- Domain values with fixed set (languages, statuses, types)
- Database enum columns
- API request/response fields with restricted values
- Validation rules dependent on enum values
- Switch/match statements on domain values

---

## When NOT to Use

- Open-ended values (free text, variable categories)
- Frequently changing value sets (use database tables instead)
- Single-use constants (simple `const` suffices)

---

## Consistency with Modularity (ADR-0018)

Enums are **shared infrastructure**:
- Located in `app/Enums/` directory
- Reused across modules (e.g., `SupportedLanguage` used by members, sync)
- Centralized definition prevents duplication
- Import in FormRequests, Services, Repositories, DTOs

---

## Related Patterns

- **Pattern 001**: Form Requests (validation with Rule::enum)
- **Pattern 003**: Data Transfer Objects (enum fields in DTOs)
- **Pattern 004**: Service Layer (type-safe method parameters)

---

## References

- [PHP 8.1 Enums Documentation](https://www.php.net/manual/en/language.enums.backed.php)
- [Laravel Enum Casting](https://laravel.com/docs/eloquent-mutators#enum-casting)
