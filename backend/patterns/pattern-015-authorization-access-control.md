# Pattern 015: Authorization & Access Control

**Status**: Active

**Related ADR**: ADR-0015 (Authentication and Authorization Strategy)

**Purpose**: Implement authorization and access control to protect endpoints based on authentication type (Terminal device, Admin user) and resource ownership.

---

## Context

Once a client is **authenticated** (Patterns 012-013), the backend must ensure they have **authorization** to access requested resources:

| Question | Authentication | Authorization |
|----------|---|---|
| **Who are you?** | ✓ Answered by auth patterns | |
| **What can you do?** | | ✓ Answered by auth patterns |
| **Can you access this resource?** | | ✓ Answered by this pattern |

**Authorization Rules**:
- **Terminal devices** (Pattern 012): Can access `/api/sync/*` (data for offline sync)
- **Terminal devices**: Cannot access `/api/admin/*` (administrative endpoints)
- **Admin users** (Pattern 013): Can access `/api/admin/*` (all admin operations)
- **Admin users**: Cannot access `/api/sync/*` with session (different auth mechanism)
- **Members**: No API access (identified by RFID, not authenticated)

---

## Pattern Definition

### Middleware for Access Control

#### 1. Terminal Token Access (Sync Endpoints)

Authorization for terminal access is enforced by Slim 4 route group middleware. The `TerminalTokenAuth` middleware (PSR-15) is applied only to `/api/sync/*` routes, so terminals structurally cannot access `/api/admin/*` endpoints.

```php
// src/Modules/Auth/Middleware/TerminalTokenAuth.php
// (See Pattern 012 for full implementation)

// Authorization is enforced via Slim 4 route groups:
// - /api/sync/* routes use TerminalTokenAuth middleware
// - /api/admin/* routes use AdminSessionAuth middleware
// - A terminal Bearer token will never pass AdminSessionAuth
// - An admin session will never pass TerminalTokenAuth
```

If additional terminal-level authorization is needed (e.g., restricting specific terminals to specific endpoints), it can be added as a separate PSR-15 middleware:

