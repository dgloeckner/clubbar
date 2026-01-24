# Pattern 007: Centralized Exception Handling

**Category**: Error Handling & Cross-Cutting Concerns
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Shared Infrastructure)

---

## Problem

Without centralized exception handling, error responses are inconsistent:

```php
// ❌ Problematic: Scattered error handling
public function updateLanguage(Request $request, string $memberId): JsonResponse
{
    try {
        // ...
        return response()->json([
            'message' => 'Member updated',
            'data' => ['member' => $member],
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'not_found'], 404);
    } catch (ValidationException $e) {
        return response()->json([
            'error' => 'validation_failed',
            'errors' => $e->errors(),
        ], 422);
    }
}

// Another controller formats errors differently
public function products(): JsonResponse
{
    try {
        // ...
    } catch (ValidationException $e) {
        return response()->json([
            'validation_errors' => $e->errors(),  // Different key!
            'status' => 'error',
        ], 400);  // Different status!
    }
}
```

Issues:
- Inconsistent error response formats across endpoints
- Duplicated error handling logic
- Difficult to maintain error responses
- No centralized error logging/auditing
- Client confusion about response structure

---

## Solution

Use **Exception Handler** to:
- Centralize error response formatting
- Ensure consistent error responses across all endpoints
- Log errors consistently
- Transform domain exceptions to HTTP responses
- Handle unexpected errors gracefully

---

## Implementation Pattern

### Exception Handler

```php
// app/Exceptions/Handler.php
<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Render exception as response
     */
    public function render($request, Throwable $exception): JsonResponse
    {
        // Only handle JSON API requests
        if ($request->expectsJson()) {
            return $this->renderApiException($exception);
        }

        return parent::render($request, $exception);
    }

    private function renderApiException(Throwable $exception): JsonResponse
    {
        // Handle validation errors
        if ($exception instanceof ValidationException) {
            return response()->json([
                'error' => 'validation_failed',
                'message' => 'Input validation failed',
                'timestamp' => now()->toIso8601ZuluString(),
                'details' => $this->formatValidationErrors($exception),
            ], 422);
        }

        // Handle not found (model not found)
        if ($exception instanceof ModelNotFoundException) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Resource not found',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 404);
        }

        // Handle custom domain exceptions
        if ($exception instanceof DomainException) {
            return response()->json([
                'error' => 'business_rule_violation',
                'message' => $exception->getMessage(),
                'timestamp' => now()->toIso8601ZuluString(),
            ], 400);
        }

        // Handle authorization errors (Illuminate)
        if ($exception instanceof \Illuminate\Auth\AuthorizationException) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Insufficient permissions',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 403);
        }

        // Handle authentication errors
        if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 401);
        }

        // Log unexpected errors
        report($exception);

        // Return generic server error (don't expose internals)
        return response()->json([
            'error' => 'server_error',
            'message' => 'An unexpected error occurred',
            'timestamp' => now()->toIso8601ZuluString(),
        ], 500);
    }

    /**
     * Format validation errors consistently
     */
    private function formatValidationErrors(ValidationException $exception): array
    {
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = [
                    'field' => $field,
                    'message' => $message,
                ];
            }
        }

        return $details;
    }
}
```

---

## Domain-Specific Exceptions

Create exceptions for business logic errors:

```php
// app/Exceptions/DomainException.php
<?php

namespace App\Exceptions;

use Exception;

class DomainException extends Exception {}

// app/Exceptions/MemberNotFoundException.php
<?php

namespace App\Exceptions;

class MemberNotFoundException extends DomainException
{
    public function __construct(string $memberId)
    {
        parent::__construct("Member '{$memberId}' not found");
    }
}

// app/Exceptions/InvalidTransactionException.php
<?php

namespace App\Exceptions;

class InvalidTransactionException extends DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct("Transaction validation failed: {$reason}");
    }
}

// app/Exceptions/SettlementLockedException.php
<?php

namespace App\Exceptions;

class SettlementLockedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Settlement is locked and cannot be modified');
    }
}
```

---

## Enhanced Exception Handler

