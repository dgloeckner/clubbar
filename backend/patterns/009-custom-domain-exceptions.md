# Pattern 009: Custom Domain Exceptions

**Category**: Exception Handling
**Status**: ✅ Recommended
**Related**: Pattern 007 (Centralized Exception Handling)

## Problem

Catching generic exceptions and parsing their messages is fragile and leads to unmaintainable code:

```php
// ❌ BAD: Fragile message parsing
try {
    $category = $this->service->updateCategory($id, $data);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'not found')) {
        return $this->json($response, ['error' => 'not_found'], 404);
    }
    throw $e;
}
```

**Problems**:
- Exception message changes break error handling
- No type safety
- Hard to test
- Doesn't support internationalization
- Couples controller to service implementation details

## Solution

Use custom domain-specific exception classes that carry semantic meaning:

```php
// ✅ GOOD: Type-safe custom exceptions
try {
    $category = $this->service->updateCategory($id, $data);
    return $this->json($response, $category->toArray());
} catch (CategoryNotFoundException $e) {
    return $this->json($response, [
        'error' => 'not_found',
        'message' => $e->getMessage()
    ], 404);
}
```

## Implementation

### 1. Create Base Exception Class

```php
<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Base exception for all application-specific exceptions
 */
abstract class AppException extends \Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the HTTP status code for this exception
     */
    abstract public function getHttpStatusCode(): int;

    /**
     * Get the error code for API responses
     */
    abstract public function getErrorCode(): string;
}
```

### 2. Create Specific Exception Classes

```php
<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a requested resource is not found
 */
class NotFoundException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 404;
    }

    public function getErrorCode(): string
    {
        return 'not_found';
    }

    public static function forResource(string $resourceType, string $id): self
    {
        return new self("{$resourceType} not found: {$id}");
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when validation fails
 */
class ValidationException extends AppException
{
    public function __construct(
        string $message,
        private array $errors = []
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'validation_failed';
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a duplicate resource is detected
 */
class DuplicateResourceException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'duplicate_resource';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a business rule is violated
 */
class BusinessRuleException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 400;
    }

    public function getErrorCode(): string
    {
        return 'business_rule_violation';
    }
}
```

### 3. Use in Services

```php
<?php

namespace App\Modules\Products\Services;

use App\Shared\Exceptions\NotFoundException;

class CategoriesService
{
    public function updateCategory(string $id, array $data, ?string $adminId): CategoryDto
    {
        $category = $this->repository->findById($id);

        if (!$category) {
            throw NotFoundException::forResource('Category', $id);
        }

        // Update logic...
        return CategoryDto::fromRow($category);
    }
}
```

### 4. Catch in Controllers

```php
<?php

namespace App\Modules\Products\Controllers;

use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;

class AdminController
{
    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        try {
            $category = $this->service->updateCategory(
                $args['categoryId'],
                $request->getParsedBody(),
                $request->getAttribute('admin_user_id')
            );

            return $this->json($response, $category->toArray());

        } catch (NotFoundException $e) {
            return $this->json($response, [
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage()
            ], $e->getHttpStatusCode());

        } catch (ValidationException $e) {
            return $this->json($response, [
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'errors' => $e->getErrors()
            ], $e->getHttpStatusCode());
        }
    }
}
```

## Alternative: Global Exception Handler

For even cleaner controllers, use middleware to handle exceptions globally:

```php
<?php

namespace App\Shared\Middleware;

use App\Shared\Exceptions\AppException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class ExceptionHandlerMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (AppException $e) {
            $response = new \Slim\Psr7\Response();

            $data = [
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage()
            ];

            if ($e instanceof ValidationException) {
                $data['errors'] = $e->getErrors();
            }

            $response->getBody()->write(json_encode($data));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($e->getHttpStatusCode());
        }
    }
}
```

**With global handler, controllers become even simpler**:

```php
public function updateCategory(Request $request, Response $response, array $args): Response
{
    // Exceptions automatically handled by middleware
    $category = $this->service->updateCategory(
        $args['categoryId'],
        $request->getParsedBody(),
        $request->getAttribute('admin_user_id')
    );

    return $this->json($response, $category->toArray());
}
```

## Benefits

✅ **Type Safety**: Catch specific exception types
✅ **Maintainable**: Changes to messages don't break handlers
✅ **Testable**: Easy to test exception scenarios
✅ **Semantic**: Exception name conveys meaning
✅ **Consistent**: Standardized error responses
✅ **i18n-Ready**: Can translate messages in exception class
✅ **Self-Documenting**: Clear what can go wrong

## Common Domain Exceptions

| Exception | HTTP Status | Error Code | Use Case |
|-----------|-------------|------------|----------|
| `NotFoundException` | 404 | `not_found` | Resource doesn't exist |
| `ValidationException` | 422 | `validation_failed` | Input validation failed |
| `DuplicateResourceException` | 409 | `duplicate_resource` | Unique constraint violated |
| `BusinessRuleException` | 400 | `business_rule_violation` | Domain rule violated |
| `UnauthorizedException` | 401 | `unauthorized` | Authentication failed |
| `ForbiddenException` | 403 | `forbidden` | Authorization failed |
| `ConflictException` | 409 | `conflict` | Resource state conflict |

## Testing

```php
<?php

use App\Shared\Exceptions\NotFoundException;

class CategoriesServiceTest extends TestCase
{
    public function test_update_throws_not_found_for_invalid_id(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Category not found: invalid-id');

        $this->service->updateCategory('invalid-id', [], null);
    }
}
```

## Migration Path

1. Create exception classes in `backend/src/Shared/Exceptions/`
2. Update services to throw custom exceptions instead of `RuntimeException`
3. Update controllers to catch specific exceptions
4. (Optional) Add global exception handler middleware
5. Remove fragile `str_contains()` message parsing

## Related Patterns

- **Pattern 007**: Centralized Exception Handling (global middleware approach)
- **Pattern 006**: Thin Controllers (exceptions keep controllers thin)
- **Pattern 004**: Service Layer (services throw domain exceptions)
