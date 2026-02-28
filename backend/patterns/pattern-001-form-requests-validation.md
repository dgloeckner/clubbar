# Pattern 001: Input Validation

**Category**: Validation & Input Handling
**Related ADR**: ADR-0017 (Input Validation, Injection Prevention)
**Related to Module**: Applicable to all API endpoints

---

## Problem

Validation logic scattered throughout controller methods leads to:
- Mixed HTTP concerns with business logic
- Duplicated validation rules across multiple endpoints
- Difficult to test validation independently
- Inconsistent error response formats

---

## Solution

Use the shared **`Validator`** class (`App\Shared\Validation\Validator`) to:
- Declare validation rules as associative arrays
- Validate incoming data before passing to services
- Return structured error messages on failure
- Support database-backed rules (e.g., `unique`) via PDO

---

## Implementation Pattern

### Validator Class

The `Validator` is a shared service injected via `ServiceFactory`:

```php
// src/Shared/Validation/Validator.php
namespace App\Shared\Validation;

class Validator
{
    public function __construct(private \PDO $pdo) {}

    public function validate(array $data, array $rules): bool
    {
        // Returns true if all rules pass, false otherwise
    }

    public function errors(): array
    {
        // Returns ['field_name' => ['error message', ...], ...]
    }
}
```

### Available Rules

| Rule | Description | Example |
|------|-------------|---------|
| `required` | Field must be present and non-empty | `'required'` |
| `string` | Must be a string | `'string'` |
| `integer` | Must be numeric | `'integer'` |
| `numeric` | Must be numeric | `'numeric'` |
| `email` | Must be valid email | `'email'` |
| `boolean` | Must be boolean-like | `'boolean'` |
| `uuid` | Must be valid UUID | `'uuid'` |
| `date` | Must be parseable date | `'date'` |
| `array` | Must be an array | `'array'` |
| `json` | Must be valid JSON | `'json'` |
| `nullable` | Value may be null | `'nullable'` |
| `min:N` | Min length (string) or min value (number) | `'min:3'` |
| `max:N` | Max length (string) or max value (number) | `'max:100'` |
| `gt:N` | Greater than N | `'gt:0'` |
| `gte:N` | Greater than or equal to N | `'gte:1'` |
| `in:a,b,c` | Must be one of listed values | `'in:de,en,fr'` |
| `regex:/pattern/` | Must match regex | `'regex:/^[0-9A-F]+$/'` |
| `same:field` | Must match another field | `'same:password'` |
| `unique:table,col` | Must be unique in database | `'unique:members,card_uid'` |
| `unique:table,col,id` | Unique excluding a specific ID | `'unique:members,card_uid,abc-123'` |

### Basic Usage in Controller

```php
// src/Modules/Members/Controllers/AdminController.php
namespace App\Modules\Members\Controllers;

use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
    ) {}

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'preferred_language' => ['required', 'string', 'in:de,en,fr'],
            'card_uid' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^[0-9A-F]+$/', 'unique:members,card_uid'],
        ])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => $this->validator->errors(),
            ], 422);
        }

        $member = $this->membersService->createMember(...);
        return $this->json($response, $member->toArray(), 201);
    }
}
```

### Validation with Unique Constraint (Excluding Current Record)

```php
public function update(Request $request, Response $response, array $args): Response
{
    $memberId = $args['memberId'];
    $body = $request->getParsedBody() ?? [];

    if (isset($body['card_uid'])) {
        if (!$this->validator->validate($body, [
            'card_uid' => ['nullable', 'string', 'min:8', 'max:20',
                           'regex:/^[0-9A-F]+$/',
                           "unique:members,card_uid,{$memberId}"],
        ])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => $this->validator->errors(),
            ], 422);
        }
    }

    $member = $this->membersService->updateMember($memberId, $body);
    return $this->json($response, $member->toArray());
}
```

### Query Parameter Validation

```php
public function index(Request $request, Response $response): Response
{
    $params = $request->getQueryParams();

    // Validate limit: reject non-numeric or values exceeding 100
    $rawLimit = $params['per_page'] ?? $params['limit'] ?? null;
    if ($rawLimit !== null) {
        if (!is_numeric($rawLimit) || (int) $rawLimit != $rawLimit) {
            return $this->json($response, [
                'error' => 'invalid_request',
                'messages' => ['limit' => ['limit must be a positive integer']],
            ], 400);
        }
    }

    // ...proceed with service call
}
```

---

## Enum Validation

Combine `in:` rule with PHP enums for type-safe input:

```php
// Validate input string
if (!$this->validator->validate($body, [
    'preferred_language' => ['required', 'string', 'in:de,en,fr'],
])) {
    return $this->json($response, [...], 422);
}

// Convert to type-safe enum after validation
$language = SupportedLanguage::from($body['preferred_language']);
```

---

## Key Benefits

- **Declarative rules**: Validation expressed as simple arrays
- **Reusable**: Same `Validator` instance across all controllers
- **Database-aware**: `unique` rule queries the database via PDO
- **Consistent errors**: Structured error format `{field: [messages]}`
- **Testable**: Validator can be unit-tested independently
- **No framework dependency**: Pure PHP with PDO for unique checks

---

## When to Use

- All public API endpoints accepting user input
- POST/PATCH/PUT request bodies
- Query parameters requiring specific formats
- Any input that needs database uniqueness checks

---

## When NOT to Use

- Simple pass-through endpoints (health checks)
- Internal methods where input is already trusted
- Query parameters that can safely fall back to defaults

---

## Consistency with Modularity (ADR-0018)

The `Validator` is **shared infrastructure**:
- Located in `src/Shared/Validation/Validator.php`
- Injected into controllers via `ServiceFactory`
- All modules use the same validation engine
- Rules are defined per-controller/per-action (not centralized)

---

## Related Patterns

- **Pattern 002**: Enum for Type-Safe Domain Values
- **Pattern 003**: Data Transfer Objects (DTOs) for Responses
- **Pattern 007**: Centralized Exception Handling

---

## References

- [ADR-0017: Input Validation and Injection Prevention](../adr/0017-input-validation-injection-prevention.md)
- [OWASP Input Validation Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html)
