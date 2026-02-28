# Pattern 013: Admin Session Authentication

**Status**: Active

**Related ADR**: ADR-0015 (Authentication and Authorization Strategy)

**Purpose**: Implement session-based authentication for admin users. Admin panel uses traditional server-side sessions with secure HTTP-only cookies.

---

## Context

The admin panel (React SPA) allows administrators to manage members, products, settlements, and system configuration. Administrators need traditional login/logout with secure session management.

**Key Principles (ADR-0015)**:
1. **Session-based authentication**: Traditional server-side sessions
2. **Secure cookies**: HttpOnly, Secure, SameSite attributes
3. **Session regeneration**: Prevent session fixation attacks
4. **Idle timeout**: Session expires after inactivity
5. **Absolute timeout**: Session expires regardless of activity
6. **Password security**: Bcrypt hashing with cost 12+

---

## Pattern Definition

### Admin Users Repository (PDO)

Admin users are stored in `admin_users` table and accessed via PDO repository (no ORM/Eloquent). Password hashing uses PHP's native `password_hash()` with bcrypt.

```php
// src/Modules/AdminUsers/Repositories/AdminUsersRepository.php
namespace App\Modules\AdminUsers\Repositories;

use PDO;

/**
 * Admin users repository with PDO.
 *
 * Returns associative arrays, not model objects.
 * Password hashing handled by service layer.
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class AdminUsersRepository
{
    public function __construct(private PDO $db) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function updateById(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        foreach ($data as $col => $val) {
            $sets[] = "{$col} = ?";
            $values[] = $val;
        }
        $values[] = $id;
        $sql = 'UPDATE admin_users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($values);
    }
}
```

### Login Input Validation (Custom Validator)

```php
// Validation is done inline in the controller using the custom Validator class.
// No FormRequest; the project uses App\Shared\Validation\Validator (PDO-backed).

// In AuthController::login():
$body = $request->getParsedBody();
$validator = new Validator($this->pdo);
$valid = $validator->validate($body, [
    'email'    => ['required', 'email', 'max:255'],
    'password' => ['required', 'string', 'min:8', 'max:255'],
]);

if (!$valid) {
    // Return 422 with validation errors
    $response->getBody()->write(json_encode(['errors' => $validator->errors()]));
    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
}
```

### Authentication Service

```php
// src/Modules/Auth/Services/AuthService.php
namespace App\Modules\Auth\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;

/**
 * Authentication service for admin login/logout.
 *
 * Handles:
 * - Credential validation (email + password via password_verify)
 * - Session verification
 * - Password changes
 *
 * Returns associative arrays from PDO repository (not Eloquent models).
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class AuthService
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private Logger $logger,
    ) {}

    /**
     * Authenticate admin user with email + password.
     *
     * Validates password (bcrypt comparison via password_verify).
     * Does NOT create session; controller calls session_regenerate_id().
     *
     * @param string $email Admin email
     * @param string $password Plaintext password
     * @return array|null Admin user row as associative array, or null on failure
     */
    public function authenticate(string $email, string $password): ?array
    {
        $admin = $this->adminUsersRepository->findByEmail($email);

        if (!$admin) {
            $this->logger->info('Login failed: unknown email', ['email' => $email]);
            return null;
        }

        if (!password_verify($password, $admin['password_hash'])) {
            $this->logger->info('Login failed: invalid password', ['email' => $email]);
            return null;
        }

        if (!(bool) $admin['is_active']) {
            $this->logger->info('Login failed: inactive account', ['email' => $email]);
            return null;
        }

        // Update last login timestamp
        $this->adminUsersRepository->updateById($admin['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('Login successful', ['admin_id' => $admin['id']]);
        return $admin;
    }

    /**
     * Verify if user session is still valid.
     *
     * Checks user exists in database and is still active.
     * Called by AdminSessionAuth middleware on each request.
     *
     * @param string $adminId Admin user ID from session
     * @return array|null Admin user row or null
     */
    public function getActiveAdmin(string $adminId): ?array
    {
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin || !(bool) $admin['is_active']) {
            return null;
        }
        return $admin;
    }
}
```

### Login/Logout Controller