```php
// app/Exceptions/Handler.php (Enhanced)
<?php

namespace App\Exceptions;

use App\Exceptions\{
    DomainException,
    MemberNotFoundException,
    InvalidTransactionException,
    SettlementLockedException,
};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    public function render($request, Throwable $exception): JsonResponse
    {
        if ($request->expectsJson()) {
            return $this->renderApiException($exception);
        }

        return parent::render($request, $exception);
    }

    private function renderApiException(Throwable $exception): JsonResponse
    {
        // Handle validation first (from FormRequest)
        if ($exception instanceof ValidationException) {
            return $this->renderValidationException($exception);
        }

        // Handle model not found
        if ($exception instanceof ModelNotFoundException) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Resource not found',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 404);
        }

        // Handle domain-specific exceptions
        if ($exception instanceof MemberNotFoundException) {
            return response()->json([
                'error' => 'member_not_found',
                'message' => $exception->getMessage(),
                'timestamp' => now()->toIso8601ZuluString(),
            ], 404);
        }

        if ($exception instanceof InvalidTransactionException) {
            return response()->json([
                'error' => 'invalid_transaction',
                'message' => $exception->getMessage(),
                'timestamp' => now()->toIso8601ZuluString(),
            ], 422);
        }

        if ($exception instanceof SettlementLockedException) {
            return response()->json([
                'error' => 'settlement_locked',
                'message' => $exception->getMessage(),
                'timestamp' => now()->toIso8601ZuluString(),
            ], 409); // Conflict
        }

        // Handle generic domain exceptions
        if ($exception instanceof DomainException) {
            return response()->json([
                'error' => 'business_rule_violation',
                'message' => $exception->getMessage(),
                'timestamp' => now()->toIso8601ZuluString(),
            ], 400);
        }

        // Handle auth/authorization
        if ($exception instanceof \Illuminate\Auth\AuthorizationException) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Insufficient permissions',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 403);
        }

        if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 401);
        }

        // Log and return generic error
        report($exception);

        return response()->json([
            'error' => 'server_error',
            'message' => 'An unexpected error occurred',
            'timestamp' => now()->toIso8601ZuluString(),
        ], 500);
    }

    private function renderValidationException(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'error' => 'validation_failed',
            'message' => 'Input validation failed',
            'timestamp' => now()->toIso8601ZuluString(),
            'details' => $this->formatValidationErrors($exception),
        ], 422);
    }

    private function formatValidationErrors(ValidationException $exception): array
    {
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = [
                    'field' => $field,
                    'message' => $message,
                ];
            }
        }

        return $details;
    }
}
```

---

## Service Layer Error Handling

Services throw exceptions; controllers/handler deal with them:

```php
// app/Services/TransactionService.php
final readonly class TransactionService
{
    public function processBatch(array $transactions): TransactionBatchResultDto
    {
        $accepted = [];
        $errors = [];

        foreach ($transactions as $idx => $txn) {
            try {
                // Validate member exists
                $member = $this->members->findById($txn['member_id']);
                if (!$member) {
                    throw new MemberNotFoundException($txn['member_id']);
                }

                // Validate amount
                if ($txn['amount_cents'] <= 0) {
                    throw new InvalidTransactionException('Amount must be positive');
                }

                // Insert transaction
                $this->transactions->insert($txn);
                $accepted[] = $txn['id'];

            } catch (InvalidTransactionException $e) {
                // Expected error; record and continue
                $errors[] = [
                    'index' => $idx,
                    'error' => 'invalid_transaction',
                    'message' => $e->getMessage(),
                ];
            } catch (MemberNotFoundException $e) {
                // Expected error; record and continue
                $errors[] = [
                    'index' => $idx,
                    'error' => 'member_not_found',
                    'message' => $e->getMessage(),
                ];
            } catch (\Exception $e) {
                // Unexpected; log and fail batch
                report($e);
                throw new DomainException('Batch processing failed');
            }
        }

        return new TransactionBatchResultDto($accepted, count($errors), $errors);
    }
}

// app/Http/Controllers/SyncController.php
public function transactions(UploadTransactionsRequest $request): JsonResponse
{
    // Service handles validation; throws for unexpected errors
    // Handler catches and formats response
    $result = $this->transactionService->processBatch(
        $request->validated('transactions')
    );

    return response()->json($result->toArray());
}
```

---

## Error Response Format

Consistent structure across all errors:

```json
{
  "error": "error_code",
  "message": "Human-readable description",
  "timestamp": "2026-01-24T12:30:45Z",
  "details": [
    {
      "field": "email",
      "message": "The email must be a valid email address"
    }
  ]
}
```

---

## Benefits

✅ **Consistency**: All endpoints return same error format
✅ **Centralization**: Error handling in one place
✅ **Reusability**: No duplicate error handling code
✅ **Maintainability**: Change error format globally
✅ **Security**: Doesn't expose internal errors to client
✅ **Logging**: Centralized error logging and auditing
✅ **Type safety**: Specific exception types for specific errors

---

## When to Use

- All API endpoints (via FormRequest validation and service exceptions)
- Any Laravel HTTP request handling
- Exception mapping to HTTP status codes

---

## Consistency with Modularity (ADR-0018)

Exception handling is **shared infrastructure**:
- Centralized in `app/Exceptions/Handler.php`
- Domain exceptions in `app/Exceptions/` directory
- All modules use same exception handler
- Consistent error responses across all modules

---

## Related Patterns

- **Pattern 001**: Form Requests (validation exceptions)
- **Pattern 004**: Service Layer (throws domain exceptions)
- **Pattern 006**: Thin Controllers (don't handle exceptions)

---

## References

- [Laravel Exception Handling](https://laravel.com/docs/errors#rendering-exceptions)
- [HTTP Status Codes](https://developer.mozilla.org/en-US/docs/Web/HTTP/Status)
- [RESTful API Error Handling Best Practices](https://www.rfc-editor.org/rfc/rfc7231#section-6)
