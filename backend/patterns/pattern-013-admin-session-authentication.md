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

### User Model & Password Hashing

```php
// app/Models/AdminUser.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Admin User Model.
 *
 * Represents administrators who can access /api/admin/* endpoints.
 * Users authenticate with email + password (session-based).
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class AdminUser extends Authenticatable
{
    protected $table = 'admin_users';
    protected $guarded = [];
    protected $hidden = ['password'];

    /**
     * Hash password before storing (mutator).
     *
     * Uses bcrypt with cost factor 12+.
     * Set during create/update via:
     *   AdminUser::create(['email' => ..., 'password' => plaintext])
     *
     * @param string $value Plaintext password
     * @return void
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::make(
            $value,
            ['cost' => 12]  // Bcrypt cost (higher = slower, more secure)
        );
    }

    /**
     * Check if user can access admin panel.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active ?? false;
    }
}
```

### Login Request Validation

```php
// app/Http/Modules/Auth/Requests/LoginRequest.php
namespace App\Http\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Login form request validation (Pattern 001).
 *
 * Validates email + password for admin login.
 * Does NOT authenticate; only validates format.
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint; no authorization needed
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:255',
        ];
    }

    public function email(): string
    {
        return $this->validated('email');
    }

    public function password(): string
    {
        return $this->validated('password');
    }
}
```

### Authentication Service

```php
// app/Http/Modules/Auth/Services/AuthService.php
namespace App\Http\Modules\Auth\Services;

use App\Http\Modules\Auth\DTOs\AuthResponseDto;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication service for admin login/logout.
 *
 * Handles:
 * - Credential validation (email + password)
 * - Session management (creation, regeneration)
 * - Password verification
 *
 * Implements Pattern 013: Admin Session Authentication
 */
final class AuthService
{
    /**
     * Authenticate admin user with email + password.
     *
     * Validates password (bcrypt comparison).
     * Does NOT create session; controller calls session_regenerate_id().
     *
     * @param string $email Admin email
     * @param string $password Plaintext password
     * @return AdminUser Authenticated user
     * @throws AuthenticationException Invalid credentials or inactive user
     */
    public function authenticate(string $email, string $password): AdminUser
    {
        $user = AdminUser::where('email', $email)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($password, $user->password)) {
            // Intentionally vague: don't reveal if email exists
            throw new AuthenticationException('Invalid email or password');
        }

        // Check if user is active
        if (!$user->isActive()) {
            throw new AuthenticationException('Account is inactive');
        }

        // Log successful authentication (optional)
        // AuditLogger::log('LOGIN', $user->id, 'Successful admin login');

        return $user;
    }

    /**
     * Verify if user session is still valid.
     *
     * Checks:
     * - User exists in database
     * - User is still active
     *
     * Called during request to ensure session user hasn't been disabled.
     *
     * @param int $userId User ID from session
     * @return AdminUser|null
     */
    public function getAuthenticatedUser(int $userId): ?AdminUser
    {
        return AdminUser::where('id', $userId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Change password for authenticated user.
     *
     * Validates old password before allowing change.
     *
     * @param AdminUser $user
     * @param string $oldPassword Current plaintext password
     * @param string $newPassword New plaintext password
     * @return void
     * @throws AuthenticationException Old password incorrect
     */
    public function changePassword(
        AdminUser $user,
        string $oldPassword,
        string $newPassword,
    ): void {
        if (!Hash::check($oldPassword, $user->password)) {
            throw new AuthenticationException('Current password is incorrect');
        }

        $user->update(['password' => $newPassword]);

        // Log password change
        // AuditLogger::log('PASSWORD_CHANGE', $user->id, 'Password changed');
    }
}
```

### Login/Logout Controller