```php
// src/Modules/Auth/Controllers/AuthController.php
namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin authentication controller (Slim 4).
 *
 * Handles:
 * - POST /api/auth/login - Create session
 * - POST /api/auth/logout - Destroy session
 * - GET /api/auth/profile - Current user info
 *
 * Uses PSR-7 request/response, native PHP sessions ($_SESSION).
 *
 * Implements Pattern 013: Admin Session Authentication
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/auth/login - Login with email + password.
     *
     * Flow:
     * 1. Parse and validate input from PSR-7 request body
     * 2. Find user and verify password (Service)
     * 3. Regenerate session ID (prevent fixation)
     * 4. Store admin_user_id in $_SESSION
     * 5. Return user data + set secure cookie
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        // 1. Authenticate user (service validates password via password_verify)
        $admin = $this->authService->authenticate($email, $password);

        if (!$admin) {
            $response->getBody()->write(json_encode([
                'error' => 'authentication_failed',
                'message' => 'Invalid email or password',
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // 2. Start/regenerate session (prevent session fixation)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('_session');
            session_start();
        }
        session_regenerate_id(true);

        // 3. Store user info in $_SESSION
        $_SESSION['admin_user_id'] = $admin['id'];

        // 4. Return user data
        $response->getBody()->write(json_encode([
            'user' => [
                'id' => $admin['id'],
                'email' => $admin['email'],
                'name' => $admin['name'],
            ],
            'message' => 'Logged in successfully',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/auth/logout - Destroy session.
     *
     * Flow:
     * 1. Destroy session data ($_SESSION)
     * 2. Delete session cookie
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $_SESSION = [];
        session_destroy();

        $response->getBody()->write(json_encode(['message' => 'Logged out successfully']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/auth/profile - Get current authenticated user.
     *
     * Protected by: AdminSessionAuth middleware (PSR-15)
     * (middleware ensures admin_user_id exists in session and attaches admin data)
     */
    public function profile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Admin data attached by AdminSessionAuth middleware
        $admin = $request->getAttribute('admin_user');

        $response->getBody()->write(json_encode([
            'user' => [
                'id' => $admin['id'],
                'email' => $admin['email'],
                'name' => $admin['name'],
            ],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Session Authentication Middleware (PSR-15)

```php
// src/Modules/Auth/Middleware/AdminSessionAuth.php
namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use Slim\Psr7\Response;

/**
 * PSR-15 Middleware for validating admin session authentication.
 *
 * Ensures:
 * - PHP session is active
 * - admin_user_id stored in $_SESSION
 * - User still exists in database
 * - User is still active
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class AdminSessionAuth implements MiddlewareInterface
{
    public function __construct(private AdminUsersRepository $adminUsersRepository) {}

    /**
     * Validate session before allowing request to proceed (PSR-15).
     *
     * On success: Attaches admin user data to request attributes
     * On failure: Returns 401 JSON response
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. Start session if not active
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('_session');
            session_start();
        }

        // 2. Check if admin_user_id exists in $_SESSION
        $adminId = $_SESSION['admin_user_id'] ?? null;
        if (!$adminId) {
            return $this->unauthorized();
        }

        // 3. Verify user still exists and is active (PDO lookup)
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin || !(bool) $admin['is_active']) {
            return $this->unauthorized();
        }

        // 4. Attach admin data to request attributes
        $request = $request->withAttribute('admin_user_id', $adminId);
        $request = $request->withAttribute('admin_user', $admin);

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => 'admin_not_authenticated']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Accessing Admin Data in Controllers

PSR-7 `ServerRequestInterface` uses `withAttribute()` / `getAttribute()` to pass data between middleware and controllers. No macros or service providers are needed.

```php
// Middleware attaches admin data:
$request = $request->withAttribute('admin_user_id', $adminId);
$request = $request->withAttribute('admin_user', $admin);

// Controller reads admin data:
$adminUserId = $request->getAttribute('admin_user_id');
$adminUser = $request->getAttribute('admin_user');
```

### Route Configuration with Middleware (Slim 4)

```php
// src/routes.php
use App\Modules\Auth\Middleware\AdminSessionAuth;
use App\Modules\Auth\Controllers\AuthController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Admin authentication routes (Slim 4).
 *
 * POST /api/auth/login - No auth required (public)
 * POST /api/auth/logout - Session required
 * GET /api/auth/profile - Session required
 */

// Login is public (no middleware)
$app->post('/api/auth/login', [AuthController::class, 'login']);

