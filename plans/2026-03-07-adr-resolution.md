# ADR Resolution Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Resolve all gaps identified in the ADR implementation review — update outdated ADRs, add missing security features, update deployment docs, and clean up obsolete use cases.

**Architecture:** ADR updates are text-only changes. CSRF middleware and rate limiting are new PHP classes wired into Slim's middleware stack. Deployment docs get GZIP and TLS sections. Frontend needs a small change to send CSRF tokens. E2E tests must be updated to handle CSRF tokens.

**Tech Stack:** PHP 8.3 / Slim 4 (backend), React/axios (admin frontend), Playwright (E2E tests), Markdown (ADRs/docs)

---

## Task 1: Update ADR-0014 (RFID → Flutter/Keyboard Emulation)

**Files:**
- Modify: `adr/0014-rfid-scanning-integration.md`

**Step 1: Rewrite ADR-0014**

Replace the Decision section and architecture to reflect Flutter keyboard emulation as the primary approach. Key changes:
- **Decision statement**: Terminal uses keyboard emulation mode (HID keyboard) as primary RFID input in Flutter app. No node-hid, no Electron.
- **Architecture diagram**: Flutter app → `RfidService` → keyboard event listener → member lookup from Drift SQLite cache
- **Remove**: All Electron/IPC/node-hid/contextBridge references
- **Remove**: HID raw mode as primary, keyboard as fallback — flip it
- **Keep**: Card UID handling specs (hex format, lengths, comparison rules)
- **Keep**: Error handling table (unknown card, inactive member, debouncing)
- **Keep**: Security considerations (RFID is identification not auth)
- **Keep**: Supported reader types table
- **Update Alternatives**: Move Electron/node-hid to "Alternatives Considered" as rejected
- **Add**: "Technology Change Note" explaining original Electron decision was revised when terminal was built in Flutter

**Step 2: Commit**

```bash
git add adr/0014-rfid-scanning-integration.md
git commit -m "docs: update ADR-0014 to reflect Flutter keyboard emulation approach"
```

---

## Task 2: Simplify ADR-0021 (RFID Card Assignment → Manual Entry Only)

**Files:**
- Modify: `adr/0021-rfid-card-assignment-workflow.md`

**Step 1: Rewrite ADR-0021**

Simplify to document manual UID entry as the only card assignment method. Key changes:
- **Decision statement**: RFID card assignment uses manual UID entry in the admin panel. Admin types or pastes the card UID into the member edit form.
- **Remove**: Unknown cards workflow (POST /api/unknown-cards, GET /api/unknown-cards, DELETE /api/unknown-cards/{uid})
- **Remove**: `unknown_card_scans` table references
- **Remove**: Immediate upload, terminal-as-scanner workflow
- **Remove**: Two-step onboarding flow (scan at terminal → select in admin)
- **Keep**: Card UID validation rules (8-20 hex chars, uppercase)
- **Keep**: Admin UI card_uid field description
- **Simplify onboarding**: Admin enters UID from card label or reads it from reader software
- **Move unknown-card workflow to Alternatives Considered** as "rejected — over-engineered for small clubs where cards are labeled with UIDs"
- **Update Consequences**: Simpler implementation, no extra API endpoints, but manual entry is error-prone for long UIDs

**Step 2: Commit**

```bash
git add adr/0021-rfid-card-assignment-workflow.md
git commit -m "docs: simplify ADR-0021 to manual UID entry only"
```

---

## Task 3: Accept ADR-0023 and ADR-0024

**Files:**
- Modify: `adr/0023-terminal-balance-state-management.md`
- Modify: `adr/0024-transaction-history-retrieval-terminal.md`

**Step 1: Change status in both files**

In each file, change:
```
**Status**: Pending Review
```
to:
```
**Status**: Accepted
```

**Step 2: Commit**