```php
// app/Http/Modules/Auth/Controllers/AuthController.php
namespace App\Http\Modules\Auth\Controllers;

use App\Http\Modules\Auth\Requests\LoginRequest;
use App\Http\Modules\Auth\Services\AuthService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin authentication controller.
 *
 * Handles:
 * - POST /api/auth/login - Create session
 * - POST /api/auth/logout - Destroy session
 * - GET /api/auth/profile - Current user info
 *
 * Implements Pattern 013: Admin Session Authentication
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/auth/login - Login with email + password.
     *
     * Flow:
     * 1. Validate email + password (FormRequest)
     * 2. Find user and verify password (Service)
     * 3. Regenerate session ID (prevent fixation)
     * 4. Store user_id + role in $_SESSION
     * 5. Return user data + set secure cookie
     *
     * @param LoginRequest $request Email + password
     * @return JsonResponse User data (no password)
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            // 1. Authenticate user (service validates password)
            $user = $this->authService->authenticate(
                $request->email(),
                $request->password()
            );

            // 2. Regenerate session ID (prevent session fixation)
            session_regenerate_id(true);

            // 3. Store user info in session
            session(['user_id' => $user->id]);

            // 4. Return user data
            // Browser automatically receives Set-Cookie with session ID
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'message' => 'Logged in successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'authentication_failed',
                'message' => 'Invalid email or password',
            ], 401);
        }
    }

    /**
     * POST /api/auth/logout - Destroy session.
     *
     * Flow:
     * 1. Check if user is authenticated
     * 2. Destroy session data
     * 3. Delete session cookie
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $userId = session('user_id');

        if ($userId) {
            // Log logout
            // AuditLogger::log('LOGOUT', $userId, 'Logout');
        }

        // Destroy session
        session()->flush();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * GET /api/auth/profile - Get current authenticated user.
     *
     * Protected by: SessionAuthenticationMiddleware
     * (middleware ensures user_id exists in session)
     *
     * @param Request $request
     * @return JsonResponse User data
     */
    public function profile(Request $request): JsonResponse
    {
        $userId = session('user_id');

        // Re-verify user still exists and is active
        $user = $this->authService->getAuthenticatedUser($userId);

        if (!$user) {
            // User was deactivated; session is invalid
            session()->flush();
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'User no longer exists or is inactive',
            ], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
        ]);
    }

    /**
     * POST /api/auth/password - Change password.
     *
     * Protected by: SessionAuthenticationMiddleware
     *
     * @param ChangePasswordRequest $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $userId = session('user_id');
        $user = AdminUser::findOrFail($userId);

        try {
            $this->authService->changePassword(
                $user,
                $request->currentPassword(),
                $request->newPassword()
            );

            return response()->json(['message' => 'Password changed successfully']);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'password_change_failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
```

### Session Authentication Middleware

```php
// app/Http/Middleware/AuthenticateSession.php
namespace App\Http\Middleware;

use App\Http\Exceptions\UnauthorizedException;
use App\Http\Modules\Auth\Services\AuthService;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware for validating admin session authentication.
 *
 * Ensures:
 * - Session ID exists (from cookie)
 * - user_id stored in session
 * - User still exists in database
 * - User is still active
 *
 * Implements Pattern 013: Admin Session Authentication
 */
final class AuthenticateSession
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Validate session before allowing request to proceed.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws UnauthorizedException
     */
    public function handle(Request $request, Closure $next)
    {
        // 1. Check if user_id exists in session
        $userId = session('user_id');

        if (!$userId) {
            throw new UnauthorizedException(
                'Not authenticated',
                'unauthenticated'
            );
        }

        // 2. Verify user still exists and is active
        $user = $this->authService->getAuthenticatedUser($userId);

        if (!$user) {
            session()->flush();
            throw new UnauthorizedException(
                'User no longer exists or is inactive',
                'user_disabled'
            );
        }

        // 3. Attach user to request
        $request->setUser($user);

        return $next($request);
    }
}
```

### Request Macro for User Access

