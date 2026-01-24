# Pattern 012: Terminal API Token Authentication

**Status**: Active

**Related ADR**: ADR-0015 (Authentication and Authorization Strategy)

**Purpose**: Implement device-level Terminal authentication using Bearer tokens. Terminals authenticate as **devices**, not users.

---

## Context

The Ruderbar system includes offline-capable Terminal devices (Electron POS) that sync with the backend periodically. Each terminal must authenticate itself, but this is **device authentication**, not user authentication.

**Key Principles (ADR-0015)**:
1. **Separation of concerns**: Terminals authenticate as devices; members are identified by RFID (not authentication)
2. **Device-level tokens**: One API token per terminal device
3. **Long-lived tokens**: No automatic refresh; manual rotation by admin
4. **Revocable**: Admin can revoke token instantly; terminal gets 401 on next sync

---

## Pattern Definition

### Token Format & Generation

**Token Characteristics**:
- **Length**: 64-character hexadecimal string (256 bits entropy)
- **Generation**: Server-side using cryptographically secure random
- **Storage**:
  - Server: bcrypt hash (never plaintext)
  - Terminal: Local config file (outside app bundle)
- **Lifetime**: Long-lived; rotated manually via admin panel
- **Scope**: One token per terminal device

### Token Generation Service

```php
// app/Shared/Services/TokenService.php
namespace App\Shared\Services;

use Exception;

/**
 * Service for generating and validating terminal API tokens.
 *
 * Implements ADR-0015: Device-level Terminal Authentication
 * - Generates cryptographically secure tokens (64-char hex, 256 bits)
 * - Stores tokens as bcrypt hashes (irreversible)
 * - Used exclusively for device authentication (not user auth)
 */
final class TokenService
{
    /**
     * Generate a new terminal API token.
     *
     * **IMPORTANT**: Token is displayed to admin ONCE during pairing.
     * Server stores only bcrypt hash. Lost tokens cannot be recovered;
     * admin must generate new token.
     *
     * @return string 64-character hex string (256 bits)
     * @throws Exception
     */
    public static function generateTerminalToken(): string
    {
        return bin2hex(random_bytes(32));  // 32 bytes = 256 bits = 64 hex chars
    }

    /**
     * Hash token for storage in database.
     *
     * Uses bcrypt with cost factor 12 (higher cost than passwords
     * since tokens are long and random, brute force infeasible).
     *
     * @param string $plainToken 64-char hex token
     * @return string bcrypt hash
     */
    public static function hashToken(string $plainToken): string
    {
        return password_hash($plainToken, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Validate plaintext token against stored hash.
     *
     * Uses password_verify (constant-time comparison).
     *
     * @param string $plainToken Token from Authorization header
     * @param string $storedHash Hash from database
     * @return bool
     */
    public static function verifyToken(string $plainToken, string $storedHash): bool
    {
        return password_verify($plainToken, $storedHash);
    }
}
```

### Middleware: Terminal Token Validation

```php
// app/Http/Middleware/AuthenticateTerminalToken.php
namespace App\Http\Middleware;

use App\Http\Exceptions\UnauthorizedException;
use App\Models\Terminal;
use App\Shared\Services\TokenService;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware for authenticating Terminal API requests via Bearer token.
 *
 * Validates:
 * - Authorization header present
 * - Format: "Bearer <token>"
 * - Token matches stored terminal hash
 * - Terminal is not revoked
 *
 * Implements ADR-0015 Device-level Terminal Authentication
 */
final class AuthenticateTerminalToken
{
    /**
     * Authenticate terminal request.
     *
     * On success: Attaches terminal to request for use in controllers
     * On failure: Throws UnauthorizedException (401)
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws UnauthorizedException
     */
    public function handle(Request $request, Closure $next)
    {
        // 1. Extract Bearer token from Authorization header
        $token = $this->extractToken($request);

        if (!$token) {
            throw new UnauthorizedException(
                'Missing Authorization header',
                'authorization_header_missing'
            );
        }

        // 2. Find terminal by token hash
        $terminal = $this->findTerminalByToken($token);

        if (!$terminal) {
            throw new UnauthorizedException(
                'Invalid or revoked terminal token',
                'invalid_terminal_token'
            );
        }

        // 3. Check terminal is active
        if (!$terminal->is_active) {
            throw new UnauthorizedException(
                'Terminal is inactive',
                'terminal_inactive'
            );
        }

        // 4. Attach terminal to request for controller use
        $request->setTerminal($terminal);

        return $next($request);
    }

    /**
     * Extract Bearer token from Authorization header.
     *
     * Format: "Authorization: Bearer <token>"
     *
     * @param Request $request
     * @return string|null Token (no "Bearer " prefix) or null
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);  // Remove "Bearer " prefix
    }

    /**
     * Find terminal by matching token against all terminal hashes.
     *
     * Linear search is safe because:
     * - Small number of terminals per deployment
     * - password_verify uses constant-time comparison
     * - No timing leak about token validity
     *
     * @param string $plainToken 64-char hex token from request
     * @return Terminal|null
     */
    private function findTerminalByToken(string $plainToken): ?Terminal
    {
        $terminals = Terminal::all();  // Small set in practice

        foreach ($terminals as $terminal) {
            if (TokenService::verifyToken($plainToken, $terminal->api_token_hash)) {
                return $terminal;
            }
        }

        return null;
    }
}
```