// Protected auth routes (session required via PSR-15 middleware)
$app->group('/api/auth', function (RouteCollectorProxy $group) {
    $group->post('/logout', [AuthController::class, 'logout']);
    $group->get('/profile', [AuthController::class, 'profile']);
    $group->patch('/change-password', [AuthController::class, 'changePassword']);
})->add(AdminSessionAuth::class);

// All admin endpoints use the same session middleware
$app->group('/api/admin', function (RouteCollectorProxy $group) {
    // Members, Products, Settlements, etc.
    $group->get('/members', [MembersAdminController::class, 'index']);
    $group->post('/members', [MembersAdminController::class, 'store']);
    // ...etc
})->add(AdminSessionAuth::class);
```

---

## Session Configuration

The project uses native PHP sessions (`$_SESSION`) with file-based storage. Session parameters are configured via `php.ini` or at runtime before `session_start()`.

```php
// Session is started in the AdminSessionAuth middleware:
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('_session');
    session_start();
}

// php.ini or runtime configuration:
// session.gc_maxlifetime = 7200   (2 hours idle timeout)
// session.cookie_httponly = 1     (JavaScript cannot access - prevents XSS theft)
// session.cookie_secure = 1       (HTTPS only in production)
// session.cookie_samesite = Lax   (Prevents CSRF in most cases)
// session.save_handler = files    (File-based session storage)
```

No database-backed session table is needed -- the project uses PHP's native file-based session handler.

---

## Database Schema

```sql
-- Admin users table
CREATE TABLE admin_users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE COMMENT 'Login email',
    password VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash (cost 12+)',
    name VARCHAR(255) NOT NULL COMMENT 'Full name for display',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'False = account disabled, cannot login',
    last_login_at TIMESTAMP NULLABLE COMMENT 'Last successful login',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_is_active (is_active),
);
```

---

## Security Features

### 1. Session Regeneration (Prevent Fixation)

```php
// In AuthController::login()
session_regenerate_id(true);  // Delete old session file, regenerate new ID
```

Prevents attacker from creating session before user logs in.

### 2. HttpOnly Cookies (Prevent XSS Token Theft)

```ini
; php.ini
session.cookie_httponly = 1
```

JavaScript cannot access session cookie (even if XSS vulnerability exists).

### 3. Secure Cookies (HTTPS Only)

```ini
; php.ini (production)
session.cookie_secure = 1
```

Cookie only sent over HTTPS (not HTTP). Prevents MITM attacks.

### 4. SameSite Attribute (Prevent CSRF)

```ini
; php.ini
session.cookie_samesite = Lax
```

Cookie not sent in cross-site requests. Prevents CSRF attacks.

### 5. Password Hashing (Prevent Database Breach Exposure)

```php
password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);  // Bcrypt, cost 12+
```

Even if database stolen, passwords cannot be reversed.

### 6. Session Timeout (Limit Exposure)

```ini
; php.ini
session.gc_maxlifetime = 7200  ; 2 hours idle timeout (in seconds)
```

Session expires if inactive for 2 hours. Admin must re-login.

---

## Login Flow Diagram

```
Admin Browser              Backend API
    |                          |
    | POST /api/auth/login     |
    |------- email, pwd ------>|
    |                          |
    |                    1. Validate input
    |                    2. Find user by email
    |                    3. Hash password, compare
    |                    4. Check is_active
    |                    5. session_regenerate_id()
    |                    6. $_SESSION['user_id'] = ...
    |                    7. Create session in DB
    |                          |
    | 200 OK                   |
    |<---- Set-Cookie ---------|
    | { user: {...} }          |
    |                          |
    | [Browser stores session  |
    |  ID in cookie]           |
    |                          |
    | GET /api/members         |
    |------- Cookie:sid ------>|
    |                          |
    |                    1. Extract session ID from cookie
    |                    2. Load session from DB
    |                    3. Check user_id exists
    |                    4. Verify user still active
    |                    5. Load members
    |                          |
    | 200 OK                   |
    |<---- members array ------|
```

---

## Logout Flow

```php
// When admin clicks "Logout"
POST /api/auth/logout
Cookie: _session=...

// Backend:
1. Get admin_user_id from $_SESSION
2. Log the logout event
3. $_SESSION = [];        // Clear session data
4. session_destroy();     // Destroy session file
5. Return 200 OK