```php
// src/Shared/Middleware/AuthorizeTerminalSync.php (optional)
namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Optional PSR-15 middleware for additional terminal authorization checks.
 */
class AuthorizeTerminalSync implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $terminal = $request->getAttribute('terminal');

        if (!$terminal) {
            return $this->forbidden('Terminal not authenticated');
        }

        // Additional authorization logic here (if needed)

        return $handler->handle($request);
    }

    private function forbidden(string $message): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write(json_encode(['error' => 'forbidden', 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

#### 2. Admin Session Access (Admin Endpoints)

Admin authorization is enforced by the `AdminSessionAuth` middleware (PSR-15), which already verifies the admin is active. It is applied to all `/api/admin/*` and `/api/auth/*` (protected) routes via Slim 4 route groups.

```php
// src/Modules/Auth/Middleware/AdminSessionAuth.php
// (See Pattern 013 for full implementation)

// The middleware already checks:
// 1. Session is active
// 2. admin_user_id exists in $_SESSION
// 3. Admin user exists in database (PDO lookup)
// 4. Admin user is_active = true
// On failure: Returns 401 JSON response
```

#### 3. Preventing Auth Mixup

Auth mixup is prevented structurally by Slim 4's route group middleware design:

```php
// src/routes.php — Each route group has exactly one auth middleware

// Terminal endpoints: ONLY accept Bearer token
$app->group('/api/sync', function (RouteCollectorProxy $group) {
    // ... sync routes
})->add(TerminalTokenAuth::class);  // Rejects requests without Bearer token

// Admin endpoints: ONLY accept session cookie
$app->group('/api/admin', function (RouteCollectorProxy $group) {
    // ... admin routes
})->add(AdminSessionAuth::class);   // Rejects requests without valid session

// The middleware implementations are mutually exclusive:
// - TerminalTokenAuth checks Authorization header for Bearer token
// - AdminSessionAuth checks $_SESSION for admin_user_id
// - A Bearer token will never satisfy AdminSessionAuth
// - A session cookie will never satisfy TerminalTokenAuth
```

No explicit "prevent mixup" middleware is needed because the route structure enforces separation.

---

### Route Protection (Slim 4)

```php
// src/routes.php
use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Modules\Auth\Middleware\AdminSessionAuth;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {

    /**
     * Public Routes (No Auth Required)
     */
    $app->get('/api/health', [HealthController::class, 'check']);
    $app->post('/api/auth/login', [AuthController::class, 'login']);

    /**
     * Terminal Sync API Routes
     *
     * Authentication: Bearer token (Pattern 012)
     * Authorization: Terminal device access (Pattern 015)
     */
    $app->group('/api/sync', function (RouteCollectorProxy $group) {
        $group->get('/members', [MembersSyncController::class, 'index']);
        $group->get('/categories', [ProductsSyncController::class, 'categories']);
        $group->get('/products', [ProductsSyncController::class, 'products']);
        $group->patch('/members/{memberId}/language', [MembersSyncController::class, 'updateLanguage']);
        $group->post('/transactions', [TransactionsSyncController::class, 'processBatch']);
    })->add(TerminalTokenAuth::class);

    /**
     * Admin API Routes
     *
     * Authentication: Session cookie (Pattern 013)
     * Authorization: Admin user access (Pattern 015)
     */
    $app->group('/api/admin', function (RouteCollectorProxy $group) {
        $group->get('/members', [MembersAdminController::class, 'index']);
        $group->post('/members', [MembersAdminController::class, 'store']);
        $group->get('/members/{memberId}', [MembersAdminController::class, 'show']);
        $group->patch('/members/{memberId}', [MembersAdminController::class, 'update']);
        // ...etc
    })->add(AdminSessionAuth::class);
};
```

---

### Resource Ownership Authorization

In the Club Bar system, all admin users have equal access to all resources (no role-based differentiation). Resource ownership checks are not needed for admin endpoints since all admins can manage all members, products, and settlements.

If resource-level authorization is needed in the future, it can be implemented as a PSR-15 middleware:

```php
// src/Shared/Middleware/AuthorizeResourceOwner.php (future, if needed)
namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * PSR-15 Middleware for resource ownership checks (optional).
 *
 * Would be used if the system introduces role-based access control (RBAC).
 */
class AuthorizeResourceOwner implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $resourceId = $route->getArgument('id');
        $adminUserId = $request->getAttribute('admin_user_id');

        // Currently all admins have equal access — no ownership restriction
        // Future: Add role checks here if needed

        return $handler->handle($request);
    }
}
```

---

### Rate Limiting by Auth Type

Rate limiting can be implemented as a custom PSR-15 middleware or via a reverse proxy (e.g., nginx):

```php
// Rate limit configuration (conceptual)
// Terminal API: 60 requests per 60 seconds (sync once per minute typical)
// Admin API: 120 requests per 60 seconds (interactive usage)
// Login attempts: 5 attempts per 60 seconds (prevent brute force)