### Request Macro for Terminal Access

```php
// In service provider (e.g., AppServiceProvider.php)
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Allow controllers to access authenticated terminal
        \Illuminate\Http\Request::macro('terminal', function () {
            return $this->attributes['terminal'] ?? null;
        });

        \Illuminate\Http\Request::macro('setTerminal', function ($terminal) {
            $this->attributes['terminal'] = $terminal;
            return $this;
        });
    }
}
```

### Usage in Controller

```php
// app/Http/Modules/Members/Controllers/SyncController.php
namespace App\Http\Modules\Members\Controllers;

use App\Http\Modules\Members\Requests\SyncRequest;
use App\Http\Modules\Members\Services\MembersService;
use Illuminate\Http\JsonResponse;

/**
 * Terminal Sync API endpoints.
 *
 * All endpoints require terminal device authentication (Bearer token).
 * Pattern 012: Terminal API Token Authentication
 */
final class SyncController extends Controller
{
    public function __construct(private readonly MembersService $service) {}

    /**
     * GET /api/sync/members - Delta sync members for terminal
     *
     * Protected by:
     * - Pattern 012: AuthenticateTerminalToken middleware
     * - Terminal must present valid Bearer token
     *
     * @param SyncRequest $request
     * @return JsonResponse
     */
    public function index(SyncRequest $request): JsonResponse
    {
        // Access authenticated terminal from request
        $terminal = $request->terminal();

        // Log sync operation (optional, for audit trail)
        // AuditLogger::log('TERMINAL_SYNC', $terminal->id, 'GET /api/sync/members');

        $result = $this->service->syncSince($request->since());
        return response()->json($result->toResponse('members'));
    }
}
```

### Route Configuration with Middleware

```php
// app/Http/Modules/Members/routes/terminal.php
use App\Http\Middleware\AuthenticateTerminalToken;
use App\Http\Modules\Members\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/**
 * Terminal Sync API routes.
 *
 * Protected by:
 * - Pattern 012: Terminal Bearer token authentication
 * - Terminal::api_token_hash validation
 * - Active terminal status check
 */
Route::prefix('sync')
    ->middleware([AuthenticateTerminalToken::class])
    ->group(function () {
        Route::get('/members', [SyncController::class, 'index']);
        Route::patch('/members/{memberId}/language', [SyncController::class, 'updateLanguage']);
        Route::post('/transactions', [SyncController::class, 'transactions']);
    });
```

### Terminal Pairing Workflow (Admin Panel)

