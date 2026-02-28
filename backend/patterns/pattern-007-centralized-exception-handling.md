# Pattern 007: Centralized Exception Handling

**Category**: Error Handling & Cross-Cutting Concerns
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Shared Infrastructure)

---

## Problem

Without centralized exception handling, error responses are inconsistent:

```php
// ❌ Problematic: Scattered error handling
public function show(Request $request, Response $response, array $args): Response
{
    try {
        $member = $this->service->getMember($args['id']);
        return $this->json($response, $member->toArray());
    } catch (NotFoundException $e) {
        return $this->json($response, ['error' => 'not_found'], 404);
    } catch (\Exception $e) {
        return $this->json($response, ['error' => 'server_error'], 500);
    }
}
```

Issues:
- Inconsistent error response formats across endpoints
- Duplicated error handling logic
- No centralized error logging
- Client confusion about response structure

---

## Solution

Use a PSR-15 **ErrorHandler middleware** to:
- Centralize error response formatting
- Ensure consistent error responses across all endpoints
- Log errors consistently
- Transform domain exceptions to HTTP responses
- Handle unexpected errors gracefully

---

## Implementation Pattern

### ErrorHandler Middleware

```php
// src/Shared/Middleware/ErrorHandler.php
namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Shared\Logging\Logger;
use App\Shared\Exceptions\AppException;
use App\Shared\Exceptions\ValidationException;
use Slim\Psr7\Response;

class ErrorHandler implements MiddlewareInterface
{
    public function __construct(
        private Logger $logger,
        private bool $debug = false,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->debug ? $e->getTraceAsString() : null,
            ]);

            // AppException subclasses define their own HTTP status and error code
            if ($e instanceof AppException) {
                $status = $e->getHttpStatusCode();
                $body = [
                    'error' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ];

                // Add validation errors if available
                if ($e instanceof ValidationException) {
                    $body['errors'] = $e->getErrors();
                }
            } else {
                // Fallback for non-AppException types
                $status = match (true) {
                    $e instanceof \Slim\Exception\HttpNotFoundException => 404,
                    $e instanceof \Slim\Exception\HttpMethodNotAllowedException => 405,
                    $e instanceof \InvalidArgumentException => 422,
                    default => 500,
                };

                $body = [
                    'error' => $this->errorCode($status),
                    'message' => $e->getMessage(),
                ];
            }

            // Include stack trace in debug mode (not for auth errors)
            if ($this->debug && !in_array($status, [401, 403, 404])) {
                $body['trace'] = $e->getTraceAsString();
            }

            $response = new Response($status);
            $response->getBody()->write(json_encode($body));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request',
            401 => 'unauthorized',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'validation_failed',
            409 => 'business_rule_violation',
            default => 'internal_error',
        };
    }
}
```

---

## Domain-Specific Exceptions

### Base Exception

```php
// src/Shared/Exceptions/AppException.php
namespace App\Shared\Exceptions;

abstract class AppException extends \Exception
{
    abstract public function getHttpStatusCode(): int;
    abstract public function getErrorCode(): string;
}
```

### Concrete Exceptions

```php
// src/Shared/Exceptions/NotFoundException.php
class NotFoundException extends AppException
{
    public function getHttpStatusCode(): int { return 404; }
    public function getErrorCode(): string { return 'not_found'; }

    public static function forResource(string $type, string $id): self
    {
        return new self("{$type} not found: {$id}");
    }
}

// src/Shared/Exceptions/ValidationException.php
class ValidationException extends AppException
{
    public function __construct(string $message, private array $errors = [])
    {
        parent::__construct($message);
    }
    public function getHttpStatusCode(): int { return 422; }
    public function getErrorCode(): string { return 'validation_failed'; }
    public function getErrors(): array { return $this->errors; }
}

// src/Shared/Exceptions/BusinessRuleException.php
class BusinessRuleException extends AppException
{
    public function getHttpStatusCode(): int { return 409; }
    public function getErrorCode(): string { return 'business_rule_violation'; }
}

// src/Shared/Exceptions/DuplicateResourceException.php
class DuplicateResourceException extends AppException
{
    public function getHttpStatusCode(): int { return 409; }
    public function getErrorCode(): string { return 'duplicate_resource'; }
}

// src/Shared/Exceptions/InvalidCredentialsException.php
class InvalidCredentialsException extends AppException
{
    public function getHttpStatusCode(): int { return 401; }
    public function getErrorCode(): string { return 'invalid_credentials'; }
}
```

---

## Service Layer Error Handling

Services throw domain exceptions; the ErrorHandler middleware catches and formats them:

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

    public function deleteMember(string $memberId, ?string $adminUserId = null): bool
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        // ...soft delete logic...
        return true;
    }
}
```

### Controller Stays Clean

```php
// src/Modules/Members/Controllers/AdminController.php
public function show(Request $request, Response $response, array $args): Response
{
    // Service throws NotFoundException if not found
    // ErrorHandler middleware catches it and returns 404 JSON
    $member = $this->membersService->getMember($args['memberId']);
    return $this->json($response, $member->toArray());
}
```

---

## Error Response Format

Consistent structure across all errors:

```json
{
  "error": "not_found",
  "message": "Member not found: abc-123"
}
```

With validation errors:

```json
{
  "error": "validation_failed",
  "message": "Validation failed",
  "errors": {
    "email": ["email must be a valid email"],
    "first_name": ["first_name is required"]
  }
}
```

---

## Middleware Registration

The ErrorHandler is registered as the outermost middleware in the Slim app:

```php
// public/index.php
$app->add($container->get(ErrorHandler::class));
$app->add($container->get(JsonBodyParser::class));
$app->add($container->get(CorsMiddleware::class));
```

This ensures all exceptions from any layer (routing, auth, controllers, services) are caught.

---

## Benefits

- **Consistency**: All endpoints return same error format
- **Centralization**: Error handling in one place
- **Reusability**: No duplicate error handling code
- **Security**: Doesn't expose internal errors to client (debug mode configurable)
- **Logging**: All errors logged with context
- **Type safety**: Specific exception types for specific errors

---

## When to Use

- All API endpoints (automatically via middleware)
- Any exception that needs HTTP response mapping

---

## Consistency with Modularity (ADR-0018)

Exception handling is **shared infrastructure**:
- `ErrorHandler` in `src/Shared/Middleware/ErrorHandler.php`
- Domain exceptions in `src/Shared/Exceptions/`
- All modules use same exception handler
- Consistent error responses across all modules

---

## Related Patterns

- **Pattern 001**: Input Validation (validation errors)
- **Pattern 004**: Service Layer (throws domain exceptions)
- **Pattern 006**: Thin Controllers (don't handle exceptions)

---

## References

- [PSR-15: HTTP Server Request Handlers](https://www.php-fig.org/psr/psr-15/)
- [HTTP Status Codes](https://developer.mozilla.org/en-US/docs/Web/HTTP/Status)