// Implementation options:
// 1. Custom PSR-15 middleware using APCu/Redis for counters
// 2. nginx rate limiting (recommended for production)
// 3. Slim 4 third-party middleware package
```

---

## Authorization Matrix

| Endpoint | Terminal Auth (Bearer) | Admin Auth (Session) | Member (RFID) |
|----------|:---:|:---:|:---:|
| `GET /api/sync/members` | ✅ Allowed | ❌ Denied | ❌ N/A |
| `PATCH /api/sync/members/{id}/language` | ✅ Allowed | ❌ Denied | ❌ N/A |
| `POST /api/sync/transactions` | ✅ Allowed | ❌ Denied | ❌ N/A |
| `GET /api/admin/members` | ❌ Denied | ✅ Allowed | ❌ N/A |
| `POST /api/admin/members` | ❌ Denied | ✅ Allowed | ❌ N/A |
| `PATCH /api/admin/members/{id}` | ❌ Denied | ✅ Allowed | ❌ N/A |
| `DELETE /api/admin/members/{id}` | ❌ Denied | ✅ Allowed | ❌ N/A |
| `POST /api/admin/members/{id}/export` | ❌ Denied | ✅ Allowed | ❌ N/A |
| `POST /api/auth/login` | ❌ N/A | ✅ Allowed | ❌ N/A |
| `GET /api/health` | ✅ Allowed | ✅ Allowed | ✅ Allowed |

---

## Error Responses

### 401 Unauthorized (Missing/Invalid Auth)

```php
// Missing or invalid authentication
HTTP 401 Unauthorized
{
    "error": "unauthorized",
    "message": "Invalid or missing authentication credentials"
}
```

### 403 Forbidden (Authenticated but Not Authorized)

```php
// Authenticated but not authorized to access this resource
HTTP 403 Forbidden
{
    "error": "forbidden",
    "message": "You do not have permission to access this resource"
}
```

### Examples

```json
// Terminal trying to access admin endpoint
{
    "error": "forbidden",
    "message": "Terminal cannot access admin endpoints"
}

// Admin trying to sync with Bearer token
{
    "error": "bad_request",
    "message": "Sync endpoints require Bearer token authentication"
}

// User trying to access another user's resource
{
    "error": "forbidden",
    "message": "Cannot access another user's resource"
}
```

---

## Controller-Level Authorization

For fine-grained control within controllers:

```php
// src/Modules/Members/Controllers/AdminController.php
namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin controller for member management.
 *
 * All endpoints require:
 * - Session authentication (AdminSessionAuth middleware)
 * - Admin data available via request attributes
 *
 * Implements Pattern 015: Authorization & Access Control
 */
final class AdminController
{
    public function __construct(private readonly MembersService $service) {}

    /**
     * DELETE /api/admin/members/{memberId}
     *
     * Admin user data is available from request attributes,
     * set by AdminSessionAuth middleware.
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $memberId = $args['memberId'];
        $adminUser = $request->getAttribute('admin_user');

        // Example: Additional authorization check (if needed)
        // Currently all admins have equal access

        $this->service->delete($memberId);

        return $response->withStatus(204);
    }
}
```

---

## Audit Logging with Authorization

```php
// src/Shared/Services/AuditService.php
namespace App\Shared\Services;

use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\AuditLog\Repositories\AuditLogRepository;

/**
 * Log authorization decisions and access (Pattern 016).
 *
 * Uses the centralized AuditService which writes to the audit_log table
 * via AuditLogRepository (PDO).
 *
 * Implements Pattern 015: Authorization & Access Control
 * Related to ADR-0013: Audit Logging
 */
class AuditService
{
    public function __construct(private AuditLogRepository $auditLogRepository) {}