```bash
git add adr/0023-terminal-balance-state-management.md adr/0024-transaction-history-retrieval-terminal.md
git commit -m "docs: accept ADR-0023 and ADR-0024 (implemented and validated)"
```

---

## Task 4: Add GZIP and TLS Sections to Deployment Docs

**Files:**
- Modify: `docs/deployment.md`

**Step 1: Add GZIP section**

Insert after the "Application Security" subsection (after line 50), a new subsection:

```markdown
### HTTP Compression (GZIP)

Enable GZIP compression to reduce API response sizes by 70-85%. See [ADR-0003](../adr/0003-gzip-compression-http.md) for architectural rationale.

**Apache** (add to `.htaccess` or virtual host config):

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json text/html text/plain text/css application/javascript
</IfModule>
```

**Nginx** (add to server block):

```nginx
gzip on;
gzip_types application/json text/html text/plain text/css application/javascript;
gzip_min_length 256;
```

**Verify:**

```bash
curl -s -H "Accept-Encoding: gzip" -o /dev/null -w "%{size_download}" https://your-domain.com/api/health
# Compare with uncompressed:
curl -s -o /dev/null -w "%{size_download}" https://your-domain.com/api/health
```
```

**Step 2: Expand the HTTPS section**

Replace the current minimal HTTPS section (lines 43-45) with:

```markdown
### HTTPS & TLS

HTTPS is mandatory in production. See [ADR-0016](../adr/0016-transport-security.md) for full security requirements.