```php
// app/Http/Modules/Terminals/Controllers/AdminController.php
namespace App\Http\Modules\Terminals\Controllers;

use App\Models\Terminal;
use App\Shared\Services\TokenService;
use Illuminate\Http\JsonResponse;

/**
 * Terminal management for admin panel.
 *
 * Includes token generation during terminal pairing.
 */
final class TerminalsAdminController extends Controller
{
    /**
     * POST /api/admin/terminals - Create new terminal and generate API token
     *
     * Returns:
     * - Terminal details
     * - API token (plaintext, displayed ONCE to admin)
     * - Instructions for terminal configuration
     *
     * IMPORTANT: Token is NOT stored again. Admin must save it.
     * Lost tokens require token rotation.
     *
     * @return JsonResponse
     */
    public function create(CreateTerminalRequest $request): JsonResponse
    {
        // 1. Generate cryptographically secure token
        $plainToken = TokenService::generateTerminalToken();

        // 2. Store hash in database (token itself never stored)
        $terminal = Terminal::create([
            'name' => $request->name(),
            'device_id' => $request->deviceId(),
            'api_token_hash' => TokenService::hashToken($plainToken),
            'is_active' => true,
        ]);

        // 3. Return plaintext token (admin must save it)
        // This is the ONLY time the plaintext token is shown
        return response()->json([
            'terminal' => [
                'id' => $terminal->id,
                'name' => $terminal->name,
                'device_id' => $terminal->device_id,
                'created_at' => $terminal->created_at->toIso8601String(),
            ],
            'api_token' => $plainToken,  // ← PLAINTEXT, shown once
            'message' => 'Save this token! It cannot be recovered. Paste it in terminal configuration.',
        ], 201);
    }

    /**
     * POST /api/admin/terminals/{id}/rotate-token - Rotate terminal token
     *
     * Invalidates old token and generates new one.
     * Useful for:
     * - Lost tokens
     * - Token rotation policy
     * - Compromised token
     *
     * @param string $terminalId
     * @return JsonResponse
     */
    public function rotateToken(string $terminalId): JsonResponse
    {
        $terminal = Terminal::findOrFail($terminalId);

        // 1. Generate new token
        $plainToken = TokenService::generateTerminalToken();

        // 2. Update hash (old token becomes invalid)
        $terminal->update([
            'api_token_hash' => TokenService::hashToken($plainToken),
        ]);

        // 3. Return new plaintext token
        return response()->json([
            'api_token' => $plainToken,
            'message' => 'Token rotated. Old token is now invalid.',
            'terminal' => [
                'id' => $terminal->id,
                'name' => $terminal->name,
            ],
        ]);
    }

    /**
     * POST /api/admin/terminals/{id}/revoke - Revoke terminal access
     *
     * Invalidates token without generating replacement.
     * Used when decommissioning a terminal.
     *
     * Terminal will get 401 on next sync attempt.
     *
     * @param string $terminalId
     * @return JsonResponse
     */
    public function revoke(string $terminalId): JsonResponse
    {
        $terminal = Terminal::findOrFail($terminalId);

        // Invalidate token (set to impossible value)
        $terminal->update([
            'api_token_hash' => null,
            'is_active' => false,
        ]);

        return response()->json([
            'status' => 'revoked',
            'terminal_id' => $terminal->id,
        ]);
    }
}
```

---

## Key Security Properties

### What This Pattern Provides

1. **Device-level authentication** ✓
   - Terminals authenticate via Bearer token, not user credentials
   - Each terminal has one token

2. **Cryptographically secure tokens** ✓
   - 256 bits entropy (64 hex chars)
   - Generated server-side using `random_bytes()`
   - Hashed with bcrypt (irreversible)

3. **No plaintext storage** ✓
   - Server stores only bcrypt hash
   - Lost tokens cannot be recovered
   - Hashing prevents admin from reading token

4. **Revocable access** ✓
   - Admin can instantly revoke token
   - Terminal gets 401 on next request
   - No delay or async operation needed

5. **Constant-time comparison** ✓
   - `password_verify()` compares at constant time
   - No timing leaks about token validity
   - Safe against timing attacks

### What This Pattern Does NOT Provide

1. **Token refresh** ✗
   - Tokens are long-lived
   - Rotation is manual via admin panel
   - No automatic refresh mechanism

2. **Token expiration** ✗
   - Tokens don't expire automatically
   - Admin must rotate periodically (policy decision)
   - Use terminal last-sync timestamp to detect stale terminals

3. **Token versioning** ✗
   - No concept of "revisions" or "generations"
   - Rotation creates new token; old one is invalid

---

## Database Schema

```sql
-- Terminals table
CREATE TABLE terminals (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID',
    name VARCHAR(255) NOT NULL COMMENT 'Terminal display name',
    device_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Device identifier (MAC address, UUID, etc.)',

    -- Authentication
    api_token_hash VARCHAR(255) NULLABLE COMMENT 'Bcrypt hash of API token (never plaintext)',
    last_sync_at TIMESTAMP NULLABLE COMMENT 'Last successful sync request timestamp',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'False = token revoked, terminal cannot sync',

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
);
```

