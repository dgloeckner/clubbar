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
7. **A password is half an authentication**: TOTP is mandatory ([ADR-0026](../../adr/0026-mandatory-totp-two-factor-authentication.md)), so login is two steps and a session becomes authenticated only at the second

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
    'email'    => ['required', 'email'],
    'password' => ['required', 'string'],
]);
// Deliberately no min/max on the password at *login*. A length rule here only
// tells an attacker which guesses are worth making, and it would lock out any
// account whose password predates the current policy. Complexity is enforced
// where a password is set, not where it is presented.

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
     * POST /api/auth/login - Password step. This is only *half* an
     * authentication: TOTP is mandatory (ADR-0026), so a correct password
     * buys an MFA-pending session, not an authenticated one.
     *
     * Flow:
     * 1. Validate input (Validator), 422 on failure
     * 2. Verify the password (AuthService), 401 + a recorded attempt on failure
     * 3. session_regenerate_id(true) — prevent fixation (ADR-0025)
     * 4a. TOTP enrolled  -> mfa_pending_user_id + a 5-minute TTL; respond
     *     {"requiresMfa": true}. No admin_user_id, no CSRF token, no timeout
     *     stamps: nothing here is an authenticated session.
     * 4b. Not enrolled    -> full session with totp_setup_required, which
     *     AdminSessionAuth turns into a 403 on every route but the two
     *     enrolment ones. Authentication is complete, so the account's
     *     rate-limit rows are cleared and SessionTimeout::begin() stamps it.
     *
     * The attempt counter is deliberately NOT cleared in branch 4a. Clearing
     * on a correct password let an attacker mint a fresh MFA window on demand
     * and brute-force TOTP forever (#78).
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface

    /**
     * POST /api/auth/mfa - Second factor. On success this is where the session
     * actually becomes authenticated: admin_user_id is set, a CSRF token is
     * minted, SessionTimeout::begin() stamps both clocks, and the account's
     * login attempts are cleared. Five wrong codes destroy the pending session,
     * and every wrong code is persisted to `login_attempts` — a session-only
     * cap is defeated by simply re-authenticating.
     */
    public function mfa(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface

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
 * Ensures, in this order:
 * - PHP session is active
 * - admin_user_id stored in $_SESSION
 * - neither timeout has been reached
 * - user still exists in database and is still active
 * - the user has enrolled a second factor (ADR-0026)
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class AdminSessionAuth implements MiddlewareInterface
{
    public function __construct(private AdminUsersRepository $adminUsersRepository) {}

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

        // 3. Both timeouts, checked BEFORE the session is touched — otherwise a
        //    request extends a session it arrived too late for. Either limit
        //    empties and destroys the session and answers 401 session_expired.
        if (SessionTimeout::hasExpired($_SESSION)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            return $this->sessionExpired();
        }

        // 4. Verify user still exists and is active (PDO lookup)
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin || !(bool) $admin['is_active']) {
            return $this->unauthorized();
        }

        // 5. Restart the idle clock. Never the absolute one — that is the point
        //    of having two (see SessionTimeout::touch()).
        SessionTimeout::touch($_SESSION);

        // 6. TOTP is mandatory. A session authenticated by password alone is
        //    confined to the two enrolment routes and gets 403 everywhere else.
        if (($_SESSION['totp_setup_required'] ?? false) === true
            && !in_array($request->getUri()->getPath(), ['/api/auth/2fa/setup', '/api/auth/2fa/confirm'], true)
        ) {
            return $this->totpSetupRequired();   // 403 totp_setup_required
        }

        // 7. Attach admin data to request attributes
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

    // sessionExpired(): 401 {"error": "session_expired", ...}
    // totpSetupRequired(): 403 {"error": "totp_setup_required", ...}
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

The project uses native PHP sessions (`$_SESSION`) with file-based storage. **The code is the authoritative layer, not a configuration file**: there is no `php.ini` in the repo, and the shared-hosting target may ignore any ini file the package ships ([ADR-0031](../../adr/0031-production-hardening-on-shared-hosting.md) decision 1). Every session directive is therefore set by `RuntimeHardening`, called from `backend/bootstrap.php` before any `session_start()` can fire — PHP refuses them all once a session is open.

```php
// backend/src/Shared/Security/RuntimeHardening.php — applied on every request:
ini_set('session.use_strict_mode', '1');     // reject a session ID the client invented
ini_set('session.use_only_cookies', '1');    // never in a URL, never in a Referer header
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', (string) $config->sessionMaxAge);
ini_set('session.save_path', $config->sessionSavePath);  // only once proven writable

session_set_cookie_params([
    'lifetime' => $config->sessionMaxAge,   // client-side expiry
    'path'     => '/',
    'secure'   => $config->sessionCookieSecure, // never sent over plain HTTP
    'httponly' => true,                     // JavaScript cannot read it — blunts XSS theft
    'samesite' => 'Lax',                    // blocks the common CSRF shapes
]);

// Session is started in the AdminSessionAuth middleware:
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('_session');
    session_start();
}
```

`session.use_strict_mode` is the one to understand: PHP's compiled default is *off*, so PHP will adopt and initialise whatever session ID arrives in the cookie. An attacker can then plant an ID in a victim's browser and wait for them to log in with it. It is the half of session fixation that `session_regenerate_id()` (ADR-0025, below) cannot reach — regeneration happens at login, and by then the planted ID is already the session being renamed. ADR-0016 requires the directive; until #246 nothing set it.

`session.save_path` moves session files off the host's shared session directory, where on mass hosting another account may be able to read them — and a readable session file is an admin login. It defaults to `backend/storage/sessions` and is overridable through `SESSION_SAVE_PATH` (`session.save_path` in the package's `config.php`), which is how the installer's data directory (#245) will point it outside the document root.

The path is applied *only after* the directory is proven writable. An unwritable `save_path` is not a weaker deployment, it is an outage — no session can be written, so nobody can log in — so the host default stands instead and `bootstrap.php` logs a warning naming the path. Taking the path over also means taking over its cleanup, so garbage collection is switched back on if the host had disabled it in favour of its own cron sweep of the shared directory.

`sessionCookieSecure` is derived, not hard-coded — a `Secure` cookie is dropped by the browser over HTTP, which would make local development and the E2E suite (both on `http://localhost`) unable to hold a session at all. `AppConfig::resolveSessionCookieSecure()` decides, in order:

1. `SESSION_COOKIE_SECURE` when set — the override, both ways.
2. `APP_URL` starts with `https://` — the deployment is HTTPS-facing.
3. The request arrived over TLS (`HTTPS`, `X-Forwarded-Proto`, port 443) — so an install that never set `APP_URL` still gets `Secure` on the sessions it establishes over HTTPS.
4. Otherwise off — plain-HTTP development.

No database-backed session table is needed -- the project uses PHP's native file-based session handler.

---

## Database Schema

As in `db/migrations/001_initial_schema.sql` (TOTP columns added by
`004_totp_2fa.sql`). Ids are UUIDs, not auto-increment integers, and the hash
column is `password_hash` — an `INSERT` naming `password` fails.

```sql
CREATE TABLE admin_users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,      -- password_hash(), bcrypt
    display_name VARCHAR(255) NULL,
    locale VARCHAR(10) NOT NULL DEFAULT 'de',
    is_active TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = disabled, cannot log in
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_users_is_active (is_active),
    INDEX idx_admin_users_email (email)
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

### 2–4. Cookie attributes (XSS, MITM, CSRF)

`httponly`, `secure` and `samesite` are set by `RuntimeHardening` in the
`session_set_cookie_params()` call shown under **Session Configuration** above —
**not** by a `php.ini` file. There is no `php.ini` in this repository, and the
shared-hosting target may ignore one the package ships ([ADR-0031](../../adr/0031-production-hardening-on-shared-hosting.md)
decision 1), so an ini-based reading of these guarantees is wrong twice over.

| Attribute | Value | Stops |
|-----------|-------|-------|
| `httponly` | always `true` | JavaScript reading the cookie, so an XSS hole cannot steal the session |
| `secure` | derived by `AppConfig::resolveSessionCookieSecure()` | the cookie travelling over plain HTTP. Derived rather than hard-coded because a `Secure` cookie is dropped by the browser on `http://localhost`, which would leave development and the E2E suite unable to hold a session at all |
| `samesite` | `Lax` | the common CSRF shapes. The CSRF token is the other half, not a substitute |

### 5. Password Hashing (Prevent Database Breach Exposure)

```php
password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);  // Bcrypt, cost 12+
```

Even if database stolen, passwords cannot be reversed.

### 6. Session Timeout (Limit Exposure)

Both limits are enforced by the application, not by `php.ini`. `session.gc_maxlifetime`
only tells PHP when a session *file* becomes eligible for collection; it is a
sweep schedule, not a promise, and on a shared host it is whatever someone else
configured. Relying on it meant a session lived as long as the host felt like
keeping it (#118).

`SessionTimeout` stamps two clocks into the session when authentication
completes, and `AdminSessionAuth` checks them on every request before it does
anything else:

| Limit | Value | Restarted by activity | What it stops |
|-------|-------|-----------------------|---------------|
| Idle | 2 hours | yes | An unattended browser left signed in |
| Absolute | 24 hours | no | A stolen cookie kept alive by polling |

Reaching either limit empties and destroys the session and answers
`401 session_expired`; the admin must sign in again. The idle clock alone would
not be enough — an attacker holding a session cookie can keep touching it
forever, which is exactly what the absolute limit refuses.

A session carrying no stamps predates this rule and is adopted at first sight
rather than rejected, so deploying the timeouts does not sign everyone out.

---

## Login Flow Diagram

```
Admin Browser              Backend API
    |                          |
    | POST /api/auth/login     |
    |------- email, pwd ------>|
    |                          |
    |                    1. Rate limit: per IP and per account
    |                    2. Validate input
    |                    3. Find user by email, password_verify()
    |                    4. Check is_active
    |                    5. session_regenerate_id(true)     [ADR-0025]
    |                    6. $_SESSION['mfa_pending_user_id'] = ...
    |                       (5-minute TTL; NOT authenticated yet)
    |                          |
    | 200 OK                   |
    |<-- {requiresMfa: true} --|
    |                          |
    | POST /api/auth/mfa       |
    |--------- code ---------->|
    |                          |
    |                    1. Rate limit: per IP and per account
    |                    2. Verify the TOTP code    [ADR-0026]
    |                    3. $_SESSION['admin_user_id'] = ...
    |                    4. Mint the CSRF token
    |                    5. SessionTimeout::begin() — stamps both clocks
    |                    6. Clear this account's login attempts
    |                          |
    | 200 OK                   |
    |<---- Set-Cookie ---------|
    | { user: {...} }          |
    |                          |
    | GET /api/admin/members   |
    |------- Cookie:sid ------>|
    |                          |
    |                    1. session_start() — file-backed, no DB
    |                    2. admin_user_id present?           else 401
    |                    3. Idle 2h / absolute 24h reached?  else 401 session_expired
    |                    4. User still exists and is active? else 401
    |                    5. SessionTimeout::touch() — idle clock only
    |                    6. Second factor enrolled?          else 403
    |                    7. Load members
    |                          |
    | 200 OK                   |
    |<---- members array ------|
```

An admin who has never enrolled a second factor takes branch 4b of `login()`
instead: a full session carrying `totp_setup_required`, which step 6 above turns
into a 403 on every route but `/api/auth/2fa/setup` and `/api/auth/2fa/confirm`.

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

1. **Session files live outside the shared session directory** — `session.save_path`
   defaults to `backend/storage/sessions`, because a session file another
   hosting account can read *is* an admin login (ADR-0031)
2. **Log all login/logout events** — implemented, see Pattern 016: Audit Logging
3. **Rate limiting on both auth steps** — implemented: per IP **and** per account,
   5 attempts / 15 minutes, on `/api/auth/login` and `/api/auth/mfa`, with failures
   persisted so re-authenticating does not reset the count (#78)
4. **Provide password reset** flow for locked-out admins — *not implemented*; an
   admin locked out of their account needs another admin to act
5. **Add last-login tracking** to detect compromised accounts — implemented
   (`last_login_at`)

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
- **ADR-0025**: Session Fixation Protection (`session_regenerate_id()` at login)
- **ADR-0026**: Mandatory TOTP Two-Factor Authentication — why login is two steps
- **ADR-0031**: Production Hardening on Shared Hosting — why the session directives are set from code rather than an ini file
- **Pattern 001**: Input Validation (Custom Validator)
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 015**: Authorization & Access Control