**Setup:**
1. Enable SSL in your hosting panel (most providers offer free Let's Encrypt certificates)
2. Force HTTPS redirect (add to `.htaccess`):
   ```apache
   RewriteEngine On
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```
3. Verify secure cookies work after enabling HTTPS

**Security headers** (add to `.htaccess` or virtual host):

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
```

**Verify:**

```bash
curl -I https://your-domain.com/api/health
# Check for: Strict-Transport-Security, X-Content-Type-Options headers
```
```

**Step 3: Commit**

```bash
git add docs/deployment.md
git commit -m "docs: add GZIP and TLS configuration to deployment guide"
```

---

## Task 5: Implement CSRF Middleware

**Files:**
- Create: `backend/src/Shared/Middleware/CsrfMiddleware.php`
- Modify: `backend/src/ServiceFactory.php`
- Modify: `backend/bootstrap.php`
- Modify: `backend/src/Modules/Auth/Controllers/AuthController.php`

**Step 1: Write the CSRF middleware E2E test**

Create a test that verifies:
- POST/PATCH/DELETE to `/api/admin/*` without CSRF token returns 403
- POST/PATCH/DELETE with valid CSRF token succeeds (existing tests implicitly cover success)
- GET requests work without CSRF token
- Terminal API (Bearer token) is not affected by CSRF
- Login endpoint is exempt (no session yet)

Test file: `e2etests/tests/api/csrf-protection.spec.ts`

```typescript
import { test, expect } from '../../fixtures/auth.fixture';

test.describe('CSRF Protection', () => {
  test('POST to admin endpoint without CSRF token returns 403', async ({ authenticatedRequest }) => {
    // First, get a valid session by making a GET request
    const profile = await authenticatedRequest.get('/api/auth/profile');
    expect(profile.ok()).toBeTruthy();

    // Now try POST without CSRF token header
    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: 'CSRFTest' }, icon_name: 'generic' },
      headers: { 'X-CSRF-Token': '' },
    });
    expect(response.status()).toBe(403);
  });

  test('GET requests work without CSRF token', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    expect(response.ok()).toBeTruthy();
  });

  test('Terminal API is not affected by CSRF', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/members');
    expect(response.ok()).toBeTruthy();
  });

  test('Login endpoint is exempt from CSRF', async ({ request }) => {
    const response = await request.post('/api/auth/login', {
      data: { email: 'admin@example.com', password: 'password123' },
    });
    // Should be 200 (success) or 401 (wrong creds), NOT 403
    expect([200, 401]).toContain(response.status());
  });
});
```

**Step 2: Run the CSRF test to see it fail**

```bash
cd e2etests && npm test -- --grep "CSRF" --workers=1
```

Expected: Tests fail because CSRF middleware doesn't exist yet.

**Step 3: Create CsrfMiddleware.php**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $method = $request->getMethod();

        // Safe methods don't need CSRF protection
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        // Check for CSRF token in header
        $token = $request->getHeaderLine('X-CSRF-Token');
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (empty($sessionToken) || empty($token) || !hash_equals($sessionToken, $token)) {
            $response = new SlimResponse(403);
            $response->getBody()->write(json_encode([
                'error' => 'csrf_validation_failed',
                'message' => 'CSRF token missing or invalid',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
```

**Step 4: Generate CSRF token on login and return it**

In `AuthController.php`, after session is started and `admin_user_id` is set (around line 55), add CSRF token generation:

```php
// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
```

And include it in the login response:

```php
return $this->json($response, [
    'message' => 'Login successful',
    'admin' => $adminData,
    'csrf_token' => $_SESSION['csrf_token'],
]);
```

Also add a `GET /api/auth/csrf-token` endpoint or include the token in the profile response so the frontend can retrieve it after page reload. The simplest approach: include `csrf_token` in the profile response too:

In the `profile()` method, add:
```php
$data['csrf_token'] = $_SESSION['csrf_token'] ?? null;
```

**Step 5: Wire CsrfMiddleware into the admin route group**

In `routes.php`, add `CsrfMiddleware` to the admin group (line 122):

```php
})->add(CsrfMiddleware::class)->add(AdminSessionAuth::class);
```

Also add the import at the top:
```php
use App\Shared\Middleware\CsrfMiddleware;
```

And add to the `/api/auth` group (line 36):
```php
})->add(CsrfMiddleware::class)->add(AdminSessionAuth::class);
```

**Step 6: Register CsrfMiddleware in ServiceFactory**

Add to FQCN_MAP:
```php
CsrfMiddleware::class => 'getCsrfMiddleware',
```

Add getter method:
```php
public function getCsrfMiddleware(): CsrfMiddleware
{
    return $this->resolve(CsrfMiddleware::class, fn() => new CsrfMiddleware());
}
```

**Step 7: Update admin frontend to send CSRF token**

In `admin-frontend/src/services/api.ts`, store the CSRF token from login/profile responses and include it in all non-GET requests via axios interceptor:

```typescript
// After login or profile fetch, store token
let csrfToken: string | null = null;

export function setCsrfToken(token: string) {
  csrfToken = token;
}

// Add request interceptor
api.interceptors.request.use((config) => {
  if (csrfToken && config.method && !['get', 'head', 'options'].includes(config.method)) {
    config.headers['X-CSRF-Token'] = csrfToken;
  }
  return config;
});
```

Update `auth.ts` login and profile functions to call `setCsrfToken()` with the token from the response.

**Step 8: Update E2E test auth fixture**

The auth fixture (`e2etests/fixtures/auth.fixture.ts`) creates `authenticatedRequest` which uses stored session cookies. It needs to also retrieve and send the CSRF token. After login, fetch the profile to get the CSRF token, then add it as a default header:

```typescript
// In the authenticatedRequest fixture setup, after login:
const profileResponse = await context.get(`${BASE_URL}/api/auth/profile`);
const profileData = await profileResponse.json();
const csrfToken = profileData.csrf_token;

// Create request context that includes CSRF token header
// Use extraHTTPHeaders in the context or add to each request
```

**Step 9: Restart PHP and run CSRF tests**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
cd e2etests && npm test -- --grep "CSRF" --workers=1
```

Expected: All CSRF tests pass.

**Step 10: Run full E2E test suite**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All existing tests still pass (they now send CSRF tokens).

**Step 11: Commit**

```bash
git add backend/src/Shared/Middleware/CsrfMiddleware.php \
  backend/src/ServiceFactory.php \
  backend/bootstrap.php \
  backend/src/Modules/Auth/Controllers/AuthController.php \
  backend/src/routes.php \
  admin-frontend/src/services/api.ts \
  admin-frontend/src/services/auth.ts \
  e2etests/tests/api/csrf-protection.spec.ts \
  e2etests/fixtures/auth.fixture.ts
git commit -m "feat: add CSRF token protection for admin API (ADR-0017)"
```

---

## Task 6: Implement Login Rate Limiting

**Files:**
- Create: `backend/src/Shared/Middleware/RateLimitMiddleware.php`
- Create: `backend/db/migrations/002_login_attempts.sql`
- Modify: `backend/src/Modules/Auth/Controllers/AuthController.php`
- Modify: `backend/src/ServiceFactory.php`
- Modify: `backend/src/routes.php`

**Step 1: Write the rate limiting E2E test**

Test file: `e2etests/tests/api/rate-limiting.spec.ts`

```typescript
import { test, expect } from '@playwright/test';

const BASE_URL = 'http://localhost:8080';

test.describe('Login Rate Limiting', () => {
  test('blocks login after 5 failed attempts from same IP', async ({ request }) => {
    // Make 5 failed login attempts
    for (let i = 0; i < 5; i++) {
      const response = await request.post(`${BASE_URL}/api/auth/login`, {
        data: { email: `ratelimit-${Date.now()}@test.com`, password: 'wrong' },
      });
      expect(response.status()).toBe(401);
    }

    // 6th attempt should be rate-limited (429)
    const blocked = await request.post(`${BASE_URL}/api/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
    expect(blocked.status()).toBe(429);
    const body = await blocked.json();
    expect(body.error).toBe('too_many_attempts');
  });
});
```

**Step 2: Run test to verify it fails**

```bash
cd e2etests && npm test -- --grep "Rate Limiting" --workers=1
```

Expected: Fails because rate limiting doesn't exist.

**Step 3: Create migration for login_attempts table**

File: `backend/db/migrations/002_login_attempts.sql`

```sql
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 4: Create RateLimitMiddleware**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private PDO $pdo) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        // Count recent failed attempts from this IP
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)'
        );
        $stmt->execute(['ip' => $ip, 'window' => self::WINDOW_MINUTES]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= self::MAX_ATTEMPTS) {
            $response = new SlimResponse(429);
            $response->getBody()->write(json_encode([
                'error' => 'too_many_attempts',
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after_seconds' => self::WINDOW_MINUTES * 60,
            ]));
            return $response->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)(self::WINDOW_MINUTES * 60));
        }

        return $handler->handle($request);
    }
}
```

**Step 5: Record failed attempts in AuthController**

In `AuthController::login()`, after returning 401 for invalid credentials, insert into `login_attempts`:

```php
// After the auth failure block (before return 401):
$ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
$stmt = $this->pdo->prepare('INSERT INTO login_attempts (ip_address, email) VALUES (:ip, :email)');
$stmt->execute(['ip' => $ip, 'email' => $body['email']]);
```

On successful login, clear attempts for that IP:

```php
// After successful login:
$ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
$stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip');
$stmt->execute(['ip' => $ip]);
```

AuthController needs PDO injected. Add it as a constructor parameter and update ServiceFactory.

**Step 6: Wire RateLimitMiddleware to login route**

In `routes.php`, add rate limiting to the login route only:

```php
$app->post('/api/auth/login', [AuthController::class, 'login'])
    ->add(RateLimitMiddleware::class);
