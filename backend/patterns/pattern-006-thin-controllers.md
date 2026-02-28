# Pattern 006: Thin Controllers (HTTP Routing Only)

**Category**: Application Layer & HTTP Handling
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Clean Separation)

---

## Problem

Fat controllers mix HTTP concerns with business logic:

```php
// ❌ Problematic: Fat controller with mixed concerns
class MembersController
{
    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();

        // Business logic (shouldn't be here!)
        $id = $this->generateUuid();
        $stmt = $this->pdo->prepare('INSERT INTO members ...');
        $stmt->execute([...]);

        // Audit logging (mixed in)
        $this->pdo->prepare('INSERT INTO audit_log ...')->execute([...]);

        // Response formatting
        $response->getBody()->write(json_encode(['id' => $id]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

Issues:
- Business logic not reusable from other consumers
- Hard to test (requires HTTP context)
- Difficult to maintain (multiple concerns in one method)
- Violates Single Responsibility Principle

---

## Solution

Keep controllers **thin** by:
- Using `Validator` for input validation (Pattern 001)
- Delegating business logic to Service Layer (Pattern 004)
- Returning DTOs from services (Pattern 003)
- Focusing solely on: HTTP request → validate → service → HTTP response

---

## Implementation Pattern

### Thin Controller Structure

```php
// src/Modules/Members/Controllers/AdminController.php
namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $result = $this->membersService->listMembers(
            limit: (int) ($params['per_page'] ?? 50),
            offset: 0,
            sortKey: $params['sort'] ?? 'created_at',
            sortOrder: $params['order'] ?? 'desc',
            search: $params['search'] ?? null,
        );
        return $this->json($response, $result->toArray());
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        // 1. Validate input (Pattern 001)
        if (!$this->validator->validate($body, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'preferred_language' => ['required', 'string', 'in:de,en,fr'],
        ])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => $this->validator->errors(),
            ], 422);
        }

        // 2. Convert to typed input
        $language = SupportedLanguage::from($body['preferred_language']);

        // 3. Delegate to service (Pattern 004)
        $member = $this->membersService->createMember(
            firstName: $body['first_name'],
            lastName: $body['last_name'],
            email: $body['email'],
            language: $language,
            adminUserId: $request->getAttribute('admin_user_id'),
        );

        // 4. Serialize DTO to JSON (Pattern 003)
        return $this->json($response, $member->toArray(), 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $member = $this->membersService->getMember($args['memberId']);
        return $this->json($response, $member->toArray());
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];
        $member = $this->membersService->updateMember(
            $args['memberId'], $body, $request->getAttribute('admin_user_id')
        );
        return $this->json($response, $member->toArray());
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->membersService->deleteMember(
            $args['memberId'], $request->getAttribute('admin_user_id')
        );
        return $this->json($response, ['message' => 'Member deleted']);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

### Controller Method Anatomy

Every controller method follows this pattern:

```php
public function someAction(Request $request, Response $response, array $args): Response
{
    // 1. Extract input from PSR-7 request
    $body = $request->getParsedBody() ?? [];
    $params = $request->getQueryParams();
    $id = $args['memberId'];

    // 2. Validate input (if needed)
    if (!$this->validator->validate($body, [...])) {
        return $this->json($response, ['error' => 'validation_failed', ...], 422);
    }

    // 3. Delegate to service
    $result = $this->service->doSomething($id, $body);

    // 4. Serialize and respond
    return $this->json($response, $result->toArray());
}
```

---

## Common Controller Patterns

### List Resource

```php
public function index(Request $request, Response $response): Response
{
    $params = $request->getQueryParams();
    $result = $this->service->listItems(
        limit: (int) ($params['per_page'] ?? 50),
        offset: (int) ($params['offset'] ?? 0),
        search: $params['search'] ?? null,
    );
    return $this->json($response, $result->toArray());
}
```

### Show Resource

```php
public function show(Request $request, Response $response, array $args): Response
{
    $item = $this->service->getItem($args['id']);
    return $this->json($response, $item->toArray());
}
```

### Create Resource

```php
public function store(Request $request, Response $response): Response
{
    $body = $request->getParsedBody() ?? [];
    // validate...
    $item = $this->service->createItem($body);
    return $this->json($response, $item->toArray(), 201);
}
```

### Delete Resource

```php
public function destroy(Request $request, Response $response, array $args): Response
{
    $this->service->deleteItem($args['id']);
    return $this->json($response, ['message' => 'Deleted']);
}
```

---

## Exception Handling in Controllers

Controllers don't catch exceptions; the `ErrorHandler` middleware (Pattern 007) deals with them:

```php
// ❌ Don't do this in controller
public function show(Request $request, Response $response, array $args): Response
{
    try {
        $item = $this->service->getItem($args['id']);
        return $this->json($response, $item->toArray());
    } catch (NotFoundException $e) {
        return $this->json($response, ['error' => 'not_found'], 404);
    }
}

// ✅ Let ErrorHandler middleware deal with it
public function show(Request $request, Response $response, array $args): Response
{
    $item = $this->service->getItem($args['id']);
    return $this->json($response, $item->toArray());
    // If not found, service throws NotFoundException → ErrorHandler catches it
}
```

---

## JSON Helper Method

All controllers use a private `json()` helper for consistent serialization:

```php
private function json(Response $response, mixed $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}
```

---

## Anti-Patterns to Avoid

### ❌ Business Logic in Controller

```php
// DON'T: Direct database queries
$stmt = $this->pdo->prepare('SELECT * FROM members WHERE id = ?');
```

### ❌ Direct Repository Access

```php
// DON'T: Bypass service layer
$row = $this->repo->findById($id);
```

### ❌ Response Construction Without DTO

```php
// DON'T: Manual response formatting
$response->getBody()->write(json_encode([
    'id' => $row['id'],
    'name' => $row['first_name'],
]));
```

---

## Benefits

- **Simplicity**: Controllers only route HTTP → Service → Response
- **Testability**: Services testable without HTTP context
- **Maintainability**: Business logic changes in service, not controller
- **Consistency**: Same service behavior regardless of entry point
- **Clarity**: Clear data flow: Request → Validate → Service → DTO → Response

---

## When to Use

- All REST API endpoints
- All HTTP controllers

---

## Consistency with Modularity (ADR-0018)

Controllers are **module-specific**:
- Located in `src/Modules/{Module}/Controllers/`
- Separate controllers for Admin and Sync APIs (e.g., `AdminController`, `SyncController`)
- Each controller handles one module's REST endpoints
- Controllers import services from same module

---

## Related Patterns

- **Pattern 001**: Input Validation (validation in controllers)
- **Pattern 003**: Data Transfer Objects (controllers serialize DTOs)
- **Pattern 004**: Service Layer (controllers delegate to services)
- **Pattern 007**: Exception Handling (centralized error handling)

---

## References

- [Clean Architecture - Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Single Responsibility Principle](https://en.wikipedia.org/wiki/Single-responsibility_principle)
- [PSR-7: HTTP Message Interface](https://www.php-fig.org/psr/psr-7/)
