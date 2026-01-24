# Pattern 001: Form Requests for Input Validation

**Category**: Validation & Input Handling
**Related ADR**: ADR-0017 (Input Validation, Injection Prevention)
**Related to Module**: Applicable to all API endpoints

---

## Problem

Validation logic scattered throughout controller methods leads to:
- Mixed HTTP concerns with business logic
- Duplicated validation rules across multiple endpoints
- Difficult to test validation independently
- No typed structures for request data
- Inconsistent error response formats

---

## Solution

Use Laravel **FormRequest** classes to:
- Declare validation rules declaratively
- Automatically validate incoming data
- Provide typed accessor methods (e.g., `preferredLanguage(): SupportedLanguage`)
- Separate validation concerns from controller logic
- Enable reusable validation across endpoints

---

## Implementation Pattern

### Basic Structure

```php
// app/Http/Requests/UpdateLanguageRequest.php
<?php

namespace App\Http\Requests;

use App\Enums\SupportedLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLanguageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'preferred_language' => [
                'required',
                'string',
                Rule::enum(SupportedLanguage::class),
            ],
        ];
    }

    // Typed accessor methods for validated data
    public function preferredLanguage(): SupportedLanguage
    {
        return SupportedLanguage::from($this->validated('preferred_language'));
    }
}
```

### Array Validation

```php
// app/Http/Requests/UploadTransactionsRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadTransactionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'min:1', 'max:100'],
            'transactions.*.id' => ['required', 'uuid'],
            'transactions.*.member_id' => ['required', 'uuid'],
            'transactions.*.product_id' => ['required', 'uuid'],
            'transactions.*.amount_cents' => ['required', 'integer', 'min:1'],
            'transactions.*.created_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }
}
```

### Query Parameter Validation

```php
// app/Http/Requests/SyncRequest.php
<?php

namespace App\Http\Requests;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class SyncRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }

    // Type-safe accessor for query parameter
    public function since(): DateTimeImmutable
    {
        $since = $this->query('since', '1970-01-01T00:00:00Z');
        return new DateTimeImmutable($since);
    }
}
```

---

## Controller Usage

Controllers inject FormRequest and use its typed accessors:

```php
public function updateLanguage(UpdateLanguageRequest $request, string $memberId): JsonResponse
{
    // Validation already passed; $request->validated() contains safe data
    $language = $request->preferredLanguage(); // Type-safe enum

    $member = $this->syncService->updateMemberLanguage($memberId, $language);

    return response()->json(['preferred_language' => $member->preferredLanguage]);
}
```

---

## Key Benefits

✅ **Automatic validation**: Laravel validates before controller method executes
✅ **Type safety**: Typed accessor methods prevent type errors
✅ **DRY**: Reusable validation rules across endpoints
✅ **Testable**: Validation logic isolated and mockable
✅ **Consistent errors**: FormRequest validation failures produce standard error responses
✅ **Security**: Prepared statements; prevents injection attacks (via framework)

---

## When to Use

- All public API endpoints accepting user input
- Query parameters requiring specific formats (UUIDs, dates, enums)
- Nested arrays/objects (batch operations, complex payloads)
- Validation rules reused across multiple endpoints

---

## When NOT to Use

- Simple pass-through endpoints (raw file uploads, proxies)
- Internal endpoints without external input
- Schema validation handled at protocol level

---

## Consistency with Modularity (ADR-0018)

FormRequests are **module-specific**:
- Located in `app/Http/Requests/` directory
- Named by module (e.g., `MembersRequest`, `ProductsRequest`)
- Each module defines its own validation rules
- Enables independent testing of validation per module

---

## Related Patterns

- **Pattern 002**: Enum for Type-Safe Domain Values
- **Pattern 003**: Data Transfer Objects (DTOs) for Responses
- **Pattern 005**: Exception Handler for Centralized Error Responses

---

## References

- [Laravel Form Requests Documentation](https://laravel.com/docs/requests#form-request-validation)
- [ADR-0017: Input Validation and Injection Prevention](../adr/0017-input-validation-injection-prevention.md)