```

Add to ServiceFactory FQCN_MAP and getter:

```php
RateLimitMiddleware::class => 'getRateLimitMiddleware',

public function getRateLimitMiddleware(): RateLimitMiddleware
{
    return $this->resolve(RateLimitMiddleware::class, fn() => new RateLimitMiddleware($this->pdo));
}
```

**Step 7: Run migration**

```bash
docker compose exec backend php -r "
\$pdo = new PDO('mysql:host=database;dbname=clubbar', 'root', 'root');
\$pdo->exec(file_get_contents('/app/db/migrations/002_login_attempts.sql'));
echo 'Migration complete';
"
```

**Step 8: Restart PHP and run tests**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
cd e2etests && npm test -- --grep "Rate Limiting" --workers=1
```

Expected: Rate limiting test passes.

**Step 9: Run full E2E suite to check for regressions**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests pass (rate limiting only affects login with >5 failures).

**Step 10: Commit**

```bash
git add backend/src/Shared/Middleware/RateLimitMiddleware.php \
  backend/db/migrations/002_login_attempts.sql \
  backend/src/Modules/Auth/Controllers/AuthController.php \
  backend/src/ServiceFactory.php \
  backend/src/routes.php \
  e2etests/tests/api/rate-limiting.spec.ts
git commit -m "feat: add login rate limiting (5 attempts/15min) per ADR-0017"
```