```php
// In service provider (AppServiceProvider.php)
\Illuminate\Http\Request::macro('user', function () {
    return $this->attributes['user'] ?? null;
});

\Illuminate\Http\Request::macro('setUser', function ($user) {
    $this->attributes['user'] = $user;
    return $this;
});

\Illuminate\Http\Request::macro('userId', function () {
    return session('user_id');
});
```

### Route Configuration with Middleware

```php
// app/Http/Modules/Auth/routes/admin.php
use App\Http\Middleware\AuthenticateSession;
use App\Http\Modules\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/**
 * Admin authentication routes.
 *
 * POST /api/auth/login - No auth required
 * POST /api/auth/logout - Session required
 * GET /api/auth/profile - Session required
 */

// Login/Logout (no auth required)
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (session required)
Route::middleware([AuthenticateSession::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/password', [AuthController::class, 'changePassword']);
});

// All other admin endpoints use this middleware
Route::middleware([AuthenticateSession::class])->group(function () {
    // Include all admin modules
    require 'modules/members.php';
    require 'modules/products.php';
    // ...etc
});
```

---

## Session Configuration

```php
// config/session.php
return [
    'driver' => env('SESSION_DRIVER', 'database'),  // Database storage
    'lifetime' => 120,  // 2 hours idle timeout (in minutes)
    'expire_on_close' => false,  // Don't expire on browser close
    'encrypt' => false,  // Session data stored plaintext (other values encrypted)
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('APP_ENV') === 'production',  // HTTPS only in production
    'http_only' => true,  // JavaScript cannot access (prevents XSS theft)
    'same_site' => 'Lax',  // Prevents CSRF in most cases

    // Database storage
    'table' => 'sessions',
    'connection' => null,
];
```

### Sessions Table Migration

```php
// database/migrations/create_sessions_table.php
Schema::create('sessions', function (Blueprint $table) {
    $table->string('id')->primary();
    $table->unsignedBigInteger('user_id')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->longText('payload');
    $table->integer('last_activity')->index();
});
```

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

```php
// config/session.php
'http_only' => true,
```

JavaScript cannot access session cookie (even if XSS vulnerability exists).

### 3. Secure Cookies (HTTPS Only)

```php
// config/session.php
'secure' => env('APP_ENV') === 'production',
```

Cookie only sent over HTTPS (not HTTP). Prevents MITM attacks.

### 4. SameSite Attribute (Prevent CSRF)

```php
// config/session.php
'same_site' => 'Lax',
```

Cookie not sent in cross-site requests. Prevents CSRF attacks.

### 5. Password Hashing (Prevent Database Breach Exposure)

```php
Hash::make($password, ['cost' => 12]);  // Bcrypt, cost 12+
```

Even if database stolen, passwords cannot be reversed.

### 6. Session Timeout (Limit Exposure)

```php
// config/session.php
'lifetime' => 120,  // 2 hours idle timeout
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
Cookie: session_id=...

// Backend:
1. Get user_id from session
2. Log the logout event
3. session()->flush()  // Delete session data
4. Return 200 OK
5. Browser deletes cookie (automatically after response)

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
public function test_authenticate_returns_user_with_valid_credentials()
{
    $user = AdminUser::factory()->create(['password' => 'test123456']);
    $authenticated = $this->authService->authenticate($user->email, 'test123456');
    $this->assertEquals($user->id, $authenticated->id);
}

public function test_authenticate_throws_with_invalid_password()
{
    AdminUser::factory()->create(['email' => 'test@test.com', 'password' => 'test123456']);
    $this->expectException(AuthenticationException::class);
    $this->authService->authenticate('test@test.com', 'wrongpassword');
}

public function test_authenticate_throws_with_inactive_user()
{
    AdminUser::factory()->create([
        'email' => 'test@test.com',
        'password' => 'test123456',
        'is_active' => false,
    ]);
    $this->expectException(AuthenticationException::class);
    $this->authService->authenticate('test@test.com', 'test123456');
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
- **Pattern 001**: Form Requests for Input Validation
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 015**: Authorization & Access Control