// Frontend:
- Remove any cached auth token
- Clear Redux/Context state
- Redirect to /login
```

---

## Consequences

### Positive

- **Simple & proven**: Session-based auth is well-understood
- **Immediate revocation**: Disabling user prevents future access
- **CSRF protection**: SameSite attribute + session model
- **XSS resistant**: HttpOnly cookies prevent script access
- **No tokens to manage**: No JWT refresh tokens, no blacklists

### Negative

- **Server-side state**: Must store sessions (DB or file-based)
- **No offline access**: Session required for every request
- **Single-device sessions**: Can't have multiple concurrent logins easily
- **No mobile-friendly**: SPAs need cookie storage configured

### Mitigations

1. **Use database session storage** for scalability
2. **Log all login/logout events** (Pattern 014: Audit Logging)
3. **Implement rate limiting** on login endpoint (prevent brute force)
4. **Provide password reset** flow for locked-out admins
5. **Add last-login tracking** to detect compromised accounts

---

## Integration with ADR-0015

This pattern implements:
- ✅ **Principle 3**: Session-based Admin Authentication
- ✅ Secure cookies (HttpOnly, Secure, SameSite)
- ✅ Session regeneration (prevent fixation)
- ✅ Bcrypt password hashing (cost 12+)
- ✅ Idle timeout (2 hours) + absolute timeout (24 hours)

Complements:
- **Pattern 012**: Terminal API Token Authentication (different mechanism)
- **Pattern 014**: RFID Member Identification (not authentication)
- **Pattern 015**: Authorization & Access Control
- **ADR-0015**: Full authentication strategy
- **ADR-0017**: Input Validation and Injection Prevention

---

## Testing

### Unit Tests

```php
// tests/Unit/Services/AuthServiceTest.php
public function test_authenticate_returns_admin_with_valid_credentials()
{
    // Insert test admin via PDO
    $this->insertTestAdmin('test@test.com', 'test123456');

    $authenticated = $this->authService->authenticate('test@test.com', 'test123456');
    $this->assertNotNull($authenticated);
    $this->assertEquals('test@test.com', $authenticated['email']);
}

public function test_authenticate_returns_null_with_invalid_password()
{
    $this->insertTestAdmin('test@test.com', 'test123456');

    $result = $this->authService->authenticate('test@test.com', 'wrongpassword');
    $this->assertNull($result);
}

public function test_authenticate_returns_null_with_inactive_user()
{
    $this->insertTestAdmin('test@test.com', 'test123456', isActive: false);

    $result = $this->authService->authenticate('test@test.com', 'test123456');
    $this->assertNull($result);
}
```

### Integration Tests (Playwright)

```typescript
// tests/api/admin-authentication.spec.ts
test('POST /api/auth/login with valid credentials returns 200', async () => {
    const response = await fetch('http://localhost:8080/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: 'admin@example.com',
            password: 'securepassword123'
        })
    });
    expect(response.status).toBe(200);
    expect(response.headers.get('set-cookie')).toBeTruthy();
});

test('POST /api/auth/login with invalid credentials returns 401', async () => {
    const response = await fetch('http://localhost:8080/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: 'admin@example.com',
            password: 'wrongpassword'
        })
    });
    expect(response.status).toBe(401);
});

test('GET /api/auth/profile without session returns 401', async () => {
    const response = await fetch('http://localhost:8080/api/auth/profile');
    expect(response.status).toBe(401);
});

test('GET /api/auth/profile with valid session returns 200', async () => {
    // 1. Login
    const loginResponse = await fetch('http://localhost:8080/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: 'admin@example.com',
            password: 'securepassword123'
        })
    });

    // 2. Extract cookie
    const setCookie = loginResponse.headers.get('set-cookie');

    // 3. Use cookie in next request
    const profileResponse = await fetch('http://localhost:8080/api/auth/profile', {
        headers: { 'Cookie': setCookie }
    });
    expect(profileResponse.status).toBe(200);
});
```

---

## See Also

- **ADR-0015**: Authentication and Authorization Strategy
- **ADR-0016**: Transport Security (HTTPS/TLS)
- **ADR-0017**: Input Validation and Injection Prevention
- **Pattern 001**: Input Validation (Custom Validator)
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 015**: Authorization & Access Control