---

## Terminal Configuration

Terminal application stores token in local config file (outside app bundle):

```json
{
  "backend_url": "https://bar.example.com",
  "terminal_id": "12345678-1234-1234-1234-123456789012",
  "api_token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f"
}
```

**Important**: Token stored in plaintext on local terminal because:
- Terminal is isolated device in trusted environment
- No way to securely store secrets in Electron app
- Loss of terminal = loss of token (terminal must be re-paired)

---

## Error Responses

```php
// 401 Unauthorized - Missing/Invalid Token
{
    "error": "unauthorized",
    "message": "Invalid or revoked terminal token"
}

// 401 Unauthorized - Inactive Terminal
{
    "error": "unauthorized",
    "message": "Terminal is inactive"
}

// 401 Unauthorized - Missing Authorization Header
{
    "error": "unauthorized",
    "message": "Missing Authorization header"
}
```

---

## Consequences

### Positive

- **Simple device authentication**: No complex flow; just Bearer token
- **Revocable access**: Admin can instantly deauthorize terminal
- **Unattended terminals**: No operator login needed
- **Secure token generation**: 256-bit entropy, bcrypt hashed
- **Clear trust model**: Device-level, not user-level auth

### Negative

- **Manual token rotation**: No automatic refresh; admin must manage
- **Token loss is permanent**: No way to recover lost tokens; must rotate
- **No built-in expiration**: Admin responsible for rotation policy
- **All terminals equal**: No differentiation of capabilities (all-or-nothing)

### Mitigations

1. **Document token rotation policy** in admin guide
2. **Provide UI for easy token rotation** in admin panel
3. **Track last-sync timestamp** to identify stale terminals
4. **Log all token rotations/revocations** for audit trail
5. **Provide fallback for lost tokens** (admin rotation)

---

## Integration with ADR-0015

This pattern implements:
- ✅ **Principle 2**: Device-level Terminal Authentication
- ✅ Bearer token authentication (not user authentication)
- ✅ Long-lived tokens with manual rotation
- ✅ Revocable access
- ✅ Clear separation from member identification (RFID)

Complements:
- **Pattern 014**: RFID Member Identification (not authentication)
- **Pattern 013**: Admin Session Authentication (different mechanism)
- **ADR-0015**: Full authentication strategy
- **ADR-0016**: Transport Security (tokens over HTTPS)
- **ADR-0017**: Input Validation and Injection Prevention

---

## Testing

### Unit Tests

```php
// tests/Unit/Services/TokenServiceTest.php
public function test_generateTerminalToken_produces_64_char_hex_string()
{
    $token = TokenService::generateTerminalToken();
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
}

public function test_verifyToken_returns_true_for_valid_hash()
{
    $token = TokenService::generateTerminalToken();
    $hash = TokenService::hashToken($token);
    $this->assertTrue(TokenService::verifyToken($token, $hash));
}

public function test_verifyToken_returns_false_for_invalid_token()
{
    $hash = TokenService::hashToken(TokenService::generateTerminalToken());
    $this->assertFalse(TokenService::verifyToken('invalid', $hash));
}
```

### Integration Tests (Playwright)

```typescript
// tests/api/terminal-authentication.spec.ts
test('GET /api/sync/members without token returns 401', async () => {
    const response = await fetch('http://localhost:8080/api/sync/members');
    expect(response.status).toBe(401);
});

test('GET /api/sync/members with invalid token returns 401', async () => {
    const response = await fetch('http://localhost:8080/api/sync/members', {
        headers: {
            'Authorization': 'Bearer invalid_token_here'
        }
    });
    expect(response.status).toBe(401);
});

test('GET /api/sync/members with valid token returns 200', async () => {
    const response = await fetch('http://localhost:8080/api/sync/members', {
        headers: {
            'Authorization': 'Bearer ' + VALID_TERMINAL_TOKEN
        }
    });
    expect(response.status).toBe(200);
});
```

---

## See Also

- **ADR-0015**: Authentication and Authorization Strategy
- **ADR-0016**: Transport Security (HTTPS/TLS)
- **ADR-0017**: Input Validation and Injection Prevention
- **Pattern 013**: Admin Session Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 015**: Authorization & Access Control