    /**
     * Log an audit entry for master data changes or access events.
     *
     * Auto-captures IP address and user agent from $_SERVER superglobals.
     *
     * @param AuditAction $action Type of action (login, logout, create, update, etc.)
     * @param EntityType $entityType Type of entity affected
     * @param string $entityId Primary key of affected record
     * @param array|null $oldValues Field values before change
     * @param array|null $newValues Field values after change
     * @param string|null $adminUserId UUID of admin who performed action
     */
    public function log(
        AuditAction $action,
        EntityType $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $adminUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->auditLogRepository->insert([
            'admin_user_id' => $adminUserId,
            'action' => $action->value,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'old_values' => $this->maskSensitiveFields($oldValues),
            'new_values' => $this->maskSensitiveFields($newValues),
            'ip_address' => $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        ]);
    }
}
```

The `Logger` class (see `App\Shared\Logging\Logger`) is also used for application-level logging (info, warning, error) in JSON format to daily log files.

---

## Consequences

### Positive

- **Clear separation**: Terminal, Admin, and Public endpoints clearly protected
- **Consistent enforcement**: Middleware applies uniformly across all routes
- **Audit trail**: Authorization decisions logged for security review
- **Prevents confusion**: Auth mixup prevented at middleware level
- **Flexible**: Easy to add resource-level checks later

### Negative

- **Multiple middleware**: More layers to understand
- **Route configuration complexity**: Must remember which middleware for each route
- **Performance**: Each request passes through multiple middleware

### Mitigations

1. **Document authorization rules** clearly in route comments
2. **Use consistent middleware patterns** across all routes
3. **Provide authorization helper functions** in base controller
4. **Test authorization thoroughly** (see Testing section)
5. **Log authorization failures** for security monitoring

---

## Integration with ADR-0015

This pattern implements:
- ✅ **Principle 2**: Device-level Terminal Authentication
  - Terminal endpoints require Bearer tokens
  - Enforced at middleware layer

- ✅ **Principle 3**: Session-based Admin Authentication
  - Admin endpoints require sessions
  - Enforced at middleware layer

- ✅ **Principle 4**: No Member Authentication
  - Members never access API directly
  - Members identified by RFID only

Complements:
- **Pattern 012**: Terminal API Token Authentication (who you are)
- **Pattern 013**: Admin Session Authentication (who you are)
- **Pattern 014**: RFID Member Identification (non-authentication)
- **ADR-0015**: Full authentication strategy
- **ADR-0013**: Audit Logging

---

## Testing

### Unit Tests

```php
// tests/Unit/Middleware/TerminalTokenAuthTest.php
public function test_terminal_auth_allows_sync_endpoints_with_valid_token()
{
    $request = $this->createServerRequest('GET', '/api/sync/members')
        ->withHeader('Authorization', 'Bearer ' . $this->validToken);

    $handler = $this->createMockHandler();
    $response = $this->middleware->process($request, $handler);

    $this->assertEquals(200, $response->getStatusCode());
}

public function test_terminal_auth_rejects_missing_token()
{
    $request = $this->createServerRequest('GET', '/api/sync/members');

    $handler = $this->createMockHandler();
    $response = $this->middleware->process($request, $handler);

    $this->assertEquals(401, $response->getStatusCode());
}
```

### Integration Tests (Playwright)

```typescript
// tests/api/authorization.spec.ts
test('Terminal cannot access /api/admin/members', async () => {
    const response = await fetch('http://localhost:8080/api/admin/members', {
        headers: {
            'Authorization': 'Bearer ' + VALID_TERMINAL_TOKEN
        }
    });
    expect(response.status).toBe(403);
    const data = await response.json();
    expect(data.error).toBe('forbidden');
});

test('Admin session cannot access /api/sync/members', async () => {
    // Login as admin
    const loginResponse = await fetch('http://localhost:8080/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: 'admin@test.com',
            password: 'password123'
        })
    });
    const cookie = loginResponse.headers.get('set-cookie');

    // Try to access sync endpoint
    const response = await fetch('http://localhost:8080/api/sync/members', {
        headers: {
            'Cookie': cookie
        }
    });
    expect(response.status).toBe(401);  // Expecting Bearer token
});

test('Unauthenticated request denied', async () => {
    const response = await fetch('http://localhost:8080/api/admin/members');
    expect(response.status).toBe(401);
});

test('Health endpoint accessible without auth', async () => {
    const response = await fetch('http://localhost:8080/api/health');
    expect(response.status).toBe(200);
});
```

---

## See Also

- **ADR-0015**: Authentication and Authorization Strategy
- **ADR-0013**: Audit Logging
- **ADR-0017**: Input Validation and Injection Prevention
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 013**: Admin Session Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 001**: Input Validation (Custom Validator)
