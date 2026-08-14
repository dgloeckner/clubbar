# Pattern 001: Input Validation

**Category**: Validation & Input Handling
**Related ADR**: ADR-0017 (Input Validation, Injection Prevention)
**Related to Module**: Applicable to all API endpoints

> **There are no Form Request classes in this codebase, and adding one would be
> the deviation.** This file used to be called `pattern-001-form-requests-validation.md`,
> and the index still advertised "declarative validation with typed accessors" —
> a Laravel shape the Slim backend never adopted. Every controller validates the
> way this document describes. The name was the last trace of the older idea and
> is now gone; if you arrived here looking for `FormRequest`, the answer is that
> the rule array below *is* the declarative part, and the DTO (Pattern 003) is
> where typed access lives.

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
| `required` | Must be present and non-empty (`''` fails) | `'required'` |
| `nullable` | Documentation only — it passes unconditionally. Every other rule already skips `null`, so this marks intent for the reader rather than changing behaviour | `'nullable'` |
| `string` | Must be a string (or null) | `'string'` |
| `integer` | **Whole numbers only.** `"12"` and `12.0` pass; `"1.5"` does not. It used to be `is_numeric()`, which let `amount_cents: "12.9"` through to a `(int)` cast and book 12 cents ([#117](https://github.com/dgloeckner/clubbar/issues/117)) | `'integer'` |
| `numeric` | Any numeric value, decimals included | `'numeric'` |
| `email` | `FILTER_VALIDATE_EMAIL` | `'email'` |
| `boolean` | `true`/`false`/`0`/`1`/`'0'`/`'1'` | `'boolean'` |
| `uuid` | Canonical 8-4-4-4-12 hex form | `'uuid'` |
| `date` | **Exact formats only** — `Y-m-d` and the ISO-8601 variants, round-tripped so `2026-02-30` fails. `strtotime()` used to accept `"next tuesday"` and `"now"`, which reach a DATE column as a value that changes with the clock ([#117](https://github.com/dgloeckner/clubbar/issues/117)) | `'date'` |
| `business_day` | A TARGET2 bank business day: Mon–Fri, excluding the six closing days (ADR-0009) | `'business_day'` |
| `iban` | Structure plus the mod-97 checksum. **Needs bcmath** — see the note below | `'iban'` |
| `array` | Must be an array | `'array'` |
| `json` | An array, or a string that decodes | `'json'` |
| `min:N` | Min string **length**, numeric value, or array count | `'min:3'` |
| `max:N` | Max string **length**, numeric value, or array count. A string is always measured by length, so `"0013466849"` is not compared as a number | `'max:100'` |
| `gt:N` / `gte:N` | Greater than / at least, numeric only | `'gt:0'` |
| `lt:N` / `lte:N` | Less than / at most, numeric only | `'lte:100'` |
| `in:a,b,c` | One of the listed values, compared as strings | `'in:de,en'` |
| `regex:/pattern/` | Must match | `'regex:/^[0-9A-F]+$/'` |
| `same:field` | Must equal another field in the same payload | `'same:password'` |
| `unique:table,col` | Must not already exist | `'unique:members,card_uid'` |
| `unique:table,col,id` | Unique excluding one row — the update case | `'unique:members,card_uid,abc-123'` |

**Three things the table cannot show.**

*An unknown rule name is silently ignored.* `check()` ends in `default => null`, so
a typo like `'requried'` validates nothing and reports nothing. When a rule
appears not to fire, check its spelling before checking the value.

*Every rule except `required` skips `null`.* A field is either `required` or
optional; `['string', 'max:100']` on an absent field passes, which is what makes
PATCH-style partial updates work without a second rule set.

*Empty string is not `null`, and the rules disagree about it.* `date`,
`business_day`, `iban`, `email` and `uuid` let `''` through; `integer` rejects it
(`is_numeric('')` is false) and `min:3` rejects it on length. So a client that
sends `""` to clear a field gets a different answer per field. Where blank means
"clear this", handle it explicitly rather than relying on the rule — the members
module does exactly that with its `BLANK_MEANS_NULL` handling.

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

## Where a rule lives, and the status code it returns

Two conventions the examples above imply but do not state:

| | |
|---|---|
| **422 for a failed rule, 400 for a malformed request** | A value the rules rejected is `422` with `{error: 'validation_failed', messages: {field: [...]}}` — all 32 `Validator` call sites do this, and new code should. A request that could not be interpreted at all — a non-numeric `per_page`, an unparseable body — is `400` with `{error: 'invalid_request'}`. ⚠️ **Two places in the terminal API do not follow this**, one deliberately: `processBatch`'s envelope checks are `400` on purpose (ruling #143 §2, #259), while `updateLanguage` returns `400` with a bare `message` string for what is plainly a rule failure. Tracked in [#446](https://github.com/dgloeckner/clubbar/issues/446); until it is settled, follow the rule above in new code rather than copying the nearest terminal controller |
| **A domain rule belongs in the rule list, not only in the controller** | `business_day` exists as a rule rather than as an `if` in one settlement endpoint, so both endpoints that accept an `execution_date` enforce it identically. When a check is about the *value* rather than about this endpoint, add a rule |

### bcmath and the `iban` rule

`iban` computes the mod-97 checksum with `bcmod()`. Without the **bcmath**
extension it fatals with `Call to undefined function bcmod()`, which reads as a
crash rather than as a missing extension. The backend container has it; a bare
host PHP often does not, which is why backend tests run in the container:

```bash
docker compose exec -w /app backend ./vendor/bin/phpunit
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

- **Pattern 002**: Enum for Type-Safe Domain Values — the `in:` rule's counterpart once the value is trusted
- **Pattern 003**: Data Transfer Objects (DTOs) for Responses — where typed access lives, since validation itself hands back a plain array
- **Pattern 006**: Thin Controllers — validation is the one piece of logic that legitimately sits in a controller
- **Pattern 007**: Centralized Exception Handling

---

## References

- [ADR-0017: Input Validation and Injection Prevention](../adr/0017-input-validation-injection-prevention.md)
- [OWASP Input Validation Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html)
