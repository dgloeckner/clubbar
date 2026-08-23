# Pattern 012: Terminal API Token Authentication

**Status**: Active

**Related ADR**: ADR-0015 (Authentication and Authorization Strategy)

**Purpose**: Implement device-level Terminal authentication using Bearer tokens. Terminals authenticate as **devices**, not users.

---

## Context

The Club Bar system includes offline-capable Terminal devices (Flutter kiosk POS) that sync with the backend periodically. Each terminal must authenticate itself, but this is **device authentication**, not user authentication.

**Key Principles (ADR-0015)**:
1. **Separation of concerns**: Terminals authenticate as devices; members are identified by RFID (not authentication)
2. **Device-level tokens**: One API token per terminal device
3. **Expiring tokens**: A token lives `API_TOKEN_TTL_DAYS` (default 365, the shared credential cryptoperiod of ADR-0036) from issue; there is no automatic refresh, an admin rotates it (#106, #395)
4. **Overlap rotation**: a rotation stages the replacement *alongside* the token in the field; the first authentication with the new one promotes it and retires the old one (#395)
5. **Step-up on issuance**: enrolling a terminal and rotating its token both require a fresh step-up credential; revocation does not
6. **Revocable**: Admin can revoke token instantly; terminal gets 401 on next sync

---

## Pattern Definition

### Token Format & Generation

**Token Characteristics**:
- **Length**: 64-character hexadecimal string (256 bits entropy)
- **Generation**: Server-side using cryptographically secure random
- **Storage**:
  - Server: SHA-256 hash (never plaintext)
  - Terminal: Local config file (outside app bundle)
- **Lifetime**: `API_TOKEN_TTL_DAYS` from issue (default 365 days); rotated manually via admin panel
- **Scope**: One token per terminal device

#### Why SHA-256 and not bcrypt

Password hashes are deliberately slow because passwords are guessable: a human
picks them, so an attacker with the hash can try a dictionary. A terminal token
is not picked by anyone — it is 256 bits from `random_bytes()`, and no amount of
hardware makes that search feasible. Slow hashing buys nothing here and costs
something real: bcrypt cannot be looked up by value, so verifying a token meant
loading every terminal and comparing one at a time.

SHA-256 is used instead, which makes the lookup a single indexed read. Terminals
enrolled before this changed still carry bcrypt hashes; `verifyToken()` detects
the format and falls back, so both keep working.

### Token Generation Service

```php
// src/Modules/Auth/Services/TokenService.php
namespace App\Modules\Auth\Services;

/**
 * Service for generating and validating terminal API tokens.
 *
 * Implements ADR-0015: Device-level Terminal Authentication
 * - Generates cryptographically secure tokens (64-char hex, 256 bits)
 * - Stores tokens as SHA-256 hashes (irreversible, O(1) lookup)
 * - Used exclusively for device authentication (not user auth)
 */
class TokenService
{
    private const TOKEN_ENTROPY_BYTES = 32;

    /**
     * Generate a new terminal API token.
     *
     * **IMPORTANT**: Token is displayed to admin ONCE during pairing.
     * Server stores only SHA-256 hash. Lost tokens cannot be recovered;
     * admin must generate new token.
     *
     * @return string 64-character hex string (256 bits)
     */
    public static function generateTerminalToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_ENTROPY_BYTES));
    }

    /**
     * Hash token for storage in database.
     *
     * Uses SHA-256 for O(1) database lookup (indexed hash column).
     * Token entropy (256 bits) makes brute force infeasible.
     *
     * @param string $plainToken 64-char hex token
     * @return string SHA-256 hex hash (64 chars)
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Validate plaintext token against stored hash.
     *
     * Uses hash_equals (constant-time comparison) for SHA-256.
     * Falls back to password_verify for legacy bcrypt hashes.
     *
     * @param string $plainToken Token from Authorization header
     * @param string $storedHash Hash from database
     * @return bool
     */
    public static function verifyToken(string $plainToken, string $storedHash): bool
    {
        // New format: SHA256 hex (64 chars)
        if (strlen($storedHash) === 64 && !str_starts_with($storedHash, '$2y$')) {
            return hash_equals(hash('sha256', $plainToken), $storedHash);
        }
        // Legacy bcrypt (pre-migration terminals)
        return password_verify($plainToken, $storedHash);
    }
}
```

### Overlap rotation: what "the token" means during a rollout

Authentication is no longer a single lookup. A terminal can have two live
credentials at once — the one in the field and a replacement an admin staged —
so resolving a bearer token is:

1. `findByTokenHash()` — the active credential. The common case, and first so a
   terminal still using the old token pays for one query.
2. `findByPendingTokenHash()` — a staged replacement, held to exactly the same
   conditions (active terminal, expiry present, expiry in the future). Matching
   here **promotes**: the pending columns are copied over the active ones and
   cleared, in one `UPDATE` guarded on the hash so two syncs arriving together
   cannot both promote. The request that loses that race re-reads the active
   hash — which its rival has just filled in — and carries on.
3. `findExpiredByTokenHash()` — a diagnostic only, to answer
   `terminal_token_expired` rather than `invalid_terminal_token`.

That is three steps with a state change in the middle, which is more than a
PSR-15 middleware should own, so it lives in
`Terminals\Services\TerminalTokenAuthenticator` and the middleware is left with
the HTTP. The authenticator also writes `TERMINAL_TOKEN_ACTIVATED` on promotion
and `TERMINAL_TOKEN_EXPIRED` on an aged-out token — the latter **once per
expiry**, not once per attempt: a terminal keeps polling for as long as it stays
switched on, and an audit log that recorded each attempt would tell an admin
nothing they did not learn from the first line while hiding everything else.

### Middleware: Terminal Token Validation

```php
// src/Modules/Auth/Middleware/TerminalTokenAuth.php
namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Auth\Services\TokenService;
use Slim\Psr7\Response;

/**
 * PSR-15 Middleware for authenticating Terminal API requests via Bearer token.
 *
 * Validates:
 * - Authorization header present
 * - Format: "Bearer <token>"
 * - Token matches stored terminal hash (SHA-256, O(1) DB lookup)
 * - Terminal is not revoked
 *
 * Implements ADR-0015 Device-level Terminal Authentication
 */
class TerminalTokenAuth implements MiddlewareInterface
{
    public function __construct(private TerminalsRepository $terminalsRepository) {}

    /**
     * Authenticate terminal request (PSR-15).
     *
     * On success: Attaches terminal data to request attributes
     * On failure: Returns 401 JSON response
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. Extract Bearer token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->unauthorized('authorization_header_missing', 'Authorization header required');
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('invalid_authorization_format', 'Expected Bearer token');
        }

        $token = substr($authHeader, 7);

        // 2. Find terminal by token hash (O(1) SHA-256 lookup)
        $terminal = $this->findTerminalByToken($token);

        if (!$terminal) {
            // The repository refuses an expired token as firmly as an unknown
            // one; this second lookup only decides which 401 to answer with.
            if ($this->isExpiredToken($token)) {
                return $this->unauthorized(
                    'terminal_token_expired',
                    'Terminal token has expired. Rotate the token in the admin panel to issue a new one.'
                );
            }

            return $this->unauthorized('invalid_terminal_token', 'Invalid terminal token');
        }

        // 3. Check terminal is active
        if (!(bool) $terminal['is_active']) {
            return $this->unauthorized('terminal_inactive', 'Terminal is inactive');
        }

        // 4. Update last sync timestamp
        $this->terminalsRepository->updateLastSync($terminal['id']);

        // 5. Attach terminal data to request attributes
        $request = $request->withAttribute('terminal_id', $terminal['id']);
        $request = $request->withAttribute('terminal', $terminal);

        return $handler->handle($request);
    }

    /**
     * Find terminal by SHA-256 hash of token.
     *
     * Uses direct DB lookup (O(1)) instead of iterating all terminals.
     * SHA-256 hash is indexed in the database for fast lookups.
     *
     * @param string $plainToken 64-char hex token from request
     * @return array|null Terminal row as associative array
     */
    private function findTerminalByToken(string $plainToken): ?array
    {
        $sha256 = TokenService::hashToken($plainToken);
        return $this->terminalsRepository->findByTokenHash($sha256);
    }

    private function isExpiredToken(string $plainToken): bool
    {
        $sha256 = TokenService::hashToken($plainToken);
        return $this->terminalsRepository->findExpiredByTokenHash($sha256) !== null;
    }

    /**
     * Return 401 JSON error response.
     */
    private function unauthorized(string $code, string $message): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => $code, 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Accessing Terminal Data in Controllers

PSR-7 `ServerRequestInterface` uses `withAttribute()` / `getAttribute()` to pass data between middleware and controllers. No macros or service providers are needed.

```php
// Middleware attaches terminal data:
$request = $request->withAttribute('terminal_id', $terminal['id']);
$request = $request->withAttribute('terminal', $terminal);

// Controller reads terminal data:
$terminalId = $request->getAttribute('terminal_id');
$terminal = $request->getAttribute('terminal');
```

### Usage in Controller

```php
// src/Modules/Members/Controllers/SyncController.php
namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Terminal Sync API endpoints.
 *
 * All endpoints require terminal device authentication (Bearer token).
 * Pattern 012: Terminal API Token Authentication
 */
final class SyncController
{
    public function __construct(private readonly MembersService $service) {}

    /**
     * GET /api/sync/members - Delta sync members for terminal
     *
     * Protected by:
     * - Pattern 012: TerminalTokenAuth middleware
     * - Terminal must present valid Bearer token
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Access authenticated terminal from request attributes
        $terminalId = $request->getAttribute('terminal_id');

        $params = $request->getQueryParams();
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        $result = $this->service->syncSince($since);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Route Configuration with Middleware (Slim 4)

```php
// src/routes.php
use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Transactions\Controllers\SyncController as TransactionsSyncController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Terminal Sync API routes.
 *
 * Protected by:
 * - Pattern 012: TerminalTokenAuth middleware (PSR-15)
 * - SHA-256 token hash validation
 * - Active terminal status check
 */
$app->group('/api/sync', function (RouteCollectorProxy $group) {
    $group->get('/members', [MembersSyncController::class, 'index']);
    $group->patch('/members/{memberId}/language', [MembersSyncController::class, 'updateLanguage']);
    $group->post('/transactions', [TransactionsSyncController::class, 'processBatch']);
})->add(TerminalTokenAuth::class);
```

### Terminal Pairing Workflow (Admin Panel)

```php
// src/Modules/Terminals/Controllers/AdminController.php
namespace App\Modules\Terminals\Controllers;

use App\Modules\Terminals\Services\TerminalsService;
use App\Modules\Auth\Services\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Terminal management for admin panel.
 *
 * Includes token generation during terminal pairing.
 */
final class AdminController
{
    public function __construct(
        private readonly TerminalsService $terminalsService,
    ) {}

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
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        // 1. Generate cryptographically secure token
        $plainToken = TokenService::generateTerminalToken();

        // 2. Create terminal with hashed token via repository (PDO)
        $terminal = $this->terminalsService->create([
            'name' => $body['name'],
            'device_id' => $body['device_id'],
            'api_token_hash' => TokenService::hashToken($plainToken),
            'is_active' => true,
        ]);

        // 3. Return plaintext token (admin must save it)
        // This is the ONLY time the plaintext token is shown
        $response->getBody()->write(json_encode([
            'terminal' => [
                'id' => $terminal['id'],
                'name' => $terminal['name'],
                'device_id' => $terminal['device_id'],
                'created_at' => $terminal['created_at'],
            ],
            'api_token' => $plainToken,  // PLAINTEXT, shown once
            'message' => 'Save this token! It cannot be recovered. Paste it in terminal configuration.',
        ]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/admin/terminals/{terminalId}/rotate-token - Rotate terminal token
     *
     * Invalidates old token and generates new one.
     * Useful for: lost tokens, rotation policy, compromised token.
     */
    public function rotateToken(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $terminalId = $args['terminalId'];

        // 1. Generate new token
        $plainToken = TokenService::generateTerminalToken();

        // 2. Update hash via repository (old token becomes invalid)
        $this->terminalsService->updateById($terminalId, [
            'api_token_hash' => TokenService::hashToken($plainToken),
        ]);

        $terminal = $this->terminalsService->findById($terminalId);

        // 3. Return new plaintext token
        $response->getBody()->write(json_encode([
            'api_token' => $plainToken,
            'message' => 'Token rotated. Old token is now invalid.',
            'terminal' => [
                'id' => $terminal['id'],
                'name' => $terminal['name'],
            ],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/admin/terminals/{terminalId}/revoke - Revoke terminal access
     *
     * Invalidates token without generating replacement.
     * Used when decommissioning a terminal.
     *
     * Terminal will get 401 on next sync attempt.
     */
    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $terminalId = $args['terminalId'];

        // Invalidate token (set to null, deactivate)
        $this->terminalsService->updateById($terminalId, [
            'api_token_hash' => null,
            'is_active' => false,
        ]);

        $response->getBody()->write(json_encode([
            'status' => 'revoked',
            'terminal_id' => $terminalId,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
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
   - Hashed with SHA-256 (irreversible)

3. **No plaintext storage** ✓
   - Server stores only the SHA-256 hash
   - Lost tokens cannot be recovered
   - Hashing prevents admin from reading token

4. **Revocable access** ✓
   - Admin can instantly revoke token
   - Terminal gets 401 on next request
   - No delay or async operation needed

5. **Constant-time comparison** ✓
   - `hash_equals()` compares the SHA-256 digests at constant time; the legacy
     bcrypt path uses `password_verify()`, which is constant-time too
   - No timing leaks about token validity
   - Safe against timing attacks

6. **Bounded token lifetime** ✓ (#106)
   - `token_expires_at` is set when a token is issued and enforced by
     `TerminalsRepository::findByTokenHash()`, not by the middleware, so no
     caller can authenticate a terminal without the check
   - Fail-closed: a row with a token hash and no expiry does not authenticate
   - A token lifted from a decommissioned or stolen device stops working on its
     own, without waiting for an admin to notice that one terminal

### What This Pattern Does NOT Provide

1. **Token refresh** ✗
   - A token has a bounded lifetime but no renewal mechanism
   - Rotation is manual via admin panel; an expired terminal stays locked out
     until an admin rotates it
   - No automatic refresh
   - What overlap rotation buys is not refresh but *preparation*: an admin can
     issue the replacement from a desk without taking the bar offline, and the
     cut-over happens when somebody types it in (#395)

2. **Token versioning** ✗
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
    api_token_hash VARCHAR(255) NULLABLE COMMENT 'SHA-256 hash of API token (never plaintext)',
    token_issued_at DATETIME NULLABLE COMMENT 'When the current token was issued',
    token_expires_at DATETIME NULLABLE COMMENT 'After this instant the token no longer authenticates (#106)',
    pending_token_hash VARCHAR(255) NULLABLE COMMENT 'Replacement token; promoted on first successful auth (#395)',
    pending_token_issued_at DATETIME NULLABLE COMMENT 'When the replacement was minted',
    pending_token_expires_at DATETIME NULLABLE COMMENT 'Lifetime the replacement carries into its promotion',
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
- No way to securely store secrets in an unattended kiosk app
- Loss of terminal = loss of token (terminal must be re-paired)

---

## Error Responses

The `error` field carries the specific code, not a generic `unauthorized` — a
terminal that aged out is a different operational problem from one that was
never enrolled, and the operator needs to be told which.

```php
// 401 Unauthorized - Unknown or revoked token
{
    "error": "invalid_terminal_token",
    "message": "Invalid terminal token"
}

// 401 Unauthorized - Token past its lifetime (#106). Distinct from an unknown
// token so a terminal that simply aged out is not mistaken for a misconfigured
// one; an admin rotates it to issue a new one.
{
    "error": "terminal_token_expired",
    "message": "Terminal token has expired. Rotate the token in the admin panel to issue a new one."
}

// 401 Unauthorized - Inactive Terminal
{
    "error": "terminal_inactive",
    "message": "Terminal is inactive"
}

// 401 Unauthorized - Missing Authorization Header
{
    "error": "authorization_header_missing",
    "message": "Authorization header required"
}

// 401 Unauthorized - Header present but not a Bearer token
{
    "error": "invalid_authorization_format",
    "message": "Expected Bearer token"
}
```

---

## Consequences

### Positive

- **Simple device authentication**: No complex flow; just Bearer token
- **Revocable access**: Admin can instantly deauthorize terminal
- **Unattended terminals**: No operator login needed
- **Secure token generation**: 256-bit entropy, SHA-256 hashed
- **Clear trust model**: Device-level, not user-level auth

### Negative

- **Manual token rotation**: No automatic refresh; admin must manage
- **Token loss is permanent**: No way to recover lost tokens; must rotate
- **Expiry is a scheduled outage**: a token that ages out takes its terminal
  offline until an admin rotates it. Mitigated but not removed by #395 — the
  Security & Credentials page warns on the shared 90/30/7 tiers and the terminal
  itself says "sales disabled, an administrator must rotate the credential"
  instead of failing silently, but an admin who reads neither still ends up with
  a bar that cannot sell
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