---

## Task 7: Remove Obsolete Use Cases

**Files:**
- Delete: `use-cases/admin/UC-A16-import-members.md`
- Delete: `use-cases/admin/UC-A70-unassigned-cards.md`
- Delete: `use-cases/admin/UC-A71-block-card.md`

**Step 1: Delete the files**

```bash
git rm use-cases/admin/UC-A16-import-members.md \
  use-cases/admin/UC-A70-unassigned-cards.md \
  use-cases/admin/UC-A71-block-card.md
```

**Step 2: Update use-cases/admin/README.md if it references these**

Check and remove any references to UC-A16, UC-A70, UC-A71.

**Step 3: Commit**

```bash
git commit -m "docs: remove obsolete use cases UC-A16, UC-A70, UC-A71

UC-A16 (member import): Not needed for small clubs.
UC-A70/UC-A71 (unassigned cards, block card): ADR-0021 simplified
to manual UID entry only; unknown card workflow removed."
```

---

## Task 8: Update ADR-0017 to Document Implemented Strategy

**Files:**
- Modify: `adr/0017-input-validation-injection-prevention.md`

**Step 1: Update CSRF section**

After CSRF and rate limiting are implemented (Tasks 5-6), update ADR-0017's CSRF section to note the actual implementation:
- CSRF token generated on login, stored in session
- Token returned in login and profile responses
- Frontend sends via `X-CSRF-Token` header
- Middleware validates on POST/PATCH/DELETE for admin routes
- Terminal API exempt (Bearer token provides CSRF protection)

Update rate limiting section to note:
- `login_attempts` table tracks failed attempts
- 5 attempts per IP per 15 minutes
- Successful login clears attempts
- 429 response with Retry-After header

**Step 2: Commit**

```bash
git add adr/0017-input-validation-injection-prevention.md
git commit -m "docs: update ADR-0017 to reflect implemented CSRF and rate limiting"
```

---

## Task 9: Update Status Document

**Files:**
- Modify: `plans/adr-implementation-status.md`

**Step 1: Update all status entries**

After all tasks complete, update the status document:
- ADR-0003: [x] → deployment docs reference added
- ADR-0014: [x] → rewritten for Flutter
- ADR-0016: [x] → deployment docs cover TLS setup
- ADR-0017: [x] → CSRF + rate limiting implemented
- ADR-0021: [x] → simplified to manual entry
- ADR-0023: [x] → accepted
- ADR-0024: [x] → accepted
- Update "Overall" to 23/23 fully implemented
- Remove "Unimplemented Use Cases" for UC-A16, UC-A70, UC-A71
- Update UC-A60, UC-DSGVO-04, UC-DSGVO-05 as implemented

**Step 2: Commit**

```bash
git add plans/adr-implementation-status.md
git commit -m "docs: update ADR implementation status — all 23 ADRs resolved"
```
