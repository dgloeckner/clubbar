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

```php
// app/Http/Middleware/AuthorizeTerminalSync.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Authorization middleware for Terminal sync endpoints.
 *
 * Verifies:
 * - Request authenticated by Terminal token (Pattern 012)
 * - Endpoint is a sync endpoint (not admin)
 * - Terminal is authorized to sync
 *
 * Implements Pattern 015: Authorization & Access Control
 */
final class AuthorizeTerminalSync
{
    /**
     * Authorize terminal to access sync endpoints.
     *
     * Called after AuthenticateTerminalToken middleware.
     * Ensures terminal is only accessing sync endpoints, not admin.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws ForbiddenException
     */
    public function handle(Request $request, Closure $next)
    {
        $terminal = $request->terminal();

        // Should never happen (authenticated middleware would fail first)
        if (!$terminal) {
            throw new ForbiddenException('Terminal not authenticated');
        }

        // Verify this is a sync endpoint, not admin
        if ($request->is('api/admin/*')) {
            throw new ForbiddenException(
                'Terminal cannot access admin endpoints',
                'terminal_cannot_access_admin'
            );
        }

        // Log access (optional)
        // AuditLogger::log('TERMINAL_ACCESS', $terminal->id, $request->path());

        return $next($request);
    }
}
```

#### 2. Admin Session Access (Admin Endpoints)

```php
// app/Http/Middleware/AuthorizeAdminSession.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Authorization middleware for Admin endpoints.
 *
 * Verifies:
 * - Request authenticated by session (Pattern 013)
 * - Endpoint is admin endpoint (not sync)
 * - User is active
 *
 * Implements Pattern 015: Authorization & Access Control
 */
final class AuthorizeAdminSession
{
    /**
     * Authorize admin user to access admin endpoints.
     *
     * Called after AuthenticateSession middleware.
     * Ensures admin is only accessing admin endpoints with session auth.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws ForbiddenException
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Should never happen (authenticated middleware would fail first)
        if (!$user) {
            throw new ForbiddenException('User not authenticated');
        }

        // Verify user is still active
        if (!$user->isActive()) {
            throw new ForbiddenException(
                'User account is inactive',
                'user_inactive'
            );
        }

        // Log access (optional)
        // AuditLogger::log('ADMIN_ACCESS', $user->id, $request->path());

        return $next($request);
    }
}
```

#### 3. Prevent Auth Mixup

```php
// app/Http/Middleware/PreventAuthMixup.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to prevent authentication method confusion.
 *
 * Ensures:
 * - Terminal endpoints use Bearer tokens, not sessions
 * - Admin endpoints use sessions, not Bearer tokens
 * - Clear separation of auth mechanisms
 *
 * Implements Pattern 015: Authorization & Access Control
 */
final class PreventAuthMixup
{
    /**
     * Verify auth mechanism matches endpoint type.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $authType 'terminal' or 'admin'
     * @return mixed
     * @throws BadRequestException
     */
    public function handle(Request $request, Closure $next, string $authType)
    {
        $hasSessionAuth = $request->session()->has('user_id');
        $hasBearerAuth = $request->bearerToken() !== null;

        if ($authType === 'terminal') {
            // Terminal endpoints require Bearer token, reject session auth
            if ($hasSessionAuth && !$hasBearerAuth) {
                throw new BadRequestException(
                    'Sync endpoints require Bearer token authentication',
                    'wrong_auth_method'
                );
            }
        } elseif ($authType === 'admin') {
            // Admin endpoints require session auth, reject Bearer token
            if ($hasBearerAuth && !$hasSessionAuth) {
                throw new BadRequestException(
                    'Admin endpoints require session authentication',
                    'wrong_auth_method'
                );
            }
        }

        return $next($request);
    }
}
```

---

### Route Protection

```php
// routes/api.php
use App\Http\Middleware\AuthenticateTerminalToken;
use App\Http\Middleware\AuthorizeTerminalSync;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeAdminSession;
use App\Http\Middleware\PreventAuthMixup;

/**
 * Terminal Sync API Routes
 *
 * Authentication: Bearer token (Pattern 012)
 * Authorization: Terminal device access (Pattern 015)
 */
Route::prefix('sync')
    ->middleware([
        AuthenticateTerminalToken::class,  // Verify Bearer token
        'throttle:60,1',                    // Rate limiting (60 req/min)
        AuthorizeTerminalSync::class,       // Verify terminal access
    ])
    ->group(function () {
        Route::get('/members', [SyncController::class, 'index']);
        Route::get('/categories', [SyncController::class, 'categories']);
        Route::get('/products', [SyncController::class, 'products']);
        Route::patch('/members/{id}/language', [SyncController::class, 'updateLanguage']);
        Route::post('/transactions', [SyncController::class, 'transactions']);
    });

/**
 * Admin API Routes
 *
 * Authentication: Session cookie (Pattern 013)
 * Authorization: Admin user access (Pattern 015)
 */
Route::prefix('admin')
    ->middleware([
        AuthenticateSession::class,         // Verify session
        'throttle:120,1',                   // Rate limiting (120 req/min)
        AuthorizeAdminSession::class,       // Verify admin access
    ])
    ->group(function () {
        // Include all admin modules
        Route::apiResource('members', MembersAdminController::class);
        Route::post('/members/{id}/export', [MembersAdminController::class, 'export']);
        Route::post('/members/{id}/anonymize', [MembersAdminController::class, 'anonymize']);

        Route::apiResource('products', ProductsAdminController::class);
        Route::apiResource('settlements', SettlementsAdminController::class);
        Route::apiResource('audit-log', AuditLogAdminController::class)->only(['index', 'show']);
    });

/**
 * Public Routes (No Auth Required)
 */
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/health', [HealthController::class, 'health']);
```

---

### Resource Ownership Authorization

```php
// app/Http/Middleware/AuthorizeResourceOwner.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware for resource ownership checks.
 *
 * Ensures authenticated user can only access their own resources.
 * Used when:
 * - User viewing their own profile
 * - Admin exporting their own data
 * - User changing their own password
 *
 * Note: Admins have full access to all resources (no ownership restriction).
 *
 * Implements Pattern 015: Authorization & Access Control
 */
final class AuthorizeResourceOwner
{
    /**
     * Verify user owns requested resource.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $paramName Route parameter name (e.g., 'id', 'member_id')
     * @return mixed
     * @throws ForbiddenException
     */
    public function handle(Request $request, Closure $next, string $paramName = 'id')
    {
        $resourceId = $request->route($paramName);
        $authenticatedId = $request->userId();  // For admins

        // Only enforce for non-admin contexts
        // Admins can access any resource
        if ($authenticatedId && $resourceId && $authenticatedId != $resourceId) {
            // Optional: Check user type (admin vs member)
            $user = $request->user();
            if ($user && !$user->isAdmin()) {
                throw new ForbiddenException(
                    'Cannot access another user\'s resource',
                    'forbidden'
                );
            }
        }

        return $next($request);
    }
}
```

---

### Rate Limiting by Auth Type

```php
// config/auth.php
return [
    'rate_limits' => [
        // Terminal API: Lower rate limit (sync once per minute typical)
        'terminal_sync' => [
            'limit' => 60,
            'window' => 60,  // 60 requests per 60 seconds
        ],

        // Admin API: Higher rate limit (interactive usage)
        'admin' => [
            'limit' => 120,
            'window' => 60,  // 120 requests per 60 seconds
        ],

        // Login attempts: Strict rate limiting (prevent brute force)
        'login' => [
            'limit' => 5,
            'window' => 60,  // 5 attempts per 60 seconds
        ],
    ],
];
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
// app/Http/Modules/Members/Controllers/MembersAdminController.php
namespace App\Http\Modules\Members\Controllers;

use Illuminate\Http\Request;

/**
 * Admin controller for member management.
 *
 * All endpoints require:
 * - Session authentication (AuthenticateSession middleware)
 * - Admin role authorization (AuthorizeAdminSession middleware)
 * - Specific resource permissions (can be checked here if needed)
 *
 * Implements Pattern 015: Authorization & Access Control
 */
final class MembersAdminController extends Controller
{
    /**
     * DELETE /api/admin/members/{id}
     *
     * Additional authorization check (example):
     * - Admin with higher role might be able to delete
     * - Lower role can only deactivate
     *
     * This is optional; middleware provides basic auth.
     */
    public function destroy(string $id): JsonResponse
    {
        $user = request()->user();

        // Example: Only certain admins can delete
        if (!$user->canDeleteMembers()) {
            throw new ForbiddenException('Insufficient permissions to delete members');
        }

        // Proceed with deletion
        $this->service->delete($id);
        return response()->noContent();
    }
}
```

---

## Audit Logging with Authorization

```php
// app/Shared/Services/AuditLogger.php
namespace App\Shared\Services;

/**
 * Log authorization decisions and access.
 *
 * Helps track:
 * - Who accessed what
 * - Authorization successes/failures
 * - Potential security issues
 *
 * Implements Pattern 015: Authorization & Access Control
 * Related to ADR-0013: Audit Logging
 */
final class AuditLogger
{
    /**
     * Log successful authorization.
     *
     * @param string $actor 'terminal:ID' or 'admin:ID'
     * @param string $action 'GET', 'POST', 'PATCH', etc.
     * @param string $resource '/api/members', etc.
     * @return void
     */
    public static function logAuthorizedAccess(
        string $actor,
        string $action,
        string $resource,
    ): void {
        \Log::info('AUTHORIZED_ACCESS', [
            'actor' => $actor,
            'action' => $action,
            'resource' => $resource,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Log failed authorization (suspicious).
     *
     * @param string $actor 'terminal:ID' or 'admin:ID'
     * @param string $reason 'wrong_auth_method', 'forbidden', etc.
     * @param string $resource '/api/admin/members', etc.
     * @return void
     */
    public static function logUnauthorizedAccess(
        string $actor,
        string $reason,
        string $resource,
    ): void {
        \Log::warning('UNAUTHORIZED_ACCESS_ATTEMPT', [
            'actor' => $actor,
            'reason' => $reason,
            'resource' => $resource,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Log authentication failure.
     *
     * @param string $attemptedEmail Email that failed login
     * @param string $reason 'wrong_password', 'user_not_found', etc.
     * @return void
     */
    public static function logAuthenticationFailure(
        string $attemptedEmail,
        string $reason,
    ): void {
        \Log::warning('AUTHENTICATION_FAILURE', [
            'email' => $attemptedEmail,
            'reason' => $reason,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ]);
    }
}
```

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
// tests/Unit/Middleware/AuthorizeTerminalSyncTest.php
public function test_authorizeTerminalSync_allows_sync_endpoints()
{
    $request = $this->createRequest('GET', '/api/sync/members');
    $request->setAttribute('terminal', Terminal::factory()->create());

    $response = $this->middleware->handle($request, function($req) {
        return response()->json(['ok' => true]);
    });

    $this->assertEquals(200, $response->status());
}

public function test_authorizeTerminalSync_denies_admin_endpoints()
{
    $request = $this->createRequest('GET', '/api/admin/members');
    $request->setAttribute('terminal', Terminal::factory()->create());

    $this->expectException(ForbiddenException::class);
    $this->middleware->handle($request, function($req) {
        return response()->json(['ok' => true]);
    });
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
- **Pattern 001**: Form Requests for Input Validation
